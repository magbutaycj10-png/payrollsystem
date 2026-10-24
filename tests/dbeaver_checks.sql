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
--  They read the standard layout of payroll2/sql/database.sql (2026-10-15): every table has its own id, a link to another table
--  is <thing>_id, an employee's number is employees.employee_code (EMP-004) and the name is employees.full_name.
--  (payroll2/sql/migrate-standard-schema.sql converts an older database.)
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
SELECT p.id AS payroll_id, pp.period_label, e.employee_code, e.full_name, p.gross_pay, p.bonus_pay, p.withholding_tax, p.sss_deduction,
       p.philhealth_deduction, p.pagibig_deduction, p.other_deductions, p.total_deductions, p.net_pay,
       ROUND(p.gross_pay + p.bonus_pay - (p.withholding_tax + p.sss_deduction + p.philhealth_deduction + p.pagibig_deduction + p.other_deductions), 2) AS net_pay_should_be
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE p.net_pay <> ROUND(p.gross_pay + p.bonus_pay - (p.withholding_tax + p.sss_deduction + p.philhealth_deduction + p.pagibig_deduction + p.other_deductions), 2)
 ORDER BY pp.start_date, e.full_name;

-- @check C02 | Negative net pay: the employee's monthly SSS / PhilHealth / Pag-IBIG / tax amounts or a deduction are larger than what was earned | expect: review
SELECT p.id AS payroll_id, pp.period_label, pp.status AS period_status, e.employee_code, e.full_name, p.gross_pay, p.sss_deduction, p.philhealth_deduction,
       p.pagibig_deduction, p.withholding_tax, p.other_deductions, p.net_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE p.net_pay < 0
 ORDER BY p.net_pay, pp.start_date;

-- @check C03 | Impossible components: a negative gross, basic pay, SSS, PhilHealth, Pag-IBIG, bonus, deduction or absence / undertime cost | expect: none
SELECT p.id AS payroll_id, pp.period_label, e.employee_code, e.full_name, p.gross_pay, p.basic_pay, p.overtime_pay,
       p.sss_deduction, p.philhealth_deduction, p.pagibig_deduction, p.bonus_pay, p.other_deductions
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE p.gross_pay < 0 OR p.basic_pay < 0 OR p.overtime_pay < 0 OR p.sss_deduction < 0 OR p.philhealth_deduction < 0 OR p.pagibig_deduction < 0
    OR p.bonus_pay < 0 OR p.other_deductions < 0 OR p.absence_deduction < 0 OR p.tardy_undertime_deduction < 0
 ORDER BY pp.start_date, e.full_name;

-- @check C04 | Negative withholding tax = a REFUND of tax taken earlier in the month (only the corrected application creates these) | expect: review
SELECT p.id AS payroll_id, pp.period_label, e.employee_code, e.full_name, p.gross_pay, p.withholding_tax AS tax_refund, p.net_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE p.withholding_tax < 0
 ORDER BY pp.start_date, e.full_name;

-- @check C05 | A payroll line without its attendance_summary row, or whose gross pay does not tie to its basic pay + overtime pay (less what lateness and undertime cost) | expect: none
SELECT p.period_id, pp.period_label, e.employee_code, e.full_name, p.basic_pay, p.overtime_pay, p.tardy_undertime_deduction, p.gross_pay,
       a.id AS attendance_summary_id
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
  LEFT JOIN attendance_summary a ON a.period_id = p.period_id AND a.employee_id = p.employee_id
 WHERE a.id IS NULL
    OR p.gross_pay > p.basic_pay + p.overtime_pay + 0.004
    OR p.gross_pay < p.basic_pay + p.overtime_pay - p.tardy_undertime_deduction - 0.004
 ORDER BY pp.start_date, e.full_name;

-- @check C06 | Locked periods whose payroll lines are not Finalized, or Open periods with Finalized lines | expect: none
SELECT pp.id AS period_id, pp.period_label, pp.status AS period_status, p.status AS line_status, COUNT(*) AS lines_
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 WHERE (pp.status = 'Locked' AND p.status <> 'Finalized') OR (pp.status = 'Open' AND p.status = 'Finalized')
 GROUP BY pp.id, pp.period_label, pp.status, p.status, pp.start_date
 ORDER BY pp.start_date;

-- =====================================================================================================================
--  2. THE MONTH, EMPLOYEE BY EMPLOYEE: each month must come to exactly the monthly amounts typed on the employee
--     (Employees > Edit: SSS, PhilHealth, Pag-IBIG, withholding tax). Nothing is worked out from pay, so there is no
--     table to check against - only whether the month took what the employee's record says. An amount edited AFTER a
--     month was paid legitimately shows that month here, which is why these are "review".
-- =====================================================================================================================

