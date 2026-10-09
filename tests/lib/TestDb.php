<?php
/*
 * TestDb - a private, throw-away MySQL for the suite.
 *
 * The app talks to a live cloud MySQL 8 (Aiven, the one DBeaver shows). Tests must never touch it, so the
 * suite starts its own server on a free loopback port with its own data folder (tests/.tmp/mysql-data),
 * loads the schema into a database called "payroll_test", and shuts it down afterwards. Nothing here reads
 * secrets.bat or any DB_* value from the caller's environment - they are all replaced.
 *
 * Engine: a real MySQL 8 is preferred, because that is what the live database runs
 * ("C:\Program Files\MySQL\MySQL Server 8.x\bin\mysqld.exe", started with its own data folder and its default
 * strict sql_mode - ONLY_FULL_GROUP_BY included). XAMPP's MariaDB is the fallback; it has to run without
 * ONLY_FULL_GROUP_BY because MariaDB cannot see that "SELECT pp.label … GROUP BY pp.id" is valid.
 * Looked for in: $PAYROLL_TEST_MYSQLD, MySQL Server 8.x, XAMPP (C:\xampp\mysql\bin), then PATH.
 *
 * To use an already-running disposable server instead, set PAYROLL_TEST_DB_HOST / _PORT / _USER / _PASS
 * (host must be loopback; the schema is DROPped and recreated, so never point it at real data).
 */
final class TestDb
{
    public const DB   = 'payroll_test';
    public const USER = 'payroll_test';
    public const PASS = 'payroll_test_pw';

    private static ?array $cfg = null;
    private static $proc = null;
    private static string $tmp = '';

    public static function tmp(): string
    {
        if (self::$tmp === '') {
            self::$tmp = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.tmp';
            if (!is_dir(self::$tmp)) mkdir(self::$tmp, 0777, true);
        }
        return self::$tmp;
    }

    /** DB_* environment for the app (parent process and every endpoint child). */
    public static function env(): array
    {
        $c = self::$cfg ?? throw new RuntimeException('TestDb not started');
        return [
            'DB_HOST' => $c['host'], 'DB_PORT' => (string)$c['port'], 'DB_NAME' => self::DB,
            'DB_USER' => $c['app_user'], 'DB_PASS' => $c['app_pass'],
            'APP_ENV' => 'local', 'APP_TZ' => 'Asia/Manila',
            // make sure no real certificate / secret leaks in
            'DB_SSL_CA' => '', 'DB_SSL_CA_PEM' => '', 'ADMIN_INITIAL_PASSWORD' => '',
        ];
    }

