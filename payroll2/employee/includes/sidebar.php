<?php
/* A piece of a page: opened on its own it would only show errors */
if (get_included_files()[0] === __FILE__) { http_response_code(404); exit; }
$e   = emp();
$nav = [
    'dashboard' => ['label' => 'Dashboard', 'short' => 'Dash',     'href' => '/employee/dashboard.php'],
    'home'      => ['label' => 'Home',      'short' => 'Home',     'href' => '/employee/home.php'],
    'month-attendance' => ['label' => 'My Attendance', 'short' => 'Attend.', 'href' => '/employee/month-attendance.php'],
    'payslips'  => ['label' => 'Payslips',  'short' => 'Payslips', 'href' => '/employee/payslips.php'],
    'leave'     => ['label' => 'Leave',     'short' => 'Leave',    'href' => '/employee/leave.php'],
    'profile'   => ['label' => 'Profile',   'short' => 'Profile',  'href' => '/employee/profile.php'],
];
$activePage = $activePage ?? '';
?>
<!-- Hamburger toggle (mobile/tablet) -->
<button class="p-sidebar-toggle" id="pSidebarToggle" aria-label="Open menu">Menu</button>
<div class="p-sidebar-overlay" id="pSidebarOverlay"></div>

<!-- Desktop sidebar / mobile drawer -->
<div class="p-sidebar" id="pSidebar">
    <div class="p-sidebar-brand">
        <span class="p-brand-title">Payroll</span>
        <span class="p-brand-role">Employee Portal</span>
    </div>
    <nav class="p-sidebar-nav">
        <?php foreach ($nav as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="p-nav-item <?= $activePage === $key ? 'active' : '' ?>">
            <span><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <div class="p-sidebar-footer">
        <a href="/index.php?logout=1" class="p-nav-item p-logout">
            <span>Logout (<?= htmlspecialchars($e['name']) ?>)</span>
        </a>
    </div>
</div>

<!-- Mobile bottom nav -->
<nav class="p-bottom-nav">
    <div class="p-bottom-nav-inner">
        <?php foreach ($nav as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="p-bn-item <?= $activePage === $key ? 'active' : '' ?>">
            <span><?= $item['short'] ?></span>
        </a>
        <?php endforeach; ?>
        <a href="/index.php?logout=1" class="p-bn-item">
            <span>Logout</span>
        </a>
    </div>
</nav>

<script>
(function () {
    var btn = document.getElementById('pSidebarToggle');
    var sb  = document.getElementById('pSidebar');
    var ov  = document.getElementById('pSidebarOverlay');
    function close() { sb.classList.remove('open'); ov.classList.remove('open'); }
    btn.addEventListener('click', function () {
        var opening = sb.classList.toggle('open');
        opening ? ov.classList.add('open') : ov.classList.remove('open');
    });
    ov.addEventListener('click', close);
    sb.querySelectorAll('.p-nav-item').forEach(function (a) {
        a.addEventListener('click', close);
    });
})();
</script>
