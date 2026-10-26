# Payroll tests

End-to-end and integration tests for the payroll calculation chain - timesheet file → upload → hours → gross → the employee's own typed SSS / PhilHealth / Pag-IBIG / withholding-tax amounts → net pay → payslip, register, 13th month pay and forecast - written from three seats: **QA** (does it behave, edge cases, regressions), the **BIR examiner** (are the published tables right - they remain the reference for the amounts the admin types) and the **accountant** (does every centavo tie out).

> **Since 2026-10-10** SSS, PhilHealth, Pag-IBIG and withholding tax are *typed per employee* and deducted as typed, not worked out from pay. The ledger and the suites were rewritten for that; the audit of the earlier, computed behaviour ([AUDIT_FINDINGS.md](AUDIT_FINDINGS.md)) is kept as it was.
>
> **Since 2026-10-15** every table points at an employee by `employee_id` = `employees.id` (foreign keys), not by a copy of the text `emp_id` - and, the same day, the database was put into the **standard layout** (14 tables, `first_name` / `last_name`, lists of branches / departments / positions, `user_scopes`, `attendance_summary`, `bonus_deductions`, `print_logs`, who-did-it as a link to `users.id`; see [`../CHANGELOG.md`](../CHANGELOG.md)). `schema.sql` is in that layout and the suites write it, so `--app=<older folder>` only works for a copy from that day on. The two layouts before it are kept in `schema_before_employee_id.sql` (suite 14 migrates it to the `employee_id` layout) and `schema_before_standard.sql` (the layout the live database had; suite 15 migrates it to the standard one).

> **Since 2026-10-24** the pay schedule (Semi-Monthly or Monthly) and the contribution schedule (which cut-off takes each typed amount) are columns of the *employee* - `pay_schedule`, `sss_timing`, `philhealth_timing`, `pagibig_timing`, `tax_timing` - not Settings. A pay period takes only the employees on its schedule, so `Fixtures::employee()` takes those five fields and `Scenario::play()` saves `cfg['timing']` on the employee (and makes it Monthly when every run is a Monthly one). Suite 16 is about all of this; suite 15 expects the five columns to arrive on the first page after the migration.

* The audit, with the numbers: [AUDIT_FINDINGS.md](AUDIT_FINDINGS.md) · what changed in the update: [`../CHANGELOG.md`](../CHANGELOG.md)
* Queries to run on your live database in DBeaver: [dbeaver_checks.sql](dbeaver_checks.sql)

## Run

```
tests\run-tests.bat                         everything (≈ 6 min)
tests\run-tests.bat --suite=03              one suite (file name contains "03"; 13 = 13th month pay)
tests\run-tests.bat --filter=SSS            tests whose name contains "SSS"
tests\run-tests.bat --strict                confirmed defects fail the run too (exit code 1)
tests\run-tests.bat --mutation-check        inject bugs into a copy of the app; the suite must catch each (69 mutants, ≈ 35 min)
tests\run-tests.bat --mutation-check=M21,M37   only these mutants
tests\run-tests.bat --app=D:\old\payroll2   test another copy of the app instead of ..\payroll2
tests\run-tests.bat --clean                 delete tests\.tmp (≈ 190 MB private database; recreated on the next run)
```

(`run-tests.bat` is just `php -r "require getenv('QA_MAIN');"` - see "Why no script file" below.)

**The live server's SQL mode.** Aiven runs MySQL in an ANSI `sql_mode` (`ANSI_QUOTES`: a double-quoted word is a column name, not text; `PIPES_AS_CONCAT`; `IGNORE_SPACE`; `STRICT_ALL_TABLES` ...) and the application does not set its own, so SQL that only MySQL's default mode accepts would fail there and nowhere else. To run the whole suite the way the live server runs SQL, set `PAYROLL_TEST_SQL_MODE` to the list `SELECT @@sql_mode` gives in DBeaver, e.g.

```
set PAYROLL_TEST_SQL_MODE=REAL_AS_FLOAT,PIPES_AS_CONCAT,ANSI_QUOTES,IGNORE_SPACE,ONLY_FULL_GROUP_BY,ANSI,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
tests\run-tests.bat
```

