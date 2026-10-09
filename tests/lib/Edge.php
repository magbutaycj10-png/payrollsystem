<?php
/*
 * Edge - run JavaScript tests in headless Microsoft Edge (there is no Node on this machine, and the
 * application's JavaScript - forecast models, timesheet parsing - only exists as browser scripts).
 *
 * A test page writes its results as JSON into <pre id="qa-result">; the DOM is dumped and decoded here.
 */
final class Edge
{
    public static function find(): ?string
    {
        $env = getenv('PAYROLL_TEST_EDGE');
        $cands = array_filter([$env ?: null,
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe']);
        foreach ($cands as $c) if (is_file($c)) return $c;
        return null;
    }

    /** write $html to a temp page, load it headless, return the decoded #qa-result JSON */
    public static function run(string $html, int $budgetMs = 30000): array
    {
        $edge = self::find() ?? throw new RuntimeException('no Edge/Chrome found (set PAYROLL_TEST_EDGE)');
        $dir = TestDb::tmp() . DIRECTORY_SEPARATOR . 'js';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $page = $dir . DIRECTORY_SEPARATOR . 'page-' . getmypid() . '-' . mt_rand(1000, 9999) . '.html';
        file_put_contents($page, $html);
        $cmd = [$edge, '--headless', '--disable-gpu', '--no-first-run', '--disable-extensions', '--allow-file-access-from-files',
                '--user-data-dir=' . $dir . DIRECTORY_SEPARATOR . 'profile', '--virtual-time-budget=' . $budgetMs,
                '--dump-dom', 'file:///' . str_replace('\\', '/', $page)];
        $proc = proc_open($cmd, [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) { @unlink($page); throw new RuntimeException('could not start the browser'); }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
        @unlink($page);
        if (!preg_match('/<pre id="qa-result">(.*?)<\/pre>/s', (string)$out, $m)) {
            throw new RuntimeException('the test page produced no result (browser output ' . strlen((string)$out) . ' bytes)');
        }
        $json = json_decode(htmlspecialchars_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        if (!is_array($json)) throw new RuntimeException('result is not JSON: ' . substr($m[1], 0, 200));
        return $json;
    }

    /** a page that loads the given script files (inlined), the DOM stubs the app's scripts expect, then $testJs */
    public static function page(array $scriptFiles, string $testJs, array $input = []): string
    {
        $scripts = '';
        foreach ($scriptFiles as $f) {
            $src = (string)file_get_contents($f);
            $scripts .= "<script>\n" . str_replace('</script', '<\/script', $src) . "\n</script>\n";
        }
        return "<!doctype html><html><head><meta charset=\"utf-8\"></head><body>\n"
            . "<div id=\"fcStatus\"></div><div id=\"fcError\"></div><div id=\"fcContent\"></div>\n"
            . "<pre id=\"qa-result\">pending</pre>\n"
            . "<script>window.QA = " . self::json($input) . ";</script>\n"
            . "<script>\n" . self::STUB_BASE . "\n" . self::STUB_DOM . "\n</script>\n$scripts"
            . "<script>\n" . self::HARNESS . "\n</script>\n"
            . "<script>\ntry {\n$testJs\n} catch (e) { window.QA_ERRORS.push('test script stopped: ' + (e && e.stack || e)); }\n</script>\n"
            . "<script>" . self::FINISH . "</script>\n</body></html>";
    }

    /**
     * A REAL page (the HTML a PHP page produced) with the tests added to it. $inline names scripts the page loads by `src`
     * and what to run instead (a file's source, or stub code for a CDN library), since a test page has no web server.
     * Unlike page() it keeps the real DOM: the application's own elements are there for the test to read.
     */
    public static function wrap(string $html, string $testJs, array $input = [], array $inline = []): string
    {
        foreach ($inline as $src => $code) {
            $js = str_replace('</script', '<\/script', (string)$code);
            $html = preg_replace_callback('#<script\b[^>]*\bsrc=["\']' . preg_quote($src, '#') . '["\'][^>]*>\s*</script>#i', fn() => "<script>\n$js\n</script>", $html, 1);
        }
        $head = "<script>window.QA = " . self::json($input) . ";</script>\n<script>\n" . self::STUB_BASE . "\n</script>\n";
        $html = preg_replace_callback('#<head\b[^>]*>#i', fn($m) => $m[0] . "\n" . $head, $html, 1);
        $tail = "<pre id=\"qa-result\">pending</pre>\n<script>\n" . self::HARNESS . "\n</script>\n"
              . "<script>\ntry {\n$testJs\n} catch (e) { window.QA_ERRORS.push('test script stopped: ' + (e && e.stack || e)); }\n</script>\n"
              . "<script>" . self::FINISH . "</script>\n";
        return preg_replace_callback('#</body>#i', fn() => $tail . '</body>', $html, 1);
    }

    private static function json(array $input): string
    {
        return json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** collects uncaught errors; every test page has it */
    private const STUB_BASE = <<<'JS'
window.QA_ERRORS = [];
window.addEventListener('error', e => window.QA_ERRORS.push(String(e.message)));
JS;

    /** the application's scripts talk to page elements that do not exist on a bare test page: hand them a harmless stand-in */
    private const STUB_DOM = <<<'JS'
(function () {
  const dummy = new Proxy(function () {}, { get: (t, k) => (k === Symbol.toPrimitive ? () => '' : dummy), apply: () => dummy, set: () => true, construct: () => dummy });
  const real = document.getElementById.bind(document);
  document.getElementById = id => real(id) || dummy;
  document.querySelectorAll = () => [];
  document.querySelector = () => dummy;
  window.fetch = () => Promise.reject(new Error('no network in tests'));
})();
JS;

    /** write the result once every asynchronous test (ta) has settled */
    private const FINISH = "Promise.all(QA_P).then(() => { document.getElementById('qa-result').textContent = JSON.stringify({ tests: QA_R, out: QA_OUT, errors: window.QA_ERRORS }); });";

    private const HARNESS = <<<'JS'
const QA_R = [];
const QA_P = [];
const QA_OUT = {};
function t(name, fn, defect) {
  try { fn(); QA_R.push({ name, ok: true, defect: defect || null, msg: '' }); }
  catch (e) { QA_R.push({ name, ok: false, defect: defect || null, msg: String((e && e.message) || e) }); }
}
/* an asynchronous test: fn may await. Async tests run one after another (they share the page); the page waits for all of
   them before writing the result */
let QA_CHAIN = Promise.resolve();
function ta(name, fn, defect) {
  QA_CHAIN = QA_CHAIN.then(fn).then(
    () => { QA_R.push({ name, ok: true, defect: defect || null, msg: '' }); },
    e => { QA_R.push({ name, ok: false, defect: defect || null, msg: String((e && e.message) || e) }); });
  QA_P.push(QA_CHAIN);
}
/* poll until cond() is true (virtual time: no real waiting) */
async function until(cond, what, ms) {
  for (let i = 0; i < (ms || 400); i++) { if (cond()) return; await new Promise(r => setTimeout(r, 25)); }
  throw new Error('timed out waiting for ' + (what || 'the page'));
}
function same(a, b, msg) {
  if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error((msg ? msg + ': ' : '') + 'expected ' + JSON.stringify(b) + ', got ' + JSON.stringify(a));
}
function near(a, b, tol, msg) {
  if (!(Math.abs(a - b) <= tol)) throw new Error((msg ? msg + ': ' : '') + 'expected ' + b + ' (±' + tol + '), got ' + a);
}
function truthy(c, msg) { if (!c) throw new Error(msg || 'expected true'); }
JS;
}