    public static function root(?string $db = null): PDO
    {
        $c = self::$cfg ?? throw new RuntimeException('TestDb not started');
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4" . ($db ? ";dbname=$db" : '');
        return new PDO($dsn, $c['root_user'], $c['root_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function version(): string
    {
        $c = self::$cfg;
        return (string)self::root()->query('SELECT VERSION()')->fetchColumn() . (($c['flavor'] ?? '') === 'mariadb' ? '' : ' (MySQL)');
    }

    public static function flavor(): string { return self::$cfg['flavor'] ?? 'mysql'; }

    /** Start (or attach to) the server and create a fresh schema. Returns a one-line description. */
    public static function start(): string
    {
        // ---- safety: only ever a loopback server
        $ext = getenv('PAYROLL_TEST_DB_HOST');
        if ($ext !== false && $ext !== '') {
            if (!in_array($ext, ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new RuntimeException("PAYROLL_TEST_DB_HOST must be a loopback address (got $ext) - tests DROP and recreate " . self::DB);
            }
            self::$cfg = [
                'host' => $ext, 'port' => (int)(getenv('PAYROLL_TEST_DB_PORT') ?: 3306),
                'root_user' => getenv('PAYROLL_TEST_DB_USER') ?: 'root', 'root_pass' => (string)getenv('PAYROLL_TEST_DB_PASS'),
                'app_user' => self::USER, 'app_pass' => self::PASS, 'external' => true, 'flavor' => 'mysql',
            ];
            $v = (string)self::root()->query('SELECT VERSION()')->fetchColumn();
            self::$cfg['flavor'] = stripos($v, 'MariaDB') !== false ? 'mariadb' : 'mysql';
        } else {
            self::$cfg = self::bootOwnServer();
        }
        self::loadSchema();
        return sprintf('%s on %s:%d (%s)', self::version(), self::$cfg['host'], self::$cfg['port'],
            self::$cfg['external'] ? 'external disposable server' : 'private instance, data in tests/.tmp');
    }

    /** every mysqld we know of, most faithful to the live database (MySQL 8) first */
    private static function candidates(): array
    {
        $out = [];
        if ($env = getenv('PAYROLL_TEST_MYSQLD')) $out[] = $env;
        foreach (['C:\\Program Files', 'D:\\Program Files', 'E:\\Program Files'] as $pf) {
            $found = glob($pf . '\\MySQL\\MySQL Server *\\bin\\mysqld.exe') ?: [];
            rsort($found, SORT_NATURAL);
            $out = array_merge($out, $found);
        }
        foreach (['C:\\xampp\\mysql\\bin', 'D:\\xampp\\mysql\\bin', 'E:\\xampp\\mysql\\bin', 'F:\\xamp\\mysql\\bin'] as $d) $out[] = $d . '\\mysqld.exe';
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) if ($d !== '') $out[] = rtrim($d, '\\/') . '\\mysqld.exe';
        return array_values(array_filter(array_unique($out), 'is_file'));
    }

    private static function findTool(string $name, string $nearDir): ?string
    {
        foreach ([$nearDir . DIRECTORY_SEPARATOR . $name . '.exe', $nearDir . DIRECTORY_SEPARATOR . $name] as $p) if (is_file($p)) return $p;
        return null;
    }

    /** run a program (no shell), return [exit code, output] */
    private static function run(array $cmd, ?string $cwd = null): array
    {
        $p = proc_open($cmd, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($p)) return [-1, 'could not start ' . $cmd[0]];
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $out];
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if (!$s) throw new RuntimeException("no free port: $err");
        $name = stream_socket_get_name($s, false);
        fclose($s);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private static function bootOwnServer(): array
    {
        $mysqld = self::candidates()[0] ?? null;
        if (!$mysqld) {
            throw new RuntimeException("No mysqld found. Install MySQL Server 8 or XAMPP, or set PAYROLL_TEST_MYSQLD to its path "
                . "(or PAYROLL_TEST_DB_HOST/PORT/USER/PASS for a disposable server that is already running).");
        }
        $bin     = dirname($mysqld);
        $baseDir = dirname($bin);
        $flavor  = stripos(self::run([$mysqld, '--version'])[1], 'MariaDB') !== false ? 'mariadb' : 'mysql';
        $data    = self::tmp() . DIRECTORY_SEPARATOR . ($flavor === 'mariadb' ? 'mariadb-data' : 'mysql-data');
        $pidFile = self::tmp() . DIRECTORY_SEPARATOR . 'server.pid';
        $log     = self::tmp() . DIRECTORY_SEPARATOR . 'server.log';

        // a server left behind by an interrupted run keeps the data folder locked
        if (is_file($pidFile)) {
            $old = (int)trim((string)file_get_contents($pidFile));
            if ($old > 0) self::run(['taskkill', '/PID', (string)$old, '/T', '/F']);
            @unlink($pidFile);
            usleep(800000);
        }

        if (!is_dir($data . DIRECTORY_SEPARATOR . 'mysql')) {
            if ($flavor === 'mysql') {
                [$rc, $out] = self::run([$mysqld, '--no-defaults', '--initialize-insecure', "--basedir=$baseDir", "--datadir=$data", '--console']);
            } else {
                $install = self::findTool('mysql_install_db', $bin) ?? self::findTool('mariadb-install-db', $bin) ?? throw new RuntimeException('mysql_install_db not found next to mysqld');
                [$rc, $out] = self::run([$install, "--datadir=$data"]);
            }
            if ($rc !== 0 || !is_dir($data . DIRECTORY_SEPARATOR . 'mysql')) {
                throw new RuntimeException("initializing the private data folder failed (exit $rc):\n" . substr($out, -1500));
            }
        }

        $port = self::freePort();
        $cmd = [$mysqld, '--no-defaults', "--basedir=$baseDir", "--datadir=$data", "--port=$port",
            '--bind-address=127.0.0.1', '--console', "--pid-file=$pidFile",
            '--innodb-buffer-pool-size=64M', '--max-connections=60', '--innodb-flush-log-at-trx-commit=0',
            '--skip-log-bin', '--default-time-zone=+08:00'];
        if ($flavor === 'mysql') {
            // MySQL 8 with its own default sql_mode (ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_DATE …): what Aiven runs
            $cmd[] = '--mysqlx=OFF';
            $cmd[] = '--default-authentication-plugin=mysql_native_password';   // PHP's driver, loopback, no TLS
            // (name resolution stays on: --initialize-insecure creates root@localhost, which 127.0.0.1 only matches that way)
        } else {
            $cmd[] = '--skip-name-resolve';
            // MariaDB cannot see that a column depends on the primary key, so ONLY_FULL_GROUP_BY would reject valid queries
            $cmd[] = '--character-set-server=utf8mb4';
            $cmd[] = '--collation-server=utf8mb4_unicode_ci';
            $cmd[] = '--sql-mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
        }
        $spec = [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];
        self::$proc = proc_open($cmd, $spec, $pipes, $bin, null, ['bypass_shell' => true]);
        if (!is_resource(self::$proc)) throw new RuntimeException('could not start mysqld');
        register_shutdown_function([self::class, 'stop']);

        $cfg = ['host' => '127.0.0.1', 'port' => $port, 'root_user' => 'root', 'root_pass' => '',
                'app_user' => self::USER, 'app_pass' => self::PASS, 'external' => false, 'flavor' => $flavor, 'bin' => $bin];
        self::$cfg = $cfg;
        $deadline = microtime(true) + 120;
        $last = '';
        while (microtime(true) < $deadline) {
            try {
                new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                return $cfg;
            } catch (Throwable $e) {
                $last = $e->getMessage();
                $st = proc_get_status(self::$proc);
                if (!$st['running']) throw new RuntimeException("mysqld exited early; see $log\n" . $last);
                usleep(300000);
            }
        }
        throw new RuntimeException("mysqld did not accept connections in 120 s ($last); see $log");
    }

    private static function loadSchema(): void
    {
        $root = self::root();
        $root->exec('DROP DATABASE IF EXISTS ' . self::DB);
        $root->exec('CREATE DATABASE ' . self::DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        // loopback only; the server is private to this run
        $plugin = self::flavor() === 'mysql' ? ' WITH mysql_native_password' : '';
        foreach (['127.0.0.1', 'localhost', '%'] as $h) {
            $root->exec("CREATE USER IF NOT EXISTS '" . self::USER . "'@'$h' IDENTIFIED$plugin BY '" . self::PASS . "'");
            $root->exec("GRANT ALL ON " . self::DB . ".* TO '" . self::USER . "'@'$h'");
        }
        $root->exec('FLUSH PRIVILEGES');

        $db  = self::root(self::DB);
        $sql = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'schema.sql');
        foreach (preg_split('/^--;;\s*$/m', $sql) as $stmt) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
            if ($stmt !== '') $db->exec($stmt);
        }
    }

    /** Wipe every row but keep the schema (fast reset between scenarios). */
    public static function truncateAll(): void
    {
        $db = self::root(self::DB);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (in_array($t, ['settings', 'users'], true)) continue;      // settings + admin are managed by the harness
            $db->exec("TRUNCATE TABLE `$t`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public static function stop(): void
    {
        if (self::$proc === null) return;
        $c = self::$cfg;
        if ($c && !$c['external'] && !empty($c['bin'])) {
            $admin = self::findTool('mysqladmin', $c['bin']);
            if ($admin) self::run([$admin, '--no-defaults', '-h127.0.0.1', '-P' . $c['port'], '-uroot', 'shutdown']);
        }
        $st = @proc_get_status(self::$proc);
        for ($i = 0; $i < 60 && $st && $st['running']; $i++) { usleep(250000); $st = proc_get_status(self::$proc); }
        if ($st && $st['running']) self::run(['taskkill', '/PID', (string)$st['pid'], '/T', '/F']);
        @proc_close(self::$proc);
        self::$proc = null;
        @unlink(self::tmp() . DIRECTORY_SEPARATOR . 'server.pid');
    }
}