(the private server is started with it; suite 15 always runs the migration in that mode). The 2026-10-15 standard-layout run passed both ways: 247 tests, 1,016,898 assertions, 0 failed, 0 skipped.

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

* **Never touches the live database.** It starts its own MySQL 8 on a free loopback port with its own data folder (`tests/.tmp/mysql-data`), loads the schema (`schema.sql` - the standard layout of `sql/database.sql`, which suite 13 proves identical; the app's own `applySchemaPatches()` has nothing left to add), and shuts it down afterwards. The server is the same major version as your Aiven database, with MySQL's default strict `sql_mode` (including `ONLY_FULL_GROUP_BY`). The runner refuses to run if the app is configured for any other host/database.
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
| `07_js_forecast_and_parsing` | in headless Edge, running the application's own scripts: ARIMA / Yule-Walker / calendar roll-over / Random Forest; the timesheet parsers and what the upload page actually POSTs (`saveDaily`, `saveTotals`, with fetch stubbed); the whole **forecast page** on real data (cards, headings, table, target selector, chart redraw, CSV for Orange3); and a parity check that the PHP port of the upload parser (`lib/BrowserSim.php`) equals the JavaScript on the same inputs, including all real timesheets |
| `08_pharmacy_samples` | your eight Feb–May 2026 timesheets through the whole pipeline: every employee's gross equals the spreadsheet's `PERIOD_TOTAL` (two explained exceptions); **the SSS and PhilHealth typed once per person reproduce every deduction in the sheets' own SSS / PHILHEALTH columns, to the centavo (83 employee-cut-offs)**; contributions equal the ledger per person-month; forecast data ties out |
| `09_controls_and_compliance` | input sanity, Labor Code overtime premium, what the forecast measures, access control (every endpoint/page without a session, idle timeout), hostile names, ambiguous names, stale month after a late correction |
| `10_punch_rollup` | biometric punches → hours (break, grace, overtime, lone punch) |
| `11_more_numbers_and_audit_fixes` | big pay with big typed amounts by hand (₱250,000 and ₱800,000 a month, ₱1,500 a day); bonuses and deductions to the centavo; the ₱90,000 yearly ceiling; a deduction may not make a payslip negative; limits on every entry door (day upload, totals file, manager's manual entry, Employee Management **and its four optional amount boxes**, Settings, Adjustments); editing an employee's amounts recomputes the open payroll and leaves finalized cut-offs alone; Labor Code overtime for every salary type; tax refund end to end (payslip, register, signature pages, employee portal); labor-cost series equals the independent employer shares and does not move when an amount is edited later; hire-date proration; **the days to compute are the days in the file** (3 days uploaded, 3 days paid, whatever the Date Hired says; this month's partial file paid as it grows; OFF days and approved leave count; the same file under four Date Hired values gives one and the same payroll line; also through the real Employees page and upload endpoints, and a daily-rate control); Recompute; the Finalize guards; the four new columns (existing employees start with none) |
| `12_dbeaver_checks` | the audit queries in `dbeaver_checks.sql`, on MySQL 8: silent on correct payroll (and C10-C13 find nothing when every month came to the typed amount), and find deliberately injected damage (every query, rolled back) |
| `13_thirteenth_month_and_install` | **13th month pay**: a full year, part year, overtime / bonus left out, half-centavo rounding on 300 random totals, year boundaries, three employees × three months against the ledger; recording the payment (full, in two instalments, never more than the balance, never twice, the ₱90,000 ceiling, closed periods, revision stamp and audit text); the page, CSV and access control; the Home page status (D-19); and a **fresh install** - `sql/database.sql` on an empty database builds exactly the tested schema and the app takes it from there |
| `14_employee_id_migration` | **the 2026-10-15 database update** (`payroll2/sql/migrate-employee-id.sql`), run statement by statement as DBeaver runs it - with every error *ignored*, the worst case - on a copy of the live layout (`schema_before_employee_id.sql`) holding IDs typed in other capitals or with a trailing space, mixed collations, a day filed under a terminal number and a deleted employee's bonus: Part A adds and fills `employee_id` and changes nothing else (backup copies exact); its report lists the two orphans; Part B's guard stops and changes nothing until they are dealt with; both documented remedies; Part B then removes `emp_id`, adds the keys and the foreign keys with their rules, every row and value unchanged and linked to the same employee, timestamps not touched; Part C; both parts again change nothing; `rollback-employee-id.sql` restores the old layout, indexes, foreign keys and data, and the migration runs again; the application (now written for the standard layout) refuses the migrated `employee_id` layout and one not yet migrated with a message that names the script to run; the foreign keys' ON DELETE / ON UPDATE rules |
| `15_standard_schema_migration` | **the standard-layout update** (`payroll2/sql/migrate-standard-schema.sql`), run the way DBeaver runs it (every error ignored, MySQL in the live server's ANSI / strict mode) on the layout the live database had (`schema_before_standard.sql`) filled with every awkward case - names with a particle, a suffix, "LAST, First", one word, extra spaces; a position typed in two capitalisations; an e-mail typed twice; two accounts with one name; managers with a branch, with none and with picked employees; two overlapping periods holding the same days; an actor name nobody has; a pay line with no attendance row; a negative late adjustment: it runs without one error; all 22 checks pass; the old tables are gone and what is left is exactly the tested layout (`schema.sql`); every employee, account, scope, pay line (every peso), attendance row, day (one per employee per date, the latest upload), leave, bonus, print log, signature, receipt number, audit row and terminal number arrived with its id; running it again changes nothing, on an empty database it stops; when a check fails the old tables are **not** deleted and a half-converted database is left alone; Part E removes the leftover tables only once its dashes are taken off; the application reads the result (register, payslip, Employees, each manager's scope, the employee portal, a day typed by a manager) and adds nothing to the layout; and every SQL file that ships (`database.sql`, both migrations, the rollback, `001_attendance_logs.sql`) passes **DBeaver's rules** - no blank line inside a statement (DBeaver ends the statement there; a PHP runner does not, which is how a half-converted database could have gone unnoticed), no line starting with `@`, no `?` |
| `16_employee_schedules` | **the pay schedule and the contribution schedule per employee** (2026-10-24): **A** a Monthly pay period beside the two cut-offs of the same month - each takes only the employees on its schedule (kinsenas ₱15,000 → ₱15,000 a cut-off, ₱500 × 13 days, the Monthly ₱30,000 in full with SSS taken in one go), the upload names who is paid elsewhere and brings the other schedule's open period up to date, later cut-offs are the same schedule only, the DBeaver checks C22 / C23 / C32 stay silent; **B** changing the pay schedule on the Employees page moves the employee between the open periods and leaves a finalized one exactly as it was; **C** the same typed SSS and tax come out on different cut-offs for different employees (first / split / second), months end at the typed amounts, a Monthly employee takes it all, the Payroll page speaks for each employee's own schedule; **D** the Add / Edit form: every field saved, junk and a greyed-out box keep what the employee had, no Weekly, no localhost login hint; **E** the bracket tables - contiguous from ₱0, all 61 SSS credits, PhilHealth, Pag-IBIG and both TRAIN tables agree with the reference calculators at both ends of every row - the window on the page, and **in headless Edge** the form's boxes (Monthly greys the schedule out, Edit shows what the employee has) and the marked rows (a ₱26,000 monthly employee, a kinsena counted twice, a day's rate × 26, the lowest and the highest pay, Esc); **F** Weekly refused, overlaps only within one schedule, a missing type = the schedule most employees are on; **G** a totals file does not take, list as missing or replace the days of the other schedule, the manager's manual entry picks the employee's own period; **H** the first page load of a database in the old layout gives every employee the old Settings' contribution schedule and the schedule of the period they were last paid in (updated_at untouched, and not again on the next load) |
| `17_receipt_signatures` | **the signatures on the receipts** (2026-10-26): **A** Settings > Authorised Signatory is saved only with the admin's own password (wrong password refused and counted, five lock it, a name change keeps the drawn signature, junk and a manager session refused, the big Save All form can no longer change the name); **B** the payslip and the bonus / deduction receipt print the signatory's real name (not "Admin"), the admin's signature only once the period is finalized - none on an open or re-opened period or a blank copy - and the employee portal's copy has it; **C** the employee e-signs the bonus / deduction receipt (refused while open, saved after finalize, both copies, History badge, a changed amount voids it, junk refused, a manager only for their own employees); **D** an existing database gets the two new tables on the first page |

## How to read a failure

Every money assertion says what was expected and what the app produced, to the centavo: `net_pay: ledger ₱5945.00, app ₱5944.99 (-0.01)`. The generated cases are seeded - *case #1003* is always the same case; its description (salary type, run shape, timing) is in the message.

## The ledger (`lib/Ledger.php`)

An independent re-computation in **whole centavos with integer arithmetic only** - no floats, no `round()`. It is written from the documented pay rules (`info/ph_government_deductions.md`), not from the engine's code. For a pay run it takes the employee's typed monthly amounts (`amt`: sss, ph, pi, tax) and the schedule (`timing`: first / split / second per amount) and works out what each cut-off takes; its BIR / SSS / PhilHealth / Pag-IBIG tables, typed again from the published figures rather than copied from `PH_RULES`, check the reference calculators in suite 01. Switches that mirror what the app does:

* `refund` - tax over-withheld in an earlier cut-off (an amount lowered mid-month) is returned on the month's last run;
* `ot_method` / `ot_mult` - flat peso rate, or the Labor Code: hourly rate × multiplier, half a centavo up;
* **the days to compute are the days in the file**: for a monthly / kinsenas salary every working day of the run that the file does not account for (hours, a day marked OFF, approved leave) is unpaid - before the employee's first day in the file, in a gap, and after the last day uploaded. There is no hire date anywhere in the ledger; a daily-rate employee's absences are only *shown*, up to the last day the uploads of the employee's branch cover.

(`refund` exists because an older copy of the app does not return over-withheld tax; `Scenario::play()` follows whichever copy is tested, while `exp_refund` is always what the month *should* come to.)

## The mutation check

`--mutation-check` edits one line of the *copy* of the app (never the source) - a changed rate, a dropped term, floor instead of round, a limit removed, a fix put back the way it was - and the named suite must fail. 69 mutants for the current app (M01–M69: the pay engine and tables, the audit fixes, the 13th month, the Home page status, the shipped schema, - M46–M56 - the typed monthly amounts: the schedule, the last cut-off's settlement, the clamp on contributions, centavo rounding, the company's share, the Employees form, the default schedule, - and M23 / M57 - the days to compute are the days in the file: a salaried employee paid for days the uploads have not reached, an OFF day deducted, - and M58–M69 - the pay schedule and the contribution schedule per employee: a pay period that takes every employee, a Weekly period that no longer takes everybody, an overlap check that ignores the schedule, an upload that does not refresh - or refreshes too much of - the other schedule's open periods, the default contribution schedule used instead of the employee's own, a schedule change that does not re-work the open payroll, a totals file that deletes the other schedule's days, a wrong SSS bracket, the first-load seeding, Weekly accepted again, the brackets window marking too many rows); an older copy gets only the few that still apply to it. A mutant that survives means the suite cannot see that bug; the first run found three (the overtime limit on its own, the break rule, the unseeded forest), which led to new tests and to treating a failing `[D-nn]` test as a regression.

## DBeaver checks (`dbeaver_checks.sql`)

Read-only queries for your Aiven database - 28 checks in seven groups: every payslip (foots, negative net, negative parts, attendance mirror, locked vs finalized), the month per employee (SSS, PhilHealth, Pag-IBIG, BIR tax), what went in (impossible hours, a day saved twice, overlapping periods, employee set-up, settings), bonuses and deductions (history vs payroll, ₱90,000 a year), compliance (overtime below the Labor Code minimum, pay before the hire date), 13th month pay (recorded more than one twelfth; the sheet per year), and two reports. Each heading says `expect: none` (any row is a mistake), `review` (a person decides) or `info`. Suite 12 proves them on MySQL 8.

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
