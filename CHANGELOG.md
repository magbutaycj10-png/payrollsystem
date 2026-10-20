# Changelog

## 2026-10-20 - new sign-in screen, a leaner forecast page, a dashboard that opens on today

* **Sign-in page** redrawn: large "Payroll System" heading with the gold "Predictive Forecasting" accent, a rounded white card, a show/hide eye in the password box, and the store-front photo (`assets/images/login-bg.jpg`) faded into the navy background. Same form, same fields, same sign-in rules.
* **Salary Forecast**: the "Turning Points" table, the "next period signal" card and the "How to Train and Validate in Orange3 (Step-by-Step Guide)" section are gone (page, `forecast.js` and their tests). The models, the cards, the chart, the history table and the CSV export are unchanged.
* **Dashboard** (the page after an admin signs in) reports on **today's** pay period - the one whose dates hold today, else the newest one that has already started (`reportingPeriod()` in `includes/helpers.php`). Before, it showed the newest period by date that had payroll, so a period dated in advance could take over and a period just opened stayed hidden behind an older one. A line under the heading names the period, its dates and how far the attendance files reach; the trend chart and the prediction stop at the period shown. Three new tests in suite 05.

## 2026-10-15 - the standard database layout (14 tables, every record has its own `id`, links are `<thing>_id`)

The database is now laid out the way a textbook ERD would draw it, from the sample the school schema was modelled on. Nothing looks different on any screen, payslip or file - the employee number is still `EMP-004` everywhere - but underneath:

| Before | Now |
|---|---|
| `employees.emp_id`, `full_name`, `position` and `branch` typed as text; the profile in a table of its own | `employee_code`; `first_name` + `last_name` (`full_name` is worked out by the database); `branch_id` and `position_id` are links into the new lists; address, phone and the emergency contact are columns of `employees` (`employee_profiles` is gone) |
| (no lists) | `branches` (gains `address`), `departments`, `positions` - Settings has a card for each, an employee's branch and position are chosen from them |
| `users.role` = admin / manager / earner, `branch` and `scope_type` on the account, `manager_employees` | roles **Admin / Manager / Employee**; what a manager handles is `user_scopes` - *Global* (no branch = everybody), *Branch*, *Department* or *Employee* (picked one by one) |
| `attendance` | `attendance_summary` - the hours and the absent, leave, undertime and day-off counts of an employee for a period, with who approved it |
| `biometric_daily` filed per period | one row per employee per **date** (`is_day_off`); a day saved in two overlapping periods is kept once |
| `payroll`: `gross_pay`, `ot_late_adj`, `sss`, `bonus` ... and a copy of the name and hours | `basic_pay`, `overtime_pay`, `bonus_pay`, `gross_pay`, `sss_deduction`, `philhealth_deduction`, `pagibig_deduction`, `withholding_tax`, `tardy_undertime_deduction`, `absence_deduction`, `other_deductions`, `total_deductions` (computed by the database), `net_pay`, `updated_at`; the name and hours are read from `employees` and `attendance_summary` |
| `bonus_deduction_history`, `print_log`, `payroll_periods.period_start / period_end` | `bonus_deductions` (`type`, `entry_date`), `print_logs` (`printed_at`), `start_date` / `end_date`, `created_by`, `created_at` |
| who did it kept as a typed name (processed, reviewed, approved, printed, entered, signed, created, period trail) | a link to the account (`processed_by`, `reviewed_by`, `approved_by`, `printed_by`, `entered_by`, `signed_by_user`, `created_by`, `performed_by` = `users.id`, `ON DELETE SET NULL`) |
| `emp_name`, `period_label` repeated in history tables | gone - read from `employees` / `payroll_periods` |

Key and index names follow one pattern (`fk_<table>_<column>`, `uq_<table>_<column>`, `idx_<table>_<column>`). The rest of the database (`settings`, `period_audit`, `login_attempts`, `document_series` / `document_serials`, `attendance_logs`, `biometric_employee_map`, `biometric_agent_state`, `biometric_api_keys`) is the same, tidied to the same naming. Money and pay rules are unchanged: `gross_pay` is still the pay after absences, late and undertime, `net_pay = gross_pay + bonus_pay - total_deductions`.

