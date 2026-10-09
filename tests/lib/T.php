<?php
/*
 * T - a tiny test framework (no Composer needed), tuned for money.
 *
 *   T::suite('name', function () { ... T::test('does x', function (T $t) { $t->money(...); }); });
 *
 * Statuses: PASS, FAIL, SKIP, and - for tests tagged with a defect id -
 *   DEFECT  the test failed, which is what it is there to prove (known bug, expected red)
 *   FIXED   a defect-tagged test now passes: the bug is gone (in the audit-fixed app: the fix works)
 * By default DEFECT does not fail the run (so a regression elsewhere stays visible);
 * `--strict` makes every confirmed defect fail the run.
 * Against the current application a defect-tagged test that FAILS is a regression of the fix,
 * so it is reported as FAIL, not DEFECT.
 */
final class AssertionFailed extends Exception {}
final class SkipTest extends Exception {}

final class T
{
    /** @var array<int,array{suite:string,name:string,status:string,msg:string,defect:?string,ms:float}> */
    public static array $results = [];
    public static string $suite = '';
    public static string $filter = '';
    public static bool $verbose = false;
    /** PHP warnings/notices raised while a test ran (collected by the runner's error handler) */
    public static array $warnings = [];
    /** defect id => ['title'=>, 'severity'=>, 'where'=>] registered by suites */
    public static array $defects = [];
    public int $checks = 0;
    private string $name = '';

    /* ------------------------------------------------------------ registry */
    public static function defect(string $id, string $severity, string $title, string $where): void
    {
        self::$defects[$id] = compact('severity', 'title', 'where');
    }

    public static function suite(string $name, callable $body): void
    {
        self::$suite = $name;
        echo "\n\033[1m== $name\033[0m\n";
        $body();
    }

