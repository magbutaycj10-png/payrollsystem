# Payroll tests

End-to-end and integration tests for the payroll calculation chain - timesheet file → upload → hours → gross → the employee's own typed SSS / PhilHealth / Pag-IBIG / withholding-tax amounts → net pay → payslip, register, 13th month pay and forecast - written from three seats: **QA** (does it behave, edge cases, regressions), the **BIR examiner** (are the published tables right - they remain the reference for the amounts the admin types) and the **accountant** (does every centavo tie out).

> **Since 2026-10-10** SSS, PhilHealth, Pag-IBIG and withholding tax are *typed per employee* and deducted as typed, not worked out from pay. The ledger and the suites were rewritten for that; the audit of the earlier, computed behaviour ([AUDIT_FINDINGS.md](AUDIT_FINDINGS.md)) is kept as it was, and `--app=<older folder>` still runs against an older copy but its contributions are computed from pay, so the ledger will not agree with them.

* The audit, with the numbers: [AUDIT_FINDINGS.md](AUDIT_FINDINGS.md) · what changed in the update: [`../CHANGELOG.md`](../CHANGELOG.md)
* Queries to run on your live database in DBeaver: [dbeaver_checks.sql](dbeaver_checks.sql)

## Run

```
tests\run-tests.bat                         everything (≈ 4 min)
tests\run-tests.bat --suite=03              one suite (file name contains "03"; 13 = 13th month pay)
tests\run-tests.bat --filter=SSS            tests whose name contains "SSS"
tests\run-tests.bat --strict                confirmed defects fail the run too (exit code 1)
tests\run-tests.bat --mutation-check        inject bugs into a copy of the app; the suite must catch each (57 mutants, ≈ 30 min)
tests\run-tests.bat --mutation-check=M21,M37   only these mutants
tests\run-tests.bat --app=D:\old\payroll2   test another copy of the app instead of ..\payroll2
tests\run-tests.bat --clean                 delete tests\.tmp (≈ 190 MB private database; recreated on the next run)
```

(`run-tests.bat` is just `php -r "require getenv('QA_MAIN');"` - see "Why no script file" below.)

