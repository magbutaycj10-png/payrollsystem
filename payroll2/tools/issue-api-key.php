<?php
/*
 * tools/issue-api-key.php
 *
 * Generates an API key for one client PC and stores only its SHA-256 hash.
 * The plaintext key is printed ONCE — copy it straight into that PC's
 * config.ini, because it cannot be recovered afterwards.
 *
 * CLI only (refuses to run over HTTP, so it can sit in the repo safely).
 *
 *   php tools/issue-api-key.php "Front desk PC" EPH-A6-01
 *   php tools/issue-api-key.php --list
 *   php tools/issue-api-key.php --revoke 3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require __DIR__ . '/../includes/helpers.php';

$db = getDB();
applySchemaPatches();

$args = array_slice($argv, 1);

/* ── List ─────────────────────────────────────────────────────── */
if (($args[0] ?? '') === '--list') {
    $rows = $db->query(
        "SELECT id, label, device_id, active, last_used_at, created_at
           FROM biometric_api_keys ORDER BY id"
    )->fetchAll();

    if (!$rows) {
        exit("No API keys issued yet.\n");
    }

    printf("%-4s %-26s %-14s %-7s %s\n", 'ID', 'LABEL', 'DEVICE', 'ACTIVE', 'LAST USED');
    echo str_repeat('-', 78), "\n";
    foreach ($rows as $row) {
        printf("%-4d %-26s %-14s %-7s %s\n",
            $row['id'],
            substr($row['label'], 0, 26),
            substr($row['device_id'], 0, 14),
            $row['active'] ? 'yes' : 'no',
            $row['last_used_at'] ?? 'never');
    }
    exit(0);
}

/* ── Revoke ───────────────────────────────────────────────────── */
if (($args[0] ?? '') === '--revoke') {
    $id = (int)($args[1] ?? 0);
    if (!$id) {
        exit("Usage: php tools/issue-api-key.php --revoke <id>\n");
    }

    $stmt = $db->prepare("UPDATE biometric_api_keys SET active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    echo $stmt->rowCount()
        ? "Key $id revoked. That PC will start getting HTTP 403 immediately.\n"
        : "No key with id $id.\n";
    exit(0);
}

/* ── Issue ────────────────────────────────────────────────────── */
$label    = trim($args[0] ?? '');
$deviceId = trim($args[1] ?? '');

if ($label === '') {
    exit("Usage:\n"
       . "  php tools/issue-api-key.php \"Front desk PC\" [device-id]\n"
       . "  php tools/issue-api-key.php --list\n"
       . "  php tools/issue-api-key.php --revoke <id>\n");
}

/* 32 random bytes, hex-encoded. random_bytes() is cryptographically secure
   and throws rather than returning weak output if no entropy is available. */
try {
    $key = bin2hex(random_bytes(32));
} catch (Exception $e) {
    exit("Could not generate a secure key: " . $e->getMessage() . "\n");
}

$stmt = $db->prepare(
    "INSERT INTO biometric_api_keys (key_hash, label, device_id) VALUES (?, ?, ?)"
);
$stmt->execute([hash('sha256', $key), $label, $deviceId]);

echo "\n";
echo "  API key issued (id " . $db->lastInsertId() . ")\n";
echo "  " . str_repeat('=', 72) . "\n\n";
echo "  Label   : $label\n";
echo "  Device  : " . ($deviceId !== '' ? $deviceId : '(any)') . "\n\n";
echo "  KEY     : $key\n\n";
echo "  " . str_repeat('=', 72) . "\n";
echo "  Paste into that PC's config.ini:\n\n";
echo "      [sync]\n";
echo "      api_key = $key\n\n";
echo "  This is the only time the key is shown. Only its hash is stored.\n\n";
