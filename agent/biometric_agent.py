#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
biometric_agent.py - zero-touch attendance sync worker for the EPH A6 terminal.

Runs silently on a Windows client PC. On every poll it tries, in order:

    1. SERIAL   - auto-detects the CH340/CH341 virtual COM port (any COMn),
                  auto-negotiates baud + frame format, then pulls the device
                  attendance buffer using ZKTeco command frames.
    2. NETWORK  - if an IP is configured, pulls the same buffer via pyzk
                  (UDP/TCP 4370). This is the best-documented path.
    3. FILES    - watches removable drives / folders for the terminal's own
                  "Individual Report_*.XLS" exports and parses the punches
                  out of them. Always works, needs no driver.

Whatever it collects is normalised to (employee_id, punch_time, punch_state)
and pushed to the Render API endpoint and/or straight into Aiven MySQL.
Dedup is enforced by a UNIQUE key on (employee_id, punch_time), so re-running
the sync - or running two PCs at once - can never double-count a punch.

The process never exits on error: every failure path backs off and retries.

Config lives in config.ini beside the executable, NOT baked into the binary.
"""

from __future__ import annotations

import configparser
import ctypes
import glob
import json
import logging
import logging.handlers
import os
import re
import socket
import string
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from struct import pack, unpack

VERSION = "1.0.0"
USHRT_MAX = 65535


# --------------------------------------------------------------------------
#  Paths - must work both as a .py and as a frozen PyInstaller bundle.
# --------------------------------------------------------------------------

def app_dir() -> str:
    """Directory holding config.ini / logs. For a frozen exe this is the
    folder the exe sits in, NOT the temp folder PyInstaller unpacks into."""
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


APP_DIR = app_dir()
CONFIG_PATH = os.path.join(APP_DIR, "config.ini")


# --------------------------------------------------------------------------
#  Configuration
# --------------------------------------------------------------------------

DEFAULTS = {
    "device": {
        "device_id": "EPH-A6-01",
        "serial_enabled": "true",
        "vid_pid_filter": "1A86:7523,1A86:5523,1A86:7522,1A86:55D4",
        "port_override": "",
        "baud": "",              # blank => auto-negotiate and remember
        "framing": "",           # blank => auto-negotiate (raw|tcp)
        "poll_seconds": "20",
        "serial_timeout": "2.0",
    },
    "network": {
        "enabled": "false",
        "ip": "",
        "port": "4370",
        "timeout": "5",
        "force_udp": "false",
    },
    "watch": {
        "enabled": "true",
        "drives_auto": "true",
        "folders": "",
        "patterns": "Individual Report*.XLS,Individual Report*.xls,Attendance Summary*.XLS,Attendance Summary*.xls,*AttLog*.dat",
        "archive": "true",
    },
    "sync": {
        "mode": "api",           # api | db | both
        "api_url": "",
        "api_key": "",
        "batch_size": "200",
        "lookback_days": "45",
        "http_timeout": "30",
    },
    "database": {
        "driver": "mysql",       # mysql | postgres
        "host": "",
        "port": "3306",
        "name": "defaultdb",
        "user": "",
        "password": "",
        "ssl_ca": "ca.pem",
    },
    "agent": {
        "log_level": "INFO",
        "log_dir": "",
        "state_file": "agent_state.json",
        "single_instance": "true",
    },
}


def load_config() -> configparser.ConfigParser:
    """Read config.ini, filling in any missing key from DEFAULTS. Writes the
    file back out on first run so the operator has something to edit."""
    cfg = configparser.ConfigParser()
    for section, values in DEFAULTS.items():
        cfg[section] = dict(values)

    if os.path.exists(CONFIG_PATH):
        try:
            cfg.read(CONFIG_PATH, encoding="utf-8")
        except (configparser.Error, OSError) as exc:
            # A corrupt config must not stop the agent - fall back to defaults.
            print("config.ini unreadable (%s); using defaults" % exc)
    else:
        save_config(cfg)

    # Re-fill anything the operator deleted from the file.
    for section, values in DEFAULTS.items():
        if not cfg.has_section(section):
            cfg.add_section(section)
        for key, value in values.items():
            if not cfg.has_option(section, key):
                cfg.set(section, key, value)
    return cfg


def save_config(cfg: configparser.ConfigParser) -> None:
    """Persist config, including values the agent auto-negotiated (baud etc.)
    so the next start is instant instead of re-sweeping."""
    try:
        with open(CONFIG_PATH, "w", encoding="utf-8") as handle:
            cfg.write(handle)
    except OSError as exc:
        logging.getLogger("agent").warning("could not write config.ini: %s", exc)


# --------------------------------------------------------------------------
#  Logging - rotating file, because --windowed means there is no console.
# --------------------------------------------------------------------------

def setup_logging(cfg: configparser.ConfigParser) -> logging.Logger:
    log_dir = cfg["agent"]["log_dir"].strip() or os.path.join(APP_DIR, "logs")
    try:
        os.makedirs(log_dir, exist_ok=True)
    except OSError:
        log_dir = APP_DIR

    logger = logging.getLogger("agent")
    logger.setLevel(getattr(logging, cfg["agent"]["log_level"].upper(), logging.INFO))
    logger.handlers.clear()

    fmt = logging.Formatter("%(asctime)s %(levelname)-7s %(message)s")

    handler = logging.handlers.RotatingFileHandler(
        os.path.join(log_dir, "biometric_agent.log"),
        maxBytes=2000000, backupCount=5, encoding="utf-8",
    )
    handler.setFormatter(fmt)
    logger.addHandler(handler)

    # Only attach a console handler when one actually exists (running as .py).
    if sys.stderr is not None and not getattr(sys, "frozen", False):
        console = logging.StreamHandler()
        console.setFormatter(fmt)
        logger.addHandler(console)

    return logger


log = logging.getLogger("agent")


# --------------------------------------------------------------------------
#  Punch record - the one shape everything else speaks.
# --------------------------------------------------------------------------

class Punch(object):
    """A single normalised fingerprint event.

    punch_state follows the ZKTeco convention:
        0 check-in   1 check-out   2 break-out
        3 break-in   4 ot-in       5 ot-out
    """

    __slots__ = ("employee_id", "punch_time", "punch_state", "verify_mode", "source")

    def __init__(self, employee_id, punch_time, punch_state=0, verify_mode=0, source="serial"):
        self.employee_id = str(employee_id).strip()
        self.punch_time = punch_time          # datetime
        self.punch_state = int(punch_state)
        self.verify_mode = int(verify_mode)
        self.source = source

    def key(self):
        return (self.employee_id, self.punch_time.replace(microsecond=0))

    def as_dict(self):
        return {
            "employee_id": self.employee_id,
            # Exactly the SQL shape the schema expects.
            "punch_time": self.punch_time.strftime("%Y-%m-%d %H:%M:%S"),
            "punch_state": self.punch_state,
            "verify_mode": self.verify_mode,
            "source": self.source,
        }

    def __repr__(self):
        return "<Punch %s %s s=%d>" % (
            self.employee_id, self.punch_time.strftime("%Y-%m-%d %H:%M:%S"), self.punch_state)


def dedupe(punches):
    """Collapse identical (employee, second) events collected from more than
    one transport - e.g. the same punch seen over serial and in an export."""
    seen, out = set(), []
    for punch in punches:
        k = punch.key()
        if k not in seen:
            seen.add(k)
            out.append(punch)
    out.sort(key=lambda p: (p.punch_time, p.employee_id))
    return out


# ==========================================================================
#  ZKTeco wire protocol
# ==========================================================================

CMD_CONNECT = 1000
CMD_EXIT = 1001
CMD_ENABLEDEVICE = 1002
CMD_DISABLEDEVICE = 1003
CMD_ACK_OK = 2000
CMD_ACK_ERROR = 2001
CMD_ACK_DATA = 2002
CMD_ACK_UNAUTH = 2005
CMD_PREPARE_DATA = 1500
CMD_DATA = 1501
CMD_FREE_DATA = 1502
CMD_ATTLOG_RRQ = 13
CMD_GET_TIME = 201
CMD_AUTH = 1102


def zk_checksum(buf):
    """ZKTeco's 16-bit ones-complement checksum over the packet with the
    checksum field itself zeroed."""
    data = bytearray(buf)
    total = 0
    idx = 0
    while len(data) - idx > 1:
        total += unpack("<H", bytes(data[idx:idx + 2]))[0]
        idx += 2
        if total > USHRT_MAX:
            total -= USHRT_MAX
    if len(data) - idx:
        total += data[idx]
    while total > USHRT_MAX:
        total -= USHRT_MAX
    total = ~total
    while total < 0:
        total += USHRT_MAX
    return pack("<H", total)


def zk_packet(command, session_id=0, reply_id=0, data=b""):
    """Build a raw ZK command packet: cmd, checksum, session, reply, payload."""
    buf = pack("<4H", command, 0, session_id, reply_id) + data
    checksum = unpack("<H", zk_checksum(buf))[0]
    return pack("<4H", command, checksum, session_id, reply_id) + data


def zk_frame(packet, framing):
    """Wrap a raw packet for the chosen transport framing.

    raw - packet as-is (UDP style; what most serial bridges expect)
    tcp - prefixed with ZK's 8-byte TCP header (some USB-CDC units tunnel this)
    """
    if framing == "tcp":
        return pack("<HHI", 0x5050, 0x7D82, len(packet)) + packet
    return packet


def zk_unframe(buf, framing):
    """Strip the TCP header if present. Tolerates a missing/partial header."""
    if len(buf) >= 8 and buf[:4] == b"\x50\x50\x82\x7d":
        return buf[8:]
    return buf


def zk_parse_head(buf):
    """Return (command, session_id, reply_id, payload) or None if too short."""
    if len(buf) < 8:
        return None
    command, _checksum, session_id, reply_id = unpack("<4H", buf[:8])
    return command, session_id, reply_id, buf[8:]


def zk_decode_time(raw):
    """Decode ZK's packed 4-byte timestamp into a datetime."""
    value = unpack("<I", raw)[0]
    second = value % 60
    value //= 60
    minute = value % 60
    value //= 60
    hour = value % 24
    value //= 24
    day = value % 31 + 1
    value //= 31
    month = value % 12 + 1
    value //= 12
    year = value + 2000
    try:
        return datetime(year, month, day, hour, minute, second)
    except ValueError:
        # Corrupt record - surface as epoch so the caller can drop it.
        return datetime(1970, 1, 1)


