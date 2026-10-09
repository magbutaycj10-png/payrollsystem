# 13th Month Pay - Rules Used by the System

Page: **Payroll Process → 13th Month Pay** (`payroll2/thirteenth-month.php`). Figures: `thirteenthMonthData()` in `payroll2/includes/helpers.php`.
This is a summary of the rules as the system applies them, not legal advice - confirm anything unusual with DOLE or your accountant.

## The rule

| | |
|---|---|
| Law | Presidential Decree No. 851 (1975) and DOLE's implementing guidelines |
| Who | Rank-and-file employees, whatever their employment status or how they are paid, who worked **at least one month** in the calendar year. Managerial employees are outside the law - the page lists everyone, so leave them unticked. |
| Amount | **not less than 1/12 of the total basic salary earned in the calendar year** |
| Basic salary | What is paid for the services rendered. **Not** included: overtime, premium pay, night differential, holiday pay, cost-of-living and other allowances, cash conversion of unused leave. |
| Part of a year | Pro-rated automatically (a month not worked adds nothing to the total). An employee who **leaves** is owed the pro-rated amount on separation - the page keeps inactive employees on the sheet. |
| When | **Not later than December 24.** It may be paid in two instalments (for example half in June and the balance by December 24). |
| Tax | 13th-month pay **together with other benefits** (Christmas bonus, productivity incentives …) is exempt up to **₱90,000** a year (TRAIN Law, RA 10963 - NIRC Sec. 32(B)(7)(e)). Whatever is above is taxable compensation. |

## What the system takes as "basic pay earned"

For each employee and each pay period that **starts** in the year: `payroll.gross_pay − payroll.ot_late_adj` - the *Basic Pay* column on the payroll page, reports and payslips. It is

* the pay for the days worked, including approved (paid) leave and the hours of a short day actually worked,
* with **absences and undertime already deducted**, and with a salaried employee's working days *before the hire date* deducted too,
* **without** overtime pay, bonuses and allowances (overtime is the `ot_late_adj` part of gross pay; bonuses are a separate column).

The year of a pay period is the year of its **start date** (a Dec 26 – Jan 10 period belongs to the old year), and the month column is the month it starts in - the same way contributions are grouped.

## The computation

```
13th month   =  Σ basic pay of the year  ÷  12      (half a centavo rounds UP, worked in whole centavos)
paid so far  =  Σ Bonus entries of that year whose reason starts "13th Month Pay"
balance      =  13th month − paid so far
```

* "Paid so far" counts payments made on the 13th Month Pay page **and** older entries typed on *Bonus & Deductions* with the reason "13th Month Pay" - so an advance and the December balance add up, and the same money cannot be paid twice (a payment may not exceed the balance).
* The *set aside each month* row is that month's total basic pay ÷ 12 - the amount an accountant would accrue each month so December is not a surprise.

## How the payment is recorded

A **Bonus** on a chosen **open** pay period (one history row per employee, reason `13th Month Pay <year>`), through the same code as *Bonus & Deductions*: payslips show it under *Bonus / Allowance*, the employee portal lists it with its reason, finalize / unlock keeps the revision trail, and the **₱90,000 ceiling** is watched - a payment that would take an employee's bonuses for the year past it is held back until a person confirms ("Record anyway"). The system does **not** withhold tax on a bonus; the taxable excess is shown on the sheet so it can be handled separately (year-end annualization is outside the system).

13th-month pay is not added to the salary used for SSS, PhilHealth or Pag-IBIG: those are computed from the pay run's own earnings, and bonuses are recorded after them.

## Worked example

Kinsenas ₱15,000, hired 1 August 2026 (paid 5 months), no absences:

| | |
|---|---|
| Basic pay Aug–Dec | 5 months × ₱30,000 = ₱150,000.00 |
| 13th month | ₱150,000.00 ÷ 12 = **₱12,500.00** |
| If half is paid in November | advance ₱6,250.00, balance ₱6,250.00 shown on the page until paid |

And a full year at ₱15,000 a kinsena: 24 × ₱15,000 = ₱360,000 ÷ 12 = **₱30,000.00** - ₱2,500.00 to set aside each month.

## Not covered

Managerial / commission-only exclusions (untick those employees), 13th month on holiday pay or premium pay (the system has no holiday calendar), year-end annualization of the taxable excess, and the BIR forms.