-- @check C10 | SSS: the month's deduction is not the employee's monthly SSS amount (an amount edited since that month shows here too) | expect: review
WITH m AS (
  SELECT p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m') AS ym,
         SUM(p.sss_deduction) AS deducted, MAX(pp.end_date) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m')
)
SELECT m.ym AS month, e.employee_code, e.full_name, e.sss_amount AS monthly_amount, m.deducted
  FROM m JOIN employees e ON e.id = m.employee_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.deducted <> e.sss_amount
 ORDER BY m.ym, e.full_name;

-- @check C11 | PhilHealth: the month's deduction is not the employee's monthly PhilHealth amount (an amount edited since that month shows here too) | expect: review
WITH m AS (
  SELECT p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m') AS ym,
         SUM(p.philhealth_deduction) AS deducted, MAX(pp.end_date) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m')
)
SELECT m.ym AS month, e.employee_code, e.full_name, e.philhealth_amount AS monthly_amount, m.deducted
  FROM m JOIN employees e ON e.id = m.employee_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.deducted <> e.philhealth_amount
 ORDER BY m.ym, e.full_name;

-- @check C12 | Pag-IBIG: the month's deduction is not the employee's monthly Pag-IBIG amount (an amount edited since that month shows here too) | expect: review
WITH m AS (
  SELECT p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m') AS ym,
         SUM(p.pagibig_deduction) AS deducted, MAX(pp.end_date) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m')
)
SELECT m.ym AS month, e.employee_code, e.full_name, e.pagibig_amount AS monthly_amount, m.deducted
  FROM m JOIN employees e ON e.id = m.employee_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.deducted <> e.pagibig_amount
 ORDER BY m.ym, e.full_name;

-- @check C13 | Withholding tax: the month's tax is not the employee's monthly tax amount (an amount edited since that month shows here too; a negative total is a refund) | expect: review
WITH m AS (
  SELECT p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m') AS ym,
         SUM(p.withholding_tax) AS deducted, MAX(pp.end_date) AS last_end, MAX(pp.period_type) AS ptype
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, DATE_FORMAT(pp.start_date, '%Y-%m')
)
SELECT m.ym AS month, e.employee_code, e.full_name, e.tax_amount AS monthly_amount, m.deducted
  FROM m JOIN employees e ON e.id = m.employee_id
 WHERE (DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 1 DAY), '%Y-%m') <> m.ym
        OR (m.ptype = 'Weekly' AND DATE_FORMAT(DATE_ADD(m.last_end, INTERVAL 7 DAY), '%Y-%m') <> m.ym))
   AND m.deducted <> e.tax_amount
 ORDER BY m.ym, e.full_name;

-- =====================================================================================================================
--  3. WHAT WENT IN: attendance, employees, settings
-- =====================================================================================================================

-- @check C20 | Day records with impossible hours (over 24 h in a day, overtime over 16 h, hours + overtime over 24, negative figures) | expect: none
SELECT b.id, e.employee_code, e.full_name, b.att_date, b.hours_worked, b.overtime_hours, b.late_hours, b.undertime_hours
  FROM biometric_daily b JOIN employees e ON e.id = b.employee_id
 WHERE b.is_day_off = 0
   AND (b.hours_worked > 24 OR b.overtime_hours > 16 OR b.hours_worked + b.overtime_hours > 24 OR b.late_hours > 24 OR b.undertime_hours > 24
        OR b.hours_worked < 0 OR b.overtime_hours < 0 OR b.late_hours < 0 OR b.undertime_hours < 0)
 ORDER BY b.att_date, e.full_name;

-- @check C21 | An attendance summary with more hours than its pay period can hold (24 h x calendar days), or with negative hours | expect: none
SELECT a.id AS attendance_summary_id, pp.period_label, e.employee_code, e.full_name, a.total_hours_worked, a.total_overtime_hours, a.total_late_hours,
       24 * (DATEDIFF(pp.end_date, pp.start_date) + 1) AS most_the_period_can_hold
  FROM attendance_summary a JOIN payroll_periods pp ON pp.id = a.period_id JOIN employees e ON e.id = a.employee_id
 WHERE a.total_hours_worked > 24 * (DATEDIFF(pp.end_date, pp.start_date) + 1)
    OR a.total_overtime_hours > 16 * (DATEDIFF(pp.end_date, pp.start_date) + 1)
    OR a.total_hours_worked < 0 OR a.total_overtime_hours < 0 OR a.total_late_hours < 0
 ORDER BY pp.start_date, e.full_name;