def zk_parse_attlog(payload, source):
    """Split an attendance-log blob into Punch objects.

    Modern devices emit 40-byte records, older ones 16-byte. Pick by size.
    """
    punches = []
    if not payload:
        return punches

    if len(payload) % 40 == 0:
        size, fmt = 40, "<H24sB4sB8s"
    elif len(payload) % 16 == 0:
        size, fmt = 16, "<H4sB4sB4s"
    else:
        log.warning("attlog blob of %d bytes matches no known record size", len(payload))
        return punches

    for offset in range(0, len(payload), size):
        chunk = payload[offset:offset + size]
        try:
            _uid, user_id, status, timestamp, punch_state, _pad = unpack(fmt, chunk)
        except Exception:
            continue

        employee_id = user_id.split(b"\x00")[0].decode("ascii", "ignore").strip()
        if not employee_id:
            continue

        when = zk_decode_time(timestamp)
        if when.year < 2000:
            continue

        punches.append(Punch(employee_id, when, punch_state, status, source))

    return punches


# ==========================================================================
#  Serial transport - COM port discovery + auto-negotiation
# ==========================================================================

try:
    import serial                       # pyserial
    from serial.tools import list_ports
    HAVE_SERIAL = True
except ImportError:                     # keep the agent alive without pyserial
    serial = None
    list_ports = None
    HAVE_SERIAL = False

