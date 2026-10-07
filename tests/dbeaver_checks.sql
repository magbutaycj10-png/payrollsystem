-- =====================================================================================================================
--  dbeaver_checks.sql - read-only payroll audit queries for the live MySQL 8 database (open in DBeaver)
--
--  HOW TO USE
--    1. In DBeaver open an SQL editor on your Aiven MySQL connection (database: defaultdb).
--    2. File > Open File... > this file.  Run the whole thing with Alt+X ("Execute script"), every SELECT opens its own
--       result tab.  Or put the cursor inside one query and press Ctrl+Enter to run just that one.
--    3. Every statement is a SELECT. Nothing here changes data.
--
--  HOW TO READ THE RESULTS      (the word after "expect:" in each heading)
--    expect: none    the query must return NO rows. Every row is a payslip / month / setting that is wrong.
--    expect: review  rows are findings for a person to look at (a refund, a negative payslip, an underpaid overtime rate ...).
--    expect: info    a report, no pass/fail.
--
--  These queries were run against a MySQL 8.0 test database holding correctly computed payroll (they returned nothing) and
--  holding deliberately damaged payroll (they found it): see tests/suites/12_dbeaver_checks.php.
--  Amounts are in pesos. "month" = the calendar month a pay period STARTS in, the way the application settles contributions.
--  A month is only checked once its LAST pay run is in the system (a month still in progress is left alone).
-- =====================================================================================================================

-- @check C00 | Which database am I looking at? | expect: info
SELECT VERSION() AS mysql_version, DATABASE() AS db, @@sql_mode AS sql_mode,
       (SELECT COUNT(*) FROM payroll) AS payroll_lines, (SELECT COUNT(*) FROM payroll_periods) AS pay_periods,
       (SELECT COUNT(*) FROM employees) AS employees;

-- =====================================================================================================================
--  1. EVERY PAYSLIP
-- =====================================================================================================================

-- @check C01 | Payslips that do not foot: net pay is not gross + bonus - tax - SSS - PhilHealth - Pag-IBIG - other deductions | expect: none
SELECT p.id AS payroll_id, pp.period_label, p.emp_id, p.emp_name, p.gross_pay, p.bonus, p.withholding_tax, p.sss, p.philhealth,
       p.pagibig, p.other_deductions, p.net_pay,
       ROUND(p.gross_pay + p.bonus - (p.withholding_tax + p.sss + p.philhealth + p.pagibig + p.other_deductions), 2) AS net_pay_should_be
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE p.net_pay <> ROUND(p.gross_pay + p.bonus - (p.withholding_tax + p.sss + p.philhealth + p.pagibig + p.other_deductions), 2)
 ORDER BY pp.period_start, p.emp_name;

-- @check C02 | Negative net pay: the statutory minimums (SSS 250, PhilHealth 250) or a deduction are larger than what was earned | expect: review
SELECT p.id AS payroll_id, pp.period_label, pp.status AS period_status, p.emp_id, p.emp_name, p.gross_pay, p.sss, p.philhealth, p.pagibig,
       p.withholding_tax, p.other_deductions, p.net_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE p.net_pay < 0
 ORDER BY p.net_pay, pp.period_start;

-- @check C03 | Impossible components: a negative gross, basic pay, SSS, PhilHealth, Pag-IBIG, bonus or deduction | expect: none
SELECT p.id AS payroll_id, pp.period_label, p.emp_id, p.emp_name, p.gross_pay, p.ot_late_adj, (p.gross_pay - p.ot_late_adj) AS basic_pay,
       p.sss, p.philhealth, p.pagibig, p.bonus, p.other_deductions
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE p.gross_pay < 0 OR (p.gross_pay - p.ot_late_adj) < 0 OR p.sss < 0 OR p.philhealth < 0 OR p.pagibig < 0
    OR p.bonus < 0 OR p.other_deductions < 0 OR p.absent_deduction < 0 OR p.undertime_deduction < 0
 ORDER BY pp.period_start, p.emp_name;

-- @check C04 | Negative withholding tax = a REFUND of tax taken earlier in the month (only the corrected application creates these) | expect: review
SELECT p.id AS payroll_id, pp.period_label, p.emp_id, p.emp_name, p.gross_pay, p.withholding_tax AS tax_refund, p.net_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE p.withholding_tax < 0
 ORDER BY pp.period_start, p.emp_name;

