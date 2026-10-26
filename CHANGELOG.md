# Changelog

## 2026-10-27 (later) - every payslip says WHY: the reason behind each bonus and deduction, and what absence and lateness cost

* **Employee portal > My Payslips**: each payslip now has an always-visible **"Why your pay is what it is"** list (it used to be a short list of the period's own entries, and only when there were any). It shows
  * **Bonuses** - every bonus the admin recorded in Bonus & Deductions (Adjustment Details), with its reason, date and amount;
  * **Deductions** - the amount of the Deductions tile split into *Tax & contributions* (withholding tax, SSS, PhilHealth, Pag-IBIG; each one is still under Details) and every **deduction entry with its reason**, date and amount, so the list adds up to the tile;
  * **Attendance** - **absent days** (how many, and the pay lost: one day's pay each) and **late / undertime** (hours, and the pay taken off), marked as already out of Basic Pay, so they are not part of the Deductions tile. A late arrival that cost nothing says so ("the day was not left short"); a daily-rate employee's absent days say they carry no pay.
  * An amount on the payslip that no entry explains (an older line, or a database edited by hand) is shown as its own row, "no reason was recorded for this amount - ask your admin", so nothing is left without a line.
* **Monthly-paid employees**: the single pay line of the month's last cut-off carries the bonuses and deductions recorded against the **1st half** as well (it always has, in the totals); until now the payslip listed only the entries recorded against the last cut-off, so part of the Bonus / Deductions had no reason beside it. The page now lists all of them.
* **Printed payslip** (admin and employee copy): two compact lines under the attendance line, **Bonus / Allowance:** and **Other Deductions:**, each entry with its reason and amount; the attendance line also names **late** hours (alone, or with undertime). Both copies still fit one A4 page.
* New `payLineReasons()` in `includes/helpers.php` does the work for both; `employee/payslips.php` and `includes/bir-print.php` only draw it. No table or column changed.
* Tests: suite 18, new test E2 (bonus and deduction reasons incl. the 1st half's, absent, late and undertime, an unexplained amount, the admin's and the employee's printed slip); mutants M71-M73 guard it (73 mutants). Suites 11, 15, 16, 17, 09 and 04 pass unchanged.

## 2026-10-27 - every trend chart shows the last six months, the newest month included

* **Admin dashboard** (payroll trend and its forecast), **Salary Forecast** chart, **employee dashboard** (Pay Trend and Hours Worked) and **manager dashboard** (Team Hours Trend) now show the **last six calendar months**: the month of the newest period that has data and the five months before it. Before, they showed the last six *periods* (about three months on a kinsenas calendar, or years of data on the forecast chart). The window ends at the newest period with data, not at today's date, so a chart is never empty because nothing was paid this month yet. New helpers `trendWindowStart()` / `withinTrendWindow()` in `includes/helpers.php`; `lastSixMonths()` in `assets/js/forecast.js`.
* The dashboard's "Predicted next cut-off net payroll" is a straight-line trend on the same six months. The Random Forest and ARIMA models on the Salary Forecast page are still trained on the **whole** history (more data makes them better, and ARIMA needs 5 periods); only the picture is cut.
* The employee's "Vs. Last Period" still compares the two newest payslips, whichever month they fall in. Chart headings say "Last 6 months (N period(s))".
* Tests: suite 05 (dashboard window, employee and manager charts), suite 07 (forecast page chart), suite 08.

## 2026-10-26 (later) - one set of cut-offs; monthly-paid employees are paid in the month's last cut-off; an overtime rate on each employee

* **There is one set of pay periods: the kinsenas cut-offs (1st-15th and 16th-end).** A separate whole-month ("Monthly") pay period is no longer created or offered (`create-period.php` refuses it). Employees whose **Pay Schedule is Monthly** are paid **once, in the month's last cut-off, for the whole month**: their days from the 1st, their absences, SSS / PhilHealth / Pag-IBIG and tax in full, and the bonuses and deductions recorded against **either** half, all in that one pay line (marked *Monthly* on the payroll page; the payslip covers "Apr 1 - Apr 30"). Until the last cut-off holds days of theirs they are *waiting*: the days are saved, nothing is computed, and the upload / payroll / adjustments pages say so.
* **Upload Attendance**: a file with a half month or the whole month can be uploaded into either cut-off. Every day goes to the cut-off that holds its date; the missing 16th-end cut-off is created; a day whose cut-off is finalized is left alone and reported.
* **Older databases** that still hold a whole-month pay period keep working (the monthly-paid employees are paid there, never twice); the payroll page offers **Tidy up** to fold such a period into the cut-offs.
* **Employees > Add / Edit** has an **Overtime Rate** (pesos per hour): blank = the company rate in Settings (now labelled *Company Overtime Rate*), 0 = no overtime pay, a number pays every overtime hour of that employee under either overtime method.
* **13th Month Pay**: *Print sheet* asks for a branch and prints one page per branch; the CSV export is gone.
* **Fix**: a leave request decided after the 1st half was already finalized did not change the monthly-paid employee's pay - their one line sits in the (open) last cut-off, which the decision did not look at. It now recomputes that line, and the message names the cut-off that is locked (the last one), not the 1st half, which holds no line of theirs.
* **Launchers**: `launch.bat` / `launch-lan.bat` serve `payroll2_std` when that folder exists (the development PC). `payroll2` there is the older layout and must not run against the live database - it creates the old tables in it. The GitHub copy has only `payroll2`, so nothing changes there.
* **DBeaver check C32** now lists a monthly-paid employee's line in a cut-off that is not the month's last, or a kinsenas employee in an older whole-month period (it used to flag every correct monthly-paid line).

### Tests
Suite 18 (12 tests) covers all of the above, with the late-leave case as test H; mutant M70 guards the fix (70 mutants). Suites 04, 11, 13, 15 and 16 were brought in line with the new design (days go to the cut-off that holds their date, no separate Monthly period, no 13th-month CSV, `overtime_rate` added by the application, a pay line's `pay_schedule` is read from the employee).

## 2026-10-26 - a real name and a drawn signature for the admin on every receipt; the bonus / deduction receipt is e-signed by the employee

* **Settings > Authorised Signatory** (new card, own form): the admin types the **name** (and position) that prints under the signature and **draws the signature**. Nothing is saved unless the admin also types **their own password**; wrong passwords count with the sign-in attempts (5 in 15 minutes lock the form). Leave the pad empty to keep the signature on file. The two old boxes in *Company & Document Details* are gone (that form has no password); a line there shows the name and points to the new card.
* **Both receipts** (the payslip & acknowledgement receipt, and the bonus advice / deduction conforme) now print that **real name** in the admin signature block. The bonus receipt used to print the account name, which is just "Admin"; that name is used only when no signatory is set. The admin's **drawn signature is stamped on the receipt once its pay period is finalized** - on an open or re-opened period the box stays empty. A *Blank Copies* print (`sig=0`) carries neither signature, both are signed in ink. The employee portal's copy has it too.
* **Bonus / deduction receipts can be e-signed by the employee** like the payslip: new page **Sign Bonus Receipt** in the admin sidebar and in the manager portal (a manager sees and signs only for the employees they manage). Only after the entry's pay period is finalized; the signature is kept with the amount it acknowledges and is shown on both copies of the receipt; **Adjustment History** has a new *E-signed* column.
* **Database**: two new tables, `adjustment_signatures` (one signature per `bonus_deductions` row, deleted with it) and `admin_signature` (one row). The first page load on an existing database creates them (`CREATE TABLE IF NOT EXISTS`, also in `sql/database.sql`); nothing existing changes. The admin's name and position stay in `settings` (`bir_signatory_name`, `bir_signatory_position`).
* New `assets/js/sig-pad.js`: the signature pad, cropped to the ink so a small signature still prints large.

### Tests
New suite 17 (13 tests: the password-protected signatory form and its lock-out, the real name and the finalize rule on both receipts and in the employee portal, blank copies, the e-signing by admin and manager with scope, deductions, History, the upgrade of an existing database). Suite 13 counts 25 tables. `Http::call()` accepts `server` (e.g. `HTTP_X_REQUESTED_WITH`).

## 2026-10-24 - the pay schedule and the contribution schedule are set on each employee; "See brackets"; Settings tidied

Not every employee is paid the same way: some are paid **Monthly**, some **kinsenas** (twice a month), and not all of them have their SSS, PhilHealth, Pag-IBIG and withholding tax taken on the same cut-off. So the two settings that used to hold this for everybody moved onto the employee.

* **Employees > Add / Edit** has a new **Pay Schedule** (Semi-Monthly (kinsenas) or Monthly - Weekly is gone) next to the salary, and a **Contribution Schedule** under the SSS / PhilHealth / Pag-IBIG / Withholding Tax amounts: for each of the four, the 1st cut-off in full, equal shares at every cut-off, or the last cut-off in full. For a Monthly employee the four boxes are greyed out (one pay run a month takes every amount in full). A new employee starts on the schedule most employees are on and the usual contribution schedule (SSS 1st cut-off, PhilHealth and Pag-IBIG the last, tax in shares). The Employees table says "Paid monthly / semi-monthly".
* **A pay period takes the employees paid on its schedule.** A Semi-Monthly cut-off holds the kinsenas employees, a Monthly pay period the Monthly ones; the other employees' days in the same file are still saved and count in the pay period of their own schedule (the upload says who they are, and brings the other schedule's open period up to date). So **a Monthly pay period can sit beside the two cut-offs of the same month** - periods of the same schedule still cannot overlap. Changing an employee's schedule on the Employees page re-works the open pay periods (out of the old schedule's, into the new one's) and leaves finalized ones alone - change it at the start of a month, because pay already made on the other schedule is not carried over. A totals file neither counts nor replaces the saved days of employees on another schedule; the manager's manual entry goes to the open period of the employee's own schedule.
* **See brackets** (button in *Contributions & Withholding Tax*): a window with a tab each for SSS (all 61 credits, with the employee, employer, EC and total), PhilHealth, Pag-IBIG and the withholding tax (the TRAIN table for a semi-monthly cut-off and for a month). The rows that fit the employee's pay - worked out from the salary and the SSS / PhilHealth / Pag-IBIG typed on the form - are marked and scrolled into view. It is a reference: nothing is filled in, payroll still deducts exactly what is typed. The figures are the same `PH_RULES` the Settings reference uses (`contributionBrackets()`).
* **Settings**: *Default Pay Schedule* and *Contribution Schedule* are gone (a line says where they went). *Contribution tables - for reference* now closes the page, after the Database Summary; its closing note says "the cut-offs chosen for that employee under *Contribution Schedule*" instead of pointing at a setting "above".
* **Employees > Add / Edit**: the line "Employee logs in at http://localhost:8765/employee/ using their Email + this password" is removed (it was wrong on the deployed site).
* **Upload Attendance**: no *Weekly* period (the form, the API - `create-period.php` refuses it - and the remembered schedule switch). A period with no stated type takes the schedule most employees are on. A Weekly period an older database already holds still works and takes everybody, as before.
* **Existing databases**: the first page load adds five columns to `employees` (`pay_schedule`, `sss_timing`, `philhealth_timing`, `pagibig_timing`, `tax_timing`; also in `sql/database.sql`) and gives every employee what the old Settings said: the old *Contribution Schedule* for the four timings, and for the pay schedule the schedule of the pay period they were **last paid in** (those never paid take the old *Default Pay Schedule*; Weekly becomes Semi-Monthly). Nothing is deleted - the old settings rows stay, unused - so no existing payroll changes by itself.
* **DBeaver checks** (`tests/dbeaver_checks.sql`): C22 and C23 count an overlap only between pay periods that both take the employee / have the same schedule; C31 no longer reads the two removed settings; new **C32** lists pay lines in a period of another schedule than the employee's.

### Tests
New suite 16 (20 tests: a Monthly run beside the cut-offs, changing the schedule, the schedule per employee, the form, the bracket tables against the reference calculators, the page in headless Edge, Weekly removed and the overlap rule, totals and manual entry, the one-time seeding on a database in the old layout). The generated cases now save their contribution schedule on the employee; suite 15 expects the five columns to arrive on the first page after the migration. New mutants M58-M69; M54 re-aimed at the Employees form.

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