### Updating the live database - nothing is lost
Run **`payroll2/sql/migrate-standard-schema.sql`** in DBeaver (Alt+X, answer "Stop" to any error). The database must already be in the 2026-10-15 `employee_id` layout (below). Everything is guarded: it only runs while the database is in the older layout, so running it twice changes nothing.
* **Part A** sets the old tables aside (as `old_*`) and builds the new ones; **Part B** copies every record with **the same id**, so everything that pointed at it still does - names are split into first and last name (the last word, with a particle before it - DE LA CRUZ, DELA CRUZ - and a suffix after it - JR., III; "LAST, First" is read as written), branches and positions typed on employees become list entries, the profile is merged in, a day saved twice is kept once (the latest upload), who-did-it names are looked up among the accounts (when two accounts share a name the role that makes sense for the action is taken, and a name nobody has stays empty).
* **Part C** checks that every record and every peso arrived (22 checks: counts, net / gross / deductions / bonus totals, hours, names, branches, positions, manager scopes, and every line's `net = gross + bonus - deductions`) and prints the verdict. **Part D only deletes the old tables when every check passed**; otherwise it says so and leaves them. **Part E** (commented out) removes the backup copies the earlier update left (`bak_emp_*`, `biometric_daily_unmatched`, `users_unmatched`).
* The new application checks the layout on its first page: on the older layout it shows "The database needs a one-time update" and changes nothing (the same happens to the old application on the new layout - deploy the new files and run the script one right after the other).

### Also changed
* Employees: first name / last name, position (type or pick), branch (pick), phone, address, emergency contact; a new position or branch typed there joins the list. Settings: three list cards (add, rename, remove only when unused). Managers: "Whole branch", "Whole department", "Selected employees" - saved as `user_scopes`.
* `sql/database.sql` and the application's own table set-up create the standard layout on a fresh install; `tests/dbeaver_checks.sql` reads it (same checks, same columns in the results).
* A conversion slip found by the new tests: the manager's "Manual Attendance" list did not read the time a day was saved - fixed.

### Tests
`schema.sql` is the standard layout, proven identical to `database.sql` (suite 13) and to what the migration produces (suite 15). **New suite 15** runs the migration the way DBeaver does (every error ignored, MySQL in the live server's mode) on the layout the live database had, filled with data that contains every awkward case: names with particles, a suffix, "LAST, First", one word, extra spaces; a position typed in two capitalisations; an e-mail typed twice; two accounts with one name; managers with a branch, with none and with picked employees; two overlapping periods holding the same days; an actor name nobody has; a pay line without an attendance row; a negative late adjustment. It proves each record, id and peso, the 22 checks, that a failed check keeps the old tables and a half-converted database is left alone, that running it twice changes nothing, Part E, and that the application reads the result and adds nothing to the layout - and that every SQL file that ships follows DBeaver's rules (a blank line inside a statement ends it there in DBeaver, which a PHP runner does not show; one such line was found in the migration and removed). Suite 14 (the earlier migration) now ends by showing the new application's message on that layout. Suite 13 proves `001_attendance_logs.sql` builds exactly the terminal tables `database.sql` builds. The whole run passes in MySQL's default mode and under the live server's ANSI mode (`PAYROLL_TEST_SQL_MODE`, see `tests/README.md`); the bug-injection check was re-aimed at the new column names (M38, M39, M42, M44) and catches 57 of 57. Agent: its direct-database write uses `attendance_logs.device_user_id` (the push API's JSON field stays `employee_id`).

## 2026-10-15 - records point at employees by `employees.id` (foreign keys)

Ten tables used to keep their own copy of the employee's text ID (`emp_id`, e.g. EMP-004): attendance, payroll, bonus_deduction_history, biometric_daily, leave_requests, payslip_signatures, employee_profiles, manager_employees, users (an employee's portal login) and biometric_employee_map. Each now stores **`employee_id` = `employees.id`**, the integer primary key, with a **FOREIGN KEY** to `employees(id)`. `emp_id` stays in `employees` only (still UNIQUE) - it is still the ID on every screen, file, payslip and CSV, so nothing looks different.

