# Biometric Attendance Sync — EPH A6 → Aiven → Payroll

Zero-touch background agent that pulls punches off the EPH A6 fingerprint
terminal on a Windows client PC and pushes them to the payroll system on
Render, which stages them in Aiven MySQL.

```
  EPH A6  ──USB/serial──┐
                        ├──▶ biometric_agent.exe ──HTTPS──▶ Render API
  USB export .XLS ──────┘         (client PC)                    │
                                                                 ▼
                                                    Aiven: attendance_logs
                                                                 │
                                          rollup-punches.php ────┤
                                                                 ▼
                                    biometric_daily → attendance → payroll
```

---

## Read this first: which transport will actually work?

The A6's Mini-USB port is **one of two different things**, and which one
decides how you deploy. Find out before anything else:

```
cd agent
pip install pyserial
python probe_device.py
```

| Probe result | What it means | What to do |
|---|---|---|
| A CH340/CH341 port answers the handshake | The port is a real UART | Leave `serial_enabled = true`. Copy the baud/framing it prints into `config.ini`. |
| A COM port appears but nothing answers | UART present, but this OEM's frame format differs | Send me `probe_report.txt` — the raw bytes are enough to decode it. Meanwhile the file watcher covers you. |
| No COM port at all | The port is for **file export**, not serial | Set `serial_enabled = false`. Use the file watcher — already proven against your sample. |

The last row is the most likely one, and it is not a failure. Your
`samples/Individual Report_00001_09_001.XLS` is exactly what these terminals
write to a USB stick, and the agent parses it correctly today:

```
Individual Report_00001_09_001.XLS: 1 punches (employee 00001)
<Punch 00001 2026-09-24 15:06:00 s=3>
```

With `drives_auto = true`, plugging that stick into the PC is the whole
workflow — the agent notices the file, parses it, uploads it, and never
re-parses it unless it changes.

### How the export files map to the database

Both export shapes are parsed by the same code path, since they share one grid:

| File | Shape | `ID:` headers |
|---|---|---|
| `Individual Report_<user>_<mm>_<nnn>.XLS` | one employee | one, at the top |
| `Attendance Summary_<nnn>_<mm>.XLS` | many employees | one before each block |

Every punch cell becomes one `attendance_logs` row:

| XLS source | → Column | Notes |
|---|---|---|
| `ID:00001` header | `employee_id` | Terminal number, not payroll `emp_id`. Resolved via `biometric_employee_map` at rollup time. |
| `MM.DD` cell + year from `Date:26.09.01~...` | `punch_time` | The day cells carry no year — it comes from the period header. Dec→Jan rollover handled. |
| *which* column the time sat in | `punch_state` | See mapping below |
| — | `verify_mode` | `0` for file imports; only serial/network carry it |
| `config.ini` → `device_id` | `device_id` | |
| — | `source` | `'file'`, `'serial'` or `'network'` |

Column → `punch_state`:

| Column | State | Meaning |
|---|---|---|
| Morning `(IN)` | 0 | check-in |
| Morning `(OUT)` | 2 | break-out |
| Afternoon `(IN)` | 3 | break-in |
| Afternoon `(OUT)` | 1 | check-out |
| Overtime `(IN)` | 4 | ot-in |
| Overtime `(OUT)` | 5 | ot-out |

**The device's own totals are deliberately ignored.** The header row carries
`Working days:30`, `Attendance days:1`, `Late Num:0`, `Early Num:0`,
`Absences days:29` — but those are computed with the *terminal's* shift rules,
which are not your payroll's. `rollup-punches.php` recomputes hours, late and
overtime from the raw punch times using the `settings` values instead, so one
set of rules governs pay. If you ever want the device figures for
cross-checking, they are easy to add — but they should not feed pay.

### Why not `pyzk` over the cable?

`pyzk` only speaks the ZK protocol over **UDP/TCP 4370** — its `ZK()` takes
an IP address and it has no serial transport at all. A CH340 link is a COM
port, which is a different channel entirely. So the agent implements the ZK
command frames over serial directly, and keeps `pyzk` for the `[network]`
path if you ever put the terminal on the LAN (that path is the most reliable
of the three — use it if the hardware allows).

---

## Part 1 — Database

Apply once against Aiven:

```bash
mysql --host=$DB_HOST \
      --port=$DB_PORT --user=$DB_USER --password=... \
      --ssl-ca=ca.pem defaultdb < payroll2/sql/001_attendance_logs.sql
```