BAUD_SWEEP = [115200, 57600, 38400, 19200, 9600]
FRAMING_SWEEP = ["raw", "tcp"]


def candidate_ports(cfg):
    """Every COM port that could plausibly be the terminal, best guess first.

    Matching is by USB VID:PID, so it does not matter whether Windows hands
    out COM3, COM4 or COM17 - and it survives the cable moving to another PC.
    """
    if not HAVE_SERIAL:
        return []

    override = cfg["device"]["port_override"].strip()
    if override:
        return [override]

    wanted = set()
    for token in cfg["device"]["vid_pid_filter"].split(","):
        token = token.strip().upper()
        if ":" in token:
            wanted.add(token)

    preferred, fallback = [], []
    for port in list_ports.comports():
        tag = ""
        if port.vid is not None and port.pid is not None:
            tag = "%04X:%04X" % (port.vid, port.pid)

        haystack = " ".join([x for x in (port.description, port.manufacturer, port.hwid) if x]).upper()
        if tag in wanted or "CH340" in haystack or "CH341" in haystack or "CH9102" in haystack:
            preferred.append(port.device)
        else:
            fallback.append(port.device)

    if preferred:
        log.debug("CH34x ports: %s", preferred)
    # Try the known-good chips first, then anything else that showed up.
    return preferred + fallback


def serial_try_handshake(port_name, baud, framing, timeout):
    """Open the port and attempt CMD_CONNECT.
    Returns (handle, session_id, ack) on success, else None."""
    handle = None
    try:
        handle = serial.Serial(port=port_name, baudrate=baud, bytesize=8,
                               parity="N", stopbits=1, timeout=timeout,
                               write_timeout=timeout)
        handle.reset_input_buffer()
        handle.reset_output_buffer()

        handle.write(zk_frame(zk_packet(CMD_CONNECT), framing))
        handle.flush()

        reply = handle.read(1024)
        if not reply:
            handle.close()
            return None

        head = zk_parse_head(zk_unframe(reply, framing))
        if head and head[0] in (CMD_ACK_OK, CMD_ACK_UNAUTH):
            # head[1] is the session id the device just assigned us.
            return handle, head[1], head[0]

        handle.close()
        return None
    except Exception:
        if handle is not None:
            try:
                handle.close()
            except Exception:
                pass
        return None


