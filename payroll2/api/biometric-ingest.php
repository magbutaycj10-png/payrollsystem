<?php
/*
 * api/biometric-ingest.php
 *
 * Machine-to-machine endpoint for biometric_agent.py running on the client
 * PCs. Receives batches of raw punches and stages them in attendance_logs.
 *
 * Deployed at: https://<your-app>.onrender.com/api/biometric-ingest.php
 *
 * Auth: X-API-Key header, matched against a SHA-256 hash in
 * biometric_api_keys. Keys are per-PC, so one can be revoked without
 * disturbing the others. NOT session-based - there is no browser here.
 *
 * Request:
 *   POST application/json
 *   X-API-Key: <the key>
 *   {
 *     "device_id":     "EPH-A6-01",
 *     "agent_version": "1.0.0",
 *     "host":          "FRONTDESK-PC",
 *     "punches": [
 *       {"employee_id":"00001","punch_time":"2026-09-24 15:06:00",
 *        "punch_state":3,"verify_mode":1,"source":"serial"}
 *     ]
 *   }
 *
 * Response:
 *   {"ok":true,"received":1,"inserted":1,"duplicates":0,"rejected":0}
 *
 * The agent retries on any non-ok response, so returning an error is always
 * safe - it will never silently drop punches.
 */

require __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

/* ── Method guard ─────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(['ok' => false, 'error' => 'POST required'], 405);
}

/* ── Authenticate the agent ───────────────────────────────────────
   Render/Apache does not always expose custom headers via
   $_SERVER['HTTP_X_API_KEY'], so fall back to getallheaders().        */
function readApiKey(): string {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'X-API-Key') === 0) return trim($value);
        }
    }
    return '';
}

$apiKey = readApiKey();
if ($apiKey === '') {
    jsonResponse(['ok' => false, 'error' => 'Missing X-API-Key'], 401);
}

$db = getDB();
applySchemaPatches();

/* Look the key up by hash — the plaintext key is never stored. */
$keyHash = hash('sha256', $apiKey);
$keyStmt = $db->prepare(
    "SELECT id, device_id FROM biometric_api_keys
      WHERE key_hash = ? AND active = 1 LIMIT 1"
);
$keyStmt->execute([$keyHash]);
$keyRow = $keyStmt->fetch();

if (!$keyRow) {
    // Deliberately vague: do not tell a prober whether the key merely expired.
    error_log('biometric-ingest: rejected key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    jsonResponse(['ok' => false, 'error' => 'Invalid API key'], 403);
}

/* ── Parse and validate the payload ───────────────────────────── */
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    jsonResponse(['ok' => false, 'error' => 'Empty body'], 400);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    jsonResponse(['ok' => false, 'error' => 'Malformed JSON'], 400);
}

$punches = $body['punches'] ?? [];
if (!is_array($punches)) {
    jsonResponse(['ok' => false, 'error' => 'punches must be an array'], 400);
}

// An empty batch is a valid heartbeat, not an error.
if (count($punches) === 0) {
    jsonResponse(['ok' => true, 'received' => 0, 'inserted' => 0,
                  'duplicates' => 0, 'rejected' => 0]);
}

if (count($punches) > 1000) {
    jsonResponse(['ok' => false, 'error' => 'Batch too large (max 1000)'], 413);
}

/* A key may be pinned to one terminal; if it is, that wins over whatever
   the agent claims, so a leaked key cannot be used to forge another
   device's attendance. */
$deviceId = $keyRow['device_id'] !== ''
    ? $keyRow['device_id']
    : substr(trim((string)($body['device_id'] ?? '')), 0, 40);

$agentHost    = substr(trim((string)($body['host']          ?? '')), 0, 100);
$agentVersion = substr(trim((string)($body['agent_version'] ?? '')), 0, 20);

/* ── Stage the punches ────────────────────────────────────────────
   INSERT IGNORE leans on uq_punch (employee_id, punch_time): a repeat
   send is a no-op, so the agent can retry as often as it likes.      */
$insert = $db->prepare(
    "INSERT IGNORE INTO attendance_logs
        (employee_id, punch_time, punch_state, verify_mode, device_id, source)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$received  = 0;
$inserted  = 0;
$rejected  = 0;
$errors    = [];
$newest    = null;

$db->beginTransaction();
try {
    foreach ($punches as $row) {
        $received++;

        if (!is_array($row)) { $rejected++; continue; }

        $empId = trim((string)($row['employee_id'] ?? ''));
        $when  = trim((string)($row['punch_time']  ?? ''));

        if ($empId === '' || $when === '') {
            $rejected++;
            $errors[] = 'row ' . $received . ': missing employee_id or punch_time';
            continue;
        }

        /* Strict YYYY-MM-DD HH:MM:SS. Rejecting anything else keeps a
           malformed agent from poisoning the payroll period with junk. */
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $when);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $when) {
            $rejected++;
            $errors[] = 'row ' . $received . ': bad punch_time "' . $when . '"';
            continue;
        }

        /* Reject clock-skewed futures — a terminal with a wrong date would
           otherwise land punches in a period that has not happened yet. */
        if ($dt->getTimestamp() > time() + 86400) {
            $rejected++;
            $errors[] = 'row ' . $received . ': punch_time is in the future';
            continue;
        }

        $state  = (int)($row['punch_state'] ?? 0);
        $verify = (int)($row['verify_mode'] ?? 0);
        $source = substr(trim((string)($row['source'] ?? 'serial')), 0, 16);

        if ($state < 0 || $state > 15)  { $state  = 0; }
        if ($verify < 0 || $verify > 15) { $verify = 0; }

        $insert->execute([
            substr($empId, 0, 32), $when, $state, $verify, $deviceId, $source,
        ]);

        // rowCount() is 0 when IGNORE swallowed a duplicate.
        if ($insert->rowCount() > 0) {
            $inserted++;
        }

        if ($newest === null || $when > $newest) {
            $newest = $when;
        }
    }

    /* Heartbeat: which PC is holding the terminal, and how current is it. */
    if ($deviceId !== '') {
        $db->prepare(
            "INSERT INTO biometric_agent_state
                (device_id, agent_host, agent_version, last_punch_time,
                 last_sync_at, punches_total)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
             ON DUPLICATE KEY UPDATE
                agent_host      = VALUES(agent_host),
                agent_version   = VALUES(agent_version),
                last_punch_time = GREATEST(
                    COALESCE(last_punch_time, '1970-01-01'),
                    COALESCE(VALUES(last_punch_time), '1970-01-01')),
                last_sync_at    = CURRENT_TIMESTAMP,
                punches_total   = punches_total + VALUES(punches_total)"
        )->execute([$deviceId, $agentHost, $agentVersion, $newest, $inserted]);
    }

    $db->prepare("UPDATE biometric_api_keys SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?")
       ->execute([$keyRow['id']]);

    $db->commit();
} catch (PDOException $e) {
    $db->rollBack();
    error_log('biometric-ingest: ' . $e->getMessage());
    // 500 so the agent backs off and retries rather than dropping the batch.
    jsonResponse(['ok' => false, 'error' => 'Database error'], 500);
}

$response = [
    'ok'         => true,
    'received'   => $received,
    'inserted'   => $inserted,
    'duplicates' => $received - $inserted - $rejected,
    'rejected'   => $rejected,
];
if ($errors) {
    $response['errors'] = array_slice($errors, 0, 20);
}

jsonResponse($response);
