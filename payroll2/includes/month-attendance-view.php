<?php
/* A piece of a page: opened on its own it would only show errors */
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; }
/*
 * includes/month-attendance-view.php
 * The body of "This Month's Attendance" — shared by the admin, manager and
 * employee portals. The calling page sets:
 *   $MA      monthAttendance() result
 *   $maMode  'admin' | 'manager' | 'employee'
 *   $maSelf  this page's URL (month links point back to it)
 * Every figure is the sum of the days saved so far: each daily upload or
 * manual entry adds its days, and this page shows the month filling up.
 */
$h      = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$hrs    = fn($n) => rtrim(rtrim(number_format((float)$n, 2), '0'), '.');
$peso   = fn($n) => '₱' . number_format((float)$n, 2);
$today  = date('Y-m-d');
$prevYm = date('Y-m', strtotime($MA['start'] . ' -1 month'));
$nextYm = date('Y-m', strtotime($MA['start'] . ' +1 month'));
$title  = date('F Y', strtotime($MA['start']));
$emps   = $MA['emps'];
$isSelf = $maMode === 'employee';

$withDays = array_filter($emps, fn($e) => $e['days']);
$tot = ['present' => 0, 'hours' => 0, 'ot' => 0, 'late' => 0, 'under' => 0, 'manual' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0];
foreach ($emps as $e) foreach ($tot as $k => $_) $tot[$k] += $e[$k];

