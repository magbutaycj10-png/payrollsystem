<?php
// api/create-period.php
// Creates a payroll period. Admin only - managers upload into existing periods.
//   { label, start, end, type }   type: Monthly | Semi-Monthly | Weekly
require '../includes/helpers.php';
session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error'=>'Unauthorized'],401); }

$body  = json_decode(file_get_contents('php://input'), true);
$label = trim($body['label'] ?? '');
$start = $body['start'] ?? '';
$end   = $body['end']   ?? '';
/* Unknown or missing type -> the Settings default, so old callers keep working */
$type  = periodType($body['type'] ?? null);

if (!$label || !$start || !$end) {
    jsonResponse(['error'=>'Missing fields'],400);
}
if (strtotime($end) < strtotime($start)) {
    jsonResponse(['error'=>'The end date is before the start date.'],400);
}

try {
    $db = getDB();
    applySchemaPatches();   // period_type column on older databases
    // Two periods covering the same days would pay those days twice
    if ($clash = overlappingPeriod($db, $start, $end)) {
        $kind = periodTypeLabel($clash);
        $same = $clash['period_start'] === $start && $clash['period_end'] === $end;
        jsonResponse([
            'error' => ($same ? "{$clash['period_label']} already exists with these exact dates"
                              : "These dates overlap {$clash['period_label']}")
                     . " ({$clash['period_start']} to {$clash['period_end']}, $kind schedule)."
                     . " Use that period, or pick dates outside it.",
            'clash' => ['id' => (int)$clash['id'], 'label' => $clash['period_label'], 'kind' => $kind, 'same' => $same],
        ], 409);
    }
    $st = $db->prepare("INSERT INTO payroll_periods (period_label,period_start,period_end,period_type,status) VALUES (?,?,?,?,'Open')");
    $st->execute([$label,$start,$end,$type]);
    $id = (int)$db->lastInsertId();
    jsonResponse(['success'=>true,'id'=>$id,'type'=>$type,
                  'type_label'=>periodTypeLabel(['period_type'=>$type,'period_start'=>$start])]);
} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
