# Philippine Payroll Deductions - Rules Used by the System

Checked against official and published sources in **October 2026**. All four agencies
kept their 2025 rates for 2026. The live numbers are in `PH_RULES` in
`payroll2/includes/helpers.php`; Settings shows them read-only.

> **Reference only since 2026-10-10.** The system no longer works SSS, PhilHealth, Pag-IBIG or
> withholding tax out from pay. The admin **types each employee's monthly amount** when adding
> or editing the employee (blank = none, which is fine), and payroll deducts exactly that - see
> "How the system computes one pay run" below. The tables in sections 1-4 are what the law
> prescribes: the guide for working out those amounts, and the rates the **company's share** is
> figured from.

---

## 1. SSS - Social Security System

| | |
|---|---|
| Rate | **15%** of the Monthly Salary Credit (MSC): employee **5%**, employer **10%** |
| MSC | ₱5,000 (pay below ₱5,250) up to ₱35,000 (pay ₱34,750 and up), in ₱500 steps |
| EC (employer only) | ₱10 a month below a ₱15,000 credit, ₱30 from ₱15,000 |
| Read on | **all compensation earned in the month** - basic, overtime, everything |
| Employee pays | ₱250 to ₱1,750 a month |
| Source | SSS Circular No. 2024-006 (RA 11199), in effect since January 2025 |

## 2. PhilHealth

| | |
|---|---|
| Rate | **5%** of monthly basic salary, split equally: employee **2.5%**, employer 2.5% |
| Floor / ceiling | ₱10,000 / ₱100,000 → employee pays ₱250 to ₱2,500 a month |
| Read on | **basic salary only** - no overtime, allowances, 13th month or bonuses |
| Source | Universal Health Care Act (RA 11223); 5% in effect since January 2024, unchanged for 2026 |

## 3. Pag-IBIG (HDMF)

| | |
|---|---|
| Rate | employee **2%** (1% if monthly pay is ₱1,500 or less), employer 2% |
| Maximum fund salary | ₱10,000 → at most ₱200 employee + ₱200 employer a month |
| Read on | monthly basic pay |
| Source | HDMF Circular No. 460 (RA 9679), in effect since February 2024 |

## 4. BIR withholding tax on compensation

Taxable compensation = gross pay − the employee's own SSS, PhilHealth and Pag-IBIG
(those are non-taxable). Tables from **BIR RR 11-2018, Annex E - effective January 1, 2023
and onwards** (TRAIN Law, RA 10963):

| Period | 0% up to | 15% over | 20% bracket | 25% bracket | 30% bracket | 35% bracket |
|---|---|---|---|---|---|---|
| Daily | ₱685 | ₱685 | ₱61.65 + 20% over ₱1,096 | ₱280.85 + 25% over ₱2,192 | ₱1,102.60 + 30% over ₱5,479 | ₱6,034.30 + 35% over ₱21,918 |
| Weekly | ₱4,808 | ₱4,808 | ₱432.60 + 20% over ₱7,692 | ₱1,971.20 + 25% over ₱15,385 | ₱7,740.45 + 30% over ₱38,462 | ₱42,355.65 + 35% over ₱153,846 |
| Semi-monthly | ₱10,417 | ₱10,417 | ₱937.50 + 20% over ₱16,667 | ₱4,270.70 + 25% over ₱33,333 | ₱16,770.70 + 30% over ₱83,333 | ₱91,770.70 + 35% over ₱333,333 |
| Monthly | ₱20,833 | ₱20,833 | ₱1,875 + 20% over ₱33,333 | ₱8,541.80 + 25% over ₱66,667 | ₱33,541.80 + 30% over ₱166,667 | ₱183,541.80 + 35% over ₱666,667 |

(The printed Annex E shows the daily top amount as "6,034.00.30"; it is ₱6,034.30.)

---

## How the system computes one pay run