    public static function test(string $name, callable $fn, array $meta = []): void
    {
        if (self::$filter !== '' && stripos(self::$suite . ' ' . $name, self::$filter) === false) return;
        $t = new self();
        $t->name = $name;
        $defect = $meta['defect'] ?? null;
        $start = microtime(true);
        $status = 'PASS';
        $msg = '';
        self::$warnings = [];
        try {
            $fn($t);
            $bad = array_filter(self::$warnings, fn($w) => !in_array($w[0], [E_DEPRECATED, E_USER_DEPRECATED], true));
            if ($bad) {
                $first = array_slice($bad, 0, 3);
                throw new AssertionFailed("PHP raised " . count($bad) . " warning(s) while running:
" . implode("
", array_map(
                    fn($w) => '  ' . $w[1] . ' @ ' . basename($w[2]) . ':' . $w[3], $first)));
            }
        } catch (SkipTest $e) {
            $status = 'SKIP';
            $msg = $e->getMessage();
        } catch (AssertionFailed $e) {
            $status = 'FAIL';
            $msg = $e->getMessage();
        } catch (Throwable $e) {
            $status = 'FAIL';
            $msg = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        if ($defect !== null) {
            // On the original application a failing [D-nn] test is the defect it documents. On the audit-fixed one the defect is
            // supposed to be gone, so the same test failing means a fix has come undone: a regression, red like any other failure.
            if ($status === 'FAIL' && AppCopy::hasFixes()) $msg = "REGRESSION - defect $defect is back\n$msg";
            elseif ($status === 'FAIL') $status = 'DEFECT';
            elseif ($status === 'PASS') {
                $status = 'FIXED';
                $msg = AppCopy::hasFixes() ? "defect $defect: fixed in this version (the original application still has it)"
                                           : "defect $defect no longer reproduces - remove the tag";
            }
        }
        $ms = (microtime(true) - $start) * 1000;
        self::$results[] = ['suite' => self::$suite, 'name' => $name, 'status' => $status, 'msg' => $msg,
                            'defect' => $defect, 'ms' => $ms, 'checks' => $t->checks];
        self::line($status, $name, $msg, $defect, $t->checks);
    }

    private static function line(string $status, string $name, string $msg, ?string $defect, int $checks): void
    {
        $color = ['PASS' => '32', 'FAIL' => '1;31', 'SKIP' => '33', 'DEFECT' => '35', 'FIXED' => '1;36'][$status] ?? '0';
        $tag = $defect ? " [$defect]" : '';
        if ($status === 'PASS' && !self::$verbose) {
            echo "  \033[{$color}m✓\033[0m $name \033[2m($checks checks)\033[0m\n";
            return;
        }
        printf("  \033[%sm%-6s\033[0m %s%s\n", $color, $status === 'PASS' ? '✓' : $status, $name, $tag);
        if ($msg !== '') foreach (explode("\n", $msg) as $l) echo "         \033[2m" . $l . "\033[0m\n";
    }

    public static function skip(string $why): never { throw new SkipTest($why); }

    /* ------------------------------------------------------------ assertions */
    private function fail(string $m): never { throw new AssertionFailed($m); }

    public function ok(bool $cond, string $msg = 'expected true'): void
    {
        $this->checks++;
        if (!$cond) $this->fail($msg);
    }

    public function same($expected, $actual, string $msg = ''): void
    {
        $this->checks++;
        if ($expected !== $actual) {
            $this->fail(($msg ? "$msg\n" : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function eq($expected, $actual, string $msg = ''): void
    {
        $this->checks++;
        if ($expected != $actual) {
            $this->fail(($msg ? "$msg\n" : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function contains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->checks++;
        if (strpos($haystack, $needle) === false) {
            $this->fail(($msg ? "$msg\n" : '') . 'expected to find "' . $needle . '"');
        }
    }

    public function notContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->checks++;
        if (strpos($haystack, $needle) !== false) $this->fail(($msg ? "$msg\n" : '') . 'did not expect "' . $needle . '"');
    }

    /**
     * Compare money to the centavo. $expected is integer centavos (Ledger) or a pesos string;
     * $actual is what the app produced (float/decimal string/int centavos is NOT accepted -
     * pass pesos). Also fails if $actual carries sub-centavo residue: a payslip must not
     * show 0.1 + 0.2 style noise.
     */
    public function money($expected, $actual, string $label = ''): void
    {
        $this->checks++;
        $e = is_int($expected) ? $expected : Ledger::c($expected);
        $raw = (float)$actual;
        $a = (int)round($raw * 100);
        if (abs($raw * 100 - $a) > 1e-6 * max(1, abs($raw))) {
            $this->fail(($label ? "$label: " : '') . 'value ' . var_export($actual, true) . ' has sub-centavo precision');
        }
        if ($e !== $a) {
            $this->fail(($label ? "$label: " : '') . 'expected ₱' . Ledger::fmt($e) . ', got ₱' . Ledger::fmt($a)
                . ' (off by ' . sprintf('%+.2f', ($a - $e) / 100) . ')');
        }
    }

    /** Compare a whole map of expected centavos to app values: ['gross' => 123456, ...] */
    public function moneyMap(array $expected, array $actual, string $prefix = ''): void
    {
        $bad = [];
        foreach ($expected as $k => $e) {
            $this->checks++;
            if (!array_key_exists($k, $actual)) { $bad[] = "$k: missing"; continue; }
            $raw = (float)$actual[$k];
            $a = (int)round($raw * 100);
            $ec = is_int($e) ? $e : Ledger::c($e);
            if (abs($raw * 100 - $a) > 1e-6 * max(1, abs($raw))) { $bad[] = "$k: sub-centavo value " . var_export($actual[$k], true); continue; }
            if ($ec !== $a) $bad[] = sprintf('%s: expected ₱%s, got ₱%s (%+.2f)', $k, Ledger::fmt($ec), Ledger::fmt($a), ($a - $ec) / 100);
        }
        if ($bad) $this->fail(($prefix ? "$prefix\n" : '') . implode("\n", $bad));
    }

    /* ------------------------------------------------------------ run summary */
    public static function summary(bool $strict): int
    {
        $c = ['PASS' => 0, 'FAIL' => 0, 'SKIP' => 0, 'DEFECT' => 0, 'FIXED' => 0];
        $checks = 0;
        foreach (self::$results as $r) { $c[$r['status']]++; $checks += $r['checks'] ?? 0; }
        echo "\n\033[1m" . str_repeat('=', 78) . "\033[0m\n";
        printf("%d tests, %d assertions: \033[32m%d passed\033[0m, \033[1;31m%d failed\033[0m, \033[35m%d defects confirmed\033[0m, \033[33m%d skipped\033[0m%s\n",
            count(self::$results), $checks, $c['PASS'], $c['FAIL'], $c['DEFECT'], $c['SKIP'],
            $c['FIXED'] ? ", \033[1;36m{$c['FIXED']} defect(s) now FIXED\033[0m" : '');

        $byDefect = [];
        foreach (self::$results as $r) if ($r['defect']) $byDefect[$r['defect']][] = $r;
        if ($byDefect) {
            echo "\n\033[1mDefect register\033[0m - problems in the application proved by the tests (red until fixed; see tests/AUDIT_FINDINGS.md)\n";
            ksort($byDefect);
            $sevColor = ['High' => '1;31', 'Medium' => '33', 'Low' => '2'];
            foreach ($byDefect as $id => $rs) {
                $d = self::$defects[$id] ?? ['severity' => '?', 'title' => '(unregistered)', 'where' => ''];
                $st = implode('/', array_unique(array_column($rs, 'status')));
                printf("  %-5s \033[%sm%-6s\033[0m %-6s %s\n         \033[2m%s\033[0m\n", $id, $sevColor[$d['severity']] ?? '0', $d['severity'], $st, $d['title'], $d['where']);
            }
        }
        if ($c['FAIL']) {
            echo "\n\033[1;31mFailures\033[0m\n";
            foreach (self::$results as $r) if ($r['status'] === 'FAIL') {
                echo "  • [{$r['suite']}] {$r['name']}\n";
                foreach (explode("\n", $r['msg']) as $l) echo "      $l\n";
            }
        }
        $bad = $c['FAIL'] + ($strict ? $c['DEFECT'] : 0);
        echo "\n" . ($bad ? "\033[1;31mRESULT: FAILED\033[0m" : "\033[1;32mRESULT: OK\033[0m")
            . ($c['DEFECT'] && !$strict ? "  (run with --strict to treat the {$c['DEFECT']} confirmed defect(s) as failures)" : '') . "\n";
        return $bad ? 1 : 0;
    }
}