-- @check C22 | A saved day that falls inside two pay periods that both take the employee (the periods overlap on the employee's own pay schedule, so that day is counted in both) | expect: none
SELECT e.employee_code, e.full_name, b.att_date, COUNT(*) AS in_periods_count, GROUP_CONCAT(pp.period_label ORDER BY pp.start_date SEPARATOR ' | ') AS in_periods
  FROM biometric_daily b JOIN employees e ON e.id = b.employee_id
  JOIN payroll_periods pp ON b.att_date BETWEEN pp.start_date AND pp.end_date AND (pp.period_type = e.pay_schedule OR pp.period_type = 'Weekly')
 GROUP BY b.id, e.employee_code, e.full_name, b.att_date
HAVING COUNT(*) > 1
 ORDER BY b.att_date, e.full_name;

-- @check C23 | Pay periods of the same schedule that overlap in dates (days can be counted in both). A Monthly run beside the kinsenas cut-offs of the same month is normal and is not listed | expect: review
SELECT a.id AS period_a, a.period_label AS label_a, a.start_date AS start_a, a.end_date AS end_a,
       b.id AS period_b, b.period_label AS label_b, b.start_date AS start_b, b.end_date AS end_b
  FROM payroll_periods a JOIN payroll_periods b ON a.id < b.id AND a.start_date <= b.end_date AND b.start_date <= a.end_date
   AND (a.period_type = b.period_type OR a.period_type = 'Weekly' OR b.period_type = 'Weekly')
 ORDER BY a.start_date;

-- @check C30 | Employee set-up that cannot be right: negative or absurd pay, a negative or absurd monthly SSS / PhilHealth / Pag-IBIG / tax amount, or a duty day outside 1-24 hours | expect: none
SELECT e.employee_code, e.full_name, e.salary_type, e.base_salary, e.hours_per_day, e.date_hired, e.status,
       e.sss_amount, e.philhealth_amount, e.pagibig_amount, e.tax_amount
  FROM employees e
 WHERE e.base_salary < 0 OR e.base_salary > 10000000 OR (e.salary_type = 'daily' AND e.base_salary > 100000)
    OR e.hours_per_day < 1 OR e.hours_per_day > 24
    OR e.sss_amount < 0 OR e.sss_amount > 100000 OR e.philhealth_amount < 0 OR e.philhealth_amount > 100000
    OR e.pagibig_amount < 0 OR e.pagibig_amount > 100000 OR e.tax_amount < 0 OR e.tax_amount > 10000000
 ORDER BY e.employee_code;

-- @check C31 | Settings that are not usable values (rates, duty day, overtime method / multiplier) | expect: none
SELECT setting_key, setting_value, 'must be a number from 0 to 100000' AS problem FROM settings
 WHERE setting_key IN ('overtime_rate', 'late_rate')
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) > 100000)
UNION ALL
SELECT setting_key, setting_value, 'must be a number from 1 to 24' FROM settings
 WHERE setting_key = 'standard_hours'
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) NOT BETWEEN 1 AND 24)
UNION ALL
SELECT setting_key, setting_value, 'must be flat or labor_code' FROM settings
 WHERE setting_key = 'overtime_method' AND setting_value NOT IN ('flat', 'labor_code')
UNION ALL
SELECT setting_key, setting_value, 'must be a number from 1 to 3' FROM settings
 WHERE setting_key = 'overtime_multiplier'
   AND (setting_value NOT REGEXP '^[0-9]+([.][0-9]+)?$' OR CAST(setting_value AS DECIMAL(14,2)) NOT BETWEEN 1 AND 3);

-- @check C32 | Pay lines in a pay period of another schedule than the one the employee is paid on now (their Pay Schedule was changed after that pay run, or the line is older than the schedules) | expect: review
SELECT pp.period_label, pp.period_type AS period_schedule, e.employee_code, e.full_name, e.pay_schedule AS employee_schedule, p.net_pay, pp.status
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE pp.period_type <> 'Weekly' AND pp.period_type <> e.pay_schedule
 ORDER BY pp.start_date, e.full_name;

-- =====================================================================================================================
--  4. BONUSES AND DEDUCTIONS
-- =====================================================================================================================

