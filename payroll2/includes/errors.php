<?php
/*
 * includes/errors.php
 *
 * No raw PHP or SQL error ever reaches the screen. Whatever goes wrong, the
 * user gets a short message in plain words (and, for a page, a way back);
 * the technical details go to payroll_error.log with a reference code, so
 * the developer can find the exact entry the user is looking at.
 *
 * Loaded first by includes/helpers.php, so it covers every page and every
 * api/ endpoint. Pages and endpoints that catch their own errors should show
 * friendlyError($e) to the user and pass $e to logAppError().
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
(function () {
    /* E:\PayrollApp2\payroll_error.log locally; the server default elsewhere */
    $dir = dirname(__DIR__, 2);
    if (is_dir($dir) && is_writable($dir)) ini_set('error_log', $dir . '/payroll_error.log');
})();

/* Buffer page output so a failure halfway through a page can still be
   replaced by a clean error page instead of half a page plus an error. */
if (PHP_SAPI !== 'cli' && ob_get_level() === 0) ob_start();

/* An api/ endpoint, or a request that expects JSON back */
function isApiRequest(): bool {
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
}

/* Writes the details to the log; returns the short reference shown to the user */
function logAppError($e): string {
    $ref = strtoupper(bin2hex(random_bytes(3)));
    $where = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
    $what = $e instanceof Throwable
        ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        : (string)$e;
    error_log("[ref $ref] $where - $what");
    return $ref;
}

/*
 * A database or PHP error, said the way a payroll user would understand it.
 * MySQL error numbers: https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
 */
function friendlyError(Throwable $e): string {
    $code = 0;
    if ($e instanceof PDOException) {
        $code = (int)($e->errorInfo[1] ?? 0);
        if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int)$m[1];
    }
    switch ($code) {
        case 1062:   /* duplicate entry for a unique key */
            $value = '';
            $thing = 'value';
            if (preg_match("/Duplicate entry '(.*?)' for key '(?:[^.']*\.)?([^']*)'/", $e->getMessage(), $m)) {
                $value = $m[1];
                $thing = [
                    'uq_email'       => 'login email',
                    'uq_emp_id'      => 'employee ID',
                    'uq_profile_emp' => 'employee profile',
                    'uq_daily'       => 'day record',
                    'uq_sig_payroll' => 'payslip signature',
                    'uq_mgr_emp'     => 'manager assignment',
                    'PRIMARY'        => 'record',
                ][$m[2]] ?? (stripos($m[2], 'name') !== false ? 'name' : 'value');
            }
            return ($value !== '' ? "“{$value}” is already used as another {$thing}." : "That {$thing} is already used.")
                 . " Please use a different {$thing}.";
        case 1451:
            return "This can't be deleted because other records still use it (for example payroll, attendance, "
                 . "leave or payslip history). Those records have to be kept.";
        case 1452:
            return 'This refers to something that no longer exists - it may have been deleted. Refresh the page and try again.';
        case 1048:
            return 'A required field was left empty. Fill it in and try again.';
        case 1406:
            return 'One of the values is too long. Shorten it and try again.';
        case 1264: case 1292: case 1366:
            return 'One of the values is in the wrong format (for example a date or a number). Check it and try again.';
        case 1205: case 1213:
            return 'The database was busy with another change. Please try again.';
        case 1045:
            return 'The database refused the login. Check the database password (secrets.bat), then restart the app.';
        case 2002: case 2003: case 2005: case 2006: case 2013:
            return "Can't reach the database right now. Check the internet connection, then try again.";
    }
    if ($e instanceof PDOException) {
        if (stripos($e->getMessage(), 'getaddrinfo') !== false || stripos($e->getMessage(), 'timed out') !== false
            || stripos($e->getMessage(), 'gone away') !== false) {
            return "Can't reach the database right now. Check the internet connection, then try again.";
        }
        return "The database couldn't complete this action. Please try again - if it keeps happening, "
             . 'give your developer the reference code.';
    }
    return 'Something went wrong on this page. Please go back and try again.';
}

/* Ends the request with a clean message: JSON for api/ calls, a page otherwise */
function showAppError(string $message, string $ref = '', int $status = 500): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . ($ref ? " (ref $ref)" : '') . PHP_EOL);
        exit(1);
    }
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent()) http_response_code($status);

    if (isApiRequest()) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['error' => $message . ($ref ? " (Reference: $ref)" : '')]);
        exit;
    }

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $msg  = htmlspecialchars($message);
    $refH = $ref ? '<p class="ref">Reference: ' . htmlspecialchars($ref) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Something went wrong - Payroll System</title>
<style>
  *{box-sizing:border-box} body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f1f5f9;color:#0f172a;padding:20px}
  .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:32px 30px;max-width:520px;width:100%;
  box-shadow:0 10px 30px rgba(15,23,42,.08)} h1{font-size:1.15rem;margin:0 0 10px}
  p{margin:0 0 18px;line-height:1.6;color:#334155} .ref{font-size:.8rem;color:#94a3b8;margin:-6px 0 18px}
  .row{display:flex;gap:10px;flex-wrap:wrap} a,button{font:inherit;font-size:.9rem;font-weight:600;padding:9px 16px;
  border-radius:8px;text-decoration:none;cursor:pointer} .primary{background:#1e293b;color:#fff;border:none}
  .ghost{background:#fff;color:#1e293b;border:1px solid #cbd5e1}
</style></head><body><div class="card">
  <h1>That didn't go through</h1>
  <p>{$msg}</p>
  {$refH}
  <div class="row"><button class="primary" onclick="history.back()">Go back</button>
  <a class="ghost" href="/index.php">Home</a></div>
</div></body></html>
HTML;
    exit;
}

set_exception_handler(function (Throwable $e) {
    $ref = logAppError($e);
    showAppError(friendlyError($e), $ref);
});

/* Fatal errors (a PHP mistake rather than an exception) */
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $ref = logAppError("{$err['message']} in {$err['file']}:{$err['line']}");
        showAppError('Something went wrong on this page. Please go back and try again.', $ref);
    }
});
