<?php
// api/log-print.php
require '../includes/helpers.php';
session_start();
if (empty($_SESSION['logged_in'])) { jsonResponse(['error'=>'Unauthorized'],401); }

$body = json_decode(file_get_contents('php://input'), true);
$doc  = trim($body['doc']  ?? '');
$type = trim($body['type'] ?? 'Physical Print');
$pid  = (int)($body['period_id'] ?? 0);

if (!$doc) jsonResponse(['error'=>'Missing document name'],400);

try {
    $db = getDB();
    $who = trim($_SESSION['admin'] ?? '') ?: 'Admin';
    $st = $db->prepare("INSERT INTO print_log (document_name,document_type,period_id,printed_by) VALUES (?,?,?,?)");
    $st->execute([$doc,$type,$pid ?: null,$who]);
    jsonResponse(['success'=>true]);
} catch (PDOException $e) {
    jsonResponse(['error' => friendlyError($e) . ' (Reference: ' . logAppError($e) . ')'], 500);
}
