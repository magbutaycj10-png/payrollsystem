<?php
/*
 * Defects — the register behind the [D-nn] tags in the suites. A test tagged with one of these ids fails on the ORIGINAL
 * application (that is what proves the defect) and passes on the audit-fixed one (fixes\payroll2), where the same test
 * is a regression guard: if it fails there, a fix has come undone and the run fails.
 * (Evidence for each defect and the fix that was applied are in tests/AUDIT_FINDINGS.md.)
 */
final class Defects
{
    /** where each defect was in the audited application, and (last) where the fix lives now */
    public static function register(): void
    {
        $d = [
            ['D-01', 'Low',    'Withholding tax: a tax of exactly half a centavo sometimes rounds DOWN (float subtraction before round())', 'payroll2/includes/helpers.php  birTax()',
                'birTax() works in whole centavos'],
            ['D-02', 'Medium', 'Tax over-withheld in an earlier cut-off (or week) is never returned when the month is settled', 'payroll2/includes/helpers.php  computePayLine(): max(0, monthly tax − tax already withheld)',
                'settleWithholdingTax(): the month\'s last run may be negative (a refund), shown as such on payslip / register / pages'],
            ['D-03', 'Medium', 'A salaried employee hired mid-period is paid the full period salary (days before the hire date are never deducted)', 'payroll2/includes/helpers.php  recomputePeriodFromDaily() / computePayLine()',
                'recomputePeriodFromDaily(): working days before the hire date count as unpaid days (absent_days / absent_deduction)'],
            ['D-04', 'High',   'Dashboard "predicted next payroll" regresses the six OLDEST periods and ignores the newest ones', 'payroll2/dashboard.php:38-48  ORDER BY period_start ASC … LIMIT 6',
                'dashboard.php takes the newest six'],
            ['D-05', 'High',   'ARIMA(2,1,0) double-counts the average change: a straight ₱100k→₱150k trend is forecast at ₱170k, not ₱160k', 'payroll2/assets/js/forecast.js  arimaForecast()',
                'forecast.js arimaForecast(): the mean change is counted once'],
            ['D-06', 'Medium', 'The forecast predicts employees\' NET pay, not the company\'s labor cost (gross + employer SSS/EC/PhilHealth/Pag-IBIG)', 'payroll2/api/forecast-data.php, assets/js/forecast.js',
                'forecast-data.php + helpers.php employerSharesByPeriod(); forecast page forecasts total labor cost (selector: net pay)'],
            ['D-07', 'Medium', 'Random Forest forecast changes on every page load (unseeded Math.random): the same data gives different budgets', 'payroll2/assets/js/forecast.js  RandomForestRegressor',
                'forecast.js makeRng() / seedFrom(): seeded from the data'],
            ['D-08', 'Medium', 'Random Forest can only repeat payroll values it has already seen: it cannot follow a rising payroll', 'payroll2/assets/js/forecast.js  RegressionTree / buildNextFeatures()',
                'forecast.js randomForestForecast(): the forest learns the change per period'],
            ['D-09', 'Low',    'Dashboard step "Period finalized" never turns done (compares with \'Finalized\'; the period status is \'Locked\')', 'payroll2/dashboard.php:105',
                'dashboard.php compares with \'Locked\''],
            ['D-10', 'Low',    'Net pay can go negative (a month with one day worked; a large deduction) with no warning', 'payroll2/includes/helpers.php computePayLine(); payroll2/adjustments.php',
                'update-payroll.php finalize asks first; adjustments.php holds back a deduction that would go below zero; payroll.php banner'],
            ['D-11', 'Medium', 'Dashboard card says "Predicted Next Month Net Payroll" but predicts the next CUT-OFF (half a month on a kinsenas calendar)', 'payroll2/dashboard.php:394',
                'dashboard.php names the period from period_type'],
            ['D-12', 'Low',    'Amount in words reads "ONE PESOS" (should be "ONE PESO") on receipts', 'payroll2/includes/bir-print.php  amountInWords()',
                'amountInWords(): singular for exactly one peso'],
            ['D-13', 'Low',    'Dashboard treats the period with the highest id as "latest", not the most recent by date', 'payroll2/dashboard.php:9',
                'dashboard.php orders by period_start'],
            ['D-14', 'Medium', 'Compliance: overtime is one flat peso rate for everyone, not at least 125% of each employee\'s hourly rate (Labor Code Art. 87); no rest-day/holiday/night premium', 'Settings overtime_rate; payroll2/includes/helpers.php computePayLine()',
                'Settings: Overtime Method "Labor Code" (overtimePay()), multiplier, and an underpayment warning (overtimeShortfalls()); default stays flat'],
            ['D-15', 'Low',    'Forecast amounts are displayed with three decimals (₱53,210.483)', 'payroll2/assets/js/forecast.js  fmt()',
                'forecast.js fmt(): two decimals, signed'],
            ['D-16', 'Medium', 'No sanity limits on hours, overtime, rates or salary: one typo (80 h, 30 h OT, −₱45 OT rate, "08:60" = 860 h) flows straight into pay', 'api/save-daily-attendance.php, settings.php, employee.php, assets/js/attendance-upload.js toHours()',
                'helpers.php dayHoursProblem() / periodHoursProblem() / pesoProblem() used by every entry door; toHours() returns NaN for unreadable times'],
            ['D-17', 'Low',    'Punch roll-up deducts the 1-hour break from any day over half the duty day: 4:00 on the clock pays 4.00 h, 4:06 pays 3.10 h', 'payroll2/api/rollup-punches.php:156, assets/js/attendance-formats.js:209',
                'rollup-punches.php and attendance-formats.js: max(duty/2, span − break)'],
            ['D-18', 'Low',    'Correcting an earlier cut-off after the later one was finalized leaves the month tax and contributions inexact, silently (only OPEN later cut-offs are re-settled)', 'payroll2/includes/helpers.php laterPeriodsInMonth() / recomputeMonthFrom()',
                'settlementDrift(): finalize refuses stale lines, payroll.php names them, new Recompute action repairs them'],
            ['D-19', 'Low',    'Home page never shows a finalized pay period as finalized: it compares the period status with \'Finalized\', but a finalized period is \'Locked\' (twin of D-09, found while cleaning up)', 'payroll2/home.php:46 and :223',
                'home.php: a Locked period counts as finalized (line and badge)'],
        ];
        foreach ($d as [$id, $sev, $title, $where, $fix]) {
            T::defect($id, $sev, $title, AppCopy::hasFixes() ? "fixed: $fix   (was: $where)" : $where);
        }
    }
}
