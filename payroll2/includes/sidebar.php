<?php
/* A piece of a page: opened on its own it would only show errors */
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; }
/* Pull the company name from the settings table (falls back to 'L&N Pharmacy') */
$companyName = getSetting('company_name', 'L&N Pharmacy');

/*
 * Navigation is grouped and ordered to follow the real payroll process:
 * set the people up once, then work down the Payroll Process group in
 * order for each pay period, then look at what was recorded.
 */
$navGroups = [
    'Setup' => [
        'dashboard'         => ['label' => 'Dashboard',          'href' => 'dashboard.php'],
        'home'              => ['label' => 'Home',               'href' => 'home.php'],
        'employee'          => ['label' => 'Employees',          'href' => 'employee.php'],
        'employee-profile'  => ['label' => 'Employee Profiles',  'href' => 'employee-profile.php'],
    ],
    'Payroll Process' => [
        'attendance-upload' => ['label' => 'Upload Attendance',  'href' => 'attendance-upload.php'],
        'month-attendance'  => ['label' => "This Month's Attendance", 'href' => 'month-attendance.php'],
        'payroll'           => ['label' => 'Payroll Processing', 'href' => 'payroll.php'],
        'adjustments'       => ['label' => 'Bonus & Deductions', 'href' => 'adjustments.php'],
        'thirteenth-month'  => ['label' => '13th Month Pay',     'href' => 'thirteenth-month.php'],
        'reports'           => ['label' => 'Reports & Payslips', 'href' => 'reports.php'],
        'sign-payslip'      => ['label' => 'Sign Payslip',       'href' => 'sign-payslip.php'],
    ],
    'Records' => [
        'history'           => ['label' => 'Adjustment History', 'href' => 'history.php'],
        'print-history'     => ['label' => 'Print History',      'href' => 'print-history.php'],
        'leave-requests'    => ['label' => 'Leave Requests',     'href' => 'leave-requests.php'],
        'leave-history'     => ['label' => 'Leave History',      'href' => 'leave-history.php'],
    ],
    'Analysis & Admin' => [
        'forecast'          => ['label' => 'Salary Forecast',    'href' => 'forecast.php'],
        'managers'          => ['label' => 'Managers',           'href' => 'managers.php'],
        'settings'          => ['label' => 'Settings',           'href' => 'settings.php'],
    ],
];
?>

<!-- Mobile hamburger button -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Open menu">Menu</button>
<!-- Tap-outside overlay to close the drawer -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="adminSidebar">

    <div class="sidebar-brand">
        <span class="brand-title">Payroll</span>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($navGroups as $groupLabel => $items): ?>
        <div class="nav-group"><?= htmlspecialchars($groupLabel) ?></div>
        <?php foreach ($items as $key => $item): ?>
        <a href="<?= $item['href'] ?>"
           class="nav-item <?= ($activePage ?? '') === $key ? 'active' : '' ?>">
            <span class="nav-label"><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="index.php?logout=1" class="nav-item logout-link">
            <span class="nav-label">Logout</span>
        </a>
    </div>

</div>

<script>
(function () {
    var btn = document.getElementById('sidebarToggle');
    var sb  = document.getElementById('adminSidebar');
    var ov  = document.getElementById('sidebarOverlay');
    function close() { sb.classList.remove('open'); ov.classList.remove('open'); }
    btn.addEventListener('click', function () {
        var opening = sb.classList.toggle('open');
        opening ? ov.classList.add('open') : ov.classList.remove('open');
    });
    ov.addEventListener('click', close);
    /* Close drawer when any nav link is tapped on mobile */
    sb.querySelectorAll('.nav-item').forEach(function (a) {
        a.addEventListener('click', close);
    });
})();
</script>