-- @check C40 | Bonus / deduction entries do not add up to the payroll line (entries edited by hand, or never linked to a period) | expect: review
SELECT p.period_id, pp.period_label, e.employee_code, e.full_name,
       p.bonus_pay AS payroll_bonus, COALESCE(b.amt, 0) AS history_bonus,
       p.other_deductions AS payroll_deductions, COALESCE(d.amt, 0) AS history_deductions
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
  LEFT JOIN (SELECT period_id, employee_id, SUM(amount) AS amt FROM bonus_deductions WHERE type = 'Bonus'     GROUP BY period_id, employee_id) b
         ON b.period_id = p.period_id AND b.employee_id = p.employee_id
  LEFT JOIN (SELECT period_id, employee_id, SUM(amount) AS amt FROM bonus_deductions WHERE type = 'Deduction' GROUP BY period_id, employee_id) d
         ON d.period_id = p.period_id AND d.employee_id = p.employee_id
 WHERE p.bonus_pay <> COALESCE(b.amt, 0) OR p.other_deductions <> COALESCE(d.amt, 0)
 ORDER BY pp.start_date, e.full_name;

-- @check C41 | Bonus / deduction entries with a zero or negative amount | expect: none
SELECT h.id, h.entry_date, e.employee_code, e.full_name, h.type, h.amount, h.reason, h.period_id
  FROM bonus_deductions h JOIN employees e ON e.id = h.employee_id
 WHERE h.amount <= 0
 ORDER BY h.entry_date;

-- @check C42 | Bonuses in one calendar year above PHP 90,000 per employee: the excess is taxable compensation and the system does not withhold on a bonus | expect: review
SELECT YEAR(pp.start_date) AS year, e.employee_code, e.full_name, SUM(p.bonus_pay) AS bonuses_for_the_year,
       SUM(p.bonus_pay) - 90000 AS taxable_excess
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 GROUP BY YEAR(pp.start_date), e.id, e.employee_code, e.full_name
HAVING SUM(p.bonus_pay) > 90000
 ORDER BY year, e.full_name;

-- =====================================================================================================================
--  5. COMPLIANCE: things the law asks for that arithmetic alone cannot show
-- =====================================================================================================================

-- @check C50 | Overtime pays less than the Labor Code minimum (hourly rate + 25%, Art. 87) for these active employees under the flat peso rate (a month is taken as 26 working days) | expect: review
SELECT e.employee_code, e.full_name, e.salary_type, e.base_salary, COALESCE(e.hours_per_day, 8) AS duty_hours,
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

-- @check C51 | Salaried employees whose Date Hired is inside the period yet who were paid with no unpaid day: the timesheet shows them working before that date. The Date Hired does not affect the pay (the days in the file do) - check it is right | expect: review
SELECT p.id AS payroll_id, pp.period_label, pp.start_date, pp.end_date, e.employee_code, e.full_name, e.salary_type, e.date_hired,
       p.gross_pay, a.absent_days, p.absence_deduction
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
  LEFT JOIN attendance_summary a ON a.period_id = p.period_id AND a.employee_id = p.employee_id
 WHERE e.date_hired IS NOT NULL AND e.salary_type <> 'daily' AND e.date_hired > DATE_ADD(pp.start_date, INTERVAL 1 DAY)
   AND e.date_hired <= pp.end_date AND COALESCE(a.absent_days, 0) = 0
 ORDER BY pp.start_date, e.full_name;

-- @check C52 | Pay for a period that ended before the employee's Date Hired: the timesheets show them working, so they were paid for the days in the file (the Date Hired does not affect the pay) - check it is not simply the day they were added in the system | expect: review
SELECT p.id AS payroll_id, pp.period_label, e.employee_code, e.full_name, e.date_hired, pp.end_date, p.gross_pay
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id JOIN employees e ON e.id = p.employee_id
 WHERE e.date_hired IS NOT NULL AND e.date_hired > pp.end_date AND p.gross_pay > 0
 ORDER BY pp.start_date, e.full_name;

-- =====================================================================================================================
--  5b. 13TH MONTH PAY (PD 851): one twelfth of the basic pay earned in the calendar year
-- =====================================================================================================================