No CLI handy? These four tables are also in `applySchemaPatches()`, so they
are created automatically on the next page load of the deployed app.

| Table | Purpose |
|---|---|
| `attendance_logs` | Raw punches. `UNIQUE (employee_id, punch_time)` makes re-syncs free. |
| `biometric_employee_map` | Terminal number (`00001`) → payroll `emp_id`. |
| `biometric_agent_state` | Which PC holds the terminal, and when it last reported. |
| `biometric_api_keys` | One SHA-256-hashed key per client PC. |

Postgres variant: `sql/001_attendance_logs.postgres.sql`.

### Map the terminal's user numbers to your employees

The A6 stores users as `00001`, `00002`. The rollup falls back to stripping
leading zeros (`00001` → `1`), which is right often enough to be dangerous.
Be explicit for anyone it gets wrong:

```sql
INSERT INTO biometric_employee_map (device_user_id, emp_id)
VALUES ('00001', 'EMP-001'), ('00002', 'EMP-002');
```

Anything unmapped comes back in the rollup's `unmatched[]` rather than being
silently dropped.

---

## Part 2 — Render endpoint

`payroll2/api/biometric-ingest.php` deploys with the rest of the app. Live at:

```
https://YOUR-APP.onrender.com/api/biometric-ingest.php
```

Issue one key per client PC:

```bash
php tools/issue-api-key.php "Front desk PC" EPH-A6-01
php tools/issue-api-key.php --list
php tools/issue-api-key.php --revoke 3
```

The key prints **once** — only its hash is stored. A key pinned to a
`device_id` can only ever write as that terminal, so a leaked key cannot
forge another device's attendance.

Smoke-test it:

```bash
curl -X POST https://YOUR-APP.onrender.com/api/biometric-ingest.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: <the key>" \
  -d '{"device_id":"EPH-A6-01","punches":[
        {"employee_id":"00001","punch_time":"2026-09-24 15:06:00","punch_state":0}]}'

# {"ok":true,"received":1,"inserted":1,"duplicates":0,"rejected":0}
# Send it twice — the second returns duplicates:1, inserted:0.
```

> **Render free tier sleeps.** A cold start takes ~50s and the first POST may
> time out. That is harmless: the agent logs it, backs off, and retries with
> the same punches. Nothing is lost.

---

## Part 3 — Build the agent

On any Windows PC with Python 3.9+ (needed **once**, not on the clients):

```bat
cd agent
build.bat
```

Which runs:

```bat
pyinstaller --noconfirm --onedir --windowed ^
    --name biometric_agent ^
    --hidden-import pymysql --hidden-import serial ^
    --collect-submodules serial ^
    biometric_agent.py
```

`--hidden-import` matters: `pymysql` and the serial drivers are imported
lazily inside functions, so PyInstaller's static analysis misses them and the
exe would fail at runtime instead of at build time.

Output is `dist\biometric_agent\` — the exe plus `_internal\`, `config.ini`
and `ca.pem`.

> **`--onedir` is a folder, not a lone file.** The exe will not run without
> `_internal\` beside it, so it cannot simply be dropped into the Startup
> folder. `install_service.bat` handles this by installing to a stable
> location and putting a **shortcut** in Startup. If you genuinely want one
> portable file, swap `--onedir` for `--onefile` — but `config.ini` still has
> to travel alongside it either way.

---

## Part 4 — Deploy to a client PC

Copy the whole `dist\` folder to the PC, then, **as Administrator the first
time** (the driver needs it):

```bat
install_service.bat
```

It will:

1. Run `CH341SER.EXE /S` silently, if you dropped it in the folder.
2. Copy the agent to `%LOCALAPPDATA%\BiometricAgent`, preserving any existing
   `config.ini`.
3. Create `BiometricAgent.lnk` in
   `%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup` (falling back to
   an `HKCU\...\Run` key if the shortcut fails).
4. Start the agent immediately — no reboot.
5. Open `config.ini` in Notepad so you cannot forget the last step.

Set these two, and nothing else is required:

```ini
[sync]
api_url = https://YOUR-APP.onrender.com/api/biometric-ingest.php
api_key = <the key from issue-api-key.php>
```

### Moving the terminal to a different PC

Run `install_service.bat` on the new PC and `uninstall_service.bat` on the
old one. Nothing else — no COM port to configure, no software to open. The
agent re-detects the port by USB VID:PID, so `COM3` on one PC and `COM17` on
the next makes no difference.

Two PCs polling the same terminal is also safe: `uq_punch` collapses the
overlap. `biometric_agent_state` shows you which host reported last.

---

## Part 5 — Punches into payroll

`attendance_logs` is a staging table; it does not touch payroll on its own.

`api/rollup-punches.php?period_id=N` collapses punches into per-day hours and
returns them in exactly the shape `api/save-daily-attendance.php` already
accepts:

```json
{"ok": true, "punches": 420, "days": 31,
 "rows": [{"emp_id":"EMP-001","emp_name":"...","att_date":"2026-09-24",
           "hours_worked":8, "overtime_hours":1.25, "late_hours":0}],
 "unmatched": [{"device_user_id":"00007","punches":12}]}
