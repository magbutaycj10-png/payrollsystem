<?php
/*
 * 10 - Raw biometric punches → hours (api/rollup-punches.php), the step before pay.
 *
 * Rules (Settings): shift starts 08:00, 15 minutes' grace, a 60-minute unpaid break (taken off a day longer than half
 * the duty day), duty day 8 h (or the employee's own, e.g. 10). Hours beyond the duty day are overtime; a lone punch is an
 * incomplete day (0 h, for manual correction). Expected values below are worked out by hand, not with the app's code.
 */

T::suite('10 · Punch roll-up (api/rollup-punches.php)', function () {

    /** punches [employee, 'Y-m-d H:i'...] -> the roll-up rows keyed "emp|date" */
    $rollup = function (array $punches, array $emp = []) {
        Fixtures::reset();
        $id = Fixtures::employee($emp + ['emp_id' => 'QA-ROLL', 'full_name' => 'Roll Up', 'base_salary' => '500.00']);
        $pid = Fixtures::period('Apr 1-15, 2026', '2026-04-01', '2026-04-15');
        $ins = getDB()->prepare('INSERT INTO attendance_logs (employee_id, punch_time, punch_state, device_id, source) VALUES (?,?,0,?,?)');
        foreach ($punches as $when) $ins->execute([$id, $when, 'QA', 'test']);
        $r = Http::call('api/rollup-punches.php', ['method' => 'GET', 'query' => ['period_id' => $pid], 'session' => Http::admin()]);
        $rows = [];
        foreach ($r['json']['rows'] ?? [] as $row) $rows[$row['att_date']] = $row;
        return [$rows, $r];
    };

    T::test('a normal day 08:00–17:00 = 8 h worked, no overtime, not late (9 h on the clock − 1 h break)', function (T $t) use ($rollup) {
        [$rows, $r] = $rollup(['2026-04-01 08:00:00', '2026-04-01 17:00:00']);
        $t->same(true, $r['json']['ok'] ?? null, $r['body']);
        $t->eq(8.0, $rows['2026-04-01']['hours_worked']);
        $t->eq(0.0, $rows['2026-04-01']['overtime_hours']);
        $t->eq(0.0, $rows['2026-04-01']['late_hours']);
    });

    T::test('08:20 in, 17:30 out: 8 h worked, 0.17 h overtime (10 min), late 0.33 h (20 min)', function (T $t) use ($rollup) {
        [$rows] = $rollup(['2026-04-01 08:20:00', '2026-04-01 17:30:00']);
        $t->eq(8.0, $rows['2026-04-01']['hours_worked']);
        $t->eq(0.17, $rows['2026-04-01']['overtime_hours']);
        $t->eq(0.33, $rows['2026-04-01']['late_hours']);
    });

    T::test('arriving within the 15-minute grace is not late; one minute past it is late for the whole 16 minutes', function (T $t) use ($rollup) {
        [$a] = $rollup(['2026-04-01 08:15:00', '2026-04-01 17:15:00']);
        $t->eq(0.0, $a['2026-04-01']['late_hours'], '08:15 is inside the grace');
        [$b] = $rollup(['2026-04-01 08:16:00', '2026-04-01 17:16:00']);
        $t->eq(0.27, $b['2026-04-01']['late_hours'], '08:16 → 16 minutes late');
    });

    T::test('a lone punch is an incomplete day with zero hours, flagged for correction', function (T $t) use ($rollup) {
        [$rows] = $rollup(['2026-04-01 08:00:00']);
        $t->eq(0.0, $rows['2026-04-01']['hours_worked']);
        $t->same(true, $rows['2026-04-01']['incomplete']);
    });

    T::test('a 10-hour duty day (08:00–19:00): 10 h worked, no overtime', function (T $t) use ($rollup) {
        [$rows] = $rollup(['2026-04-01 08:00:00', '2026-04-01 19:00:00'], ['hours_per_day' => '10.00']);
        $t->eq(10.0, $rows['2026-04-01']['hours_worked']);
        $t->eq(0.0, $rows['2026-04-01']['overtime_hours']);
    });

    T::test('a half day 08:00–12:00 pays 4 h', function (T $t) use ($rollup) {
        [$rows] = $rollup(['2026-04-01 08:00:00', '2026-04-01 12:00:00']);
        $t->eq(4.0, $rows['2026-04-01']['hours_worked']);
    });

    T::test('working longer never pays fewer hours: a half day that runs 6 minutes past 4 hours must not drop to 3.1 h', function (T $t) use ($rollup) {
        [$a] = $rollup(['2026-04-01 08:00:00', '2026-04-01 12:00:00']);
        [$b] = $rollup(['2026-04-01 08:00:00', '2026-04-01 12:06:00']);
        $t->ok((float)$b['2026-04-01']['hours_worked'] >= (float)$a['2026-04-01']['hours_worked'],
            sprintf('4:00 on the clock → %.2f h, 4:06 on the clock → %.2f h (the 1-hour break is deducted from any day longer than half the duty day)',
                $a['2026-04-01']['hours_worked'], $b['2026-04-01']['hours_worked']));
    }, ['defect' => 'D-17']);

    T::test('the pay that follows: a punched 08:20–17:30 day for a ₱500 employee = ₱500 + 0.17 h × ₱45 overtime', function (T $t) use ($rollup) {
        [$rows] = $rollup(['2026-04-01 08:20:00', '2026-04-01 17:30:00']);
        $emp = Fixtures::empRow('QA-ROLL');
        $pid = (int)getDB()->query('SELECT id FROM payroll_periods LIMIT 1')->fetchColumn();
        $r = Fixtures::days($pid, [Fixtures::day('Roll Up', '2026-04-01', $rows['2026-04-01']['hours_worked'], $rows['2026-04-01']['overtime_hours'], $rows['2026-04-01']['late_hours'])]);
        $t->same(true, $r['success'] ?? null, json_encode($r));
        $row = Fixtures::payroll($pid)['QA-ROLL'];
        $t->money('507.65', $row['gross_pay'], '500.00 + 0.17 × 45.00 = 7.65');   // 0.17 × 45 = 7.65
    });
});