-- @check C05 | The attendance table disagrees with the payroll table (missing line, or different gross / tax) | expect: none
SELECT p.period_id, pp.period_label, p.emp_id, p.emp_name, p.gross_pay AS payroll_gross, a.gross_pay AS attendance_gross,
       p.withholding_tax AS payroll_tax, a.withholding_tax AS attendance_tax
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
  LEFT JOIN attendance a ON a.period_id = p.period_id AND a.emp_id = p.emp_id
 WHERE a.id IS NULL OR a.gross_pay <> p.gross_pay OR a.withholding_tax <> p.withholding_tax
 ORDER BY pp.period_start, p.emp_name;

-- @check C06 | Locked periods whose payroll lines are not Finalized, or Open periods with Finalized lines | expect: none
SELECT pp.id AS period_id, pp.period_label, pp.status AS period_status, p.status AS line_status, COUNT(*) AS lines_
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE (pp.status = 'Locked' AND p.status <> 'Finalized') OR (pp.status = 'Open' AND p.status = 'Finalized')
 GROUP BY pp.id, pp.period_label, pp.status, p.status
 ORDER BY pp.period_start;

-- =====================================================================================================================
--  2. THE MONTH, EMPLOYEE BY EMPLOYEE: contributions and tax must come to exactly what the month's pay requires
-- =====================================================================================================================

-- @check C10 | SSS: the month's deduction is not 5% of the salary credit of the month's pay (credit = pay to the nearest 500, between 5,000 and 35,000) | expect: none
WITH m AS (
  SELECT p.emp_id, MAX(p.emp_name) AS emp_name, DATE_FORMAT(pp.period_start, '%Y-%m') AS ym,
         SUM(p.gross_pay) AS gross, SUM(p.sss) AS sss, MAX(pp.period_end) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, DATE_FORMAT(pp.period_start, '%Y-%m')
)
SELECT m.ym AS month, m.emp_id, m.emp_name, m.gross AS month_pay, e.deduct_sss AS sss_switch_on, m.sss AS deducted,
       IF(e.deduct_sss = 1, ROUND(0.05 * LEAST(35000, GREATEST(5000, ROUND(m.gross / 500, 0) * 500)), 2), 0) AS should_be
  FROM m JOIN employees e ON e.emp_id = m.emp_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.sss <> IF(e.deduct_sss = 1, ROUND(0.05 * LEAST(35000, GREATEST(5000, ROUND(m.gross / 500, 0) * 500)), 2), 0)
 ORDER BY m.ym, m.emp_name;

-- @check C11 | PhilHealth: the month's deduction is not 2.5% of basic pay (between 10,000 and 100,000, a salaried employee at least the contract salary) | expect: none
WITH m AS (
  SELECT p.emp_id, MAX(p.emp_name) AS emp_name, DATE_FORMAT(pp.period_start, '%Y-%m') AS ym,
         SUM(p.gross_pay - p.ot_late_adj) AS basic, SUM(p.philhealth) AS ph, MAX(pp.period_end) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, DATE_FORMAT(pp.period_start, '%Y-%m')
)
SELECT m.ym AS month, m.emp_id, m.emp_name, m.basic AS month_basic_pay, e.salary_type, e.base_salary, e.deduct_philhealth AS switch_on, m.ph AS deducted,
       IF(e.deduct_philhealth = 1, ROUND(0.025 * LEAST(100000, GREATEST(10000,
          IF(e.salary_type = 'daily', m.basic, GREATEST(m.basic, IF(e.salary_type = 'kinsenas', 2 * e.base_salary, e.base_salary))))), 2), 0) AS should_be
  FROM m JOIN employees e ON e.emp_id = m.emp_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.ph <> IF(e.deduct_philhealth = 1, ROUND(0.025 * LEAST(100000, GREATEST(10000,
          IF(e.salary_type = 'daily', m.basic, GREATEST(m.basic, IF(e.salary_type = 'kinsenas', 2 * e.base_salary, e.base_salary))))), 2), 0)
 ORDER BY m.ym, m.emp_name;

