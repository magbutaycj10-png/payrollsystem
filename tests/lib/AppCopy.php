<?php
/*
 * AppCopy - the suite runs the application from a fresh COPY of payroll2/, never from the original:
 *
 *   • includes/db.php auto-discovers payroll2/ca.pem (the Aiven certificate) and then insists on
 *     SSL, which a local test database cannot offer. The copy leaves every *.pem out.
 *   • includes/errors.php writes payroll_error.log two folders above includes/. In the copy that is
 *     tests/.tmp, so test noise never lands in the real log.
 *   • nothing a test does can modify the real source tree.
 *
 * The copy is rebuilt on every run, so the tests always see the code as it is right now.
 */
final class AppCopy
{
    private static string $root = '';

    /**
     * The application tree under test: the payroll2 folder next to tests/ unless PAYROLL_APP_SRC / --app=<folder> names
     * another copy (an older one, to watch the audit's defects happen). The suite never edits it.
     */
    public static function original(): string
    {
        $env = getenv('PAYROLL_APP_SRC');
        if ($env !== false && $env !== '') {
            $p = $env;
            $real = realpath($p);
            if ($real === false || !is_file($real . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'helpers.php')) {
                throw new RuntimeException("PAYROLL_APP_SRC / --app does not point at an app folder (no includes/helpers.php): $p");
            }
            return $real;
        }
        return realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'payroll2');
    }

    /** true when the tree under test carries the 2026-10-07 audit fixes (they define PAYROLL_AUDIT_FIXES) - false only for an older copy */
    public static function hasFixes(): bool { return defined('PAYROLL_AUDIT_FIXES'); }

    public static function root(): string { return self::$root ?: throw new RuntimeException('AppCopy::prepare() not called'); }

    public static function prepare(): string
    {
        $src  = self::original();
        $base = TestDb::tmp() . DIRECTORY_SEPARATOR . 'app';
        $dst  = $base . DIRECTORY_SEPARATOR . 'payroll2';
        self::rmtree($base);
        mkdir($dst, 0777, true);
        $skip = '/\.(pem|key|log|env)$/i';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($src) + 1);
            if (preg_match($skip, $rel)) continue;
            if ($f->isDir()) { @mkdir($dst . DIRECTORY_SEPARATOR . $rel, 0777, true); continue; }
            copy($f->getPathname(), $dst . DIRECTORY_SEPARATOR . $rel);
        }
        self::$root = realpath($dst);
        if ($m = getenv('QA_MUTATION')) Mutations::apply($m, self::$root);   // self-check of the suite, see Mutations.php
        return self::$root;
    }

    private static function rmtree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