def serial_open(cfg):
    """Find the terminal and return (handle, session_id, framing), or None.

    On the first successful negotiation the winning baud + framing are written
    back to config.ini, so subsequent starts connect on the first attempt.
    """
    if not HAVE_SERIAL or cfg["device"]["serial_enabled"].strip().lower() != "true":
        return None

    timeout = float(cfg["device"]["serial_timeout"] or 2.0)

    # Honour a remembered/pinned combination before sweeping.
    pinned_baud = cfg["device"]["baud"].strip()
    pinned_frame = cfg["device"]["framing"].strip()
    bauds = [int(pinned_baud)] if pinned_baud.isdigit() else BAUD_SWEEP
    frames = [pinned_frame] if pinned_frame in FRAMING_SWEEP else FRAMING_SWEEP

    for port_name in candidate_ports(cfg):
        for framing in frames:
            for baud in bauds:
                result = serial_try_handshake(port_name, baud, framing, timeout)
                if not result:
                    continue

                handle, session_id, ack = result
                if ack == CMD_ACK_UNAUTH:
                    log.error("%s answered but demands a comm key - set it on the "
                              "device or clear it in the terminal menu", port_name)
                    handle.close()
                    continue

                log.info("connected: %s @ %d (%s framing), session %d",
                         port_name, baud, framing, session_id)

                # Remember what worked.
                if cfg["device"]["baud"] != str(baud) or cfg["device"]["framing"] != framing:
                    cfg["device"]["baud"] = str(baud)
                    cfg["device"]["framing"] = framing
                    save_config(cfg)

                return handle, session_id, framing

    return None


def serial_command(handle, framing, command, session_id, reply_id, data=b"", timeout=3.0):
    """Send one command and collect the reply, including any streamed data."""
    handle.reset_input_buffer()
    handle.write(zk_frame(zk_packet(command, session_id, reply_id, data), framing))
    handle.flush()

    deadline = time.time() + timeout
    buf = b""
    while time.time() < deadline:
        chunk = handle.read(4096)
        if chunk:
            buf += chunk
            deadline = time.time() + 0.5      # keep draining while data flows
        elif buf:
            break
    return zk_unframe(buf, framing)


def serial_read_attlog(cfg):
    """Full serial session: connect, freeze the device, drain the log, release."""
    opened = serial_open(cfg)
    if not opened:
        return []

    handle, session_id, framing = opened
    reply_id = 0
    punches = []

    try:
        # Freezing the terminal keeps the buffer stable while we read it.
        reply_id += 1
        serial_command(handle, framing, CMD_DISABLEDEVICE, session_id, reply_id)

        reply_id += 1
        payload = serial_command(handle, framing, CMD_ATTLOG_RRQ, session_id, reply_id, timeout=8.0)

        head = zk_parse_head(payload)
        if head:
            command, _sid, _rid, body = head
            if command == CMD_PREPARE_DATA:
                # Device announced a size; the bulk follows in the same stream.
                trailing = body[4:] if len(body) > 4 else b""
                extra = serial_command(handle, framing, CMD_DATA, session_id,
                                       reply_id + 1, timeout=8.0)
                nxt = zk_parse_head(extra)
                body = trailing + (nxt[3] if nxt else extra)
            punches = zk_parse_attlog(body, "serial")

        log.info("serial: %d punches read", len(punches))
    except Exception as exc:
        log.warning("serial read failed: %s", exc)
    finally:
        # Always re-enable the terminal - a frozen clock locks staff out.
        try:
            serial_command(handle, framing, CMD_ENABLEDEVICE, session_id, reply_id + 2, timeout=1.0)
            serial_command(handle, framing, CMD_EXIT, session_id, reply_id + 3, timeout=1.0)
        except Exception:
            pass
        try:
            handle.close()
        except Exception:
            pass

    return punches


# ==========================================================================
#  Network transport - pyzk over UDP/TCP 4370
# ==========================================================================

def network_read_attlog(cfg):
    """Pull the buffer over IP. This is the best-documented ZK path; use it
    whenever the terminal has an ethernet/wifi port on the same LAN."""
    if cfg["network"]["enabled"].strip().lower() != "true":
        return []

    ip = cfg["network"]["ip"].strip()
    if not ip:
        return []

    try:
        from zk import ZK                       # pyzk
    except ImportError:
        log.warning("network sync configured but pyzk is not installed")
        return []

    conn = None
    punches = []
    try:
        zk = ZK(ip,
                port=int(cfg["network"]["port"] or 4370),
                timeout=int(cfg["network"]["timeout"] or 5),
                force_udp=cfg["network"]["force_udp"].strip().lower() == "true",
                ommit_ping=True)
        conn = zk.connect()
        conn.disable_device()
        for record in conn.get_attendance() or []:
            punches.append(Punch(record.user_id, record.timestamp,
                                 getattr(record, "punch", 0),
                                 getattr(record, "status", 0), "network"))
        log.info("network: %d punches read from %s", len(punches), ip)
    except Exception as exc:
        log.warning("network read failed (%s): %s", ip, exc)
    finally:
        if conn is not None:
            try:
                conn.enable_device()
                conn.disconnect()
            except Exception:
                pass

    return punches