-- @check C12 | Pag-IBIG: the month's deduction is not 1% of basic pay up to 1,500 / 2% above, on at most 10,000 | expect: none
WITH m AS (
  SELECT p.emp_id, MAX(p.emp_name) AS emp_name, DATE_FORMAT(pp.period_start, '%Y-%m') AS ym,
         SUM(p.gross_pay - p.ot_late_adj) AS basic, SUM(p.pagibig) AS pi, MAX(pp.period_end) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, DATE_FORMAT(pp.period_start, '%Y-%m')
)
SELECT m.ym AS month, m.emp_id, m.emp_name, m.basic AS month_basic_pay, e.deduct_pagibig AS switch_on, m.pi AS deducted,
       IF(e.deduct_pagibig = 1, ROUND(LEAST(m.basic, 10000) * IF(m.basic <= 1500, 0.01, 0.02), 2), 0) AS should_be
  FROM m JOIN employees e ON e.emp_id = m.emp_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.pi <> IF(e.deduct_pagibig = 1, ROUND(LEAST(m.basic, 10000) * IF(m.basic <= 1500, 0.01, 0.02), 2), 0)
 ORDER BY m.ym, m.emp_name;

-- @check C13 | Withholding tax: the month's tax is not the BIR monthly table (RR 11-2018 Annex E) on the month's taxable pay - a positive "over_withheld" is tax that should have been returned | expect: none
-- (Months in which some run has pay below its own contributions are left out: the application settles those run by run.)
WITH m AS (
  SELECT p.emp_id, MAX(p.emp_name) AS emp_name, DATE_FORMAT(pp.period_start, '%Y-%m') AS ym,
         SUM(p.gross_pay - p.sss - p.philhealth - p.pagibig) AS taxable,
         MIN(p.gross_pay - p.sss - p.philhealth - p.pagibig) AS worst_run,
         SUM(p.withholding_tax) AS tax, MAX(pp.period_end) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, DATE_FORMAT(pp.period_start, '%Y-%m')
), t AS (
  SELECT m.*, ROUND(CASE
            WHEN m.taxable <= 20833  THEN 0
            WHEN m.taxable <= 33333  THEN (m.taxable - 20833) * 0.15
            WHEN m.taxable <= 66667  THEN 1875.00 + (m.taxable - 33333) * 0.20
            WHEN m.taxable <= 166667 THEN 8541.80 + (m.taxable - 66667) * 0.25
            WHEN m.taxable <= 666667 THEN 33541.80 + (m.taxable - 166667) * 0.30
            ELSE 183541.80 + (m.taxable - 666667) * 0.35 END, 2) AS should_be
    FROM m
)
SELECT t.ym AS month, t.emp_id, t.emp_name, t.taxable AS month_taxable_pay, t.tax AS withheld, t.should_be, ROUND(t.tax - t.should_be, 2) AS over_withheld
  FROM t
 WHERE t.worst_run >= 0
   AND (DATE_FORMAT(DATE_ADD(t.last_end, INTERVAL 1 DAY), '%Y-%m') <> t.ym
        OR (t.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(t.last_end, INTERVAL 7 DAY), '%Y-%m') <> t.ym))
   AND t.tax <> t.should_be
 ORDER BY t.ym, t.emp_name;

-- =====================================================================================================================
--  3. WHAT WENT IN: attendance, employees, settings
-- =====================================================================================================================

-- @check C20 | Day records with impossible hours (over 24 h in a day, overtime over 16 h, hours + overtime over 24, negative figures) | expect: none
SELECT b.id, pp.period_label, b.emp_id, b.emp_name, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours, b.undertime_hours
  FROM biometric_daily b JOIN payroll_periods pp ON pp.id = b.period_id
 WHERE b.day_off = 0
   AND (b.hours_worked > 24 OR b.overtime_hours > 16 OR b.hours_worked + b.overtime_hours > 24 OR b.late_hours > 24 OR b.undertime_hours > 24
        OR b.hours_worked < 0 OR b.overtime_hours < 0 OR b.late_hours < 0 OR b.undertime_hours < 0)
 ORDER BY b.att_date, b.emp_name;

-- @check C21 | A payroll line with more hours than its pay period can hold (24 h x calendar days), or with negative hours | expect: none
SELECT p.id AS payroll_id, pp.period_label, p.emp_id, p.emp_name, p.hours_worked, p.overtime_hours, p.late_hours,
       24 * (DATEDIFF(pp.period_end, pp.period_start) + 1) AS most_the_period_can_hold
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE p.hours_worked > 24 * (DATEDIFF(pp.period_end, pp.period_start) + 1)
    OR p.overtime_hours > 16 * (DATEDIFF(pp.period_end, pp.period_start) + 1)
    OR p.hours_worked < 0 OR p.overtime_hours < 0 OR p.late_hours < 0
 ORDER BY pp.period_start, p.emp_name;

