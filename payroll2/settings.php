<?php
require 'includes/bir-print.php';
requireAuth();

$activePage = 'settings';
$db         = getDB();
$msg        = null;

/* ── Branch list maintenance ────────────────────────────────────────────
 * Posts under its own action so it never collides with the settings form.
 *
 * employees.branch and users.branch store the branch NAME, not an id, so a
 * rename has to carry across to both of them. That happens in one
 * transaction with the branches row: either every reference moves or none
 * does, and the list can never disagree with the employees pointing at it.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['branch_action'])) {
    $action = $_POST['branch_action'];
    $name   = trim($_POST['branch_name'] ?? '');
    $bid    = (int)($_POST['branch_id'] ?? 0);

    /* The name this id currently carries — also proves the row exists. */
    $current = null;
    if ($bid) {
        $st = $db->prepare("SELECT name FROM branches WHERE id = ?");
        $st->execute([$bid]);
        $current = $st->fetchColumn();
        if ($current === false) $current = null;
    }

    try {
        if ($action === 'add') {
            if ($name === '') {
                $msg = ['type' => 'error', 'text' => 'Enter a branch name.'];
            } else {
                $db->prepare("INSERT INTO branches (name) VALUES (?)")->execute([$name]);
                $msg = ['type' => 'success', 'text' => 'Branch "' . $name . '" added.'];
            }

        } elseif ($action === 'rename') {
            if ($current === null) {
                $msg = ['type' => 'error', 'text' => 'That branch no longer exists.'];
            } elseif ($name === '') {
                $msg = ['type' => 'error', 'text' => 'A branch needs a name.'];
            } elseif ($name === $current) {
                $msg = ['type' => 'success', 'text' => 'Nothing to change — the name is already "' . $name . '".'];
            } else {
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE branches SET name = ? WHERE id = ?")->execute([$name, $bid]);

                    $eSt = $db->prepare("UPDATE employees SET branch = ? WHERE branch = ?");
                    $eSt->execute([$name, $current]);
                    $moved = $eSt->rowCount();

                    $mSt = $db->prepare("UPDATE users SET branch = ? WHERE branch = ?");
                    $mSt->execute([$name, $current]);
                    $movedMgr = $mSt->rowCount();

                    $db->commit();
                    $msg = ['type' => 'success', 'text' => sprintf(
                        'Renamed "%s" to "%s". %d employee(s) and %d manager account(s) moved with it.',
                        $current, $name, $moved, $movedMgr
                    )];
                } catch (PDOException $e) {
                    $db->rollBack();
                    throw $e;
                }
            }

        } elseif ($action === 'delete') {
            if ($current === null) {
                $msg = ['type' => 'error', 'text' => 'That branch no longer exists.'];
            } else {
                /* Refuse while anything still points at the name — deleting the
                   row would otherwise strand those employees on a branch that
                   is no longer in the list. */
                $c = $db->prepare("SELECT COUNT(*) FROM employees WHERE branch = ?");
                $c->execute([$current]);
                $empUse = (int)$c->fetchColumn();

                $c = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'manager' AND branch = ?");
                $c->execute([$current]);
                $mgrUse = (int)$c->fetchColumn();

                if ($empUse || $mgrUse) {
                    $msg = ['type' => 'error', 'text' => sprintf(
                        'Cannot delete "%s" — it is still assigned to %d employee(s) and %d manager account(s). Move them to another branch first.',
                        $current, $empUse, $mgrUse
                    )];
                } else {
                    $db->prepare("DELETE FROM branches WHERE id = ?")->execute([$bid]);
                    $msg = ['type' => 'success', 'text' => 'Branch "' . $current . '" deleted.'];
                }
            }
        }

    } catch (PDOException $e) {
        $msg = ['type' => 'error', 'text' => $e->getCode() === '23000'
            ? 'A branch called "' . $name . '" already exists.'
            : 'Could not update the branch list. Please try again.'];
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'overtime_rate', 'late_rate', 'payroll_period',
        'contribution_timing_sss', 'contribution_timing_philhealth', 'contribution_timing_pagibig',
        // Company details — printed on every payslip, receipt and report
        'bir_registered_name', 'bir_business_style', 'bir_address', 'bir_tin',
        'bir_system_name', 'bir_signatory_name', 'bir_signatory_position',
    ];
    // setSetting() inserts the key when it does not exist yet, so the company
    // fields save on first use instead of a bare UPDATE matching no row.
    foreach ($keys as $k) {
        if (isset($_POST[$k])) setSetting($k, trim($_POST[$k]));
    }
    /* A regular duty day: whole or half hours, 1 to 24 */
    if (isset($_POST['standard_hours'])) {
        $h = round((float)$_POST['standard_hours'] * 2) / 2;
        if ($h >= 1 && $h <= 24) setSetting('standard_hours', (string)$h);
    }

    // Admin email update (stored in users table)
    if (!empty($_POST['admin_email_new'])) {
        $newEmail = trim($_POST['admin_email_new']);
        $db->prepare("UPDATE users SET email=? WHERE role='admin'")->execute([$newEmail]);
    }

    // Admin password change (verified + stored as bcrypt hash in users table)
    if (!empty($_POST['new_password'])) {
        $currentPass = trim($_POST['current_password'] ?? '');
        $newPass     = $_POST['new_password'];
        $confirmPass = $_POST['confirm_password'];

        $adminUser = $db->query("SELECT id, password_hash FROM users WHERE role='admin' LIMIT 1")->fetch();

        if (!$adminUser || !password_verify($currentPass, $adminUser['password_hash'])) {
            $msg = ['type' => 'error', 'text' => 'Current password is incorrect. Password was not changed.'];
        } elseif ($newPass !== $confirmPass) {
            $msg = ['type' => 'error', 'text' => 'New passwords do not match. Password was not changed.'];
        } elseif (strlen($newPass) < 8) {
            $msg = ['type' => 'error', 'text' => 'New password must be at least 8 characters.'];
        } elseif (hash_equals(DEFAULT_ADMIN_PASSWORD, $newPass) || hash_equals($currentPass, $newPass)) {
            $msg = ['type' => 'error', 'text' => 'Choose a new password — not the default one, and not the current one.'];
        } else {
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
               ->execute([password_hash($newPass, PASSWORD_BCRYPT), $adminUser['id']]);
            unset($_SESSION['must_change_pw']);
            $msg = ['type' => 'success', 'text' => 'Password changed successfully. Settings saved.'];
        }
    } else {
        if (!isset($msg)) $msg = ['type' => 'success', 'text' => 'Settings saved.'];
    }
}