# ==========================================================================
#  File transport - the terminal's own USB export
# ==========================================================================
#
#  "Individual Report_00001_09_001.XLS" is not a real XLS. It is Excel 2003
#  SpreadsheetML (XML), encoded gb2312, laid out as:
#
#     row 1  Attendance Summary
#     row 2  Company Name: | Name: | ID:00001 | Depart.: | Date:26.09.01~26.09.30
#     row 3  Working days:30 | Attendance days:1 | Late Num:0 | ...
#     row 5  Device ID: | Morning | Afternoon | Overtime  (merged header)
#     row 6  Date | Week | (IN) | (OUT) | (IN) | (OUT) | (IN) | (OUT)   x2
#     row 7+ 09.01 | Tue | ... | 09.17 | Thurs | ...
#
#  Two side-by-side 8-column blocks hold the two halves of the month, and the
#  day cells carry only MM.DD - the year comes from the "Date:" header. A
#  naive row reader therefore gets 16 days instead of 30, which is why this
#  parser locates block starts by scanning row 6 for every "Date" cell.

# Column offsets within one 8-wide day block, mapped to ZK punch states.
BLOCK_COLUMNS = [
    (2, 0),   # Morning   (IN)  -> check-in
    (3, 2),   # Morning   (OUT) -> break-out
    (4, 3),   # Afternoon (IN)  -> break-in
    (5, 1),   # Afternoon (OUT) -> check-out
    (6, 4),   # Overtime  (IN)  -> ot-in
    (7, 5),   # Overtime  (OUT) -> ot-out
]

# "ID:00001". The lookbehind keeps it off the "Device ID:" header in the
# column-title row, which would otherwise capture the word after it.
ID_RE = re.compile(r"(?<!Device )ID[:：]\s*([A-Za-z0-9_-]{1,32})")

# "Date:26.09.01~26.09.30" - only the first date is needed, for the year.
PERIOD_RE = re.compile(r"Date[:：]\s*(\d{2})\.(\d{2})\.(\d{2})")

ROW_RE = re.compile(r"<Row[^>]*>(.*?)</Row>", re.S)
CELL_RE = re.compile(r"<Cell([^>]*?)(?:/>|>(.*?)</Cell>)", re.S)
DATA_RE = re.compile(r"<Data[^>]*>(.*?)</Data>", re.S)
INDEX_RE = re.compile(r'ss:Index="(\d+)"')
MERGE_RE = re.compile(r'ss:MergeAcross="(\d+)"')
TAG_RE = re.compile(r"<[^>]+>")
ENTITY = {"&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": '"', "&apos;": "'", "&#10;": " "}


def _cell_text(raw):
    """Strip markup/entities out of one <Cell> body."""
    match = DATA_RE.search(raw or "")
    text = match.group(1) if match else (raw or "")
    text = TAG_RE.sub("", text)
    for entity, char in ENTITY.items():
        text = text.replace(entity, char)
    return text.strip()


def _sheet_rows(xml):
    """Expand SpreadsheetML rows into flat column lists.

    Honours ss:Index (sparse cells) and ss:MergeAcross (merged headers), both
    of which otherwise shift every column to the left.
    """
    rows = []
    for row_xml in ROW_RE.findall(xml):
        columns, position = [], 0
        for attrs, body in CELL_RE.findall(row_xml):
            index_match = INDEX_RE.search(attrs)
            if index_match:
                # ss:Index is 1-based and jumps over empty cells.
                target = int(index_match.group(1)) - 1
                while position < target:
                    columns.append("")
                    position += 1
            columns.append(_cell_text(body))
            position += 1

            merge_match = MERGE_RE.search(attrs)
            if merge_match:
                for _ in range(int(merge_match.group(1))):
                    columns.append("")
                    position += 1
        rows.append(columns)
    return rows