-- @check C22 | The same day saved in two pay periods (that day is paid twice) | expect: none
SELECT b.emp_id, MAX(b.emp_name) AS emp_name, b.att_date, COUNT(*) AS saved_times, GROUP_CONCAT(pp.period_label ORDER BY pp.period_start SEPARATOR ' | ') AS in_periods
  FROM biometric_daily b JOIN payroll_periods pp ON pp.id = b.period_id
 GROUP BY b.emp_id, b.att_date
HAVING COUNT(*) > 1
 ORDER BY b.att_date, emp_name;

-- @check C23 | Pay periods that overlap in dates (days can be counted in both) | expect: review
SELECT a.id AS period_a, a.period_label AS label_a, a.period_start AS start_a, a.period_end AS end_a,
       b.id AS period_b, b.period_label AS label_b, b.period_start AS start_b, b.period_end AS end_b
  FROM payroll_periods a JOIN payroll_periods b ON a.id < b.id AND a.period_start <= b.period_end AND b.period_start <= a.period_end
 ORDER BY a.period_start;

-- @check C30 | Employee set-up that cannot be right: negative or absurd pay, or a duty day outside 1-24 hours | expect: none
SELECT e.emp_id, e.full_name, e.salary_type, e.base_salary, e.hours_per_day, e.date_hired, e.status
  FROM employees e
 WHERE e.base_salary < 0 OR e.base_salary > 10000000 OR (e.salary_type = 'daily' AND e.base_salary > 100000)
    OR e.hours_per_day < 1 OR e.hours_per_day > 24
 ORDER BY e.emp_id;

-- @check C31 | Settings that are not usable values (rates, duty day, schedule, contribution timing, overtime method / multiplier) | expect: none
SELECT setting_key, setting_value, 'must be a number from 0 to 100000' AS problem FROM settings
 WHERE setting_key IN ('overtime_rate', 'late_rate')
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) > 100000)
UNION ALL
SELECT setting_key, setting_value, 'must be a number from 1 to 24' FROM settings
 WHERE setting_key = 'standard_hours'
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) NOT BETWEEN 1 AND 24)
UNION ALL
SELECT setting_key, setting_value, 'must be Monthly, Semi-Monthly or Weekly' FROM settings
 WHERE setting_key = 'payroll_period' AND setting_value NOT IN ('Monthly', 'Semi-Monthly', 'Weekly')
UNION ALL
SELECT setting_key, setting_value, 'must be split or second' FROM settings
 WHERE setting_key LIKE 'contribution_timing_%' AND setting_value NOT IN ('split', 'second')
UNION ALL
SELECT setting_key, setting_value, 'must be flat or labor_code' FROM settings
 WHERE setting_key = 'overtime_method' AND setting_value NOT IN ('flat', 'labor_code')
UNION ALL
SELECT setting_key, setting_value, 'must be a number from 1 to 3' FROM settings
 WHERE setting_key = 'overtime_multiplier'
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) NOT BETWEEN 1 AND 3);

-- =====================================================================================================================
--  4. BONUSES AND DEDUCTIONS
-- =====================================================================================================================

-- @check C40 | Bonus / deduction history does not add up to the payroll line (entries edited by hand, or never linked to a period) | expect: review
SELECT p.period_id, pp.period_label, p.emp_id, p.emp_name,
       p.bonus AS payroll_bonus, COALESCE(b.amt, 0) AS history_bonus,
       p.other_deductions AS payroll_deductions, COALESCE(d.amt, 0) AS history_deductions
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
  LEFT JOIN (SELECT period_id, emp_id, SUM(amount) AS amt FROM bonus_deduction_history WHERE entry_type = 'Bonus'     GROUP BY period_id, emp_id) b
         ON b.period_id = p.period_id AND b.emp_id = p.emp_id
  LEFT JOIN (SELECT period_id, emp_id, SUM(amount) AS amt FROM bonus_deduction_history WHERE entry_type = 'Deduction' GROUP BY period_id, emp_id) d
         ON d.period_id = p.period_id AND d.emp_id = p.emp_id
 WHERE p.bonus <> COALESCE(b.amt, 0) OR p.other_deductions <> COALESCE(d.amt, 0)
 ORDER BY pp.period_start, p.emp_name;

-- @check C41 | History entries with a zero or negative amount | expect: none
SELECT h.id, h.entry_date, h.emp_id, h.emp_name, h.entry_type, h.amount, h.reason, h.period_id
  FROM bonus_deduction_history h
 WHERE h.amount <= 0
 ORDER BY h.entry_date;