### What the database now guarantees
| Tables | ON DELETE | ON UPDATE | Meaning |
|---|---|---|---|
| attendance, payroll, bonus_deduction_history, biometric_daily, leave_requests, payslip_signatures | RESTRICT | CASCADE | history: an employee who has any of it cannot be deleted (the app already refused; now the database does too) |
| employee_profiles, manager_employees, users, biometric_employee_map | CASCADE | CASCADE | they belong to the employee: deleting an employee who has no history also removes their portal login, profile, manager assignments and terminal number (before, these were left behind, pointing at nobody) |

* A record can no longer point at an employee who does not exist (an error instead of an orphan row).
* Changing an employee's ID (EMP-004 → E-0004) can no longer cut them off from their own history - the history follows `employees.id`.
* The unique keys are on `employee_id` now: one payroll / attendance line per employee per period, one day per employee per period and date, one profile per employee.

### Updating the live database - nothing is deleted
Run **`payroll2/sql/migrate-employee-id.sql`** in DBeaver *before or right after* deploying this version (until it has run, this version shows "The database needs a one-time update…" instead of its pages, and changes nothing; the old version keeps working after Part A). The file explains each step; in short:
1. Full backup first (DBeaver → Tools → Backup).
2. **Part A** (Alt+X) only adds: copies of the ten tables (`bak_emp_*`), the new `employee_id` column, filled in from `emp_id` in one transaction (capitals and trailing spaces ignored, as MySQL always compared them). It ends with two reports; **A6 must show no rows**. A row whose emp_id matches no employee is listed there, with three remedies that keep the data (restore the employee as Inactive; point the rows at the right employee; for terminal numbers from old uploads, move the days into `biometric_daily_unmatched`).
3. **Part B** (Alt+X) switches: removes `emp_id` from the ten tables and adds the keys and foreign keys. It checks first and **stops on purpose if any row has no employee - every step after its check is skipped even if DBeaver is told to ignore the error.** (MySQL cannot roll back ALTER TABLE, which is why the safety is a guard, backup copies and a rollback script rather than one transaction.)
4. **Part C** checks that every row of the backup copies is still there and points at the same employee; later it drops the copies.

`payroll2/sql/rollback-employee-id.sql` puts the old layout back (emp_id filled in again from `employees`, the old indexes and foreign keys as Part A recorded them) for going back to the previous version.

### Also changed
* Upload: the step that moved days filed under a terminal number onto the employee's real ID is gone - every day now belongs to an employee (remedy 3 of the migration handles old ones).
* Employees page: the search box works again (it failed with an "ambiguous column" database error once the portal-login and profile tables were joined).
* `sql/database.sql` and the application's own table set-up create the new layout on a fresh install; `sql/001_attendance_logs.sql` too. (`attendance_logs.employee_id` is the terminal's own number, not `employees.id` - unchanged.)
* `tests/dbeaver_checks.sql` reads the new layout (same checks, same columns in the results).

### Tests
New suite 14 runs the migration file statement by statement on a copy of the live layout (2026-10-13 ERD export) filled with data - IDs in other capitals, a trailing space, mixed collations, a terminal number, a deleted employee: Part A, the guard holding with every error ignored, both remedies, Part B, every row and value afterwards, re-running both parts, the rollback and running forward again, the application reading the migrated data, a not-yet-migrated database refused with a message, and the foreign keys' rules. Full run: 236 tests, 1,016,061 assertions, 0 failed. `--app=<older copy>` only works for copies with this layout now (the suites write `employee_id`).

## 2026-10-10 - the days to compute are the days in the uploaded file

A **monthly or kinsenas** employee is paid for **the days that are in the uploaded file** - not for the days from a hire date to the end of the period. Before, a kinsenas ₱13,000 employee who started on the 8th and whose file held 3 days (the 8th-10th) was paid ₱7,000: every working day from the hire date to the end of the cut-off, four of them never uploaded. They are now paid **₱3,000 - 3 days × ₱1,000**. An employee added today can have this month's file, or any past month's, uploaded and is paid for the days that file holds, whatever the Date Hired says.