/* Class for one day's cell */
$cellClass = function (?array $d, float $std) {
    if (!$d) return '';
    /* a full duty day is one without undertime — the same rule the pay uses */
    $c = $d['hours'] <= 0 ? 'ma-zero' : ($d['under'] <= 0 ? 'ma-full' : 'ma-short');
    if ($d['ot'] > 0)  $c .= ' ma-ot';
    if ($d['manual'])  $c .= ' ma-manual';
    return $c;
};
$cellTitle = function (string $date, ?array $d) use ($hrs) {
    if (!$d) return date('D, M j', strtotime($date)) . ' — no record';
    return date('D, M j', strtotime($date)) . ' — ' . $hrs($d['hours']) . ' h'
        . ($d['ot'] ? ', OT ' . $hrs($d['ot']) . ' h' : '')
        . ($d['late'] ? ', late ' . $hrs($d['late']) . ' h' : '')
        . (!empty($d['under']) ? ', undertime ' . $hrs($d['under']) . ' h' : '')
        . ($d['manual'] ? ' (entered by ' . $d['manual'] . ')' : '')
        . ' · ' . $d['period'];
};
/* A day without hours: day off, leave or absent (marks from monthAttendance()) */
$marks = [
    'off'             => ['ma-off',     'OFF', 'Day off — not absent, no deduction'],
    'leave'           => ['ma-leave',   'L',   'Approved leave — not absent, no deduction'],
    'pending'         => ['ma-pending', 'L?',  'Leave request waiting for a decision'],
    'absent'          => ['ma-absent',  'A',   'Absent — no attendance and no approved leave'],
    'absent-pending'  => ['ma-absent',  'A',   'Absent — leave request still pending (approve it to excuse the day)'],
    'absent-rejected' => ['ma-absent',  'A',   'Absent — leave request was rejected, no pay for this day'],
];
?>
<style>
    .ma-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 16px; }
    .ma-bar form { display: flex; gap: 8px; align-items: center; }
    .ma-bar input[type=month] { padding: 7px 10px; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; font: inherit; background: var(--surface, #fff); }
    .ma-nav { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px;
              border: 1px solid var(--border, #e5e7eb); border-radius: 8px; background: var(--surface, #fff);
              color: inherit; text-decoration: none; font-weight: 600; }
    .ma-nav:hover { background: #f3f4f6; }
    .ma-updated { font-size: .85rem; color: var(--text-muted, #6b7280); }
    .ma-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
    .ma-chip { font-size: .74rem; padding: 3px 9px; border-radius: 999px; background: #eef2ff; color: #3730a3; white-space: nowrap; }
    .ma-chip.locked { background: #f1f5f9; color: #475569; }

    .ma-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); gap: 12px; margin-bottom: 18px; }
    .ma-card { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 14px 16px; }
    .ma-card .l { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted, #6b7280); }
    .ma-card .v { font-size: 1.45rem; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
    .ma-card .s { font-size: .74rem; color: var(--text-muted, #6b7280); margin-top: 2px; }

    .ma-box { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; margin-bottom: 18px; overflow: hidden; }
    .ma-box-h { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border, #e5e7eb); }
    .ma-box-h h2 { font-size: 1rem; margin: 0; }
    .ma-box-h input[type=search] { padding: 6px 10px; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; font: inherit; min-width: 200px; }
    .ma-scroll { overflow-x: auto; }

    .ma-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
    .ma-table th, .ma-table td { padding: 9px 12px; border-bottom: 1px solid var(--border, #e5e7eb); text-align: left; white-space: nowrap; }
    .ma-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted, #6b7280); background: #f9fafb; }
    .ma-table td.n, .ma-table th.n { text-align: right; font-variant-numeric: tabular-nums; }
    .ma-table tr.ma-none td { color: #9ca3af; }

    .ma-grid { border-collapse: separate; border-spacing: 0; font-size: .76rem; }
    .ma-grid th, .ma-grid td { border-bottom: 1px solid var(--border, #e5e7eb); padding: 0; text-align: center; }
    .ma-grid th { font-weight: 600; color: var(--text-muted, #6b7280); padding: 6px 0; background: #f9fafb; min-width: 34px; }
    .ma-grid th.we { color: #b91c1c; }
    .ma-grid th.td { background: #dbeafe; color: #1e3a8a; }
    .ma-grid .who { position: sticky; left: 0; z-index: 1; background: var(--surface, #fff); text-align: left; padding: 6px 12px;
                    min-width: 170px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border-right: 1px solid var(--border, #e5e7eb); }
    .ma-grid th.who { background: #f9fafb; }
    .ma-grid .sum { padding: 6px 10px; font-weight: 700; font-variant-numeric: tabular-nums; border-left: 1px solid var(--border, #e5e7eb); }
    .ma-cell { display: block; margin: 3px; height: 26px; line-height: 26px; border-radius: 5px; font-variant-numeric: tabular-nums; }
    .ma-full  { background: #dcfce7; color: #166534; }
    .ma-short { background: #fef3c7; color: #92400e; }
    .ma-zero  { background: #f3f4f6; color: #6b7280; }
    .ma-ot    { box-shadow: inset 0 -3px 0 #2563eb; }
    .ma-manual { outline: 2px dashed #7c3aed; outline-offset: -2px; }
    .ma-off     { background: #f1f5f9; color: #64748b; font-size: .66rem; font-weight: 700; }
    .ma-leave   { background: #dbeafe; color: #1d4ed8; font-weight: 700; }
    .ma-pending { background: #fff7ed; color: #c2410c; font-weight: 700; outline: 1px dashed #fdba74; outline-offset: -1px; }
    .ma-absent  { background: #fee2e2; color: #b91c1c; font-weight: 700; }
    .ma-future { background: repeating-linear-gradient(45deg, transparent 0 4px, #f3f4f6 4px 8px); }

    .ma-legend { display: flex; flex-wrap: wrap; gap: 14px; padding: 10px 16px; font-size: .78rem; color: var(--text-muted, #6b7280); }
    .ma-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .ma-legend i { display: inline-block; width: 18px; height: 14px; border-radius: 3px; }

    .ma-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; padding: 14px 16px; }
    .ma-cal .dow { font-size: .72rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted, #6b7280); text-align: center; }
    .ma-cal .day { min-height: 64px; border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 6px 8px; font-size: .8rem; position: relative; }
    .ma-cal .day .dn { font-weight: 700; font-size: .78rem; color: var(--text-muted, #6b7280); }
    .ma-cal .day .hv { font-size: 1.05rem; font-weight: 700; margin-top: 4px; }
    .ma-cal .day .sub { font-size: .7rem; }
    .ma-cal .day.is-today { border-color: #2563eb; box-shadow: 0 0 0 1px #2563eb; }
    .ma-cal .blank { border: 0; }

    .ma-empty { padding: 28px 16px; text-align: center; color: var(--text-muted, #6b7280); }
    @media (max-width: 640px) {
        .ma-chips { margin-left: 0; }
        .ma-cal { gap: 3px; padding: 10px 8px; }
        .ma-cal .day { min-height: 52px; padding: 4px 5px; }
        .ma-cal .day .hv { font-size: .9rem; }
        .ma-cal .day .sub { display: none; }
    }
</style>

<div class="ma-bar">
    <a class="ma-nav" href="<?= $h($maSelf) ?>?month=<?= $prevYm ?>" title="Previous month">‹</a>
    <form method="GET">
        <input type="month" name="month" value="<?= $h($MA['ym']) ?>" onchange="this.form.submit()" aria-label="Month">
    </form>
    <a class="ma-nav" href="<?= $h($maSelf) ?>?month=<?= $nextYm ?>" title="Next month">›</a>
    <?php if ($MA['ym'] !== date('Y-m')): ?>
        <a class="ma-nav" href="<?= $h($maSelf) ?>">This month</a>
    <?php endif; ?>
    <span class="ma-updated">
        <?= $MA['last'] ? 'Recorded through <b>' . date('M j, Y', strtotime($MA['last'])) . '</b>' : 'Nothing recorded for ' . $h($title) . ' yet' ?>
    </span>
    <div class="ma-chips">
        <?php foreach ($MA['periods'] as $p): ?>
            <span class="ma-chip <?= $p['status'] === 'Open' ? '' : 'locked' ?>" title="<?= $h($p['period_start'] . ' to ' . $p['period_end']) ?>">
                <?= $h($p['period_label']) ?> · <?= $p['status'] === 'Open' ? 'Open' : 'Finalized' ?>
            </span>
        <?php endforeach; ?>
        <?php if (!$MA['periods']): ?><span class="ma-chip locked">No pay period for this month yet</span><?php endif; ?>
    </div>
</div>

<?php if (!$withDays): ?>
    <div class="ma-box"><div class="ma-empty">
        No attendance has been saved for <?= $h($title) ?> yet.
        <?php if (!$isSelf): ?>Each daily upload (or manual entry) adds its days here as they come in.<?php endif; ?>
        <?php if ($MA['latest'] && substr($MA['latest'], 0, 7) !== $MA['ym']): ?>
            <br><a href="<?= $h($maSelf) ?>?month=<?= substr($MA['latest'], 0, 7) ?>">Go to <?= date('F Y', strtotime($MA['latest'])) ?></a>, the latest month with records.
        <?php endif; ?>
    </div></div>
<?php else: ?>

<?php if ($isSelf):
    $me = reset($emps); ?>
    <div class="ma-cards">
        <div class="ma-card"><div class="l">Days present</div><div class="v"><?= $me['present'] ?></div><div class="s">so far in <?= $h(date('F', strtotime($MA['start']))) ?></div></div>
        <div class="ma-card"><div class="l">Hours worked</div><div class="v"><?= $hrs($me['hours']) ?></div></div>
        <div class="ma-card"><div class="l">Overtime</div><div class="v"><?= $hrs($me['ot']) ?> h</div></div>
        <div class="ma-card"><div class="l">Late</div><div class="v"><?= $hrs($me['late']) ?> h</div></div>
        <div class="ma-card"><div class="l">Undertime</div><div class="v"><?= $hrs($me['under']) ?> h</div><div class="s">short of full duty days</div></div>
        <div class="ma-card"><div class="l">Absent</div><div class="v" style="color:<?= $me['absent'] ? '#b91c1c' : 'inherit' ?>;"><?= $me['absent'] ?></div><div class="s">days, unexcused</div></div>
        <div class="ma-card"><div class="l">Leave</div><div class="v"><?= $me['leave'] ?></div><div class="s">approved days</div></div>
        <div class="ma-card"><div class="l">Days off</div><div class="v"><?= $me['off'] ?></div><div class="s">so far</div></div>
        <div class="ma-card"><div class="l">Last recorded</div><div class="v" style="font-size:1.1rem;"><?= $me['last'] ? date('M j', strtotime($me['last'])) : '—' ?></div></div>
    </div>

    <div class="ma-box">
        <div class="ma-box-h"><h2><?= $h($title) ?></h2></div>
        <div class="ma-cal">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dw): ?><div class="dow"><?= $dw ?></div><?php endforeach; ?>
            <?php for ($i = 0, $lead = (int)date('w', strtotime($MA['start'])); $i < $lead; $i++): ?><div class="day blank"></div><?php endfor; ?>
            <?php for ($n = 1; $n <= $MA['days']; $n++):
                $date = $MA['ym'] . '-' . str_pad($n, 2, '0', STR_PAD_LEFT);
                $d    = $me['days'][$n] ?? null;
                $mk   = !$d || $d['hours'] <= 0 ? ($marks[$me['marks'][$n] ?? ''] ?? null) : null; ?>
                <div class="day <?= $mk ? $mk[0] : ($d ? $cellClass($d, $me['std']) : ($date > $today ? 'ma-future' : '')) ?> <?= $date === $today ? 'is-today' : '' ?>"
                     title="<?= $h(date('D, M j', strtotime($date)) . ' — ' . ($mk ? $mk[2] : $cellTitle($date, $d))) ?>">
                    <div class="dn"><?= $n ?></div>
                    <?php if ($mk): ?>
                        <div class="hv"><?= $mk[1] === 'OFF' ? 'Day off' : ($mk[1] === 'L' ? 'Leave' : ($mk[1] === 'L?' ? 'Leave?' : 'Absent')) ?></div>
                    <?php elseif ($d): ?>
                        <div class="hv"><?= $hrs($d['hours']) ?>h</div>
                        <?php if ($d['ot'] || $d['late']): ?>
                            <div class="sub"><?= $d['ot'] ? 'OT ' . $hrs($d['ot']) : '' ?><?= $d['ot'] && $d['late'] ? ' · ' : '' ?><?= $d['late'] ? 'late ' . $hrs($d['late']) : '' ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        <div class="ma-legend">
            <span><i class="ma-full"></i>Full day</span>
            <span><i class="ma-short"></i>Short day</span>
            <span><i class="ma-full ma-ot"></i>With overtime</span>
            <span><i class="ma-full ma-manual"></i>Entered by a manager</span>
            <span><i class="ma-off"></i>Day off</span>
            <span><i class="ma-leave"></i>Approved leave</span>
            <span><i class="ma-pending"></i>Leave pending</span>
            <span><i class="ma-absent"></i>Absent</span>
        </div>
    </div>

<?php else: ?>
    <div class="ma-cards">
        <div class="ma-card"><div class="l">Employees</div><div class="v"><?= count($withDays) ?></div><div class="s">with records, of <?= count($emps) ?></div></div>
        <div class="ma-card"><div class="l">Days recorded</div><div class="v"><?= $tot['present'] ?></div><div class="s">all employees</div></div>
        <div class="ma-card"><div class="l">Hours worked</div><div class="v"><?= number_format($tot['hours'], 1) ?></div></div>
        <div class="ma-card"><div class="l">Overtime</div><div class="v"><?= number_format($tot['ot'], 1) ?> h</div></div>
        <div class="ma-card"><div class="l">Late</div><div class="v"><?= number_format($tot['late'], 1) ?> h</div></div>
        <div class="ma-card"><div class="l">Undertime</div><div class="v"><?= number_format($tot['under'], 1) ?> h</div></div>
        <div class="ma-card"><div class="l">Absent</div><div class="v" style="color:<?= $tot['absent'] ? '#b91c1c' : 'inherit' ?>;"><?= $tot['absent'] ?></div><div class="s">days, unexcused</div></div>
        <div class="ma-card"><div class="l">Leave</div><div class="v"><?= $tot['leave'] ?></div><div class="s">approved days</div></div>
        <?php if ($tot['manual']): ?>
        <div class="ma-card"><div class="l">Manual days</div><div class="v"><?= $tot['manual'] ?></div><div class="s">entered by managers</div></div>
        <?php endif; ?>
    </div>

    <div class="ma-box">
        <div class="ma-box-h">
            <h2>Day by day</h2>
            <input type="search" placeholder="Find employee…" oninput="maFilter(this.value)" aria-label="Find employee">
        </div>
        <div class="ma-scroll">
            <table class="ma-grid">
                <thead><tr>
                    <th class="who">Employee</th>
                    <?php for ($n = 1; $n <= $MA['days']; $n++):
                        $date = $MA['ym'] . '-' . str_pad($n, 2, '0', STR_PAD_LEFT);
                        $w = (int)date('w', strtotime($date)); ?>
                        <th class="<?= $w === 0 ? 'we' : '' ?> <?= $date === $today ? 'td' : '' ?>" title="<?= date('l', strtotime($date)) ?>"><?= $n ?></th>
                    <?php endfor; ?>
                    <th class="sum">Days</th>
                </tr></thead>
                <tbody>
                <?php foreach ($emps as $id => $e): ?>
                    <tr data-name="<?= $h(strtolower($e['name'] . ' ' . $id)) ?>">
                        <td class="who" title="<?= $h($e['name']) ?>"><?= $h($e['name']) ?></td>
                        <?php for ($n = 1; $n <= $MA['days']; $n++):
                            $date = $MA['ym'] . '-' . str_pad($n, 2, '0', STR_PAD_LEFT);
                            $d  = $e['days'][$n] ?? null;
                            $mk = !$d || $d['hours'] <= 0 ? ($marks[$e['marks'][$n] ?? ''] ?? null) : null; ?>
                            <td title="<?= $h($e['name'] . ' · ' . ($mk ? date('D, M j', strtotime($date)) . ' — ' . $mk[2] : $cellTitle($date, $d))) ?>">
                                <span class="ma-cell <?= $mk ? $mk[0] : ($d ? $cellClass($d, $e['std']) : ($date > $today ? 'ma-future' : '')) ?>"><?= $mk ? $mk[1] : ($d ? $hrs($d['hours']) : '') ?></span>
                            </td>
                        <?php endfor; ?>
                        <td class="sum"><?= $e['present'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ma-legend">
            <span><i class="ma-full"></i>Full duty day</span>
            <span><i class="ma-short"></i>Short day</span>
            <span><i class="ma-full ma-ot"></i>With overtime</span>
            <span><i class="ma-full ma-manual"></i>Manual entry</span>
            <span><i class="ma-off"></i>OFF = day off</span>
            <span><i class="ma-leave"></i>L = approved leave</span>
            <span><i class="ma-pending"></i>L? = leave pending</span>
            <span><i class="ma-absent"></i>A = absent</span>
            <span><i class="ma-future"></i>Not uploaded yet</span>
        </div>
    </div>

    <div class="ma-box">
        <div class="ma-box-h"><h2>Totals so far</h2></div>
        <div class="ma-scroll">
            <table class="ma-table">
                <thead><tr>
                    <th>Employee</th><th class="n">Days</th><th class="n">Hours</th><th class="n">OT h</th><th class="n">Late h</th><th class="n">UT h</th>
                    <th class="n">Absent</th><th class="n">Leave</th><th class="n">Off</th><th class="n">Manual</th><th>Last day</th>
                    <?php if ($MA['withPay']): ?><th class="n">Gross so far</th><th class="n">Net so far</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($emps as $id => $e): ?>
                    <tr class="<?= $e['days'] ? '' : 'ma-none' ?>" data-name="<?= $h(strtolower($e['name'] . ' ' . $id)) ?>">
                        <td><strong><?= $h($e['name']) ?></strong> <small style="color:#9ca3af;"><?= $h($id) ?></small></td>
                        <td class="n"><?= $e['present'] ?></td>
                        <td class="n"><?= number_format($e['hours'], 2) ?></td>
                        <td class="n"><?= number_format($e['ot'], 2) ?></td>
                        <td class="n"><?= number_format($e['late'], 2) ?></td>
                        <td class="n"><?= number_format($e['under'], 2) ?></td>
                        <td class="n" style="color:<?= $e['absent'] ? '#b91c1c' : 'inherit' ?>;"><?= $e['absent'] ?: '' ?></td>
                        <td class="n"><?= $e['leave'] ?: '' ?></td>
                        <td class="n"><?= $e['off'] ?: '' ?></td>
                        <td class="n"><?= $e['manual'] ?: '' ?></td>
                        <td><?= $e['last'] ? date('M j', strtotime($e['last'])) : 'No record' ?></td>
                        <?php if ($MA['withPay']): ?>
                            <td class="n"><?= $e['gross'] !== null ? $peso($e['gross']) : '—' ?></td>
                            <td class="n"><?= $e['net']   !== null ? $peso($e['net'])   : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($MA['withPay']): ?>
        <div class="ma-legend">Pay is the payroll computed so far for this month's pay period(s) — a draft until the period is finalized.</div>
        <?php endif; ?>
    </div>

    <script>
    function maFilter(q) {
        q = q.trim().toLowerCase();
        document.querySelectorAll('tr[data-name]').forEach(function (tr) {
            tr.hidden = q !== '' && tr.dataset.name.indexOf(q) < 0;
        });
    }
    </script>
<?php endif; ?>
<?php endif; ?>
