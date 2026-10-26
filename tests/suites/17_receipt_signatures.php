<?php
/*
 * 17 - The signatures on the receipts (2026-10-26).
 *
 *   A  Settings > Authorised Signatory: the real name, position and drawn signature of the admin are saved only with the admin's own
 *      password; the general settings form can no longer change them; wrong passwords count and lock
 *   B  both receipts (payslip and bonus / deduction) print that name instead of "Admin", and the admin's signature only once the pay
 *      period is finalized - never on a blank copy; the employee portal's copy carries it too
 *   C  the employee e-signs the bonus / deduction receipt (admin and manager pages): only after the period is finalized, only for
 *      the manager's own employees, and a signature for another amount no longer counts
 *   D  a database set up before this update gets the two new tables on the first page
 */

/** a 1 x 1 PNG, standing in for a drawn signature */
const QA17_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const QA17_PASS = 'QaSign#2026';

/** a manager account with the given scope: 'Global', or an employee_code to see only that employee */
function qa17_manager(string $scope = 'Global'): array
{
    $db = getDB();
    $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES ('Mgr17', ?, 'x', 'Manager')")->execute(['mgr17-' . uniqid() . '@test']);
    $id = (int)$db->lastInsertId();
    if ($scope === 'Global') {
        $db->prepare("INSERT INTO user_scopes (user_id, scope_type) VALUES (?, 'Global')")->execute([$id]);
    } else {
        $db->prepare("INSERT INTO user_scopes (user_id, scope_type, employee_id) VALUES (?, 'Employee', (SELECT id FROM employees WHERE employee_code = ?))")->execute([$id, $scope]);
    }
    return Http::manager($id, 'Mgr17', '');
}

/** the admin knows their password; returns the settings.php answer for a signatory form */
function qa17_signatory(array $f, string $pass = QA17_PASS): array
{
    return Http::page('settings.php', $f + ['signatory_action' => 'save', 'sig_password' => $pass]);
}

/** one employee with a finished-days period, a Holiday Bonus recorded in it; returns [emp_code, period_id, payroll_id, adjustment_id] */
function qa17_scene(bool $finalize = false): array
{
    Fixtures::reset();
    $db = getDB();
    $db->prepare("UPDATE users SET password_hash = ? WHERE role = 'Admin'")->execute([password_hash(QA17_PASS, PASSWORD_BCRYPT)]);
    $emp = Fixtures::employee(['full_name' => 'Bon Us', 'base_salary' => '500.00']);
    $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
    Fixtures::days($pid, Fixtures::fullDays('Bon Us', '2026-04-01', '2026-04-15'));
    Http::page('adjustments.php', ['period_id' => $pid, 'emp_ids' => [$emp], 'entry_type' => 'Bonus', 'amount' => '1000', 'reason_select' => 'Holiday Bonus']);
    if ($finalize) qa17_finalize($pid);
    return [$emp, $pid, (int)$db->query("SELECT id FROM payroll WHERE period_id = $pid")->fetchColumn(),
            (int)$db->query('SELECT id FROM bonus_deductions ORDER BY id DESC LIMIT 1')->fetchColumn()];
}

function qa17_finalize(int $pid): void
{
    Http::api('update-payroll.php', ['action' => 'finalize', 'period_id' => $pid, 'ignore_drift' => true, 'allow_negative' => true]);
}

