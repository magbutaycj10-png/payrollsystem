# Philippine Payroll Deductions - Rules Used by the System

Checked against official and published sources in **October 2026**. All four agencies
kept their 2025 rates for 2026. The live numbers are in `PH_RULES` in
`payroll2/includes/helpers.php`; Settings shows them read-only.

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
     day's rate per unexcused absent day (days off and approved leave are never deducted) and
     per working day **before the employee's hire date**.
2. **Gross pay** = basic + overtime pay − late deductions.
   This is the timesheet's GROSS PAY; it is what `payroll.gross_pay` stores. Overtime is paid by the
   method chosen in *Settings → Overtime Method*: **flat** - OT hours × the peso rate in Settings
   (the pharmacy's own sheets) - or **Labor Code** - OT hours × the employee's own hourly rate ×
   the multiplier (1.25 on an ordinary day, Art. 87; the hourly rate is a day's pay ÷ duty hours).
3. **Contributions are monthly**, read on the pay earned *so far this calendar month* and
   minus what earlier cut-offs already deducted - so floors and caps apply once a month and
   each month ends exact. *Settings → Contribution Schedule* picks, per contribution, whether
   every cut-off takes what is due so far (**split**) or the month's last cut-off takes it all
   (**second**). Default: SSS split (from the 1st cut-off), PhilHealth and Pag-IBIG on the
   2nd - the way the pharmacy's timesheets take them.
4. **Withholding tax**: each cut-off on its own Annex E table; the month's last cut-off
   settles the month on the monthly table, minus what earlier cut-offs withheld. If they withheld
   more than the month owes, the difference comes back as a **negative tax - a refund** - so every
   month ends exact. Worked in whole centavos (a half centavo rounds up).
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

**Government deductions in the samples do not follow the current rules:**

| Sample | Rule |
|---|---|
| SSS taken for only 3 of 11 employees, at **4.5%** (2024 rate) of a fixed credit - e.g. HASMIN ₱540 = 4.5% × ₱12,000 | 5% of the credit for the month's actual pay - HASMIN Feb: ₱17,450 → credit ₱17,500 → ₱875 |
| PhilHealth only for HASMIN, ₱250 (the ₱10,000 floor) | 2.5% of her February basic pay ₱17,360 → ₱434 |
| No Pag-IBIG for anyone | 2% of basic pay up to ₱10,000 → ₱200 for most |
| No withholding tax | correct - everyone's taxable pay is under ₱20,833 a month |

Every monthly total the system produced (19 employee-months) was checked against an
independent implementation of the rules above: all match exactly.

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
