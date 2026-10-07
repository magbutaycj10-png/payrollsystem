# Changelog

## 2026-10-07 — payroll audit, 13th month pay, clean-up

An independent audit (QA, BIR examiner and accountant seats; [`tests/AUDIT_FINDINGS.md`](tests/AUDIT_FINDINGS.md)) confirmed the core arithmetic — gross, SSS, PhilHealth, Pag-IBIG, withholding tax, net pay — to the centavo, and found **19 defects around it. All are fixed.** There are **no database changes**: the same tables and columns, so the update can be applied to a live database and undone by restoring the files.

### New
* **13th Month Pay** page (PD 851): basic pay earned in the year ÷ 12, month by month, with advances, balance, the ₱90,000 exemption watched, print and CSV. See [`info/ph_13th_month_pay.md`](info/ph_13th_month_pay.md).
* **Settings → Overtime Method**: *flat* peso rate (the default, unchanged) or the **Labor Code** (the employee's own hourly rate × a multiplier, 1.25 by default); Settings lists employees a flat rate underpays.
* **Recompute** button on Payroll Processing, and a warning when a month's contributions or tax no longer add up (an earlier cut-off was corrected after a later one was finalized).
* **`payroll2/sql/database.sql`** — the base tables, so a fresh install no longer depends on a file that was missing from the repository.
* `tests/dbeaver_checks.sql` — 27 read-only queries to audit the live database from DBeaver.

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
* `payroll2/README.md` — described an older XAMPP layout and a `database.sql` that did not exist; replaced by the root README.
* `payroll2/assets/images/logo2.png` (unused, 0.9 MB) and two unused helper functions (`birMonthlyTax()`, `salaryRateUnit()`).

### Tests
~200 tests, ~1 million checks, a bug-injection self-check (the suite catches every bug deliberately put into the code), the forecast page and the upload page exercised in a real browser DOM, and the DBeaver queries proven on MySQL 8. Run `tests\run-tests.bat`.