Needs: PHP 8.2 (the bundled `..\php\php.exe`, or `php` on the PATH); a **MySQL 8** server binary - `C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe` is found automatically (or set `PAYROLL_TEST_MYSQLD`; XAMPP's MariaDB is the fallback); Microsoft Edge or Chrome for suite 07; and - optionally - the pharmacy's timesheets in `E:\payroll\samp` (or `PAYROLL_SAMPLES`) for suite 08.

### Reading the result

| Status | Meaning |
|---|---|
| ✓ PASS | the check holds |
| FAIL | a regression - something that used to work, or a fix that has come undone: the run fails (exit code 1) |
| FIXED `[D-nn]` | a test written for one of the audit's 19 defects, passing: the defect is fixed in this app |
| DEFECT `[D-nn]` | only when testing an **older copy** (`--app=`) that still has the defect - see the *Defect register* printed at the end |
| SKIP | a prerequisite is missing (no browser, no sample files) or the test is for behaviour the tested copy lacks - the reason is printed |

## What it does (and does not touch)

* **Never touches the live database.** It starts its own MySQL 8 on a free loopback port with its own data folder (`tests/.tmp/mysql-data`), loads the schema (`schema.sql`, from the ERD export of the live DB; the app's own `applySchemaPatches()` adds the rest), and shuts it down afterwards. The server is the same major version as your Aiven database, with MySQL's default strict `sql_mode` (including `ONLY_FULL_GROUP_BY`). The runner refuses to run if the app is configured for any other host/database.
* **Never touches the source tree.** The app runs from a fresh *copy* made in `tests/.tmp/app` on every run - without `*.pem`, because `includes/db.php` auto-discovers `ca.pem` (the Aiven certificate) and then insists on SSL. It also keeps test noise out of the real `payroll_error.log`.
* **Calls the real code.** Endpoints and pages run in their own PHP process through `lib/Http.php` (session, `php://input` and all); engine tests call `recomputePeriodFromDaily()` - the function every upload, manual entry and leave decision uses - against the real SQL. Browser code runs in headless Edge: the application's own scripts, and the real `forecast.php` page in a real DOM.

## The suites

| Suite | What it proves |
|---|---|
| `00_smoke` | the rig itself: private DB, schema, a real endpoint round trip, 401 without a session |
| `01_statutory_tables` | the **reference calculators** (what the law prescribes - no longer applied to payroll): **BIR Annex E** (4 tables × 5 brackets) equals the published table, is continuous at every boundary, hits 40 hand-computed points, agrees with the annual TRAIN schedule ÷ periods per year; **SSS** credit/EC/shares, **PhilHealth**, **Pag-IBIG** at every published boundary and on dense sweeps (≈ 550 000 probes) against an integer-centavo ledger; amount in words |
| `02_hours_and_calendar` | working days (every month 2026–28 × 9 rest-day patterns), leave days, absence classification, undertime rounding, "is this the month's last run?", the schedule's defaults and which run takes what (`first` / `split` / `second`) |
| `03_pay_engine` | eight worked examples derived **by hand** with typed amounts (a daily, a kinsenas, a monthly, a weekly employee, one with only some typed, one with nothing typed), the schedule per amount (`first`, `split`, `second`, an odd ₱495.01, a four-week month), an amount **edited between the cut-offs** (a contribution stops at 0, tax is handed back), then 600 generated employee-months (every salary type, rest-day pattern, run shape, random typed amounts - some none - and schedule combination, flat and Labor Code overtime) through the real engine against the ledger, field by field |
| `04_e2e_april_payroll` | a month on a kinsenas calendar through the real pages and endpoints: CSV → browser parsing → upload → month settlement (each person's month = exactly their typed amounts) → idempotent re-upload → corrected file → bonus/deduction → finalize/unlock → payslips (every printed figure, amount in words, receipt numbers) → register totals → forecast API → manager scope → totals-file path |
| `05_dashboard_forecast` | the dashboard's regression against an independent least-squares fit; period status and labels |
| `06_accounting_tieouts` | over 200 generated employee-months: every payslip foots, every month ends exact (SSS, PhilHealth, Pag-IBIG **and** tax = the typed amounts), employer shares follow what was deducted, attendance mirrors payroll, recompute is stable and order-independent; 5 000 random `computePayLine` calls return centavo-exact figures |
| `07_js_forecast_and_parsing` | in headless Edge, running the application's own scripts: ARIMA / Yule-Walker / turning points / calendar roll-over / Random Forest; the timesheet parsers and what the upload page actually POSTs (`saveDaily`, `saveTotals`, with fetch stubbed); the whole **forecast page** on real data (cards, headings, table, target selector, chart redraw, CSV for Orange3); and a parity check that the PHP port of the upload parser (`lib/BrowserSim.php`) equals the JavaScript on the same inputs, including all real timesheets |
| `08_pharmacy_samples` | your eight Feb–May 2026 timesheets through the whole pipeline: every employee's gross equals the spreadsheet's `PERIOD_TOTAL` (two explained exceptions); **the SSS and PhilHealth typed once per person reproduce every deduction in the sheets' own SSS / PHILHEALTH columns, to the centavo (83 employee-cut-offs)**; contributions equal the ledger per person-month; forecast data ties out |
| `09_controls_and_compliance` | input sanity, Labor Code overtime premium, what the forecast measures, access control (every endpoint/page without a session, idle timeout), hostile names, ambiguous names, stale month after a late correction |
| `10_punch_rollup` | biometric punches → hours (break, grace, overtime, lone punch) |
| `11_more_numbers_and_audit_fixes` | big pay with big typed amounts by hand (₱250,000 and ₱800,000 a month, ₱1,500 a day); bonuses and deductions to the centavo; the ₱90,000 yearly ceiling; a deduction may not make a payslip negative; limits on every entry door (day upload, totals file, manager's manual entry, Employee Management **and its four optional amount boxes**, Settings **and the three timings**, Adjustments); editing an employee's amounts recomputes the open payroll and leaves finalized cut-offs alone; Labor Code overtime for every salary type; tax refund end to end (payslip, register, signature pages, employee portal); labor-cost series equals the independent employer shares and does not move when an amount is edited later; hire-date proration; **the days to compute are the days in the file** (3 days uploaded, 3 days paid, whatever the Date Hired says; this month's partial file paid as it grows; OFF days and approved leave count; the same file under four Date Hired values gives one and the same payroll line; also through the real Employees page and upload endpoints, and a daily-rate control); Recompute; the Finalize guards; the four new columns (existing employees start with none) |
| `12_dbeaver_checks` | the audit queries in `dbeaver_checks.sql`, on MySQL 8: silent on correct payroll (and C10-C13 find nothing when every month came to the typed amount), and find deliberately injected damage (every query, rolled back) |
| `13_thirteenth_month_and_install` | **13th month pay**: a full year, part year, overtime / bonus left out, half-centavo rounding on 300 random totals, year boundaries, three employees × three months against the ledger; recording the payment (full, in two instalments, never more than the balance, never twice, the ₱90,000 ceiling, closed periods, revision stamp and audit text); the page, CSV and access control; the Home page status (D-19); and a **fresh install** - `sql/database.sql` on an empty database builds exactly the tested schema and the app takes it from there |

## How to read a failure

Every money assertion says what was expected and what the app produced, to the centavo: `net_pay: ledger ₱5945.00, app ₱5944.99 (-0.01)`. The generated cases are seeded - *case #1003* is always the same case; its description (salary type, run shape, timing) is in the message.

## The ledger (`lib/Ledger.php`)

An independent re-computation in **whole centavos with integer arithmetic only** - no floats, no `round()`. It is written from the documented pay rules (`info/ph_government_deductions.md`), not from the engine's code. For a pay run it takes the employee's typed monthly amounts (`amt`: sss, ph, pi, tax) and the schedule (`timing`: first / split / second per amount) and works out what each cut-off takes; its BIR / SSS / PhilHealth / Pag-IBIG tables, typed again from the published figures rather than copied from `PH_RULES`, check the reference calculators in suite 01. Switches that mirror what the app does:

* `refund` - tax over-withheld in an earlier cut-off (an amount lowered mid-month) is returned on the month's last run;
* `ot_method` / `ot_mult` - flat peso rate, or the Labor Code: hourly rate × multiplier, half a centavo up;
* **the days to compute are the days in the file**: for a monthly / kinsenas salary every working day of the run that the file does not account for (hours, a day marked OFF, approved leave) is unpaid - before the employee's first day in the file, in a gap, and after the last day uploaded. There is no hire date anywhere in the ledger; a daily-rate employee's absences are only *shown*, up to the last day the uploads of the employee's branch cover.

(`refund` exists because an older copy of the app does not return over-withheld tax; `Scenario::play()` follows whichever copy is tested, while `exp_refund` is always what the month *should* come to.)

## The mutation check

`--mutation-check` edits one line of the *copy* of the app (never the source) - a changed rate, a dropped term, floor instead of round, a limit removed, a fix put back the way it was - and the named suite must fail. 57 mutants for the current app (M01–M57: the pay engine and tables, the audit fixes, the 13th month, the Home page status, the shipped schema, - M46–M56 - the typed monthly amounts: the schedule, the last cut-off's settlement, the clamp on contributions, centavo rounding, the company's share, the Employees form, the default schedule, - and M23 / M57 - the days to compute are the days in the file: a salaried employee paid for days the uploads have not reached, an OFF day deducted); an older copy gets only the few that still apply to it. A mutant that survives means the suite cannot see that bug; the first run found three (the overtime limit on its own, the break rule, the unseeded forest), which led to new tests and to treating a failing `[D-nn]` test as a regression.

## DBeaver checks (`dbeaver_checks.sql`)

Read-only queries for your Aiven database - 27 checks in seven groups: every payslip (foots, negative net, negative parts, attendance mirror, locked vs finalized), the month per employee (SSS, PhilHealth, Pag-IBIG, BIR tax), what went in (impossible hours, a day saved twice, overlapping periods, employee set-up, settings), bonuses and deductions (history vs payroll, ₱90,000 a year), compliance (overtime below the Labor Code minimum, pay before the hire date), 13th month pay (recorded more than one twelfth; the sheet per year), and two reports. Each heading says `expect: none` (any row is a mistake), `review` (a person decides) or `info`. Suite 12 proves them on MySQL 8.

## Adding a test

```php
T::test('what must be true', function (T $t) {
    $r = Scenario::play([...]);                 // employee + days through the real engine, ledger alongside
    $t->moneyMap(['gross_pay' => '6225.00', 'sss' => '300.00'], $r['app'][0]);
    $t->same([], Scenario::diff($r['app'][0], $r['exp'][0]));
});
```

Tag a test `['defect' => 'D-nn']` (and register it in `lib/Defects.php`) when it documents a defect: on a copy that still has the defect it reports DEFECT, on this app it must pass - and if it ever fails here it is a REGRESSION. Call `qa_need_fixes()` first when the test describes behaviour an older copy lacks.

## Why no script file

The security software on this PC deletes PHP files that are *started as scripts* and then talk to a database or behave like a web request (it removed `run.php`, `payroll-tests.php` and the first request runner between runs). Everything therefore lives in library files that are `require`d, the entry point is a `.bat` that starts `php -r "require …"`, and the per-request runner is passed inline to `php -r`. If a library file ever disappears, restore it from version control.

## Housekeeping

`tests/.tmp/` is git-ignored. The real timesheets are read in place and **never copied into the repository** (they hold real names and pay); everything else in the suites is synthetic.