```

It deliberately does **not** write to `biometric_daily`, `attendance` or
`payroll` itself — it hands the rows back so your existing Daily Biometrics
flow posts them, keeping all the payroll maths (SSS, PhilHealth, Pag-IBIG,
BIR brackets, OT and late rates) in one place.

Day rules, all overridable in the `settings` table:

| Setting | Default | Meaning |
|---|---|---|
| `shift_start` | `08:00` | Arrivals later than this + grace count as late |
| `grace_minutes` | `15` | |
| `break_minutes` | `60` | Deducted only when the day runs past half a standard day |
| `standard_hours` | `8` | Anything beyond is overtime |

A day with a single punch (someone forgot to clock out) is returned with zero
hours and `incomplete: true` rather than being guessed at or dropped.

**Not yet wired into the UI.** The Daily Biometrics tab in
`attendance-upload.php` still expects a file. Say the word and I'll add a
"Pull from biometric device" button that calls the rollup and feeds the
existing preview table.

---

## Security notes

**Use `mode = api`.** It is the default for a reason: the client PC holds
only a revocable per-device key. `mode = db` puts database credentials in a
text file on a desktop PC, and a PyInstaller bundle is trivially unpacked.

If you must use `mode = db`, never use the admin database user. Create a restricted user
(the grants are commented at the bottom of `001_attendance_logs.sql`):

```sql
CREATE USER 'biometric_agent'@'%' IDENTIFIED BY 'a-long-random-password';
GRANT INSERT ON defaultdb.attendance_logs TO 'biometric_agent'@'%';
GRANT INSERT, UPDATE, SELECT ON defaultdb.biometric_agent_state TO 'biometric_agent'@'%';
```

**Unrelated but worth doing:** `payroll2/includes/db.php:26` has your live
Aiven password as a literal, and it is committed to git. Rotate it in the
Aiven console and move it to an environment variable on Render — the code
already prefers `getenv('DB_PASS')`, so only the fallback needs emptying.

---

## Troubleshooting

Log: `%LOCALAPPDATA%\BiometricAgent\logs\biometric_agent.log`
(`--windowed` means there is no console — the log is the only output.)

| Symptom | Cause | Fix |
|---|---|---|
| `pyserial not installed` | Built without the hidden import | Rebuild with `build.bat` |
| No COM port ever appears | Driver missing, or export-only port | Run `CH341SER.EXE`; if still nothing, use the file watcher |
| `device wants a comm key` | Terminal has a comm password set | Clear it in the terminal menu |
| `api rejected the key (403)` | Key revoked, or typo'd | `--list` to check, re-issue if needed |
| `api unreachable` | Render cold start / offline | None — it retries automatically |
| Punches land but payroll is empty | Rollup not run, or IDs unmapped | Call `rollup-punches.php`; check `unmatched[]` |
| Nothing at all in the log | Agent not running | Check Task Manager, re-run `install_service.bat` |

Raise detail with `log_level = DEBUG` in `config.ini` — it is re-read every
poll, so no restart is needed.

---

## Files

```
agent/
  biometric_agent.py       the worker (serial + network + file transports)
  probe_device.py          run this FIRST — identifies your hardware
  config.ini               all settings; lives beside the exe
  requirements.txt
  build.bat                PyInstaller packaging
  install_service.bat      per-PC setup: driver, copy, auto-start
  uninstall_service.bat    clean removal when the terminal moves

payroll2/
  sql/001_attendance_logs.sql            MySQL migration
  sql/001_attendance_logs.postgres.sql   Postgres variant
  api/biometric-ingest.php               Render ingest endpoint
  api/rollup-punches.php                 punches → daily hours
  tools/issue-api-key.php                key issue / list / revoke
```
