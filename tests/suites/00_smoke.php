<?php
/* 00 - is the test rig itself sound? If these fail, nothing else means anything. */

T::suite('00 · Test rig', function () {

    T::test('runs against the private test database, never the live one', function (T $t) {
        $t->same('127.0.0.1', DB_HOST);
        $t->same('payroll_test', DB_NAME);
        $t->same('payroll_test', (string)getDB()->query('SELECT DATABASE()')->fetchColumn());
    });

    T::test('the app created the rest of its schema (applySchemaPatches)', function (T $t) {
        $have = getDB()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'leave_requests', 'biometric_daily', 'period_audit', 'document_series', 'payslip_signatures'] as $tbl) {
            $t->ok(in_array($tbl, $have, true), "table $tbl exists");
        }
        $cols = getDB()->query("SHOW COLUMNS FROM payroll")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['undertime_hours', 'undertime_deduction', 'days_off', 'absent_deduction', 'revised_after_finalize'] as $c) {
            $t->ok(in_array($c, $cols, true), "payroll.$c exists");
        }
    });

    T::test('an endpoint can be called end to end (create period → upload a day → payroll row)', function (T $t) {
        Fixtures::reset();
        $emp = Fixtures::employee(['full_name' => 'Smoke Test', 'base_salary' => '500.00']);
        $pid = Fixtures::period('Smoke 1-15', '2026-04-01', '2026-04-15');
        $r = Fixtures::days($pid, [Fixtures::day('Smoke Test', '2026-04-01', 8)]);
        $t->same(true, $r['success'] ?? null, 'upload accepted: ' . json_encode($r));
        $t->same([], $r['warnings'], 'endpoint ran without PHP warnings');
        $row = Fixtures::payroll($pid)[$emp] ?? null;
        $t->ok($row !== null, 'a payroll line exists');
        $t->money('500.00', $row['gross_pay'], 'one day at ₱500');
    });

    T::test('endpoints refuse a visitor who is not signed in', function (T $t) {
        $r = Http::call('api/save-daily-attendance.php', ['method' => 'POST', 'body' => '{}', 'session' => []]);
        $t->same(401, $r['status']);
    });
});
