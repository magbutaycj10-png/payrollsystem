<?php
/*
 * Runner — boots the private database, loads the app from a clean copy, runs suites/*.php
 * and prints the report. Started by tests/run-tests.bat (see lib/Main.php).
 */
final class Runner
{
    public static function main(array $argv): int
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        if (function_exists('sapi_windows_vt100_support')) @sapi_windows_vt100_support(STDOUT, true);

        $opts = self::parse($argv);
        $suites = glob(dirname(__DIR__) . '/suites/*.php') ?: [];
        sort($suites);

        if ($opts['app'] !== '') putenv('PAYROLL_APP_SRC=' . $opts['app']);
        if ($opts['clean']) { self::rmtree(TestDb::tmp()); echo "removed tests/.tmp (the private database and scratch files; recreated on the next run)\n"; return 0; }
        if ($opts['list']) { foreach ($suites as $s) echo basename($s, '.php') . "\n"; return 0; }
        if ($opts['mutate'] !== '') return Mutations::check($opts['mutate'] === '1' ? [] : explode(',', $opts['mutate']));
        if ($opts['suite'] !== '') $suites = array_values(array_filter($suites, fn($s) => stripos(basename($s), $opts['suite']) !== false));
        T::$filter  = $opts['filter'];
        T::$verbose = $opts['verbose'];

        echo "\033[1mL&N Payroll — integration tests\033[0m\n";
        echo '  PHP ' . PHP_VERSION . ' · ' . PHP_OS_FAMILY . ' · ' . date('Y-m-d H:i') . "\n";

        try {
            $dbInfo = TestDb::start();
        } catch (Throwable $e) {
            fwrite(STDERR, "\n\033[1;31mCould not start the test database:\033[0m " . $e->getMessage() . "\n");
            return 2;
        }
        echo "  database: $dbInfo\n";

        // the app reads its database from the environment — point it at the private server, nothing else
        foreach (TestDb::env() as $k => $v) { putenv("$k=$v"); $_ENV[$k] = $v; }
        $appRoot = AppCopy::prepare();
        putenv('QA_APP_ROOT=' . $appRoot);
        require_once $appRoot . '/includes/helpers.php';
        ini_set('display_errors', '1');
        ini_set('error_log', TestDb::tmp() . DIRECTORY_SEPARATOR . 'runner-errors.log');

        // hard stop if anything points anywhere but the private test database
        if (!in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true) || DB_NAME !== TestDb::DB) {
            fwrite(STDERR, "\n\033[1;31mREFUSING TO RUN:\033[0m the app is configured for " . DB_HOST . '/' . DB_NAME . ", not the test database.\n");
            TestDb::stop();
            return 2;
        }

        set_error_handler(function ($no, $str, $file, $line) {
            if (!(error_reporting() & $no)) return true;
            T::$warnings[] = [$no, $str, $file, $line];
            return true;
        });

        applySchemaPatches();               // the app creates the rest of its tables itself
        Fixtures::reset();
        echo '  app: ' . AppCopy::original() . '  — ' . (AppCopy::hasFixes() ? "\033[32mwith the 2026-10-07 audit fixes\033[0m" : "\033[33moriginal code (no audit fixes)\033[0m")
            . "  (tested from a fresh copy, without *.pem)\n";

        Defects::register();
        foreach ($suites as $file) {
            self::load($file);
        }

        $code = T::summary($opts['strict']);
        TestDb::stop();
        return $code;
    }

    private static function rmtree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    private static function load(string $file): void
    {
        require $file;
    }

    private static function parse(array $argv): array
    {
        $o = ['suite' => '', 'filter' => '', 'strict' => false, 'list' => false, 'verbose' => false, 'mutate' => '', 'clean' => false, 'app' => ''];
        foreach (array_slice($argv, 1) as $a) {
            if (str_starts_with($a, '--app=')) $o['app'] = substr($a, 6);
            elseif ($a === '--strict') $o['strict'] = true;
            elseif ($a === '--list') $o['list'] = true;
            elseif ($a === '--clean') $o['clean'] = true;
            elseif ($a === '-v') $o['verbose'] = true;
            elseif ($a === '--mutation-check') $o['mutate'] = '1';
            elseif (str_starts_with($a, '--mutation-check=')) $o['mutate'] = substr($a, 17);
            elseif (str_starts_with($a, '--suite=')) $o['suite'] = substr($a, 8);
            elseif (str_starts_with($a, '--filter=')) $o['filter'] = substr($a, 9);
        }
        return $o;
    }
}
