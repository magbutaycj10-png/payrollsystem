<?php
// api/create-period.php
// Creates a payroll period. Admin only - managers upload into existing periods.
//   { label, start, end, type }   type: Semi-Monthly (the kinsenas cut-offs: 1st-15th and 16th-end)
//   There is ONE set of pay periods. Employees paid once a month are paid in the month's last cut-off, for the whole month, so a
//   separate whole-month ("Monthly") period is no longer created (Weekly neither).
require '../includes/helpers.php';
session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error'=>'Unauthorized'],401); }

$body  = json_decode(file_get_contents('php://input'), true);
$label = trim($body['label'] ?? '');
$start = $body['start'] ?? '';
$end   = $body['end']   ?? '';
$asked = $body['type'] ?? null;
if ($asked === 'Weekly') {
    jsonResponse(['error' => 'Weekly pay periods are no longer offered. Create the kinsenas cut-offs (1st-15th and 16th-end).'], 400);
}
if ($asked === 'Monthly') {
    jsonResponse(['error' => 'A separate whole-month pay period is no longer needed: employees paid monthly are paid in the month\'s last cut-off (16th-end), '
                           . 'for the whole month. Create the 1st-15th and 16th-end cut-offs instead.'], 400);
}

if (!$label || !$start || !$end) {
    jsonResponse(['error'=>'Missing fields'],400);
}
if (strtotime($end) < strtotime($start)) {
    jsonResponse(['error'=>'The end date is before the start date.'],400);
}

try {
    $db = getDB();
    applySchemaPatches();   // period_type column on older databases
    $type = 'Semi-Monthly';     /* the only kind of pay period there is now */
    // Two periods of the same schedule covering the same days would pay those days twice (periods of different schedules hold different employees)
    if ($clash = overlappingPeriod($db, $start, $end, 0, $type)) {
        $kind = periodTypeLabel($clash);
        $same = $clash['start_date'] === $start && $clash['end_date'] === $end;
        jsonResponse([
            'error' => ($same ? "{$clash['period_label']} already exists with these exact dates"
                              : "These dates overlap {$clash['period_label']}")
                     . " ({$clash['start_date']} to {$clash['end_date']}, $kind schedule)."
                     . " Use that period, or pick dates outside it.",
            'clash' => ['id' => (int)$clash['id'], 'label' => $clash['period_label'], 'kind' => $kind, 'same' => $same],
        ], 409);
    }
    $st = $db->prepare("INSERT INTO payroll_periods (period_label,start_date,end_date,period_type,status,created_by) VALUES (?,?,?,?,'Open',?)");
    $st->execute([$label,$start,$end,$type,currentUserId()]);
    $id = (int)$db->lastInsertId();
    jsonResponse(['success'=>true,'id'=>$id,'type'=>$type,
                  'type_label'=>periodTypeLabel(['period_type'=>$type,'start_date'=>$start])]);
} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