1. **Basic pay**
   - *Daily-rate*: daily rate × days paid. Each duty day is one full day of the employee's
     **duty hours** (Settings standard, 8, or the employee's own, e.g. 10) minus its undertime,
     so undertime costs *rate ÷ duty hours* per hour. Approved leave is paid as a full day.
   - *Monthly / kinsenas*: the month's salary × the period's share of a month, minus one
     day's rate for every working day of the period **that the uploaded file does not account
     for**. The days to compute are the days IN THE FILE: a working day is paid when the file shows
     hours for it, marks it OFF, or it is approved leave (days off are never deducted); every other
     working day is unpaid - before the employee's first day in the file, in a gap, **and after the
     last day uploaded**, uploaded yet or not. A day's rate is the month's salary ÷ its working
     days. So an employee added today (whatever the Date Hired says - it is only a record) can have
     this month's file or any earlier month's uploaded and is paid for the days it holds: 3 days in
     the file, 3 days paid. A part-uploaded period is paid for the days so far and grows as the
     next days are uploaded. (A daily-rate employee is simply paid for the days the file shows.)
2. **Gross pay** = basic + overtime pay − late deductions.
   This is the timesheet's GROSS PAY; it is what `payroll.gross_pay` stores. Overtime is paid by the
   method chosen in *Settings → Overtime Method*: **flat** - OT hours × the peso rate in Settings
   (the pharmacy's own sheets) - or **Labor Code** - OT hours × the employee's own hourly rate ×
   the multiplier (1.25 on an ordinary day, Art. 87; the hourly rate is a day's pay ÷ duty hours).
3. **SSS, PhilHealth, Pag-IBIG and withholding tax are the employee's own monthly amounts**, typed
   by the admin in *Employees → Add / Edit* (all optional; blank or 0 = the employee has none).
   Nothing is worked out from the pay - a month's pay does not change them. A monthly payroll
   takes them in full. With several cut-offs a month, *Settings → Contribution Schedule* picks,
   for each of the four, which cut-off takes it:
   - **1st cut-off, in full** (**first**) - the month's first run;
   - **every cut-off, in equal shares** (**split**) - each run takes its share of the month (half on a
     semi-monthly payroll, 12/52 on a weekly one), the last run takes what is left;
   - **last cut-off, in full** (**second**).

   Whatever is picked, the month's **last run settles whatever is still owed**, so a month always comes to
   exactly the amount typed, to the centavo (a half centavo of a share rounds up). If an amount is edited
   mid-month the last run takes the difference: a contribution is never refunded (it stops at 0), tax is
   (a **negative tax - a refund**). Defaults, as in the pharmacy's timesheets: SSS in full on the 1st
   cut-off, PhilHealth and Pag-IBIG in full on the 2nd, tax in equal shares.
4. **The company's share** is figured on what was deducted (not on pay): SSS **twice** the employee's
   share (10% against 5%) plus the Employees' Compensation of ₱10, or ₱30 from a ₱750 SSS share (a ₱15,000
   credit); PhilHealth equal to the employee's; Pag-IBIG 2% (matching the employee's, or twice it
   when monthly pay is ₱1,500 or less). This is the "Company Cost & Remittances" on Payroll Processing
   and the employer part of the forecast's labor cost.
5. **Net pay** = gross + bonus − (tax + SSS + PhilHealth + Pag-IBIG + other deductions).
6. **13th month pay** is not part of a pay run: it is worked out once a year from the basic pay
   earned and recorded as a bonus - see [`ph_13th_month_pay.md`](ph_13th_month_pay.md).

**Limits on what is accepted:** a day holds at most 24 hours, overtime at most 16 hours, nothing is
negative; rates and salaries are bounded; unreadable times (such as `08:60`) are refused, never guessed.

---

## Check against the pharmacy's sample timesheets (E:\payroll\samp)

The app's own upload code read all four sample workbooks (Feb 1–15, Feb 16–28,
Mar 1–15, Mar 16–31 2026; 43 employee cut-offs), and the pay code computed them.

**Gross pay: 42 of 43 match the samples to the centavo.** The one difference is a sample error:

- ROLLY IRINCO JR., Mar 16–31: the sheet pays overtime at ₱62.50/h although its own
  OT_PAY_PER_HOUR column (and every other sheet) says ₱45 → sample ₱5,375, system ₱5,305.

HASMIN DIEGA works a **10-hour duty day** (≈10.2 h a day on every sheet); the sample values
her undertime at ₱62/h = ₱620 ÷ 10. With her duty hours set to 10 the system matches (₱8,308).

**Government deductions in the samples are the pharmacy's own figures, not the statutory tables:**

| Sample | The law |
|---|---|
| SSS taken for only 3 of 11 employees, at **4.5%** (2024 rate) of a fixed credit - e.g. HASMIN ₱540 = 4.5% × ₱12,000 - **in full on the 1st cut-off** | 5% of the credit for the month's actual pay - HASMIN Feb: ₱17,450 → credit ₱17,500 → ₱875 |
| PhilHealth only for HASMIN, ₱250 (the ₱10,000 floor) - **in full on the 2nd cut-off** | 2.5% of her February basic pay ₱17,360 → ₱434 |
| No Pag-IBIG for anyone | 2% of basic pay up to ₱10,000 → ₱200 for most |
| No withholding tax | correct - everyone's taxable pay is under ₱20,833 a month |

That is why the amounts are typed per employee: enter HASMIN's SSS ₱540 and PhilHealth ₱250, JACQUE's
SSS ₱495, MICHELLE's SSS ₱405, leave everyone else blank, keep the default schedule (SSS 1st cut-off, PhilHealth
2nd) and the system takes exactly what the sheets take. The tables above remain the guide when the pharmacy
decides to follow the law's figures instead.

(Before 2026-10-10 the system worked these amounts out from pay, and every monthly total it produced
- 19 employee-months - was checked against an independent implementation of the rules above: all matched exactly.)

**Labor Code note (not a deduction):** the ₱45/h overtime rate is below the legal minimum
(Art. 87: the hourly rate plus 25% - e.g. ₱480/day → ₱60/h → ₱75/h OT). With the *flat* method the
system pays whatever Settings says (and Settings now warns who is underpaid); choose the
*Labor Code* method to pay each employee their own rate (₱480/day → ₱75.00/h, ₱620/day → ₱96.88/h).
Rest-day, holiday and night-shift premiums are higher still and are not computed.

---

## Sources

- SSS Circular No. 2024-006 - [Grant Thornton summary](https://www.grantthornton.com.ph/insights/articles-and-updates1/tax-notes/sss-implements-revised-contribution-rates-for-2025/)
- SSS 2026 table - [MoneyHub PH](https://moneyhubph.com/sss-pagibig/sss-contribution-table-2026)
- PhilHealth 2026 - [PhilPad](https://philpad.com/new-philhealth-contribution-table/), [Globe](https://www.globe.com.ph/blog/updated-philhealth-contribution)
- PhilHealth basic-salary definition - [PhilHealth Circular 2018-0001](https://www.philhealth.gov.ph/circulars/2018/circ2018-0001.pdf)
- Pag-IBIG 2026 - [PinoyCompute](https://pinoycompute.com/pag-ibig-contribution-table-employee-employer-share-guide/), [Hashmicro](https://www.hashmicro.com/ph/blog/hr-guide-pag-ibig-contribution/)
- BIR Annex E, RR 11-2018 - [bir.gov.ph PDF](https://bir-cdn.bir.gov.ph/local/pdf/Annex%20E%20RR%2011-2018.pdf)
