# Automated Payroll System

A payroll system for a Philippine small business - built for **L&N Pharmacy** - that turns the attendance files you already have (CSV, Excel, the biometric terminal's export) into correct payslips, and tells you what next month's payroll will cost.

* **PHP 8.2 + MySQL 8** - no framework, no build step. Runs on a PC with one double-click, or on any Docker host (Render, Railway, Fly.io).
* **Philippine rules built in** - SSS, PhilHealth, Pag-IBIG, BIR withholding tax (Annex E tables), overtime, leave and absences, **13th month pay (PD 851)** - each checked to the centavo against an independent calculator (see [Tests](#tests)).
* **Three portals** - admin, managers (their own employees only) and employees (their own payslips, attendance and leave).

## What it does

| | |
|---|---|
| **Attendance in** | Upload day-by-day or totals files (CSV / Excel), the pharmacy's timesheet workbooks, or the EPH A6 biometric terminal's export (a background [agent](agent/README.md) can push punches automatically). Names are matched to employees; unreadable or impossible hours are refused and listed. |
| **Pay** | Daily-rate, kinsenas and monthly salaries; undertime, absences, approved leave, days off, hire dates; overtime at a flat peso rate **or** the Labor Code's hourly rate + 25%; late deductions. |
| **Contributions & tax** | SSS (with EC), PhilHealth, Pag-IBIG - settled per calendar month, floors and caps applied once - and withholding tax on the BIR table of each pay run, with the month's last run settling the month (over-withheld tax comes back as a refund). |
| **Bonus & deductions** | Per employee or in bulk, with a history and an audit trail; a deduction can't push net pay below zero; bonuses are watched against the ₱90,000 tax-exempt ceiling. |
| **13th month pay** | Works out *basic pay earned in the year ÷ 12* for everyone, shows every month so it can be checked, accepts mid-year advances, and records the payment on payslips. [How it works](#13th-month-pay) |
| **Payslips & records** | Printable payslips and register, e-signatures, finalize / unlock with a revision trail, print log. |
| **Forecast** | Random Forest + ARIMA forecast of the next period's **labor cost** (pay + bonus + the employer's SSS, EC, PhilHealth, Pag-IBIG) or take-home pay, with turning points and a CSV for Orange3. |

## 13th month pay

*Admin sidebar → Payroll Process → **13th Month Pay*** (`payroll2/thirteenth-month.php`).

* **Rule (Presidential Decree 851):** 13th-month pay = the **basic pay earned in the calendar year ÷ 12**, due **not later than December 24**. "Basic pay" is the payroll's own *Basic Pay* column - pay for days worked and paid leave, with absences and undertime already out; **overtime, bonuses and allowances are not part of it**. Part-year employees get a proportional amount; someone who has left stays on the sheet because it is due on separation.
* **The page** shows the whole year per employee, month by month (the computation sheet an accountant expects), the 13th month due, what was **already paid** (a mid-year advance, or an older entry typed on *Bonus & Deductions* with the reason "13th Month Pay"), the **balance**, and a "set aside each month" row for budgeting. Print it or export it as CSV.
* **Paying it:** pick an *open* pay period, tick the employees (or press *Pay full balance* / *Pay half (advance)*) and record it. It becomes a **Bonus** on that period's payslips and a row in Adjustment History with the reason *13th Month Pay 2026*. The balance shrinks, so the same money can never be paid twice, and nobody can be paid more than the balance.
* **Tax:** 13th-month pay and other benefits are exempt up to **₱90,000** a year together. The system does not withhold tax on a bonus, so a payment that would take an employee past ₱90,000 is **held back until you confirm it**; the taxable excess is shown on the sheet.
* Rounded half a centavo **up**, in whole centavos - [`info/ph_13th_month_pay.md`](info/ph_13th_month_pay.md) has the rules and what is deliberately left out.

## Quick start on one PC (Windows)

1. Put the project in a folder, e.g. `C:\PayrollApp\`, and add a portable PHP 8.2 in `php\` (see [`SETUP_README.txt`](SETUP_README.txt) - step by step).
2. Create an empty **MySQL 8** database (Aiven, or a local one) and run [`payroll2/sql/database.sql`](payroll2/sql/database.sql) on it once - in DBeaver: open the file, pick the connection, **Alt+X**. The application creates everything else itself on first load.
3. Copy `secrets.bat.example` to `secrets.bat` and fill in the database host, port, name, user and password (and put the CA certificate next to it as `ca.pem` if the host needs SSL).
4. Double-click `launch.vbs` → <http://localhost:8765>. The first admin is created from `ADMIN_INITIAL_PASSWORD` (or the default, which the app makes you change at first sign-in and refuses in production).

## Deploy (Docker / Render)

The [`Dockerfile`](Dockerfile) builds `php:8.2-apache` with the app as the web root, PHP errors hidden from visitors, and the code-only folders (`includes`, `sql`, `tools`) blocked. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` and the CA certificate (Secret File `ca.pem`, or the text in `DB_SSL_CA_PEM`) on the host. Nothing private is in the repository.

## Tests

```
tests\run-tests.bat                  everything (~4 min)  - private MySQL 8, never your database
tests\run-tests.bat --suite=13       one suite (13 = 13th month pay)
tests\run-tests.bat --mutation-check inject bugs on purpose; the suite must catch each one
```

* **~200 tests / ~1 million checks.** Every figure is compared with an independent integer-centavo ledger: statutory tables at every boundary, a thousand generated pay runs, your real timesheets, the forecast models and the forecast **page** in a real browser DOM, what the upload page sends, and every entry point's limits.
* [`tests/AUDIT_FINDINGS.md`](tests/AUDIT_FINDINGS.md) - the QA / BIR / accountant audit (19 defects found and fixed, with the numbers).
* [`tests/dbeaver_checks.sql`](tests/dbeaver_checks.sql) - 27 read-only queries to run on the **live** database in DBeaver: payslips that don't foot, wrong SSS / PhilHealth / Pag-IBIG / tax for a month, impossible hours, bonuses over ₱90,000, overtime below the legal minimum, 13th month over-paid …

## Layout

```
payroll2/      the application (PHP)        sql/database.sql  the base tables
agent/         biometric terminal → payroll sync agent (Python)
docker/ Dockerfile docker-start.sh          deployment
tests/         the test suite, the audit, the DBeaver queries
info/          the Philippine rules the system applies, with sources
launch.* stop.bat secrets.bat.example       the one-PC launcher
```

## Security

Credentials, the CA certificate, data snapshots, logs and the agent's per-device config are git-ignored (`.gitignore`) - check `git status` before the first commit. Sign-in is throttled, sessions expire after 8 hours idle, every query is a prepared statement, and the live image refuses the default admin password.

## What is not covered

Rest-day, holiday and night-shift pay premiums; year-end tax annualization and BIR forms 2316 / 1601-C; withholding tax on the part of bonuses above ₱90,000. Payslips and reports are internal documents, not BIR filings. The rules were checked against published sources in October 2026 - if SSS, PhilHealth, Pag-IBIG or the BIR change a rate, edit `PH_RULES` in `payroll2/includes/helpers.php` and the independent constants in `tests/lib/Ledger.php`, and run the tests.