def parse_attendance_export(path):
    """Parse a terminal export into Punch objects.

    Handles both shapes the A6 produces, because they share one grid:

      Individual Report_<user>_<mm>_<nnn>.XLS
          one employee, a single "ID:" header at the top

      Attendance Summary_<nnn>_<mm>.XLS
          many employees, an "ID:" header repeating before each block

    Rather than special-casing the two, it walks the sheet top to bottom and
    tracks whichever employee and period header it last saw - which collapses
    to the single-employee case on its own.

    Returns [] for anything that is not one of these, so the watcher can skip
    foreign files quietly.
    """
    raw = None
    for encoding in ("gb2312", "gbk", "utf-8", "latin-1"):
        try:
            with open(path, "r", encoding=encoding, errors="strict") as handle:
                raw = handle.read()
            break
        except (UnicodeDecodeError, LookupError):
            continue
        except OSError as exc:
            log.warning("cannot read %s: %s", path, exc)
            return []

    if raw is None:
        with open(path, "r", encoding="latin-1", errors="replace") as handle:
            raw = handle.read()

    if "<Workbook" not in raw:
        return []

    name = os.path.basename(path)

    rows = _sheet_rows(raw)
    if not rows:
        # A styles-and-columns skeleton with ExpandedRowCount="0". The
        # terminal writes one of these when the export matched no records.
        # Not an error - just nothing to load.
        log.info("%s: export contains no rows (nothing was exported)", name)
        return []

    # Walk top to bottom, carrying the last employee and period header seen.
    # A summary export repeats those before each employee block; an individual
    # report simply has one set, so the same loop covers both.
    device_user_id = None
    base_year = None
    base_month = None
    block_starts = []

    punches = []
    employees_seen = set()

    for columns in rows:
        joined = " ".join(columns)

        # -- section header: whose block are we in now? --
        id_match = ID_RE.search(joined)
        if id_match:
            token = id_match.group(1).strip()
            # Terminal user numbers always carry a digit; this rejects stray
            # matches on decorative header text.
            if any(char.isdigit() for char in token):
                device_user_id = token

        # -- section header: which month do the MM.DD cells belong to? --
        period_match = PERIOD_RE.search(joined)
        if period_match:
            base_year = 2000 + int(period_match.group(1))
            base_month = int(period_match.group(2))

        # -- column-title row: locate every 8-wide day block --
        starts = [i for i, value in enumerate(columns) if value.lower() == "date"]
        if starts and any(v.upper().startswith("(IN") for v in columns):
            block_starts = starts
            continue

        # -- data row --
        if not block_starts or device_user_id is None or base_year is None:
            continue

        for start in block_starts:
            if start >= len(columns):
                continue

            day_cell = columns[start].strip()
            day_match = re.match(r"^(\d{1,2})\.(\d{1,2})$", day_cell)
            if not day_match:
                continue

            month, day = int(day_match.group(1)), int(day_match.group(2))
            # The export can straddle a year boundary (Dec -> Jan).
            year = base_year + 1 if month < base_month else base_year

            for offset, state in BLOCK_COLUMNS:
                position = start + offset
                if position >= len(columns):
                    continue

                time_cell = columns[position].strip()
                time_match = re.match(r"^(\d{1,2}):(\d{2})(?::(\d{2}))?$", time_cell)
                if not time_match:
                    continue

                hour = int(time_match.group(1))
                minute = int(time_match.group(2))
                second = int(time_match.group(3) or 0)
                try:
                    stamp = datetime(year, month, day, hour, minute, second)
                except ValueError:
                    continue

                punches.append(Punch(device_user_id, stamp, state, 0, "file"))
                employees_seen.add(device_user_id)

    if device_user_id is None:
        log.warning("%s: no employee ID header - not a recognised export", name)
        return []

    log.info("%s: %d punches across %d employee(s)",
             name, len(punches), len(employees_seen))
    return punches


# The watcher and probe call this name; keep it working.
parse_individual_report = parse_attendance_export


def watch_folders(cfg):
    """Folders to scan: the configured list plus every removable drive root."""
    folders = []
    for entry in cfg["watch"]["folders"].split(","):
        entry = entry.strip()
        if entry:
            folders.append(entry)

    if cfg["watch"]["drives_auto"].strip().lower() == "true":
        for letter in string.ascii_uppercase[3:]:          # skip A, B, C
            root = "%s:\\" % letter
            if not os.path.isdir(root):
                continue
            folders.append(root)
            # Terminals commonly drop reports one level down.
            for sub in ("Report", "REPORT", "attlog", "ATTLOG", "GLG"):
                candidate = os.path.join(root, sub)
                if os.path.isdir(candidate):
                    folders.append(candidate)

    return folders


def file_read_attlog(cfg, state):
    """Scan for new terminal exports and parse them.

    Each file is remembered by path+mtime+size, so a USB stick that stays
    plugged in is not re-parsed on every poll.
    """
    if cfg["watch"]["enabled"].strip().lower() != "true":
        return []

    patterns = [p.strip() for p in cfg["watch"]["patterns"].split(",") if p.strip()]
    seen = state.setdefault("seen_files", {})
    punches = []

    for folder in watch_folders(cfg):
        for pattern in patterns:
            try:
                matches = glob.glob(os.path.join(folder, pattern))
            except OSError:
                continue

            for path in matches:
                try:
                    stat = os.stat(path)
                except OSError:
                    continue

                fingerprint = "%d:%d" % (int(stat.st_mtime), stat.st_size)
                if seen.get(path) == fingerprint:
                    continue          # unchanged since last scan

                try:
                    found = parse_individual_report(path)
                except Exception as exc:
                    log.warning("parse failed for %s: %s", path, exc)
                    found = []

                # Record it either way so a bad file is not retried forever.
                seen[path] = fingerprint
                punches.extend(found)

    # Keep the ledger from growing without bound across many USB sticks.
    if len(seen) > 500:
        for key in list(seen)[:-500]:
            seen.pop(key, None)

    return punches


# ==========================================================================
#  Sinks - Render API and direct Aiven database
# ==========================================================================