/** the sign-adjustment page answering a fetch(): the signature of one entry */
function qa17_sign(string $page, int $adjId, ?array $session = null, string $png = QA17_PNG): array
{
    return Http::call($page, ['method' => 'POST', 'body' => json_encode(['adjustment_id' => $adjId, 'signature_data' => $png]),
                              'session' => $session ?? Http::admin(), 'server' => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']]);
}

T::suite('17 · Signatures on the receipts', function () {
    $adminImg = 'alt="Authorised signatory signature"';
    $empImg   = 'alt="Employee signature"';
    /** the name line of the right-hand signature box of a bonus receipt */
    $nameUnder = fn(string $html, string $role) => preg_match('#<div class="nm">([^<]*)</div>\s*<div class="rl">[^<]*' . preg_quote($role, '#') . '#', $html, $m) ? $m[1] : null;

    /* ================================================================== A · Settings > Authorised Signatory */

    T::test('A - the signatory\'s name, position and drawn signature are saved only with the admin\'s own password', function (T $t) {
        qa17_scene();
        $db = getDB();
        $r = qa17_signatory(['sig_name' => 'Maria L. Reyes', 'sig_position' => 'HR Manager', 'sig_data' => QA17_PNG], 'not-the-password');
        $t->contains('password is incorrect', $r['body'], 'a wrong password is refused, in words');
        $t->same('QA Signatory', getSetting('bir_signatory_name'), 'name unchanged');
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM admin_signature')->fetchColumn(), 'no signature stored');

        $r = qa17_signatory(['sig_name' => '  Maria   L. Reyes ', 'sig_position' => 'HR Manager', 'sig_data' => QA17_PNG]);
        $t->contains('Signatory saved', $r['body'], 'the right password saves');
        $db2 = getDB();
        $now = $db2->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bir_signatory_name','bir_signatory_position')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $t->same('Maria L. Reyes', $now['bir_signatory_name'] ?? null, 'the name, with the stray spaces tidied');
        $t->same('HR Manager', $now['bir_signatory_position'] ?? null);
        $t->same(QA17_PNG, $db2->query('SELECT signature_data FROM admin_signature WHERE id = 1')->fetchColumn(), 'the drawn signature');
        $t->same(Http::admin()['admin_id'], (int)$db2->query('SELECT updated_by FROM admin_signature WHERE id = 1')->fetchColumn(), 'and who saved it');
        $page = Http::page('settings.php')['body'];
        $t->contains('Maria L. Reyes', $page, 'Settings shows the name');
        $t->contains('alt="Signature on file"', $page, 'and the signature on file');
    });

    T::test('A - a name change alone keeps the signature; junk is refused; the general Save All Settings form cannot touch the signatory', function (T $t) {
        qa17_scene();
        $db = getDB();
        qa17_signatory(['sig_name' => 'Maria L. Reyes', 'sig_position' => 'HR Manager', 'sig_data' => QA17_PNG]);

        $r = qa17_signatory(['sig_name' => 'Maria Reyes-Cruz', 'sig_position' => 'Payroll Head', 'sig_data' => '']);
        $t->contains('Signatory saved', $r['body']);
        $t->same('Maria Reyes-Cruz', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_name'")->fetchColumn(), 'new name');
        $t->same(QA17_PNG, $db->query('SELECT signature_data FROM admin_signature WHERE id = 1')->fetchColumn(), 'the signature on file is kept when none is drawn');

        $bad = [['sig_name' => '', 'sig_data' => ''], ['sig_name' => 'X', 'sig_data' => ''], ['sig_name' => str_repeat('N', 101), 'sig_data' => ''],
                ['sig_name' => 'Valid Name', 'sig_data' => 'data:image/png;base64,@@@@'], ['sig_name' => 'Valid Name', 'sig_data' => 'data:text/html;base64,PHNjcmlwdD4=']];
        foreach ($bad as $f) {
            $r = qa17_signatory($f + ['sig_position' => 'P']);
            $t->notContains('Signatory saved', $r['body'], 'refused: ' . json_encode(array_map(fn($v) => substr($v, 0, 20), $f)));
        }
        $t->same('Maria Reyes-Cruz', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_name'")->fetchColumn(), 'name still the last good one');
        $t->same(QA17_PNG, $db->query('SELECT signature_data FROM admin_signature WHERE id = 1')->fetchColumn(), 'signature still the last good one');

        // the big form (no password) used to carry the two fields - now it must ignore them
        Http::page('settings.php', ['overtime_rate' => '45', 'bir_signatory_name' => 'Somebody Else', 'bir_signatory_position' => 'Intruder']);
        $t->same('Maria Reyes-Cruz', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_name'")->fetchColumn(), 'Save All Settings leaves the name alone');
        $t->same('Payroll Head', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_position'")->fetchColumn(), 'and the position');
        $t->notContains('name="bir_signatory_name"', Http::page('settings.php')['body'], 'the big form no longer has the field');
    });

    T::test('A - a manager (or anybody who is not the signed-in admin) cannot save the signatory', function (T $t) {
        qa17_scene();
        $r = Http::page('settings.php', ['signatory_action' => 'save', 'sig_name' => 'Intruder', 'sig_position' => 'x', 'sig_data' => '', 'sig_password' => QA17_PASS],
                        [], Http::manager(1, 'Mgr', ''));
        $t->same('QA Signatory', getSetting('bir_signatory_name'), 'a manager session never reaches Settings');
        $t->same('', trim($r['body']), 'a manager is turned away at the door: no page at all');
        // an admin session that points at no admin account
        $r = Http::page('settings.php', ['signatory_action' => 'save', 'sig_name' => 'Intruder', 'sig_position' => 'x', 'sig_data' => '', 'sig_password' => QA17_PASS],
                        [], ['logged_in' => true, 'admin' => 'x', 'admin_id' => 999999, 'last_seen' => time()]);
        $t->contains('Only the signed-in admin', $r['body']);
        $t->same('QA Signatory', (string)getDB()->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_name'")->fetchColumn());
    });

    T::test('A - five wrong passwords lock the form, and the right one is refused until the wait is over', function (T $t) {
        qa17_scene();
        $db = getDB();
        $db->exec('DELETE FROM login_attempts');
        for ($i = 0; $i < 5; $i++) qa17_signatory(['sig_name' => 'Guess Work', 'sig_position' => 'x', 'sig_data' => ''], 'wrong-' . $i);
        $r = qa17_signatory(['sig_name' => 'Guess Work', 'sig_position' => 'x', 'sig_data' => '']);
        $t->contains('Too many wrong passwords', $r['body'], 'locked, even for the right password');
        $t->same('QA Signatory', (string)$db->query("SELECT setting_value FROM settings WHERE setting_key = 'bir_signatory_name'")->fetchColumn(), 'nothing saved');
        $db->exec('DELETE FROM login_attempts');
        $t->contains('Signatory saved', qa17_signatory(['sig_name' => 'Guess Work', 'sig_position' => 'x', 'sig_data' => ''])['body'], 'after the wait it works');
    });

    /* ================================================================== B · the two receipts */

    T::test('B - the bonus receipt shows the signatory\'s real name, and the admin\'s signature only once the period is finalized', function (T $t) use ($adminImg, $nameUnder) {
        [$emp, $pid, $payId, $adjId] = qa17_scene();
        qa17_signatory(['sig_name' => 'Maria L. Reyes', 'sig_position' => 'HR Manager', 'sig_data' => QA17_PNG]);
        $print = fn(array $q = []) => Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $adjId, 'auto' => 0] + $q)['body'];

        $open = $print();
        $t->same('Maria L. Reyes', $nameUnder($open, 'Processed by'), 'the name under the admin box is the real one, not "Admin"');
        $t->notContains('<div class="nm">Admin</div>', $open, 'the account name "Admin" is not printed as the signer');
        $t->notContains($adminImg, $open, 'period still open: the admin box is empty, waiting for the finalize');

        qa17_finalize($pid);
        $done = $print();
        $t->contains($adminImg, $done, 'finalized: the admin\'s signature is on the receipt');
        $t->same(2, substr_count($done, $adminImg), 'on both the employee copy and the company copy');
        $t->same('Maria L. Reyes', $nameUnder($done, 'Processed by'));

        $blank = $print(['sig' => '0']);
        $t->notContains($adminImg, $blank, 'a blank copy carries no stored signature');
        $t->same('Maria L. Reyes', $nameUnder($blank, 'Processed by'), 'but the name is printed');

        // re-opening the period takes the signature off again: the pay can change, so the admin has not signed it yet
        getDB()->exec("UPDATE payroll_periods SET status = 'Open' WHERE id = $pid");
        $t->notContains($adminImg, $print(), 'a re-opened period is unsigned again');
    });

    T::test('B - without a signatory name the account that recorded the entry stands in (as before); with no drawn signature only the name prints', function (T $t) use ($adminImg, $nameUnder) {
        [$emp, $pid, $payId, $adjId] = qa17_scene(true);
        Fixtures::setting('bir_signatory_name', '');
        $html = Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $adjId, 'auto' => 0])['body'];
        $who  = (string)getDB()->query("SELECT u.full_name FROM bonus_deductions h JOIN users u ON u.id = h.processed_by WHERE h.id = $adjId")->fetchColumn();
        $t->ok($who !== '', 'the entry was recorded by an account with a name');
        $t->same($who, $nameUnder($html, 'Processed by'), 'fallback to the account name');
        $t->notContains($adminImg, $html, 'no drawn signature on file, nothing to stamp');
    });

    T::test('B - the payslip receipt: the real name, the signature once finalized, none on a blank copy; the employee portal\'s copy has it too', function (T $t) use ($adminImg, $nameUnder) {
        [$emp, $pid, $payId, $adjId] = qa17_scene();
        qa17_signatory(['sig_name' => 'Maria L. Reyes', 'sig_position' => 'HR Manager', 'sig_data' => QA17_PNG]);
        $print = fn(array $q = []) => Http::page('print-doc.php', [], ['doc' => 'payslip', 'payroll_id' => $payId, 'auto' => 0] + $q)['body'];

        $open = $print();
        $t->same('Maria L. Reyes', $nameUnder($open, 'Authorised signatory'), 'the real name under the admin box');
        $t->notContains($adminImg, $open, 'open period: not signed yet');

        qa17_finalize($pid);
        $done = $print();
        $t->same(2, substr_count($done, $adminImg), 'finalized: signed on both copies');
        $t->notContains($adminImg, $print(['sig' => '0']), 'blank copy: unsigned');
        $t->notContains($adminImg, Http::page('print-doc.php', [], ['doc' => 'payslip', 'period' => $pid, 'sig' => '0', 'auto' => 0])['body'], 'blank copies of the whole period: unsigned');
        $t->same(1, substr_count(Http::page('print-doc.php', [], ['doc' => 'payslip', 'period' => $pid, 'copies' => 'employee', 'auto' => 0])['body'], $adminImg), 'the whole period at once');

        $mine = Http::page('employee/print-doc.php', [], ['payroll_id' => $payId, 'auto' => 0], Http::employee($emp))['body'];
        $t->same(1, substr_count($mine, $adminImg), 'the employee portal prints the same signed receipt');
        $t->notContains($adminImg, Http::page('employee/print-doc.php', [], ['payroll_id' => $payId, 'auto' => 0, 'sig' => '0'], Http::employee($emp))['body'], 'and a blank one');
    });

    /* ================================================================== C · the employee e-signs the bonus receipt */

    T::test('C - the employee signs the bonus receipt: refused while the period is open, saved after finalize, shown on the receipt and in History', function (T $t) use ($empImg) {
        [$emp, $pid, $payId, $adjId] = qa17_scene();
        $db = getDB();
        $r = qa17_sign('sign-adjustment.php', $adjId);
        $t->same(409, $r['status'], 'open period: ' . json_encode($r['json']));
        $t->contains('still open', (string)($r['json']['message'] ?? ''));
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn(), 'nothing stored');

        qa17_finalize($pid);
        $r = qa17_sign('sign-adjustment.php', $adjId);
        $t->same(200, $r['status'], json_encode($r['json']));
        $row = $db->query('SELECT adjustment_id, amount_signed, signed_by_user FROM adjustment_signatures')->fetch();
        $t->same($adjId, (int)$row['adjustment_id']);
        $t->money('1000.00', $row['amount_signed'], 'stamped with the amount it acknowledges');
        $t->same(Http::admin()['admin_id'], (int)$row['signed_by_user'], 'and who collected it');

        $html = Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $adjId, 'auto' => 0])['body'];
        $t->same(2, substr_count($html, $empImg), 'the employee\'s signature is on both copies of the receipt');
        $t->notContains($empImg, Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $adjId, 'auto' => 0, 'sig' => '0'])['body'], 'but not on a blank copy');

        $page = Http::page('sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId])['body'];
        $t->contains('Signed ', $page, 'the sign page lists it as signed');
        $t->contains('Re-sign', $page);
        $t->ok((bool)preg_match('/badge-green"[^>]*>Signed /', Http::page('history.php')['body']), 'History shows the e-signed badge');

        // signing again replaces it, never adds a second one
        qa17_sign('sign-adjustment.php', $adjId);
        $t->same(1, (int)$db->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn(), 'one signature per entry');

        // the amount changed afterwards (it cannot in the app - this is the safety net): the old signature no longer counts
        $db->exec("UPDATE bonus_deductions SET amount = 1500 WHERE id = $adjId");
        $t->notContains($empImg, Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $adjId, 'auto' => 0])['body'], 'a signature for another amount is not printed');
        $t->ok(!preg_match('/badge-green"[^>]*>Signed /', Http::page('history.php')['body']), 'nor listed as signed');
    });

    T::test('C - junk signatures and unknown entries are refused', function (T $t) {
        [$emp, $pid, $payId, $adjId] = qa17_scene(true);
        $t->same(409, qa17_sign('sign-adjustment.php', $adjId, null, 'data:image/png;base64,@@@')['status'], 'not a PNG');
        $t->same(409, qa17_sign('sign-adjustment.php', $adjId, null, 'javascript:alert(1)')['status'], 'not a data URL');
        $t->same(404, qa17_sign('sign-adjustment.php', 987654)['status'], 'no such entry');
        $t->same(400, Http::call('sign-adjustment.php', ['method' => 'POST', 'body' => '{}', 'session' => Http::admin(), 'server' => ['HTTP_X_REQUESTED_WITH' => 'x']])['status'], 'empty request');
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn());
        $t->same('', trim(Http::call('sign-adjustment.php', ['method' => 'GET', 'session' => null])['body']), 'not signed in: no page at all (sent to the login)');
    });

    T::test('C - the manager collects the signature for the employees they manage only', function (T $t) {
        [$emp, $pid, $payId, $adjId] = qa17_scene(true);
        $other = Fixtures::employee(['full_name' => 'Other Person']);
        $mine   = qa17_manager($emp);
        $theirs = qa17_manager($other);
        $all    = qa17_manager('Global');

        $page = Http::page('manager/sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId], $mine)['body'];
        $t->contains('Holiday Bonus', $page, 'the manager sees the entry of their employee');
        $t->contains('Get Signature', $page);
        $t->notContains('Holiday Bonus', Http::page('manager/sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId], $theirs)['body'], 'another manager\'s employee: not listed');

        $t->same(404, qa17_sign('manager/sign-adjustment.php', $adjId, $theirs)['status'], 'a manager outside the scope cannot sign it either');
        $t->same(0, (int)getDB()->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn());
        $r = qa17_sign('manager/sign-adjustment.php', $adjId, $mine);
        $t->same(200, $r['status'], json_encode($r['json']));
        $t->same(1, (int)getDB()->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn(), 'saved');
        $t->contains('Signed ', Http::page('manager/sign-adjustment.php', [], ['period' => $pid], $all)['body'], 'a manager over everybody sees it signed');
    });

    T::test('C - a deduction receipt (the employee\'s Conforme) is signed the same way', function (T $t) use ($empImg) {
        [$emp, $pid, $payId, $bonusId] = qa17_scene();
        Http::page('adjustments.php', ['period_id' => $pid, 'emp_ids' => [$emp], 'entry_type' => 'Deduction', 'amount' => '200', 'reason_select' => 'Cash Advance']);
        $dedId = (int)getDB()->query("SELECT id FROM bonus_deductions WHERE type = 'Deduction'")->fetchColumn();
        $t->ok($dedId > 0, 'a deduction was recorded');
        qa17_finalize($pid);
        $t->same(200, qa17_sign('sign-adjustment.php', $dedId)['status']);
        $t->same(2, substr_count(Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $dedId, 'auto' => 0])['body'], $empImg));
        $t->notContains($empImg, Http::page('print-doc.php', [], ['doc' => 'adjustment', 'id' => $bonusId, 'auto' => 0])['body'], 'the bonus beside it is still unsigned');
    });

    T::test('C - deleting an employee\'s entry (or its period) takes its signature with it; the new pages and sidebar links render clean', function (T $t) {
        [$emp, $pid, $payId, $adjId] = qa17_scene(true);
        qa17_sign('sign-adjustment.php', $adjId);
        $db = getDB();
        $t->same(1, (int)$db->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn());
        $m = qa17_manager('Global');
        foreach (['admin' => Http::page('sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId]),
                  'admin, none picked' => Http::page('sign-adjustment.php', [], ['period' => $pid]),
                  'manager' => Http::page('manager/sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId], $m),
                  'settings' => Http::page('settings.php'), 'history' => Http::page('history.php')] as $name => $r) {
            $t->same(200, $r['status'], "$name page");
            $t->same([], $r['warnings'], "$name page: no PHP warnings " . json_encode($r['warnings']));
        }
        $t->contains('sign-adjustment.php', Http::page('home.php')['body'], 'the admin sidebar links to it');
        $t->contains('/manager/sign-adjustment.php', Http::page('manager/home.php', [], [], $m)['body'], 'and so does the manager\'s');
        $db->exec("DELETE FROM bonus_deductions WHERE id = $adjId");
        $t->same(0, (int)$db->query('SELECT COUNT(*) FROM adjustment_signatures')->fetchColumn(), 'the signature goes with its entry');
    });

    /* ================================================================== D · a database set up before this update */

    T::test('D - an existing database gets the two new tables on the first page, without losing a thing', function (T $t) {
        [$emp, $pid, $payId, $adjId] = qa17_scene(true);
        $db = getDB();
        $db->exec('DROP TABLE adjustment_signatures');
        $db->exec('DROP TABLE admin_signature');
        $db->exec("DELETE FROM settings WHERE setting_key = 'schema_version'");        // the next page looks at the schema again
        $r = Http::page('sign-adjustment.php', [], ['period' => $pid]);
        $t->same(200, $r['status'], 'the page opens');
        $have = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('adjustment_signatures','admin_signature') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        $t->same(['adjustment_signatures', 'admin_signature'], $have, 'both tables are there');
        $t->same(1, (int)$db->query('SELECT COUNT(*) FROM bonus_deductions')->fetchColumn(), 'the entries are untouched');
        $t->same(200, qa17_sign('sign-adjustment.php', $adjId)['status'], 'and signing works');
        $fk = (int)$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'adjustment_signatures'")->fetchColumn();
        $t->same(2, $fk, 'with both foreign keys');
    });

    /* ================================================================== E · the signature pad, in a real browser */

    if (!Edge::find()) {
        T::test('JS · the signature pad in a browser', fn() => T::skip('no Microsoft Edge / Chrome found - set PAYROLL_TEST_EDGE to a browser path'));
    } else {
        try {
            [$emp, $pid, $payId, $adjId] = qa17_scene(true);
            $pad  = (string)file_get_contents(AppCopy::root() . '/assets/js/sig-pad.js');
            $runs = ['Settings' => [Http::page('settings.php')['body'], 'signature-pad.test.js', []],
                     'Sign Bonus Receipt' => [Http::page('sign-adjustment.php', [], ['period' => $pid, 'adj' => $adjId])['body'], 'sign-adjustment-page.test.js', ['adj' => $adjId]]];
            foreach ($runs as $label => [$body, $file, $input]) {
                $html = Edge::wrap($body, (string)file_get_contents(__DIR__ . '/../js/' . $file), $input, ['assets/js/sig-pad.js' => $pad]);
                $res  = Edge::run($html, 60000);
                foreach ($res['tests'] as $jt) {
                    T::test("JS · $label · " . $jt['name'], function (T $t) use ($jt) {
                        $t->checks++;
                        if (!$jt['ok']) throw new AssertionFailed($jt['msg']);
                    });
                }
                if ($res['errors']) T::test("JS · $label raised no uncaught error", function (T $t) use ($res) { $t->same([], $res['errors']); });
            }
        } catch (Throwable $e) {
            T::test('JS · the signature pad in a browser', function () use ($e) { throw new AssertionFailed('could not run the page test: ' . $e->getMessage()); });
        }
    }
});