$s           = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$bir         = birConfig();   // company letterhead fields, with defaults filled in
$serAR       = birSeriesStatus('AR');   // payslip / receipt numbering
$serADJ      = birSeriesStatus('ADJ');  // bonus / deduction numbering

/* Branch list plus what each one is currently carrying, so the page can say
   why a branch cannot be deleted before anyone tries. */
$branchRows = $db->query("
    SELECT b.id, b.name,
           (SELECT COUNT(*) FROM employees e WHERE e.branch = b.name)                        AS emp_count,
           (SELECT COUNT(*) FROM users u WHERE u.role = 'manager' AND u.branch = b.name)     AS mgr_count
      FROM branches b
  ORDER BY b.name
")->fetchAll();

/* Branch names sitting on employee rows that are not in the list — only
   possible on data that predates the managed list. Offered for adoption
   rather than silently ignored. */
$orphanBranches = $db->query("
    SELECT DISTINCT TRIM(e.branch) AS name
      FROM employees e
     WHERE e.branch IS NOT NULL AND TRIM(e.branch) <> ''
       AND NOT EXISTS (SELECT 1 FROM branches b WHERE b.name = TRIM(e.branch))
  ORDER BY name
")->fetchAll(PDO::FETCH_COLUMN);
$adminUser   = $db->query("SELECT id, full_name, email, created_at FROM users WHERE role='admin'  LIMIT 1")->fetch();
$managers    = $db->query("SELECT id, full_name, email, status FROM users WHERE role='manager' ORDER BY full_name")->fetchAll();

$empCount    = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$payCount    = $db->query("SELECT COUNT(*) FROM payroll")->fetchColumn();
$histCount   = $db->query("SELECT COUNT(*) FROM bonus_deduction_history")->fetchColumn();
$logCount    = $db->query("SELECT COUNT(*) FROM print_log")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Payroll System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php require 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="page-header">
        <div>
            <h1>Settings</h1>
            <p>Configure payroll rates, company details, and security</p>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['must_change_pw'])): ?>
        <div class="alert alert-error">
            <span><strong>Change the admin password before continuing.</strong>
            It is still the default one, which anybody reading the code knows — and the live site refuses it.
            Use the Admin Account section below; the rest of the system opens once it is changed.</span>
            <a href="#adminAccount">Change it now</a>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header"><h2>Company Information</h2></div>
            <div class="box-body">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));">
                    <div class="form-group">
                        <label>Company Name</label>
                        <div style="padding:9px 12px;border-radius:7px;border:1.5px solid #e5e7eb;background:#f9fafb;font-weight:600;color:#111827;">
                            L&amp;N Pharmacy
                        </div>
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">Fixed — contact your developer to change</span>
                    </div>
                    <div class="form-group">
                        <label>Default Pay Schedule</label>
                        <select name="payroll_period" class="form-control">
                            <?php
                            /* Value stays the stored key; only the label explains it. */
                            $periodOpts = [
                                'Monthly'      => 'Monthly',
                                'Semi-Monthly' => 'Semi-Monthly (kinsenas — twice a month)',
                                'Weekly'       => 'Weekly',
                            ];
                            foreach ($periodOpts as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($s['payroll_period'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                            Pre-selected when creating a payroll period. Each period keeps its own
                            schedule, so changing this does not alter existing periods.
                        </span>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Contribution Schedule <span style="font-weight:400;color:#9ca3af;">(semi-monthly and weekly payrolls)</span></label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;">
                            <?php
                            $timing = contributionTiming();
                            foreach (['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG'] as $k => $lbl): ?>
                            <label style="font-weight:600;font-size:.84rem;display:flex;flex-direction:column;gap:4px;">
                                <?= $lbl ?>
                                <select name="contribution_timing_<?= $k ?>" class="form-control">
                                    <option value="split"  <?= $timing[$k] === 'split'  ? 'selected' : '' ?>>Every cut-off, on the pay so far</option>
                                    <option value="second" <?= $timing[$k] === 'second' ? 'selected' : '' ?>>Last cut-off of the month, in full</option>
                                </select>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:6px;display:block;">
                            Each contribution is monthly. "Every cut-off" takes what is due on the month's pay so far and
                            the last cut-off settles the rest; "Last cut-off" waits and takes the whole month at once on the
                            month's actual pay. Either way the month ends exact and minimums like PhilHealth's ₱250 are
                            charged once a month. The pharmacy's own timesheets take SSS from the 1st cut-off and
                            PhilHealth on the 2nd. Withholding tax: each cut-off on its BIR table, the month's last
                            cut-off settles the month.
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header"><h2>Company &amp; Document Details</h2></div>
            <div class="box-body">
                <h3 style="font-size:.82rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:10px;">Company</h3>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin-bottom:22px;">
                    <div class="form-group" style="grid-column:1/-1;max-width:520px;">
                        <label>Company Name</label>
                        <input type="text" name="bir_registered_name" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_registered_name']) ?>" placeholder="L &amp; N PHARMACY">
                    </div>
                    <div class="form-group">
                        <label>Line of Business</label>
                        <input type="text" name="bir_business_style" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_business_style']) ?>" placeholder="Retail Pharmacy">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;max-width:640px;">
                        <label>Business Address</label>
                        <input type="text" name="bir_address" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_address']) ?>"
                               placeholder="No. / Street, Barangay, City, Province">
                    </div>
                    <div class="form-group">
                        <label>Company TIN <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
                        <input type="text" name="bir_tin" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_tin']) ?>" placeholder="000-000-000-00000">
                    </div>
                    <div class="form-group" style="grid-column:1/-1;max-width:420px;">
                        <label>System Name &amp; Version</label>
                        <input type="text" name="bir_system_name" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_system_name']) ?>">
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                            Printed in the footer of every document.
                        </span>
                    </div>
                </div>

                <h3 style="font-size:.82rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:10px;">Document Numbering &amp; Signatory</h3>
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));">
                    <div class="form-group">
                        <label>Payslip / Receipt Series</label>
                        <div style="padding:9px 12px;border-radius:7px;border:1.5px solid #e5e7eb;background:#f9fafb;font-weight:700;color:#111827;font-variant-numeric:tabular-nums;">
                            <?= htmlspecialchars(birNextDocNo('AR')) ?>
                        </div>
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                            Next number to be issued. <?= (int)$serAR['issued'] ?> receipt(s) issued so far.
                        </span>
                    </div>
                    <div class="form-group">
                        <label>Bonus / Deduction Series</label>
                        <div style="padding:9px 12px;border-radius:7px;border:1.5px solid #e5e7eb;background:#f9fafb;font-weight:700;color:#111827;font-variant-numeric:tabular-nums;">
                            <?= htmlspecialchars(birNextDocNo('ADJ')) ?>
                        </div>
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                            Next number to be issued. <?= (int)$serADJ['issued'] ?> receipt(s) issued so far.
                        </span>
                    </div>
                    <div class="form-group">
                        <label>Authorised Signatory</label>
                        <input type="text" name="bir_signatory_name" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_signatory_name']) ?>" placeholder="Name printed on the receipt">
                    </div>
                    <div class="form-group">
                        <label>Signatory Position</label>
                        <input type="text" name="bir_signatory_position" class="form-control"
                               value="<?= htmlspecialchars($bir['bir_signatory_position']) ?>">
                    </div>
                </div>
            </div>
        </div>
        <div class="box" style="margin-bottom:20px;">
            <div class="box-header"><h2>Payroll Computation Rates</h2></div>
            <div class="box-body">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));">
                    <div class="form-group">
                        <label>Overtime Rate (₱/hr)</label>
                        <input type="number" name="overtime_rate" class="form-control" step="0.01" value="<?= htmlspecialchars($s['overtime_rate'] ?? '150') ?>">
                    </div>
                    <div class="form-group">
                        <label>Late Deduction Rate (₱/hr)</label>
                        <input type="number" name="late_rate" class="form-control" step="0.01" value="<?= htmlspecialchars($s['late_rate'] ?? '80') ?>">
                    </div>
                    <div class="form-group">
                        <label>Standard Duty Day (hours)</label>
                        <input type="number" name="standard_hours" class="form-control" step="0.5" min="1" max="24" value="<?= htmlspecialchars($s['standard_hours'] ?? '8') ?>">
                        <span style="font-size:.78rem;color:#9ca3af;margin-top:4px;display:block;">
                            A daily-rate employee earns the daily rate per duty day, so undertime costs the rate ÷ these hours
                            per hour. Someone on a longer shift gets their own hours in Employees.
                        </span>
                    </div>
                </div>

                <?php
                /* Read-only: the official tables the calculation uses (PH_RULES in
                   includes/helpers.php). Rates set by law are not edited here. */
                $R   = PH_RULES;
                $pct = fn($x) => rtrim(rtrim(number_format($x * 100, 2), '0'), '.') . '%';
                $php = fn($x) => '₱' . number_format($x, $x == floor($x) ? 0 : 2);
                $rules = [
                    [$R['sss']['name'],
                     'Employee share ' . $pct($R['sss']['ee_rate']) . ' of the Monthly Salary Credit (employer 10%, total 15%). '
                     . 'Credit in ' . $php($R['sss']['msc_step']) . ' steps from ' . $php($R['sss']['msc_min']) . ' to ' . $php($R['sss']['msc_max'])
                     . ' — employee pays ' . $php($R['sss']['msc_min'] * $R['sss']['ee_rate']) . ' to ' . $php($R['sss']['msc_max'] * $R['sss']['ee_rate']) . ' a month.',
                     $R['sss']['since'], $R['sss']['source']],
                    [$R['philhealth']['name'],
                     $pct($R['philhealth']['rate']) . ' of monthly basic salary, split equally with the employer (employee '
                     . $pct($R['philhealth']['rate'] * $R['philhealth']['ee_share']) . '). Salary counted from '
                     . $php($R['philhealth']['floor']) . ' to ' . $php($R['philhealth']['ceiling']) . ' — employee pays '
                     . $php($R['philhealth']['floor'] * $R['philhealth']['rate'] * $R['philhealth']['ee_share']) . ' to '
                     . $php($R['philhealth']['ceiling'] * $R['philhealth']['rate'] * $R['philhealth']['ee_share']) . ' a month.',
                     $R['philhealth']['since'], $R['philhealth']['source']],
                    [$R['pagibig']['name'],
                     'Employee ' . $pct($R['pagibig']['rate']) . ' (' . $pct($R['pagibig']['rate_low']) . ' if earning '
                     . $php($R['pagibig']['low_limit']) . ' or less) of pay up to ' . $php($R['pagibig']['max_comp'])
                     . ' — at most ' . $php($R['pagibig']['max_comp'] * $R['pagibig']['rate']) . ' a month; the employer matches it.',
                     $R['pagibig']['since'], $R['pagibig']['source']],
                    [$R['bir']['name'],
                     'TRAIN graduated tables on taxable pay (gross pay minus the employee\'s SSS, PhilHealth and Pag-IBIG): '
                     . 'none up to ₱10,417 a semi-monthly cut-off or ₱20,833 a month, then 15%, 20%, 25%, 30% and 35% brackets. '
                     . 'Each cut-off uses its own table; the month\'s last cut-off settles the month on the monthly table.',
                     $R['bir']['since'], $R['bir']['source']],
                ];
                ?>
                <div style="margin-top:22px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;">
                        <strong style="font-size:.92rem;">Contribution tables in use</strong>
                        <span style="font-size:.78rem;color:#6b7280;">Set by law — applied automatically, not edited here</span>
                    </div>
                    <table class="data-table" style="min-width:0;">
                        <thead><tr><th style="width:130px;">Deduction</th><th>How it is computed</th><th style="width:120px;">In effect since</th><th style="width:210px;">Basis</th></tr></thead>
                        <tbody>
                        <?php foreach ($rules as [$name, $how, $since, $src]): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($name) ?></td>
                                <td style="font-size:.84rem;line-height:1.5;"><?= htmlspecialchars($how) ?></td>
                                <td style="font-size:.84rem;"><?= htmlspecialchars($since) ?></td>
                                <td style="font-size:.78rem;color:#6b7280;"><?= htmlspecialchars($src) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin:0;padding:10px 16px;font-size:.78rem;color:#6b7280;border-top:1px solid #e5e7eb;">
                        SSS is read on all pay earned in the month (overtime included); PhilHealth and Pag-IBIG on basic pay.
                        On a semi-monthly or weekly payroll they are taken per the <em>Contribution Schedule</em> above.
                        When an agency changes its rates, the developer updates <code>PH_RULES</code> in <code>includes/helpers.php</code>.
                    </p>
                </div>
            </div>
        </div>

        <div class="box" style="margin-bottom:20px;">
            <div class="box-header" id="adminAccount"><h2>Admin Account</h2></div>
            <div class="box-body">
                <div class="form-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:20px;">
                    <div class="form-group" style="grid-column:1/-1;max-width:360px;">
                        <label>Admin Email <span style="font-weight:400;color:#9ca3af;">(used to log in)</span></label>
                        <input type="email" name="admin_email_new" class="form-control"
                               value="<?= htmlspecialchars($adminUser['email'] ?? '') ?>" placeholder="you@company.com">
                    </div>
                </div>
                <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;">
                    <div class="form-group">
                        <label>Current Password *</label>
                        <input type="password" name="current_password" class="form-control" placeholder="Enter current password">
                        <span style="font-size:.78rem;color:#6b7280;margin-top:4px;display:block;">Required only when changing password</span>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="At least 8 characters" minlength="8">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password">
                    </div>
                </div>
                <p style="font-size:.8rem;color:#9ca3af;margin-top:10px;">Leave all three blank to keep your current password unchanged.</p>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save All Settings</button>
        </div>

    </form>

    <!-- Branches — the list every employee and branch-scoped manager picks from.
         Outside the settings form above: each row posts its own action. -->
    <div class="box" style="margin-top:24px;">
        <div class="box-header">
            <h2>Branches</h2>
            <span style="font-size:.8rem;color:#6b7280;">
                <?= count($branchRows) ?> branch<?= count($branchRows) === 1 ? '' : 'es' ?> &mdash;
                offered when adding an employee or scoping a manager
            </span>
        </div>
        <div class="box-body">

            <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;">
                <input type="hidden" name="branch_action" value="add">
                <div class="form-group" style="flex:1;min-width:220px;max-width:340px;">
                    <label>New Branch Name</label>
                    <input type="text" name="branch_name" class="form-control" placeholder="e.g. Main Branch" required>
                </div>
                <button type="submit" class="btn btn-primary">Add Branch</button>
            </form>

            <?php if (!empty($orphanBranches)): ?>
            <div class="alert alert-warn" style="margin-bottom:16px;">
                <?= count($orphanBranches) ?> branch name(s) already on employee records are not in this list:
                <strong><?= htmlspecialchars(implode(', ', $orphanBranches)) ?></strong>.
                Add them above to manage them here.
            </div>
            <?php endif; ?>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Employees</th>
                            <th>Managers</th>
                            <th style="width:1%;white-space:nowrap;">Rename</th>
                            <th style="width:1%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($branchRows)): ?>
                        <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:24px;">
                            No branches yet. Add the first one above.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($branchRows as $b): ?>
                        <?php $inUse = (int)$b['emp_count'] + (int)$b['mgr_count']; ?>
                        <tr>
                            <td style="font-weight:600;"><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= (int)$b['emp_count'] ?></td>
                            <td><?= (int)$b['mgr_count'] ?></td>
                            <td>
                                <form method="POST" style="display:flex;gap:6px;align-items:center;">
                                    <input type="hidden" name="branch_action" value="rename">
                                    <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
                                    <input type="text" name="branch_name" class="form-control"
                                           style="min-width:170px;padding:6px 10px;font-size:.84rem;"
                                           value="<?= htmlspecialchars($b['name']) ?>" required>
                                    <button type="submit" class="btn btn-ghost btn-sm">Save</button>
                                </form>
                            </td>
                            <td>
                                <?php if ($inUse): ?>
                                    <?php
                                    /* Spell out why there is no Delete button */
                                    $who = array_filter([
                                        (int)$b['emp_count'] ? (int)$b['emp_count'] . ' employee' . ((int)$b['emp_count'] === 1 ? '' : 's') : '',
                                        (int)$b['mgr_count'] ? (int)$b['mgr_count'] . ' manager' . ((int)$b['mgr_count'] === 1 ? '' : 's') : '',
                                    ]); ?>
                                    <span style="font-size:.76rem;color:#6b7280;line-height:1.35;display:inline-block;max-width:170px;">
                                        Can&rsquo;t delete &mdash; <?= implode(' and ', $who) ?> assigned.
                                        Move them to another branch first.
                                    </span>
                                <?php else: ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Delete the branch &quot;<?= htmlspecialchars($b['name'], ENT_QUOTES) ?>&quot;?');">
                                        <input type="hidden" name="branch_action" value="delete">
                                        <input type="hidden" name="branch_id" value="<?= (int)$b['id'] ?>">
                                        <button type="submit" class="btn btn-red btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p style="font-size:.78rem;color:#9ca3af;margin-top:12px;">
                Renaming a branch also updates every employee and manager account assigned to it.
                A branch can only be deleted once nothing is assigned to it.
            </p>
        </div>
    </div>

    <!-- Account Storage — shows exactly where each account type lives in the DB -->
    <div class="box" style="margin-top:24px;">
        <div class="box-header">
            <h2>Account Storage</h2>
            <span style="font-size:.8rem;color:#6b7280;">All accounts live in the <code>users</code> table — distinguished by the <code>role</code> column</span>
        </div>
        <div class="box-body">

            <!-- Admin account -->
            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:8px;">
                Admin &mdash; <code style="font-weight:400;">users.role = 'admin'</code>
            </p>
            <?php if ($adminUser): ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;padding:12px 16px;border-radius:8px;border:1px solid #e5e7eb;background:#f9fafb;margin-bottom:20px;">
                <div>
                    <span style="font-size:.75rem;color:#9ca3af;">Full Name</span>
                    <div style="font-weight:600;"><?= htmlspecialchars($adminUser['full_name']) ?></div>
                </div>
                <div>
                    <span style="font-size:.75rem;color:#9ca3af;">Email (login)</span>
                    <div style="font-weight:600;"><?= htmlspecialchars($adminUser['email']) ?></div>
                </div>
                <div>
                    <span style="font-size:.75rem;color:#9ca3af;">Password</span>
                    <div style="font-weight:600;color:#6b7280;">bcrypt hash (not shown)</div>
                </div>
                <div>
                    <span style="font-size:.75rem;color:#9ca3af;">Row ID</span>
                    <div style="font-weight:600;"><?= $adminUser['id'] ?></div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-warn" style="margin-bottom:20px;">No admin account found. Reload the page — the system will create one automatically.</div>
            <?php endif; ?>

            <!-- Manager accounts -->
            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:8px;">
                Managers &mdash; <code style="font-weight:400;">users.role = 'manager'</code>
                &nbsp;<a href="managers.php" style="font-size:.75rem;font-weight:400;color:#3b82f6;">Manage &rarr;</a>
            </p>
            <?php if (empty($managers)): ?>
            <p style="color:#9ca3af;font-size:.875rem;margin-bottom:20px;">No manager accounts yet. <a href="managers.php">Add one in Managers.</a></p>
            <?php else: ?>
            <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px;">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                    <thead>
                        <tr style="background:#1e293b;color:#f1f5f9;">
                            <th style="padding:8px 14px;text-align:left;font-size:.72rem;text-transform:uppercase;">ID</th>
                            <th style="padding:8px 14px;text-align:left;font-size:.72rem;text-transform:uppercase;">Full Name</th>
                            <th style="padding:8px 14px;text-align:left;font-size:.72rem;text-transform:uppercase;">Email (login)</th>
                            <th style="padding:8px 14px;text-align:left;font-size:.72rem;text-transform:uppercase;">Password</th>
                            <th style="padding:8px 14px;text-align:left;font-size:.72rem;text-transform:uppercase;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($managers as $m): ?>
                    <tr style="border-top:1px solid #e5e7eb;">
                        <td style="padding:9px 14px;color:#9ca3af;"><?= $m['id'] ?></td>
                        <td style="padding:9px 14px;font-weight:600;"><?= htmlspecialchars($m['full_name']) ?></td>
                        <td style="padding:9px 14px;"><?= htmlspecialchars($m['email']) ?></td>
                        <td style="padding:9px 14px;color:#6b7280;">bcrypt hash</td>
                        <td style="padding:9px 14px;">
                            <span class="badge badge-<?= $m['status']==='Active' ? 'green' : 'red' ?>"><?= $m['status'] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Earner note -->
            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;">
                Earners (employees with portal access) &mdash; <code style="font-weight:400;">users.role = 'earner'</code>
                &nbsp;<a href="employee.php" style="font-size:.75rem;font-weight:400;color:#3b82f6;">Manage &rarr;</a>
            </p>
            <p style="font-size:.85rem;color:#6b7280;margin-bottom:20px;">
                Set or revoke access per employee in the <a href="employee.php">Employees</a> page.
                Each earner's <code>users.emp_id</code> links their portal account to the <code>employees</code> table for payroll data.
            </p>

        </div>
    </div>

    <div class="box" style="margin-top:24px;">
        <div class="box-header"><h2>Database Summary</h2></div>
        <div class="box-body">
            <div class="card-grid" style="grid-template-columns:repeat(4,1fr);margin:0;">
                <div class="card" style="margin:0;"><div class="card-label">Employees</div><div class="card-value"><?= $empCount ?></div></div>
                <div class="card" style="margin:0;"><div class="card-label">Payroll Records</div><div class="card-value"><?= $payCount ?></div></div>
                <div class="card" style="margin:0;"><div class="card-label">History Entries</div><div class="card-value"><?= $histCount ?></div></div>
                <div class="card" style="margin:0;"><div class="card-label">Print Logs</div><div class="card-value"><?= $logCount ?></div></div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