def post_to_api(cfg, punches):
    """POST a batch to the Render endpoint. Returns True when accepted.

    This is the preferred sink: the PC only ever holds a revocable per-device
    API key, never the database credentials.
    """
    url = cfg["sync"]["api_url"].strip()
    key = cfg["sync"]["api_key"].strip()
    if not url:
        log.error("sync.mode includes api but sync.api_url is empty")
        return False

    payload = {
        "device_id": cfg["device"]["device_id"].strip(),
        "agent_version": VERSION,
        "host": socket.gethostname(),
        "punches": [p.as_dict() for p in punches],
    }
    body = json.dumps(payload).encode("utf-8")

    request = urllib.request.Request(url, data=body, method="POST")
    request.add_header("Content-Type", "application/json")
    request.add_header("X-API-Key", key)
    request.add_header("User-Agent", "biometric-agent/%s" % VERSION)

    try:
        timeout = int(cfg["sync"]["http_timeout"] or 30)
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", "replace")
            try:
                result = json.loads(raw)
            except ValueError:
                log.warning("api returned non-JSON: %s", raw[:200])
                return False

            log.info("api: sent %d, inserted %s, duplicates %s",
                     len(punches), result.get("inserted"), result.get("duplicates"))
            return bool(result.get("ok"))
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", "replace")[:200]
        if exc.code in (401, 403):
            log.error("api rejected the key (HTTP %d): %s", exc.code, detail)
        else:
            log.warning("api HTTP %d: %s", exc.code, detail)
        return False
    except (urllib.error.URLError, socket.timeout, OSError) as exc:
        # Offline / Render cold start - the caller will retry next poll.
        log.warning("api unreachable: %s", exc)
        return False


def db_connect(cfg):
    """Open a TLS connection straight to Aiven. Returns (conn, driver)."""
    driver = cfg["database"]["driver"].strip().lower()
    host = cfg["database"]["host"].strip()
    if not host:
        raise RuntimeError("database.host is empty")

    ssl_ca = cfg["database"]["ssl_ca"].strip()
    if ssl_ca and not os.path.isabs(ssl_ca):
        ssl_ca = os.path.join(APP_DIR, ssl_ca)
    if ssl_ca and not os.path.exists(ssl_ca):
        log.warning("ssl_ca %s not found - connecting without CA pinning", ssl_ca)
        ssl_ca = ""

    if driver == "postgres":
        import psycopg2
        conn = psycopg2.connect(
            host=host,
            port=int(cfg["database"]["port"] or 5432),
            dbname=cfg["database"]["name"],
            user=cfg["database"]["user"],
            password=cfg["database"]["password"],
            sslmode="verify-ca" if ssl_ca else "require",
            sslrootcert=ssl_ca or None,
            connect_timeout=15,
        )
        return conn, "postgres"

    import pymysql
    conn = pymysql.connect(
        host=host,
        port=int(cfg["database"]["port"] or 3306),
        user=cfg["database"]["user"],
        password=cfg["database"]["password"],
        database=cfg["database"]["name"],
        charset="utf8mb4",
        ssl={"ca": ssl_ca} if ssl_ca else {"ssl": {}},
        connect_timeout=15,
        autocommit=False,
    )
    return conn, "mysql"


def write_to_db(cfg, punches):
    """UPSERT straight into Aiven. Duplicate punches are dropped by the
    UNIQUE key on (employee_id, punch_time), so re-syncs are free."""
    conn = None
    try:
        conn, driver = db_connect(cfg)
        device_id = cfg["device"]["device_id"].strip()

        if driver == "postgres":
            sql = ("INSERT INTO attendance_logs "
                   "(employee_id, punch_time, punch_state, verify_mode, device_id, source) "
                   "VALUES (%s, %s, %s, %s, %s, %s) "
                   "ON CONFLICT (employee_id, punch_time) DO NOTHING")
        else:
            sql = ("INSERT IGNORE INTO attendance_logs "
                   "(employee_id, punch_time, punch_state, verify_mode, device_id, source) "
                   "VALUES (%s, %s, %s, %s, %s, %s)")

        rows = [(p.employee_id, p.punch_time.strftime("%Y-%m-%d %H:%M:%S"),
                 p.punch_state, p.verify_mode, device_id, p.source) for p in punches]

        with conn.cursor() as cursor:
            cursor.executemany(sql, rows)
            inserted = cursor.rowcount

            # Record which PC the terminal is plugged into right now.
            heartbeat = ("INSERT INTO biometric_agent_state "
                         "(device_id, agent_host, agent_version, last_sync_at) "
                         "VALUES (%s, %s, %s, CURRENT_TIMESTAMP) ")
            if driver == "postgres":
                heartbeat += ("ON CONFLICT (device_id) DO UPDATE SET "
                              "agent_host = EXCLUDED.agent_host, "
                              "agent_version = EXCLUDED.agent_version, "
                              "last_sync_at = CURRENT_TIMESTAMP")
            else:
                heartbeat += ("ON DUPLICATE KEY UPDATE "
                              "agent_host = VALUES(agent_host), "
                              "agent_version = VALUES(agent_version), "
                              "last_sync_at = CURRENT_TIMESTAMP")
            cursor.execute(heartbeat, (device_id, socket.gethostname(), VERSION))

        conn.commit()
        log.info("db: sent %d, %d new", len(punches), max(inserted, 0))
        return True
    except ImportError as exc:
        log.error("database driver missing: %s", exc)
        return False
    except Exception as exc:
        log.warning("db write failed: %s", exc)
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
        return False
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