-- @check C60 | More 13th month pay recorded for a year than one twelfth of that year's basic pay (payroll.basic_pay) | expect: none
WITH basic AS (
  SELECT p.employee_id, YEAR(pp.start_date) AS yr, SUM(p.basic_pay) AS basic_pay
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, YEAR(pp.start_date)
), paid AS (
  SELECT h.employee_id, COALESCE(YEAR(pp.start_date), YEAR(h.entry_date)) AS yr, SUM(h.amount) AS paid_13th
    FROM bonus_deductions h LEFT JOIN payroll_periods pp ON pp.id = h.period_id
   WHERE h.type = 'Bonus' AND h.reason LIKE '13th Month Pay%'
   GROUP BY h.employee_id, COALESCE(YEAR(pp.start_date), YEAR(h.entry_date))
)
SELECT paid.yr AS year, e.employee_code, e.full_name,
       COALESCE(b.basic_pay, 0) AS basic_pay_that_year, ROUND(COALESCE(b.basic_pay, 0) / 12, 2) AS thirteenth_month_due,
       paid.paid_13th AS recorded, ROUND(paid.paid_13th - ROUND(COALESCE(b.basic_pay, 0) / 12, 2), 2) AS recorded_over_due
  FROM paid JOIN employees e ON e.id = paid.employee_id
  LEFT JOIN basic b ON b.employee_id = paid.employee_id AND b.yr = paid.yr
 WHERE paid.paid_13th > ROUND(COALESCE(b.basic_pay, 0) / 12, 2) + 0.004
 ORDER BY paid.yr, e.employee_code;

-- @check R03 | 13th month pay by year and employee: months with pay, basic pay earned, one twelfth (due), recorded so far, balance | expect: info
WITH basic AS (
  SELECT p.employee_id, YEAR(pp.start_date) AS yr, COUNT(DISTINCT MONTH(pp.start_date)) AS months_with_pay, SUM(p.basic_pay) AS basic_pay
    FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
   GROUP BY p.employee_id, YEAR(pp.start_date)
), paid AS (
  SELECT h.employee_id, COALESCE(YEAR(pp.start_date), YEAR(h.entry_date)) AS yr, SUM(h.amount) AS paid_13th
    FROM bonus_deductions h LEFT JOIN payroll_periods pp ON pp.id = h.period_id
   WHERE h.type = 'Bonus' AND h.reason LIKE '13th Month Pay%'
   GROUP BY h.employee_id, COALESCE(YEAR(pp.start_date), YEAR(h.entry_date))
)
SELECT b.yr AS year, e.employee_code, e.full_name, b.months_with_pay,
       b.basic_pay, ROUND(b.basic_pay / 12, 2) AS thirteenth_month_due, COALESCE(x.paid_13th, 0) AS recorded,
       ROUND(b.basic_pay / 12, 2) - COALESCE(x.paid_13th, 0) AS balance
  FROM basic b JOIN employees e ON e.id = b.employee_id
  LEFT JOIN paid x ON x.employee_id = b.employee_id AND x.yr = b.yr
 ORDER BY b.yr DESC, e.full_name;

-- =====================================================================================================================
--  6. REPORTS (no pass / fail)
-- =====================================================================================================================

-- @check R01 | Month by month: people paid, gross, bonus, deductions, contributions, tax, net - for the accountant's tie-out | expect: info
SELECT DATE_FORMAT(pp.start_date, '%Y-%m') AS month, COUNT(DISTINCT p.employee_id) AS people_paid, SUM(p.gross_pay) AS gross_pay, SUM(p.bonus_pay) AS bonus,
       SUM(p.sss_deduction) AS sss_employee, SUM(p.philhealth_deduction) AS philhealth_employee, SUM(p.pagibig_deduction) AS pagibig_employee,
       SUM(p.withholding_tax) AS withholding_tax, SUM(p.other_deductions) AS other_deductions, SUM(p.net_pay) AS net_pay,
       ROUND(SUM(p.gross_pay + p.bonus_pay) - SUM(p.total_deductions) - SUM(p.net_pay), 2) AS unexplained_difference
  FROM payroll p JOIN payroll_periods pp ON pp.id = p.period_id
 GROUP BY DATE_FORMAT(pp.start_date, '%Y-%m')
 ORDER BY month;

-- @check R02 | Pay period by pay period: status, lines, totals | expect: info
SELECT pp.id AS period_id, pp.period_label, pp.period_type, pp.status, pp.finalize_count, pp.reopen_count, COUNT(p.id) AS lines_,
       SUM(p.gross_pay) AS gross_pay, SUM(p.bonus_pay) AS bonus, SUM(p.withholding_tax) AS tax, SUM(p.net_pay) AS net_pay
  FROM payroll_periods pp LEFT JOIN payroll p ON p.period_id = pp.id
 GROUP BY pp.id, pp.period_label, pp.period_type, pp.status, pp.finalize_count, pp.reopen_count, pp.start_date
 ORDER BY pp.start_date, pp.id;
