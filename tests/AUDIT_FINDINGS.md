# Payroll calculation audit - findings

> **Update, 10 Oct 2026.** This is the audit of 7 Oct, when SSS, PhilHealth, Pag-IBIG and withholding tax were *worked out from pay*. They are now **typed per employee and deducted as typed** ([`../CHANGELOG.md`](../CHANGELOG.md)), so the findings about *computing* them - the "core arithmetic is sound" verdict on the tables, D-01 (BIR half-centavo rounding) and D-02 (tax over-withheld is never returned) in particular - describe the earlier behaviour. The audit's other findings (forecasting, hire-date proration, Labor Code overtime, input limits, the Finalize guards, the 13th month …) are unaffected. What D-01/D-02/D-18 mean now: the reference calculators in `helpers.php` still round a half centavo up (suite 01); a tax amount lowered after a cut-off took its share is handed back on the month's last cut-off (suite 03); and a finalized cut-off that stops adding up after the employee's amounts or an earlier cut-off were changed is still caught (suite 09). D-03 (a salaried employee who starts mid-period is paid the full period) is still fixed, by a different rule: the days to compute are the days in the uploaded file, so the days before the first one - and any other working day the file does not account for - are unpaid, and the Date Hired plays no part (suites 03 and 11). The tests were rewritten for that, with the same tags.