def sync(cfg, punches):
    """Push one batch through whichever sinks are enabled.

    'both' is treated as belt-and-braces: success from either sink counts,
    because the UNIQUE key makes a double-write harmless.
    """
    mode = cfg["sync"]["mode"].strip().lower()
    batch_size = max(1, int(cfg["sync"]["batch_size"] or 200))
    all_ok = True

    for start in range(0, len(punches), batch_size):
        batch = punches[start:start + batch_size]
        ok = False

        if mode in ("api", "both"):
            ok = post_to_api(cfg, batch) or ok
        if mode in ("db", "both"):
            ok = write_to_db(cfg, batch) or ok

        if mode not in ("api", "db", "both"):
            log.error("unknown sync.mode %r - nothing sent", mode)
            return False

        all_ok = all_ok and ok

    return all_ok


# ==========================================================================
#  Local state
# ==========================================================================

def state_path(cfg):
    name = cfg["agent"]["state_file"].strip() or "agent_state.json"
    return name if os.path.isabs(name) else os.path.join(APP_DIR, name)


def load_state(cfg):
    try:
        with open(state_path(cfg), "r", encoding="utf-8") as handle:
            return json.load(handle)
    except (OSError, ValueError):
        return {}


def save_state(cfg, state):
    try:
        with open(state_path(cfg), "w", encoding="utf-8") as handle:
            json.dump(state, handle, indent=2)
    except OSError as exc:
        log.warning("could not write state: %s", exc)


def single_instance_guard(cfg):
    """Named mutex so a Startup entry plus a manual launch do not double-sync."""
    if cfg["agent"]["single_instance"].strip().lower() != "true":
        return True
    if os.name != "nt":
        return True
    try:
        handle = ctypes.windll.kernel32.CreateMutexW(None, False, "Global\\BiometricAgentSingleton")
        if ctypes.windll.kernel32.GetLastError() == 183:   # ERROR_ALREADY_EXISTS
            return False
        # Leak the handle deliberately: it must live as long as the process.
        _ = handle
        return True
    except Exception:
        return True


# ==========================================================================
#  Main loop
# ==========================================================================

def collect(cfg, state):
    """One pass over every enabled transport."""
    punches = []
    for reader, label in ((serial_read_attlog, "serial"),
                          (network_read_attlog, "network")):
        try:
            punches.extend(reader(cfg))
        except Exception as exc:
            log.warning("%s transport error: %s", label, exc)

    try:
        punches.extend(file_read_attlog(cfg, state))
    except Exception as exc:
        log.warning("file transport error: %s", exc)

    return dedupe(punches)


def main():
    cfg = load_config()
    setup_logging(cfg)

    log.info("=" * 62)
    log.info("biometric_agent %s starting on %s", VERSION, socket.gethostname())
    log.info("app dir: %s", APP_DIR)

    if not single_instance_guard(cfg):
        log.info("another instance is already running - exiting")
        return 0

    if not HAVE_SERIAL:
        log.warning("pyserial not installed - serial transport disabled")

    state = load_state(cfg)

    poll_seconds = max(5, int(cfg["device"]["poll_seconds"] or 20))
    lookback_days = max(1, int(cfg["sync"]["lookback_days"] or 45))
    backoff = poll_seconds
    max_backoff = 300

    while True:
        try:
            # Reload config each pass so edits apply without a restart.
            cfg = load_config()
            punches = collect(cfg, state)

            # Trim ancient records: the device buffer holds months of history
            # and there is no point re-sending it forever. The DB's unique key
            # is still the real guard against duplicates.
            cutoff = datetime.now() - timedelta(days=lookback_days)
            fresh = [p for p in punches if p.punch_time >= cutoff]
            if len(fresh) != len(punches):
                log.debug("dropped %d punches older than %d days",
                          len(punches) - len(fresh), lookback_days)

            if fresh:
                if sync(cfg, fresh):
                    newest = max(p.punch_time for p in fresh)
                    state["last_punch_time"] = newest.strftime("%Y-%m-%d %H:%M:%S")
                    state["last_success"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    save_state(cfg, state)
                    backoff = poll_seconds          # healthy again
                else:
                    # Sync failed: forget the file fingerprints for this pass so
                    # the same exports are retried rather than silently lost.
                    state.pop("seen_files", None)
                    backoff = min(max_backoff, max(poll_seconds, backoff * 2))
                    log.info("retrying in %ds", backoff)
            else:
                save_state(cfg, state)
                backoff = poll_seconds

            time.sleep(backoff)

        except KeyboardInterrupt:
            log.info("interrupted - shutting down")
            return 0
        except Exception as exc:
            # Absolutely nothing is allowed to kill the loop.
            log.exception("unhandled error in main loop: %s", exc)
            backoff = min(max_backoff, max(poll_seconds, backoff * 2))
            time.sleep(backoff)


if __name__ == "__main__":
    sys.exit(main())
