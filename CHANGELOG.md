# Changelog

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