Reviewed from three seats: **QA** (behaviour, edge cases, regressions), the **BIR examiner** (published tables applied the published way) and the **accountant** (every centavo ties out). The audit itself changed no application code; everything here is reproducible with `tests\run-tests.bat` (see [README.md](README.md)) - the evidence below was gathered on the app as it was **before** the corrections, which then went into this version of the app: see [the status section below](#status-after-the-fixes-7-oct-2026) and [`../CHANGELOG.md`](../CHANGELOG.md). (To see a defect happen, run the suite against an older copy: `tests\run-tests.bat --app=<folder of the old payroll2>`.)

## Verdict

The **core payroll arithmetic is sound.** The statutory tables are the published ones, and the day-by-day engine agrees with an independent integer-centavo ledger to the centavo on every figure - on worked examples derived by hand, on 1,337 generated pay runs, and on your eight real Feb–May timesheets. The problems are at the edges: **the forecasting feature (the capstone's headline) has two outright calculation bugs**, month-end tax true-up is one-directional, a few rules are missing (hire-date proration, labor-cost forecast, Labor Code overtime premium), and input is not sanity-checked.

18 defects were confirmed - 2 High, 8 Medium, 8 Low - and a 19th (D-19, the Home page's twin of D-09) turned up during the clean-up. Each has a test tagged `[D-nn]`: it fails on an app that still has the defect and passes - as a regression guard - on this one.

## Status after the fixes (7 Oct 2026)

All 19 are fixed in this version of the app (**no database change**). What was done for each, and where:

| ID | Fix | Where |
|---|---|---|
| D-01 | tax worked in whole centavos (`intdiv`); a half centavo always rounds up | `includes/helpers.php` `birTax()` |
| D-02 | the month's last run takes the monthly table on the whole month **minus what was withheld**, and may come out negative - a refund - printed as such | `settleWithholdingTax()`; payslip, register, reports, payroll page, signature pages, employee portal |
| D-03 | working days before the hire date are unpaid days (same day rate and columns as absences) for salaried employees | `recomputePeriodFromDaily()` |
| D-04 | the dashboard regression uses the newest six periods | `dashboard.php` |
| D-05 | ARIMA counts the mean change once | `assets/js/forecast.js` `arimaForecast()` |
| D-06 | `total_employer_share` and `total_labor_cost` in the API; the page forecasts **labor cost** by default (selector for net pay); both in the Orange3 CSV | `api/forecast-data.php`, `employerSharesByPeriod()`, `forecast.php`, `forecast.js` |
| D-07 | the forest's randomness comes from a generator seeded with a hash of the data | `forecast.js` `makeRng()` / `seedFrom()` |
| D-08 | the forest is trained on the change from period to period and forecasts last value + predicted change | `forecast.js` `randomForestForecast()` |
| D-09 / D-13 / D-11 | step compares with `'Locked'`; latest period by date; the card is named after the schedule ("next cut-off") | `dashboard.php` |
| D-10 | Finalize asks before locking a negative net (override: confirm); a deduction that would push net pay below zero is held back and named; banner on the payroll page | `api/update-payroll.php`, `adjustments.php`, `payroll.php`, `assets/js/payroll.js` |
| D-12 | "ONE PESO" | `includes/bir-print.php` `amountInWords()` |
| D-14 | **Settings → Overtime Method**: *flat* (default, unchanged) or *Labor Code* - the employee's own hourly rate × a multiplier (1.00–3.00, 1.25 default), in integer centavos; Settings lists who the flat rate underpays | `overtimePay()`, `overtimeShortfalls()`, `settings.php` |
| D-15 | forecast amounts to two decimals, negatives signed | `forecast.js` `fmt()` |
| D-16 | day: hours ≤ 24, overtime ≤ 16, hours + overtime ≤ 24, nothing negative or non-numeric; totals file: ≤ 24 h per calendar day of the period; rates and salaries 0 – limit; Settings values validated; unreadable times (`08:60`, `8h30m`) are `NaN`, never hundreds of hours; every refused row is listed | `dayHoursProblem()`, `periodHoursProblem()`, `pesoProblem()`; `save-daily-attendance.php`, `save-attendance.php`, `manager/manual-attendance.php`, `employee.php`, `settings.php`, `adjustments.php`, `attendance-upload.js`, `attendance-formats.js` |
| D-17 | the break comes off only down to half the duty day: `max(duty/2, span − break)` | `api/rollup-punches.php`, `attendance-formats.js` |
| D-19 | the Home page counts a *Locked* period as finalized - in the one-line status and in the badge (it compared with `'Finalized'`, which a pay period never is) | `home.php` |
| D-18 | `settlementDrift()` finds lines that no longer settle their month; Finalize refuses them, the payroll page names them (a *finalized* month only when an earlier cut-off of it changed after it was finalized - a raise made months later is no reason to rewrite history), a new **Recompute** action rebuilds an open period from its days | `api/update-payroll.php`, `payroll.php`, `earlierRunChangedAfterFinalize()` |

**One more thing the audit added:** the **₱90,000** yearly ceiling for tax-exempt 13th-month pay and other benefits is now watched - a bonus that would take an employee past it is held back until a person confirms (the system still withholds nothing on a bonus; the tax on the excess has to be handled separately).

**13th month pay was added** (it was a number the admin had to work out and type): *Payroll Process → 13th Month Pay* computes basic pay earned in the year ÷ 12 per employee, month by month, records the payment as a bonus through the same code as Bonus & Deductions, and watches the ₱90,000 ceiling - see [`../info/ph_13th_month_pay.md`](../info/ph_13th_month_pay.md).

**Deliberately not changed** (decisions for the owner): the overtime default stays the flat rate; bonuses stay untaxed; no rest-day / holiday / night-shift premium, year-end annualization or BIR forms (see Observations).

The suite grew from 148 to the tests listed in [README.md](README.md): high tax brackets by hand, bonus / deduction / ceiling rules, input limits on every door, Labor Code overtime, refunds end to end, hire-date proration, Recompute and the Finalize guards, the forecast **page** in a real browser DOM, the upload page's requests, and the DBeaver queries. Mutation check: every one of the 45 bugs injected into this app is caught (and all 20 of those that apply to an older copy).

## What was checked and found correct

| Area | How it was checked | Result |
|---|---|---|
| **BIR withholding tables** (daily, weekly, semi-monthly, monthly) | 4 tables × 5 brackets compared with the Annex E schedule (typed again, not copied); continuity at every bracket boundary; 40 hand-computed points; agreement with the annual TRAIN schedule ÷ 365/52/24/12 | exact; every table is within ₱0.08 of the annual schedule |
| **SSS** (RA 11199 / Circ. 2024-006) | credit at every ₱500 step incl. ₱5,250 → 5,500 and ₱34,750 → 35,000; 5% / 10%; EC ₱10 → ₱30 at a ₱15,000 credit; ≈160 000 probes vs ledger | exact |
| **PhilHealth** 5% (2.5% each), ₱10k floor / ₱100k cap | boundaries + ≈324 000 probes | exact |
| **Pag-IBIG** 1% to ₱1,500, 2% above, ₱10k fund-salary cap (₱200) | boundaries + ≈65 000 probes | exact |
| **Gross pay**, daily / kinsenas / monthly / weekly | 7 hand-derived examples; 1,337 generated pay runs (every salary type, rest-day pattern, run shape, all 8 contribution-timing combinations, leave, undertime, OT, OFF days) | exact, all 14 money/day fields per run |
| **Month settlement** (contributions are monthly; floors and caps apply once) | 200 generated employee-months | "each month ends exact": Σ contributions = contribution on the month's pay |
| **Net pay / payslip / register** | 5,000 random `computePayLine` calls; payslips printed from the DB | every figure cent-exact, foots, printed = stored, register totals = Σ rows, amount in words correct for 220 000 amounts |
| **Re-uploads, corrections, finalize/unlock** | end-to-end through the real endpoints | idempotent, ids stable, bonus/deductions survive, locked periods refuse uploads and adjustments, audit trail written |
| **Your real timesheets** (Feb–May 2026, 83 employee-cut-offs) | read the way the browser reads them, uploaded cut-off by cut-off | gross = the spreadsheet's PERIOD TOTAL for every employee, apart from two explained differences below |
| **Parser fidelity** | the PHP port the tests use vs the *original* JavaScript, on ~1,100 real day rows | identical |
| **Suite sensitivity** | bugs injected into a copy of the app (`--mutation-check`): 20 on the audited app, 45 on this one (the 20 plus each fix and the 13th month put back the way they were) | all caught (one needed a new test - the EC threshold boundary; three more needed new tests or a stricter rule - the overtime limit alone, the break rule, the unseeded forest) |

## Defects

| ID | Sev. | Defect | Evidence | Suggested fix |
|---|---|---|---|---|
| **D-04** | **High** | Dashboard "predicted next payroll" regresses the **six oldest** periods, ignoring the newest | `dashboard.php:38-48` selects `ORDER BY period_start ASC … LIMIT 6`. With 8 cut-offs it fits Jan–Mar and never sees Apr–May. On your sample data it shows **₱50,294.67**; the latest six give **₱51,005.25** | take the newest six, then re-sort: `SELECT … FROM (… ORDER BY period_start DESC LIMIT 6) t ORDER BY period_start` |
| **D-05** | **High** | ARIMA(2,1,0) counts the average change **twice** | `forecast.js arimaForecast()`: `next = mu·(1−Σφ) + Σφ·(d−mu) + mu` - the intercept appears twice; correct is `mu + Σφ·(d−mu)`. A straight ₱100k → ₱150k payroll forecasts **₱170,000** (should be ₱160,000). On a realistic series the error is mu·(1−Σφ): app ₱61,307.42 vs textbook ₱59,313.37 | drop the first term (`let next = 0`) |
| D-02 | Medium | Tax over-withheld in an earlier cut-off (or week) is **never returned** | month-end settlement does `max(0, monthly tax − already withheld)`. ₱1,000/day, 13 days in the first half (tax ₱289.95), one day in the second: the month's taxable pay ₱12,750 is under the ₱20,833 exemption so the month's tax is ₱0 - the employee keeps paying ₱289.95. In 39 of 1,337 generated pay runs (₱4,415.60). Contradicts the app's own rule "each month ends exact". Weekly payroll makes it likely: four weekly exemptions total ₱19,232, less than the ₱20,833 monthly one | allow a negative amount in the last run (show it as "tax refund"), or add a refund line |
| D-03 | Medium | A **salaried employee hired mid-period is paid the full period** | kinsenas ₱7,500 hired Wed 8 Apr works every duty day from the 8th: paid ₱7,500 for 8–15 Apr although 6 of the 13 duty days precede the hire date (`dayStatus()` returns `nothired`, which nobody deducts) | treat each pre-hire duty day like an absence (₱15,000 / 26) or pro-rate by hire date |
| D-06 | Medium | The forecast predicts **net pay** (take-home), not company **labor cost** | `forecast-data.php` offers `total_net`; employer SSS (10%), EC, PhilHealth 2.5%, Pag-IBIG 2% are never added, and a loan deducted from an employee *lowers* the "budget". The capstone objective is "forecast monthly labor cost" | add `total_labor_cost = gross + bonus + employer shares` to the API and forecast that (plus a 13th-month accrual) |
| D-07 | Medium | Random Forest gives **a different forecast every page load** | unseeded `Math.random()`: the same history gave ₱140,626.39 and ₱139,044.44 | seeded PRNG (e.g. mulberry32), or average many seeded runs |
| D-08 | Medium | Random Forest **cannot follow a rising payroll** | tree leaves are averages of past payrolls: a straight line ₱100k → ₱150k forecasts ≈₱138–140k (should be ≈₱160k) | add a trend model (OLS / ARIMA with drift) to the ensemble, or forecast the detrended series |
| D-11 | Medium | Dashboard card says **"Predicted Next Month"** but predicts the next **cut-off** | on a kinsenas calendar every series point is half a month; a reader takes ₱50k as the month | label from `period_type` ("next cut-off") - `forecast.js` already does this |
| D-14 | Medium | **Compliance:** overtime is one flat ₱/h for everybody | Labor Code Art. 87: at least hourly rate + 25%. ₱480/day → ₱75/h; ₱620/day → ₱96.88/h; the app pays ₱45 (Settings). No rest-day/holiday (130% / 200%), no night-shift differential, no 13th month | per-employee OT rate = hourly × 1.25 (× 1.30 / 2.00 by day type); a holiday calendar |
| D-16 | Medium | **No sanity limits** on hours, overtime, rates, salary | a day of 80 h / 30 OT h is accepted and pays ₱1,830 for one day; a device report with 80 h becomes **72 h of overtime**; Settings accepts a **negative** overtime rate; Employee Management accepts a **negative** salary; `toHours("08:60")` = **860 hours** | validate: hours ≤ 24, OT ≤ 16 (hours + OT ≤ 24), rates ≥ 0, `toHours` returns NaN for malformed times; show what was skipped |
| D-01 | Low | Tax of exactly **half a centavo rounds down** at random | `round($base + ($taxable − $over) · $rate, 2)`: the float subtraction leaves ~1e-13 noise, so ₱0.045 may become ₱0.04. Daily taxable ₱685.30 → ₱0.04 (should be ₱0.05). 216 of 5,880 near-threshold ties; ≈0.003% of random amounts; always 1 centavo low | compute in integer centavos (`intdiv`) or round `$taxable*100` first |
| D-09 | Low | Dashboard step **"Period finalized" never completes** | `dashboard.php:105` compares with `'Finalized'`; `payroll_periods.status` is `'Locked'` | compare with `'Locked'` |
| D-10 | Low | **Negative net pay**, silently | one day worked in a month: gross ₱480, SSS ₱250 + PhilHealth ₱250 + Pag-IBIG ₱4.80 → net −₱24.80; a ₱50,000 deduction on ₱6,175 → net −₱43,825 | warn on the payroll page and block finalize; cap deductions (Art. 113) |
| D-12 | Low | Receipts read **"ONE PESOS"** | `amountInWords(1.00)` | singular for exactly 1 |
| D-13 | Low | Dashboard treats the **highest id** as "latest period" | `dashboard.php:9` `ORDER BY id DESC`; a backfilled older period relabels every card | `currentPeriod()` / `ORDER BY period_start DESC` |
| D-15 | Low | Forecast amounts show **three decimals** | `fmt(53210.4833)` → `₱53,210.483` | `maximumFractionDigits: 2` |
| D-17 | Low | Punch roll-up **deducts the 1-hour break from any day longer than half the duty day** | 4:00 on the clock → 4.00 h paid; 4:06 → **3.10 h** (`rollup-punches.php:156`, same rule in `attendance-formats.js:209`) | `max(std/2, span − break)` |
| D-18 | Low | **Correcting an earlier cut-off after the later one is finalized** leaves the month inexact, silently | only *Open* later cut-offs are re-settled. ₱1,200/day, +₱1,980 OT added to cut-off 1 afterwards: month tax ₱1,459.45 vs ₱1,457.55 required | warn "cut-off 2 is stale - unlock and recompute", or re-settle with a revision flag |

## Differences from your spreadsheets (not app defects)

Gross pay from the 8 timesheets matches the workbooks' PERIOD TOTAL except:

* **ERLINDA, 1–15 Mar** - the 10 Mar date cell holds a *time*, read as `1899-12-31`. The workbook still pays the day (₱350); the upload page skips rows outside the pay period, so the system pays **₱350 less**. (It prints "N day record(s) outside the pay period were skipped" - easy to miss.)
* **ROLLY, from 16 Mar** - the sheets pay overtime at **₱62.50/h** (his hourly rate, ₱500 ÷ 8) although their own `OT_PAY_PER_HOUR` column says ₱45; the app has one overtime rate for everybody (₱45): **−₱70, −₱140, −₱87.50, −₱192.50** in the four cut-offs where he worked overtime. (The April–May files I generated earlier were copied from the March template and inherit this.) This is the same root cause as D-14 - overtime should be per employee.
* The sheets' own deductions do not follow current rules (see `info/ph_government_deductions.md`): across the eight files they take ₱5,760 of SSS, ₱1,000 of PhilHealth and no Pag-IBIG, where the rules require ₱24,000, ₱12,210.44 and ₱7,557.90. The system applies the current rules.
* The ROLLY/JASH-style sheets compute undertime as *shortfall + late minutes*, but "hours worked" already starts at the actual time-in, so lateness appears to be counted twice (arrive 25 min late, leave on time → 50 min → 1 hour deducted). The app imports the sheet's undertime as stated. Worth asking the owner whether that is intended.

## Observations (no test - outside what the code claims to do)

* **BIR reporting.** No year-end annualization / refund of excess withholding (RR 11-2018), no Form 2316, 1601-C or alphalist output; the printed payslips are internal documents (the code says so). The ₱90,000 exemption for 13th-month and other benefits is not withheld on: bonuses are never taxed - fine for small amounts, wrong once a year's bonuses pass ₱90,000. (This version now **watches** the ceiling and holds a bonus back for confirmation when it would be crossed; computing the tax on the excess, and year-end annualization, remain outside the system.)
* **13th-month pay (PD 851)** was not computed in the audited version (an admin typed it in as a bonus); it is now - [`../info/ph_13th_month_pay.md`](../info/ph_13th_month_pay.md). Service-incentive leave and holiday pay are still not computed.
* **Absence deduction uses the month's own working-day count** (24 in February, 26–27 in other months): the same ₱7,200 kinsena loses ₱600 a day in February and ₱533.33 in March. A fixed, documented divisor is easier to defend.
* **Rounding method.** Everything money-related uses PHP float `round()`. It is correct on PHP 8.2 (local 8.2.31 and the Docker image `php:8.2-apache`) apart from D-01, but `round()` changed in PHP 8.4 - rerun the suite after any PHP upgrade, or move to integer centavos / `bcmath` (already installed).
* **Totals files** carry no day detail, so overtime inside the `Hours` column would be paid twice (the day-by-day path removes it; this one cannot).
* **Settings are global.** Changing the overtime rate or contribution timing changes what a re-upload of an *Open* period produces (finalized periods are protected).

## Limits of this audit

Statutory rates and tables were checked against the regulations as I know them and against the app's own sourcing notes (`info/ph_government_deductions.md`, "checked October 2026"); I did not re-fetch the agencies' circulars. If SSS, PhilHealth, Pag-IBIG or the BIR publish new tables, update `PH_RULES` *and* the independently typed `Ledger` constants, and rerun. Tests ran against MySQL 8.0.43 (a private instance - the same major version as the Aiven database) and PHP 8.2.31 on Windows. The first pass of this audit used MariaDB 10.4 and gave identical results. The browser-side parser was exercised on CSV text, not through SheetJS's `.xlsx` reader, and the biometric agent and ingest endpoint were not tested. No load, concurrency (two simultaneous uploads) or accessibility testing. The Random Forest can only be tested for properties, not exact values. This is a calculation and controls review, not legal or tax advice.
