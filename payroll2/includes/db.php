<?php
// =============================================================
//  Database connection + request security
//
//  Nothing about the database is written in this file — host, user and
//  password all come from environment variables — so the code can be
//  published (GitHub) without giving the database away.
//
//    DB_HOST    database host                     (required)
//    DB_PORT    port, default 3306
//    DB_NAME    database name                     (required)
//    DB_USER    user                              (required)
//    DB_PASS    password                          (required)
//    DB_SSL_CA  path to the CA certificate (.pem) — or —
//    DB_SSL_CA_PEM  the certificate's text itself (easiest on Render)
//
//  Local (launch.bat):  secrets.bat sets them. It is gitignored;
//                       secrets.bat.example is the template.
//  Render:              Dashboard -> the service -> Environment.
//                       The certificate can also be a Secret File
//                       named ca.pem (mounted at /etc/secrets/ca.pem).
// =============================================================

$_env = fn(string $k, string $default = '') => (($v = getenv($k)) !== false && $v !== '') ? $v : $default;

define('DB_HOST', $_env('DB_HOST'));
define('DB_PORT', $_env('DB_PORT', '3306'));
define('DB_NAME', $_env('DB_NAME'));
define('DB_USER', $_env('DB_USER'));
define('DB_PASS', $_env('DB_PASS'));
define('DB_CHAR', 'utf8mb4');

// Philippine time for everything: PHP's dates ("today", printed-on lines,
// greetings) and the database's NOW() / timestamps, which are shown as
// stored. Both default to UTC — 8 hours behind — which put "today" on the
// wrong date before 8 AM. The Philippines has no daylight saving, so the
// fixed +08:00 offset is exact all year.
define('APP_TZ',     $_env('APP_TZ', 'Asia/Manila'));
define('APP_TZ_SQL', $_env('APP_TZ_SQL', '+08:00'));
date_default_timezone_set(APP_TZ);

// 'production' on the live server (the Dockerfile sets it): stricter rules,
// e.g. the default admin password is refused there.
define('APP_ENV', $_env('APP_ENV', 'local'));

// The CA certificate that proves the database server is the real one.
define('DB_SSL_CA', _resolveCaPath());

function _resolveCaPath(): string {
    /* 1. a path given outright */
    $path = getenv('DB_SSL_CA');
    if ($path !== false && $path !== '' && is_file($path)) return $path;

    /* 2. the certificate text in an environment variable — written once to a
          private temp file, since the MySQL driver wants a file */
    $pem = getenv('DB_SSL_CA_PEM');
    if ($pem !== false && trim($pem) !== '') {
        $file = sys_get_temp_dir() . '/payroll-db-ca-' . substr(sha1($pem), 0, 12) . '.pem';
        if (!is_file($file)) {
            @file_put_contents($file, str_replace('\n', "\n", trim($pem)) . "\n", LOCK_EX);
            @chmod($file, 0600);
        }
        if (is_file($file)) return $file;
    }

    /* 3. a Render Secret File, then the local layout (never inside the web root on a server) */
    foreach (['/etc/secrets/ca.pem', __DIR__ . '/../../ca.pem', __DIR__ . '/../ca.pem'] as $candidate) {
        if (is_file($candidate)) return $candidate;
    }
    return '';
}

// =============================================================
//  Request security — runs for every page, before any session starts
// =============================================================

/* HTTPS, directly or behind a proxy that terminates it (Render does) */
function appIsHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $proto === 'https';
}

/* "host[:port]" with the default port dropped, for comparing origins */
function _hostKey(?string $host, ?int $port, bool $https): string {
    $host = strtolower(trim((string)$host));
    if ($port && !($https && $port === 443) && !(!$https && $port === 80)) $host .= ':' . $port;
    return $host;
}

/*
 * A form or script on ANOTHER website must not be able to act with a
 * signed-in user's session (cross-site request forgery). Browsers say where a
 * request comes from (Origin, or Referer); a POST from a different site is
 * refused. Machine clients send neither and are let through — the biometric
 * agent authenticates with its own API key.
 */
function _rejectCrossSitePost(): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') return;

    $https = appIsHttps();
    $here  = (string)($_SERVER['HTTP_HOST'] ?? '');
    [$hHost, $hPort] = array_pad(explode(':', $here, 2), 2, null);
    $here  = _hostKey($hHost, $hPort !== null ? (int)$hPort : null, $https);

    $u = parse_url($source);
    $from = ($source === 'null' || !$u || empty($u['host'])) ? 'null'
          : _hostKey($u['host'], isset($u['port']) ? (int)$u['port'] : null, ($u['scheme'] ?? '') === 'https');
    if ($from !== $here) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Request refused: it came from another website.\n");
    }
}

if (PHP_SAPI !== 'cli') {
    /* The session cookie: never readable by page scripts, never sent along
       with requests started by other sites, HTTPS-only on HTTPS. */
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => appIsHttps(),
        ]);
    }
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');           /* files are what they say they are */
        header('X-Frame-Options: SAMEORIGIN');               /* no framing by other sites (clickjacking) */
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (appIsHttps()) header('Strict-Transport-Security: max-age=31536000');
        header_remove('X-Powered-By');                       /* do not advertise the PHP version */
    }
    _rejectCrossSitePost();
}

// =============================================================
//  Connection
// =============================================================

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!extension_loaded('pdo_mysql')) {
        http_response_code(500);
        die(_dbErrorPage('The pdo_mysql extension is not loaded.'));
    }

    $missing = array_keys(array_filter(['DB_HOST' => DB_HOST, 'DB_NAME' => DB_NAME, 'DB_USER' => DB_USER, 'DB_PASS' => DB_PASS],
                                       fn($v) => $v === ''));
    if ($missing) {
        http_response_code(500);
        die(_dbErrorPage(
            'The database connection is not configured: ' . implode(', ', $missing) . ' not set. '
          . 'On this PC: copy secrets.bat.example to secrets.bat, fill it in, and start with launch.bat. '
          . 'On Render: add them under the service\'s Environment settings.'
        ));
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHAR);

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . APP_TZ_SQL . "'",
    ];

    /* Encrypted, and the server's certificate must be signed by the CA we hold —
       so nobody in between can pose as the database. */
    if (DB_SSL_CA !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
        $options[PDO::MYSQL_ATTR_SSL_CA]                 = DB_SSL_CA;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        /* Usually the internet dropped or the database is asleep: say that,
           log the technical detail (errors.php) — never show it. */
        if (function_exists('showAppError')) {
            showAppError(friendlyError($e), logAppError($e), 503);
        }
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        die(_dbErrorPage("Can't reach the database right now. Check the internet connection, then try again."));
    }

    return $pdo;
}

function _dbErrorPage(string $error): string {
    return '<!DOCTYPE html><html><head><title>Payroll — Setup Error</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;
             min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .card{background:#1e293b;border-radius:14px;padding:36px;max-width:600px;width:100%}
        h1{font-size:1.1rem;color:#ef4444;margin-bottom:8px}
        .err{background:#0f0a0a;border:1px solid #7f1d1d;border-radius:8px;
             padding:14px;font-family:monospace;font-size:.85rem;color:#fca5a5;
             margin:14px 0;word-break:break-all}
    </style></head><body>
    <div class="card">
        <h1>Database Setup Error</h1>
        <div class="err">' . htmlspecialchars($error) . '</div>
    </div></body></html>';
}
