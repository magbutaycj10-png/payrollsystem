#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
probe_device.py - one-off diagnostic for the EPH A6 serial link.

Run this ONCE on the PC the terminal is plugged into, before deploying the
agent. It answers three questions:

    1. Does the CH340/CH341 bridge show up as a COM port at all?
       (If not, the Mini-USB port is a file-export port, not a UART, and you
        should rely on the [watch] file transport instead.)
    2. Which baud rate and frame format does the device answer on?
    3. Does it return a parseable attendance log?

Usage:   python probe_device.py
Output:  printed to the console AND written to probe_report.txt
"""

import sys
import time

import biometric_agent as agent


def main():
    lines = []

    def emit(text=""):
        print(text)
        lines.append(text)

    emit("=" * 66)
    emit("EPH A6 serial probe - biometric_agent %s" % agent.VERSION)
    emit("=" * 66)
    emit()

    # ---- 1. Enumerate every COM port Windows knows about -----------------
    if not agent.HAVE_SERIAL:
        emit("FAIL: pyserial is not installed.  Run: pip install pyserial")
        return 1

    ports = list(agent.list_ports.comports())
    if not ports:
        emit("No COM ports found at all.")
        emit()
        emit("This usually means one of:")
        emit("  a) the CH341SER driver is not installed  -> run CH341SER.EXE")
        emit("  b) the cable is not a data cable         -> try another cable")
        emit("  c) the A6's Mini-USB is an export port, not a UART")
        emit()
        emit("If (c), that is fine - leave [device] serial_enabled = false and")
        emit("use the [watch] file transport. Export the report from the")
        emit("terminal to a USB stick and the agent will pick it up.")
        _write(lines)
        return 1

    emit("COM ports detected:")
    for port in ports:
        tag = "----:----"
        if port.vid is not None and port.pid is not None:
            tag = "%04X:%04X" % (port.vid, port.pid)
        emit("   %-6s  %-12s  %s" % (port.device, tag, port.description))
    emit()

    candidates = agent.candidate_ports(_fake_cfg())
    emit("Will try, in order: %s" % ", ".join(candidates))
    emit()

    # ---- 2. Sweep baud x framing looking for a valid CMD_ACK_OK ----------
    emit("Handshake sweep (CMD_CONNECT):")
    winner = None

    for port_name in candidates:
        for framing in agent.FRAMING_SWEEP:
            for baud in agent.BAUD_SWEEP:
                result = agent.serial_try_handshake(port_name, baud, framing, 1.5)
                status = "no reply"

                if result:
                    handle, session_id, ack = result
                    status = "ACK_OK session=%d" % session_id
                    if ack == agent.CMD_ACK_UNAUTH:
                        status = "ACK_UNAUTH (device wants a comm key)"
                    if winner is None and ack == agent.CMD_ACK_OK:
                        winner = (port_name, baud, framing)
                    handle.close()

                emit("   %-6s %-7s %-6d -> %s" % (port_name, framing, baud, status))
                time.sleep(0.05)

    emit()

    if not winner:
        emit("RESULT: nothing answered the ZK handshake on any port.")
        emit()
        emit("The A6's Mini-USB is most likely an export port rather than a")
        emit("serial link. Set serial_enabled = false in config.ini and use")
        emit("the file watcher - it is already proven against your sample.")
        _write(lines)
        return 1

    port_name, baud, framing = winner
    emit("RESULT: device answers on %s @ %d, %s framing." % (port_name, baud, framing))
    emit()
    emit("Put these in config.ini to skip the sweep on every start:")
    emit("   [device]")
    emit("   port_override = %s" % port_name)
    emit("   baud          = %d" % baud)
    emit("   framing       = %s" % framing)
    emit()

    # ---- 3. Try an actual attendance read --------------------------------
    emit("Attempting an attendance-log read...")
    cfg = _fake_cfg()
    cfg["device"]["port_override"] = port_name
    cfg["device"]["baud"] = str(baud)
    cfg["device"]["framing"] = framing

    punches = agent.serial_read_attlog(cfg)
    if punches:
        emit("Read %d punches. First 10:" % len(punches))
        for punch in punches[:10]:
            emit("   %s" % punch)
    else:
        emit("Handshake works but no punches came back.")
        emit("Either the buffer is empty, or this OEM uses a different")
        emit("attlog frame. Send probe_report.txt over and it can be decoded.")

    emit()
    _write(lines)
    return 0


def _fake_cfg():
    """A config object with defaults only - the probe ignores config.ini."""
    import configparser
    cfg = configparser.ConfigParser()
    for section, values in agent.DEFAULTS.items():
        cfg[section] = dict(values)
    return cfg


def _write(lines):
    try:
        with open("probe_report.txt", "w", encoding="utf-8") as handle:
            handle.write("\n".join(lines))
        print("\nWritten to probe_report.txt")
    except OSError as exc:
        print("could not write probe_report.txt: %s" % exc)


if __name__ == "__main__":
    sys.exit(main())
