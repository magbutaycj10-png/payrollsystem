<?php
/*
 * 02 — Hours and calendar: the building blocks the pay engine stands on.
 * undertime rounding, duty-day hours, working days, leave days, absence classification,
 * and "is this the month's last pay run?".
 */

T::suite('02 · Hours & calendar primitives', function () {
    Fixtures::reset();

    /* ================================================================== working days */

    T::test('working days in a month (Sunday off): Feb 2026 = 24, Mar 2026 = 26, Apr 2026 = 26, leap Feb 2028 = 25', function (T $t) {
        $t->same(24, workingDaysInMonth('2026-02-10'));
        $t->same(26, workingDaysInMonth('2026-03-31'));
        $t->same(26, workingDaysInMonth('2026-04-01'));
        $t->same(25, workingDaysInMonth('2028-02-29'), '2028 is a leap year: 29 days, 4 Sundays');
    });

    T::test('working days with other rest days: Sat+Sun off in Apr 2026 = 22; no rest day = 30; Wednesday off = 25 (five Wednesdays)', function (T $t) {
        $t->same(22, workingDaysInMonth('2026-04-15', [6, 7]));
        $t->same(30, workingDaysInMonth('2026-04-15', []));
        $t->same(25, workingDaysInMonth('2026-04-15', [3]));
    });

    T::test('the engine\'s working-day count equals an independent count for every month of 2026-2028 and 9 rest-day patterns', function (T $t) {
        $patterns = [[7], [6, 7], [], [1], [3, 4], [7, 1], [5], [2, 6], [1, 2, 3, 4, 5, 6, 7]];
        for ($y = 2026; $y <= 2028; $y++) for ($m = 1; $m <= 12; $m++) foreach ($patterns as $rest) {
            $date = sprintf('%d-%02d-10', $y, $m);
            $t->same(Ledger::workingDays($date, $rest), workingDaysInMonth($date, $rest), "$date rest " . json_encode($rest));
        }
    });

    T::test('restDayList / restDayLabel read "6,7", blanks and rubbish safely', function (T $t) {
        $t->same([6, 7], restDayList('6,7'));
        $t->same([7], restDayList(null), 'unknown = Sunday, as the pay computation always assumed');
        $t->same([], restDayList(''), 'blank = no fixed day off');
        $t->same([1, 3], restDayList('1,3,3,9,0,x'), 'duplicates, out-of-range and junk are dropped');
        $t->same('Sat, Sun', restDayLabel('7,6'));
        $t->same('None', restDayLabel(''));
    });

    /* ================================================================== leave days */

    T::test('leave days: calendar days vs duty days (days off are not leave)', function (T $t) {
        // Mon 13 Apr – Wed 15 Apr 2026
        $t->same(['calendar' => 3, 'duty' => 3], leaveDays('2026-04-13', '2026-04-15', '7'));
        // Fri 10 Apr – Mon 13 Apr: Sat+Sun in between
        $t->same(['calendar' => 4, 'duty' => 2], leaveDays('2026-04-10', '2026-04-13', '6,7'));
        $t->same(['calendar' => 1, 'duty' => 0], leaveDays('2026-04-12', '2026-04-12', '7'), 'a single Sunday is not a duty day');
        $t->same('4 days (2 duty days)', leaveDaysLabel('2026-04-10', '2026-04-13', '6,7'));
        $t->same('1 day', leaveDaysLabel('2026-04-13', '2026-04-13', '7'));
    });

    /* ================================================================== day status */

    T::test('dayStatus: worked beats everything; before hire; marked OFF; weekly rest; approved leave; absent', function (T $t) {
        $cal = ['rest' => [7], 'hired' => '2026-04-06', 'branch' => 'MAIN',
                'leave' => ['2026-04-08' => ['status' => 'Approved', 'type' => 'Vacation'],
                            '2026-04-09' => ['status' => 'Pending',  'type' => 'Vacation'],
                            '2026-04-10' => ['status' => 'Rejected', 'type' => 'Vacation'],
                            '2026-04-12' => ['status' => 'Approved', 'type' => 'Vacation']]];
        $t->same('nothired', dayStatus($cal, '2026-04-03', false), 'before the hire date');
        $t->same('worked',   dayStatus($cal, '2026-04-03', true), 'a day worked is a day worked, even before the recorded hire date');
        $t->same('worked',   dayStatus($cal, '2026-04-12', true), 'working your rest day counts as worked');
        $t->same('off',      dayStatus($cal, '2026-04-07', false, true), 'timesheet says OFF');
        $t->same('off',      dayStatus($cal, '2026-04-12', false), 'Sunday is the rest day…');
        $t->same('off',      dayStatus($cal, '2026-04-12', false), '…even with approved leave on it (leave days are duty days only)');
        $t->same('leave',    dayStatus($cal, '2026-04-08', false), 'approved leave');
        $t->same('absent',   dayStatus($cal, '2026-04-09', false), 'pending leave does not excuse an absence');
        $t->same('absent',   dayStatus($cal, '2026-04-10', false), 'rejected leave does not excuse an absence');
        $t->same('absent',   dayStatus($cal, '2026-04-14', false), 'plain absence');
        $t->same('absent',   dayStatus(null, '2026-04-14', false), 'unknown employee, weekday → absent');
        $t->same('off',      dayStatus(null, '2026-04-12', false), 'unknown employee, Sunday → off');
    });

    /* ================================================================== undertime */

    T::test('dayUndertime: the timesheet\'s own figure wins; otherwise the shortfall to the nearest whole hour', function (T $t) {
        $d = fn($h, $u = null) => ['hours_worked' => $h, 'undertime_hours' => $u];
        $t->same(0.0, dayUndertime($d(8.0), 8.0));
        $t->same(0.0, dayUndertime($d(9.37), 8.0), 'a long day is still one day');
        $t->same(0.0, dayUndertime($d(7.58), 8.0), '7:35 of 8 → 0 (the code comment\'s own example)');
        $t->same(1.0, dayUndertime($d(7.45), 8.0), '7:27 of 8 → 1');
        $t->same(1.0, dayUndertime($d(7.0), 8.0));
        $t->same(4.0, dayUndertime($d(4.0), 8.0), 'a half day loses four hours');
        $t->same(8.0, dayUndertime($d(0.4), 8.0), 'a few minutes in a day: the whole day is lost');
        $t->same(2.0, dayUndertime($d(8.0, 2), 8.0), 'the sheet says 2 hours short even though 8 were worked');
        $t->same(0.0, dayUndertime($d(5.0, 0), 8.0), 'the sheet says 0 → 0, whatever the hours');
        $t->same(8.0, dayUndertime($d(8.0, 11), 8.0), 'never more than the day itself');
        $t->same(0.0, dayUndertime($d(8.0, -2), 8.0), 'never negative');
        $t->same(1.0, dayUndertime($d(9.0), 10.0), 'a 10-hour duty day: 9 h worked → 1 h short');
        $t->same(0.0, dayUndertime($d(9.55), 10.0));
        $t->same(1.0, dayUndertime($d(9.45), 10.0));
    });

    T::test('dayUndertime: exactly half an hour short rounds the shortfall UP (7:30 of 8 → 1 h) — matches the pharmacy\'s second sheet format', function (T $t) {
        // The pharmacy's workbook has two formulas. The ROLLY/JASH sheets round the SHORTFALL (30 min → 1 h);
        // the BERNA sheets round the hours WORKED (7:30 → 8 → 0 h). The app follows the first. Recorded so a change is noticed.
        $t->same(1.0, dayUndertime(['hours_worked' => 7.5, 'undertime_hours' => null], 8.0));
        $t->same(2.0, dayUndertime(['hours_worked' => 6.5, 'undertime_hours' => null], 8.0));
    });

    T::test('payHoursFromDays: paid hours = duty hours minus undertime per worked day; overtime and late are summed; days off add nothing', function (T $t) {
        $days = [
            ['hours_worked' => 8.0,  'overtime_hours' => 0, 'late_hours' => 0.25, 'undertime_hours' => null],
            ['hours_worked' => 7.4,  'overtime_hours' => 0, 'late_hours' => 0,    'undertime_hours' => null],   // 1 h short
            ['hours_worked' => 8.3,  'overtime_hours' => 1, 'late_hours' => 0,    'undertime_hours' => 0],
            ['hours_worked' => 0.0,  'overtime_hours' => 0, 'late_hours' => 0,    'undertime_hours' => null],   // a day off
            ['hours_worked' => 6.0,  'overtime_hours' => 0, 'late_hours' => 0.5,  'undertime_hours' => 2],
        ];
        $r = payHoursFromDays($days, 8.0);
        $t->same(4, $r['days']);
        $t->eq(8 + 7 + 8 + 6, $r['paid_hours']);
        $t->eq(3.0, $r['under']);
        $t->eq(1.0, $r['ot']);
        $t->eq(0.75, $r['late']);
        $t->eq(29.7, round($r['hours'], 2));
    });

    /* ================================================================== period shape */

    T::test('the month\'s LAST pay run is detected for every period shape (first half no, second half yes, monthly always, weekly by next week)', function (T $t) {
        $cases = [
            ['Semi-Monthly', '2026-04-01', '2026-04-15', false],
            ['Semi-Monthly', '2026-04-16', '2026-04-30', true],
            ['Semi-Monthly', '2026-03-16', '2026-03-31', true],
            ['Semi-Monthly', '2026-02-01', '2026-02-15', false],
            ['Semi-Monthly', '2026-02-16', '2026-02-28', true],
            ['Semi-Monthly', '2028-02-16', '2028-02-29', true],
            ['Semi-Monthly', '2026-04-16', '2026-04-25', false],   // a short second half that stops before the month's end
            ['Monthly',      '2026-04-01', '2026-04-30', true],
            ['Monthly',      '2026-04-10', '2026-05-09', true],
            ['Weekly',       '2026-04-01', '2026-04-07', false],
            ['Weekly',       '2026-04-22', '2026-04-28', true],
            ['Weekly',       '2026-04-29', '2026-05-05', true],
            ['Weekly',       '2026-04-15', '2026-04-21', false],
        ];
        foreach ($cases as [$type, $start, $end, $final]) {
            $ctx = buildPayContext(['period_start' => $start, 'period_end' => $end, 'period_type' => $type]);
            $t->same($final, $ctx['final'], "$type $start → $end");
            $t->same($final, Ledger::isFinal($type, $start, $end), "ledger agrees for $type $start → $end");
        }
    });

    T::test('period context: fraction of a month, tax table, half of the month, working days, standard hours', function (T $t) {
        $a = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-15', 'period_type' => 'Semi-Monthly']);
        $t->eq(0.5, $a['fraction']);
        $t->same('semi', $a['tax_table']);
        $t->same(1, $a['half']);
        $t->same(26, $a['working_days']);
        $t->eq(8.0, $a['standard']);
        $b = buildPayContext(['period_start' => '2026-04-16', 'period_end' => '2026-04-30', 'period_type' => 'Semi-Monthly']);
        $t->same(2, $b['half']);
        $w = buildPayContext(['period_start' => '2026-04-06', 'period_end' => '2026-04-12', 'period_type' => 'Weekly']);
        $t->eq(12 / 52, $w['fraction'], 'a week is 12/52 of a month');
        $t->same('weekly', $w['tax_table']);
        $m = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-30', 'period_type' => 'Monthly']);
        $t->eq(1.0, $m['fraction']);
        $t->same('monthly', $m['tax_table']);
        // a period with no stored type falls back to the Settings default (Semi-Monthly here)
        $d = buildPayContext(['period_start' => '2026-04-01', 'period_end' => '2026-04-15', 'period_type' => null]);
        $t->same('Semi-Monthly', $d['period_type']);
    });

    T::test('monthlyEquivalent: monthly as is, kinsenas × 2, daily × working days', function (T $t) {
        $t->eq(30000.0, monthlyEquivalent('monthly', 30000.0, 26));
        $t->eq(15000.0, monthlyEquivalent('kinsenas', 7500.0, 26));
        $t->eq(12480.0, monthlyEquivalent('daily', 480.0, 26));
        $t->eq(30000.0, monthlyEquivalent('rubbish', 30000.0, 26), 'unknown type is treated as monthly');
    });

    T::test('contribution timing falls back to the defaults for blank or unknown settings', function (T $t) {
        Fixtures::setting('contribution_timing_sss', 'bogus');
        Fixtures::setting('contribution_timing_philhealth', '');
        Fixtures::setting('contribution_timing_pagibig', 'split');
        $t->same(['sss' => 'split', 'philhealth' => 'second', 'pagibig' => 'split'], contributionTiming());
        Fixtures::reset();
    });
});