* **How a day counts** (monthly / kinsenas salary): a working day is paid when the file accounts for it - hours worked, a day the timesheet marks **OFF**, or **approved leave**. Every other working day of the period is unpaid, one day's rate each (the month's salary ÷ its working days, as for any absence), **whether or not the uploads have reached it yet**. So a partly uploaded period is paid for the days uploaded so far and grows with each day's file; a weekly day off is never deducted.
* **The Date Hired is only a record.** No pay depends on it any more. (It used to decide which days were "before the hire date": OFF days and approved leave before it were deducted, and the days after it were paid to the end of the period whether or not they were in the file.)
* A **daily-rate** employee is unchanged: paid for the hours the file shows.
* Payslip and Payroll page say "Unpaid days (absent, or not in the timesheet)". Employees → Date Hired says what it does.
* A monthly / kinsenas payroll built from a **totals file** (no day rows) is not day-based and is unchanged.
* DBeaver C51 / C52 (pay for a period before the Date Hired) are now *review* queries.
* Tests: the exact example above and its neighbours (this month so far, accumulating day by day; a past month with OFF days and approved leave; the same file under four different Date Hired values giving one and the same payroll line; a daily rate), one through the real Employees page and upload endpoints; the independent ledger follows the same rule; a bug-injection mutant for it (M23, M57).
* Typed SSS / PhilHealth / Pag-IBIG / tax amounts apply to **every** month you upload, past months included - they are the employee's monthly amounts, not a date-stamped setting.

## 2026-10-10 - SSS, PhilHealth, Pag-IBIG and withholding tax are typed per employee

Until now the system **worked these four out** from each month's gross pay (the law's tables, month to date). They are now **the employee's own monthly amounts, typed by the admin** and deducted as typed. Some employees have none, and that is fine: leave the boxes blank.

### New
* **Employees → Add / Edit → "Contributions & Withholding Tax"**: four optional boxes (SSS, PhilHealth, Pag-IBIG, withholding tax; ₱ per month). Blank = none. They replace the old SSS / PhilHealth / Pag-IBIG tick-boxes; the employee list shows each person's amounts.
* **Settings → Contribution Schedule** now has three choices per amount and a line for **withholding tax**: the **1st cut-off, in full** (new), **every cut-off, in equal shares**, or the **last cut-off, in full**. The month's last cut-off always settles whatever is still owed, so a month comes to **exactly** the amount typed (to the centavo; a half centavo of a share rounds up). Defaults follow the pharmacy's own timesheets: SSS on the 1st cut-off, PhilHealth and Pag-IBIG on the 2nd, tax in shares.
* Tax is the one amount that can be handed back: if the admin lowers it after a cut-off took its share, the last cut-off returns the difference (a negative tax, shown as a refund). A contribution already taken is never refunded.

### Changed
* Net pay = gross − the four typed amounts, whatever the pay: a half month's pay no longer lowers SSS, and a month with one day worked still takes the full amounts (the existing "negative net pay" guard on Finalize still asks first).
* **The company's share** (SSS 10% plus the Employees' Compensation of ₱10 / ₱30, PhilHealth, Pag-IBIG 2%) is figured **on what each pay line actually deducted**. Editing an employee's amounts later no longer changes the company cost - or the forecast's labor cost - of periods already paid.
* Editing an employee's amounts recomputes their **open** payroll straight away; finalized cut-offs are left exactly as they were (the Payroll page's "no longer settle the month" warning appears only when a finalized cut-off really is behind a corrected earlier one).
* Settings' tables of the law (SSS, PhilHealth, Pag-IBIG, BIR) are now labelled **reference** - a guide for working out the amounts to type. The functions behind them (`sssMonthly()`, `birTax()` …) stay, and are still tested against the published tables, but payroll no longer calls them.
* DBeaver queries C10-C13 now ask "did the month come to the amount typed on the employee?" (they are *review* queries: an amount edited after a month was paid shows that month); C30 / C31 also check the new amounts and the new timing.

### Database
Four columns added to `employees`: `sss_amount`, `philhealth_amount`, `pagibig_amount`, `tax_amount` - `DECIMAL(12,2) NOT NULL DEFAULT 0`. They are created by the application the first time a page loads (that one page load takes noticeably longer on the cloud database - the application re-checks its whole schema once, up to about half a minute - and then it is as fast as before), and are in `payroll2/sql/database.sql` for fresh installs. **Existing employees start with 0, so nothing is deducted from them until their amounts are typed** - open the employee, type the amounts, save (their open payroll is recomputed). Finalized periods keep their figures. The old `deduct_sss` / `deduct_philhealth` / `deduct_pagibig` columns stay in the database but are no longer read.

