<?php
/*
 * Http - call the app's real pages and api/ endpoints against the test database.
 *
 *   $r = Http::api('save-daily-attendance.php', ['period_id' => 3, 'rows' => [...]]);
 *   $r['status'], $r['json'], $r['body'], $r['headers'], $r['redirect'], $r['warnings']
 *
 *   Http::page('adjustments.php', post: [...])      a page with a POST form
 *   Http::page('print-doc.php', query: [...])       a page that renders HTML
 *
 * Each call runs in its own PHP process (the endpoints finish with exit, and php://input is
 * empty on the command line, so a small stream wrapper feeds the request body in). The
 * runner's code is passed inline with `php -r`, not as a script file: this machine's security
 * software deletes PHP files that are started as scripts and then behave like a web request.
 */
final class Http
{
    private static int $n = 0;

    public static function admin(): array
    {
        return ['logged_in' => true, 'admin' => 'QA Admin', 'last_seen' => time()];
    }

    public static function manager(int $id, string $name = 'QA Manager', string $branch = ''): array
    {
        return ['mgr_logged_in' => true, 'mgr_id' => $id, 'mgr_name' => $name, 'mgr_branch' => $branch, 'last_seen' => time()];
    }

    public static function api(string $endpoint, array $json = [], ?array $session = null): array
    {
        return self::call('api/' . $endpoint, ['method' => 'POST', 'body' => json_encode($json), 'session' => $session ?? self::admin()]);
    }

    public static function page(string $script, array $post = [], array $query = [], ?array $session = null): array
    {
        return self::call($script, ['method' => $post ? 'POST' : 'GET', 'post' => $post, 'query' => $query,
                                    'session' => $session ?? self::admin()]);
    }