-- @check C42 | Bonuses in one calendar year above PHP 90,000 per employee: the excess is taxable compensation and the system does not withhold on a bonus | expect: review
SELECT YEAR(pp.period_start) AS year, p.emp_id, MAX(p.emp_name) AS emp_name, SUM(p.bonus) AS bonuses_for_the_year,
       SUM(p.bonus) - 90000 AS taxable_excess
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 GROUP BY YEAR(pp.period_start), p.emp_id
HAVING SUM(p.bonus) > 90000
 ORDER BY year, emp_name;

-- =====================================================================================================================
--  5. COMPLIANCE: things the law asks for that arithmetic alone cannot show
-- =====================================================================================================================

-- @check C50 | Overtime pays less than the Labor Code minimum (hourly rate + 25%, Art. 87) for these active employees under the flat peso rate (a month is taken as 26 working days) | expect: review
SELECT e.emp_id, e.full_name, e.salary_type, e.base_salary, COALESCE(e.hours_per_day, 8) AS duty_hours,
       ROUND(CASE e.salary_type WHEN 'daily' THEN e.base_salary WHEN 'kinsenas' THEN e.base_salary * 2 / 26 ELSE e.base_salary / 26 END
             / COALESCE(e.hours_per_day, (SELECT CAST(s.setting_value AS DECIMAL(6,2)) FROM settings s WHERE s.setting_key = 'standard_hours'), 8) * 1.25, 2) AS legal_minimum_per_overtime_hour,
       COALESCE((SELECT CAST(s.setting_value AS DECIMAL(12,2)) FROM settings s WHERE s.setting_key = 'overtime_rate'), 150) AS flat_rate_paid_now
  FROM employees e
 WHERE e.status = 'Active' AND e.base_salary > 0
   AND COALESCE((SELECT s.setting_value FROM settings s WHERE s.setting_key = 'overtime_method'), 'flat') <> 'labor_code'
   AND ROUND(CASE e.salary_type WHEN 'daily' THEN e.base_salary WHEN 'kinsenas' THEN e.base_salary * 2 / 26 ELSE e.base_salary / 26 END
             / COALESCE(e.hours_per_day, (SELECT CAST(s.setting_value AS DECIMAL(6,2)) FROM settings s WHERE s.setting_key = 'standard_hours'), 8) * 1.25, 2)
       > COALESCE((SELECT CAST(s.setting_value AS DECIMAL(12,2)) FROM settings s WHERE s.setting_key = 'overtime_rate'), 150) + 0.004
 ORDER BY e.full_name;

-- @check C51 | Salaried employees paid with no unpaid day although they were hired AFTER the period began (days before the hire date are not worked, so not payable) | expect: review
SELECT p.id AS payroll_id, pp.period_label, pp.period_start, pp.period_end, p.emp_id, p.emp_name, e.salary_type, e.date_hired,
       p.gross_pay, p.absent_days, p.absent_deduction
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.emp_id = p.emp_id
 WHERE e.date_hired IS NOT NULL AND e.salary_type <> 'daily' AND e.date_hired > DATE_ADD(pp.period_start, INTERVAL 1 DAY)
   AND e.date_hired <= pp.period_end AND p.absent_days = 0
 ORDER BY pp.period_start, p.emp_name;

-- @check C52 | Pay for a period that ended before the employee's hire date | expect: none
SELECT p.id AS payroll_id, pp.period_label, p.emp_id, p.emp_name, e.date_hired, pp.period_end, p.gross_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.emp_id = p.emp_id
 WHERE e.date_hired IS NOT NULL AND e.date_hired > pp.period_end AND p.gross_pay > 0
 ORDER BY pp.period_start, p.emp_name;

-- =====================================================================================================================
--  5b. 13TH MONTH PAY (PD 851): one twelfth of the basic pay earned in the calendar year
-- =====================================================================================================================

