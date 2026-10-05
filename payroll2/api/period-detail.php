<?php
/*
 * api/period-detail.php
 * Returns the full employee payroll breakdown for a single period.
 * Used by the forecast page detail modal and the print functions.
 */
require '../includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error' => 'Unauthorized'], 401); }

$db  = getDB();
$pid = (int)($_GET['period_id'] ?? 0);
if (!$pid) { jsonResponse(['error' => 'Missing period_id'], 400); }

/* Fetch the period metadata */
$pSt = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$pSt->execute([$pid]);
$period = $pSt->fetch();
if (!$period) { jsonResponse(['error' => 'Period not found'], 404); }

/* Fetch every employee payroll row for this period */
$rSt = $db->prepare("SELECT * FROM payroll WHERE period_id = ? ORDER BY emp_name ASC");
$rSt->execute([$pid]);
$rows = $rSt->fetchAll();

/* Pre-compute totals server-side for convenience */
$totals = [
    'headcount'        => count($rows),
    'total_gross'      => array_sum(array_column($rows, 'gross_pay')),
    'total_net'        => array_sum(array_column($rows, 'net_pay')),
    'total_bonus'      => array_sum(array_column($rows, 'bonus')),
    'total_deductions' => array_sum(array_column($rows, 'other_deductions')),
    'total_tax'        => array_sum(array_column($rows, 'withholding_tax')),
    'total_sss'        => array_sum(array_column($rows, 'sss')),
    'total_philhealth' => array_sum(array_column($rows, 'philhealth')),
    'total_pagibig'    => array_sum(array_column($rows, 'pagibig')),
    'total_ot'         => array_sum(array_column($rows, 'overtime_hours')),
    'total_late'       => array_sum(array_column($rows, 'late_hours')),
];

jsonResponse(['success' => true, 'period' => $period, 'rows' => $rows, 'totals' => $totals]);