    public static function call(string $script, array $spec): array
    {
        $tmp  = TestDb::tmp() . DIRECTORY_SEPARATOR . 'req';
        $sess = TestDb::tmp() . DIRECTORY_SEPARATOR . 'sessions';
        foreach ([$tmp, $sess] as $d) if (!is_dir($d)) mkdir($d, 0777, true);
        $id   = getmypid() . '-' . (++self::$n);
        $file = "$tmp/$id.json";
        $out  = "$tmp/$id.out.json";
        $spec += ['app_root' => AppCopy::root(), 'out' => $out, 'session_dir' => $sess, 'session_id' => 'qa' . bin2hex(random_bytes(6))];
        $spec['script'] = $script;
        file_put_contents($file, json_encode($spec));

        $cmd = [PHP_BINARY];
        if (php_ini_loaded_file()) { $cmd[] = '-c'; $cmd[] = php_ini_loaded_file(); }
        $cmd[] = '-d'; $cmd[] = 'extension_dir=' . ini_get('extension_dir');
        $cmd[] = '-d'; $cmd[] = 'display_errors=0';
        $cmd[] = '-r'; $cmd[] = self::RUNNER;
        $cmd[] = $file;

        $env = array_merge(getenv(), TestDb::env(), $spec['env'] ?? []);       // 'env' lets one request point at another database (the fresh-install test)
        $proc = proc_open($cmd, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('could not start PHP for ' . $script);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = microtime(true) + 120;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            if (microtime(true) > $deadline) { proc_terminate($proc); throw new RuntimeException("$script timed out"); }
            usleep(5000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        proc_close($proc);

        $res = is_file($out) ? json_decode((string)file_get_contents($out), true) : null;
        @unlink($file);
        @unlink($out);
        if (!is_array($res)) {
            throw new RuntimeException("$script produced no response. stdout: " . trim($stdout) . ' stderr: ' . trim($stderr));
        }
        if (!empty($res['fatal'])) {
            throw new RuntimeException("$script fatal error: {$res['fatal']['message']} @ " . basename($res['fatal']['file']) . ':' . $res['fatal']['line']);
        }
        $res['json'] = json_decode($res['body'], true);
        $res['redirect'] = null;
        foreach ($res['headers'] as $h) if (stripos($h, 'Location:') === 0) $res['redirect'] = trim(substr($h, 9));
        $res['stderr'] = trim($stderr);
        return $res;
    }

    /** the request runner: PHP source for `php -r`, reads the request spec named in $argv[1] */
    private const RUNNER = <<<'PHPCODE'
$specFile = $argv[1] ?? '';
$spec = json_decode((string)@file_get_contents($specFile), true);
if (!is_array($spec)) { fwrite(STDERR, "bad spec\n"); exit(2); }

$root   = realpath($spec['app_root']);
$target = realpath($root . DIRECTORY_SEPARATOR . $spec['script']);
if ($root === false || $target === false || strncmp($target, $root, strlen($root)) !== 0 || substr($target, -4) !== '.php') {
    fwrite(STDERR, "refusing to run {$spec['script']}\n");
    exit(2);
}

/* php://input -> the request body; every other php:// stream goes to the real wrapper */
final class QaRequestBody
{
    public static string $body = '';
    public $context;
    private $inner = null;
    private int $pos = 0;
    private bool $isInput = false;
    public function stream_open($path, $mode, $options, &$opened)
    {
        if ($path === 'php://input') { $this->isInput = true; return true; }
        stream_wrapper_restore('php');
        $this->inner = @fopen($path, $mode);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        return $this->inner !== false;
    }
    public function stream_read($n)
    {
        if ($this->isInput) { $c = (string)substr(self::$body, $this->pos, $n); $this->pos += strlen($c); return $c; }
        return fread($this->inner, $n);
    }
    public function stream_write($d) { return $this->isInput ? 0 : fwrite($this->inner, $d); }
    public function stream_eof() { return $this->isInput ? $this->pos >= strlen(self::$body) : feof($this->inner); }
    public function stream_stat() { return []; }
    public function stream_close() { if ($this->inner) fclose($this->inner); }
    public function stream_flush() { return $this->inner ? fflush($this->inner) : true; }
    public function stream_set_option($o, $a, $b) { return false; }
}
QaRequestBody::$body = (string)($spec['body'] ?? '');
stream_wrapper_unregister('php');
stream_wrapper_register('php', QaRequestBody::class);

/* the superglobals a web request would have */
$_SERVER['REQUEST_METHOD'] = $spec['method'] ?? 'POST';
$_SERVER['SCRIPT_NAME']    = '/' . str_replace('\\', '/', $spec['script']);
$_SERVER['REQUEST_URI']    = $_SERVER['SCRIPT_NAME'] . (empty($spec['query']) ? '' : '?' . http_build_query($spec['query']));
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
if (($spec['body'] ?? '') !== '') $_SERVER['CONTENT_TYPE'] = 'application/json';
$_GET     = $spec['query'] ?? [];
$_POST    = $spec['post'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);

/* a signed-in session, written where the app's own session_start() will find it */
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
if (!empty($spec['session_dir'])) session_save_path($spec['session_dir']);
if (!empty($spec['session'])) {
    session_id($spec['session_id']);
    session_start();
    $_SESSION = $spec['session'];
    session_write_close();
}

/* PHP warnings/notices are collected (not logged) so a test can assert the page ran clean */
$GLOBALS['__qa_warnings'] = [];
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return true;
    $GLOBALS['__qa_warnings'][] = [$no, $str, str_replace(chr(92), '/', $file), $line];
    return true;
});

/* record the response when the script ends - by return, exit or fatal error */
ob_start();
register_shutdown_function(function () use ($spec) {
    $fatal = error_get_last();
    while (ob_get_level() > 1) ob_end_flush();
    $body = ob_get_level() ? ob_get_clean() : '';
    $status = http_response_code();
    file_put_contents($spec['out'], json_encode([
        'status'   => $status === false ? 200 : $status,
        'headers'  => function_exists('headers_list') ? headers_list() : [],
        'body'     => $body,
        'warnings' => $GLOBALS['__qa_warnings'],
        'fatal'    => $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) ? $fatal : null,
    ], JSON_INVALID_UTF8_SUBSTITUTE));
});

chdir(dirname($target));
(function () use ($target) { include $target; })();
PHPCODE;
}