### Tests
The independent ledger takes the typed amounts and the schedule; new tests cover the three schedules (including a weekly payroll and an odd ₱495.01), an amount edited mid-month, blank = none, the Employees form limits, "editing recomputes the open payroll", and the new columns. **Against the pharmacy's real timesheets (83 employee-cut-offs, Feb-May 2026) the typed SSS and PhilHealth reproduce every deduction in the sheets to the centavo.** The bug-injection self-check has new mutants M46-M56 for the new rules. The tests that checked the law's tables against pay (SSS 5% of the credit on the month's pay, the BIR table per cut-off …) were rewritten, because the system no longer does that.

## 2026-10-07 - payroll audit, 13th month pay, clean-up

An independent audit (QA, BIR examiner and accountant seats; [`tests/AUDIT_FINDINGS.md`](tests/AUDIT_FINDINGS.md)) confirmed the core arithmetic - gross, SSS, PhilHealth, Pag-IBIG, withholding tax, net pay - to the centavo, and found **19 defects around it. All are fixed.** There are **no database changes**: the same tables and columns, so the update can be applied to a live database and undone by restoring the files.

### New
* **13th Month Pay** page (PD 851): basic pay earned in the year ÷ 12, month by month, with advances, balance, the ₱90,000 exemption watched, print and CSV. See [`info/ph_13th_month_pay.md`](info/ph_13th_month_pay.md).
* **Settings → Overtime Method**: *flat* peso rate (the default, unchanged) or the **Labor Code** (the employee's own hourly rate × a multiplier, 1.25 by default); Settings lists employees a flat rate underpays.
* **Recompute** button on Payroll Processing, and a warning when a month's contributions or tax no longer add up (an earlier cut-off was corrected after a later one was finalized).
* **`payroll2/sql/database.sql`** - the base tables, so a fresh install no longer depends on a file that was missing from the repository.
* `tests/dbeaver_checks.sql` - 27 read-only queries to audit the live database from DBeaver.

### Fixed
| | |
|---|---|
| Tax | Tax over-withheld in an earlier cut-off is **returned** on the month's last run (it was kept); a tax of exactly half a centavo no longer rounds down. |
| Salary | A salaried employee hired mid-period is paid from the hire date, not the whole period. |
| Net pay | Negative net pay can no longer be locked in unnoticed; a deduction that would push net pay below zero is held back. |
| Input | Impossible hours (80 h in a day, negative figures, `08:60` read as 860 hours), negative rates or salaries, and junk Settings values are refused, and every skipped row is listed. |
| Punches | A longer day never pays fewer hours than a shorter one (the break rule). |
| Forecast | Dashboard used the six *oldest* periods; ARIMA counted the average change twice; the Random Forest changed on every page load and could not follow a rising payroll; it forecast take-home pay instead of the company's labor cost; amounts showed three decimals. |
| Pages | "Predicted next month" on a kinsenas calendar; "Period finalized" and the Home page never showed a finalized period as finalized (its status is *Locked*); the "latest period" was chosen by id instead of date; receipts read "ONE PESOS". |

### Changed
* Bonus and deduction entries go through one shared function (`recordAdjustments()`), used by both *Bonus & Deductions* and *13th Month Pay*.
* A bonus that would take an employee past the ₱90,000 tax-exempt ceiling for the year waits for a decision.
* Finalized periods are flagged out-of-date only when an *earlier cut-off of the same month* changed after they were finalized.

### Removed
* `payroll2/README.md` - described an older XAMPP layout and a `database.sql` that did not exist; replaced by the root README.
* `payroll2/assets/images/logo2.png` (unused, 0.9 MB) and two unused helper functions (`birMonthlyTax()`, `salaryRateUnit()`).

### Tests
~200 tests, ~1 million checks, a bug-injection self-check (the suite catches every bug deliberately put into the code), the forecast page and the upload page exercised in a real browser DOM, and the DBeaver queries proven on MySQL 8. Run `tests\run-tests.bat`.