-- @check C60 | More 13th month pay recorded for a year than one twelfth of that year's basic pay (basic pay = gross pay less the overtime / tardiness adjustment) | expect: none
WITH basic AS (
  SELECT p.emp_id, YEAR(pp.period_start) AS yr, SUM(p.gross_pay - p.ot_late_adj) AS basic_pay
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, YEAR(pp.period_start)
), paid AS (
  SELECT h.emp_id, COALESCE(YEAR(pp.period_start), YEAR(h.entry_date)) AS yr, SUM(h.amount) AS paid_13th
    FROM bonus_deduction_history h LEFT JOIN payroll_periods pp ON pp.id = h.period_id
   WHERE h.entry_type = 'Bonus' AND h.reason LIKE '13th Month Pay%'
   GROUP BY h.emp_id, COALESCE(YEAR(pp.period_start), YEAR(h.entry_date))
)
SELECT paid.yr AS year, paid.emp_id, (SELECT MAX(e.full_name) FROM employees e WHERE e.emp_id = paid.emp_id) AS emp_name,
       COALESCE(b.basic_pay, 0) AS basic_pay_that_year, ROUND(COALESCE(b.basic_pay, 0) / 12, 2) AS thirteenth_month_due,
       paid.paid_13th AS recorded, ROUND(paid.paid_13th - ROUND(COALESCE(b.basic_pay, 0) / 12, 2), 2) AS recorded_over_due
  FROM paid LEFT JOIN basic b ON b.emp_id = paid.emp_id AND b.yr = paid.yr
 WHERE paid.paid_13th > ROUND(COALESCE(b.basic_pay, 0) / 12, 2) + 0.004
 ORDER BY paid.yr, paid.emp_id;

-- @check R03 | 13th month pay by year and employee: months with pay, basic pay earned, one twelfth (due), recorded so far, balance | expect: info
WITH basic AS (
  SELECT p.emp_id, YEAR(pp.period_start) AS yr, COUNT(DISTINCT MONTH(pp.period_start)) AS months_with_pay, SUM(p.gross_pay - p.ot_late_adj) AS basic_pay
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.emp_id, YEAR(pp.period_start)
), paid AS (
  SELECT h.emp_id, COALESCE(YEAR(pp.period_start), YEAR(h.entry_date)) AS yr, SUM(h.amount) AS paid_13th
    FROM bonus_deduction_history h LEFT JOIN payroll_periods pp ON pp.id = h.period_id
   WHERE h.entry_type = 'Bonus' AND h.reason LIKE '13th Month Pay%'
   GROUP BY h.emp_id, COALESCE(YEAR(pp.period_start), YEAR(h.entry_date))
)
SELECT b.yr AS year, b.emp_id, (SELECT MAX(e.full_name) FROM employees e WHERE e.emp_id = b.emp_id) AS emp_name, b.months_with_pay,
       b.basic_pay, ROUND(b.basic_pay / 12, 2) AS thirteenth_month_due, COALESCE(x.paid_13th, 0) AS recorded,
       ROUND(b.basic_pay / 12, 2) - COALESCE(x.paid_13th, 0) AS balance
  FROM basic b LEFT JOIN paid x ON x.emp_id = b.emp_id AND x.yr = b.yr
 ORDER BY b.yr DESC, emp_name;

-- =====================================================================================================================
--  6. REPORTS (no pass / fail)
-- =====================================================================================================================

-- @check R01 | Month by month: people paid, gross, bonus, deductions, contributions, tax, net - for the accountant's tie-out | expect: info
SELECT DATE_FORMAT(pp.period_start, '%Y-%m') AS month, COUNT(DISTINCT p.emp_id) AS people_paid, SUM(p.gross_pay) AS gross_pay, SUM(p.bonus) AS bonus,
       SUM(p.sss) AS sss_employee, SUM(p.philhealth) AS philhealth_employee, SUM(p.pagibig) AS pagibig_employee,
       SUM(p.withholding_tax) AS withholding_tax, SUM(p.other_deductions) AS other_deductions, SUM(p.net_pay) AS net_pay,
       ROUND(SUM(p.gross_pay + p.bonus) - SUM(p.sss + p.philhealth + p.pagibig + p.withholding_tax + p.other_deductions) - SUM(p.net_pay), 2) AS unexplained_difference
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 GROUP BY DATE_FORMAT(pp.period_start, '%Y-%m')
 ORDER BY month;

-- @check R02 | Pay period by pay period: status, lines, totals | expect: info
SELECT pp.id AS period_id, pp.period_label, pp.period_type, pp.status, pp.finalize_count, pp.reopen_count, COUNT(p.id) AS lines_,
       SUM(p.gross_pay) AS gross_pay, SUM(p.bonus) AS bonus, SUM(p.withholding_tax) AS tax, SUM(p.net_pay) AS net_pay
  FROM payroll_periods pp LEFT JOIN payroll p ON p.period_id = pp.id
 GROUP BY pp.id, pp.period_label, pp.period_type, pp.status, pp.finalize_count, pp.reopen_count, pp.period_start
 ORDER BY pp.period_start, pp.id;
