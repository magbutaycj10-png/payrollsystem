<?php
require 'includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Logout
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// Already logged in
if (!empty($_SESSION['logged_in'])) {
    header('Location: dashboard.php');
    exit;
}
if (!empty($_SESSION['mgr_logged_in'])) {
    header('Location: /manager/dashboard.php');
    exit;
}
if (!empty($_SESSION['emp_logged_in'])) {
    header('Location: /employee/dashboard.php');
    exit;
}

// Seed users table on first-ever run (creates admin, migrates legacy accounts)
applySchemaPatches();

$error = isset($_GET['expired']) ? 'You were signed out after a long time without activity. Please sign in again.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    $db = getDB();

    /* Too many wrong passwords lately: wait, whatever was typed this time */
    if (loginBlocked($db, $email)) {
        $error = 'Too many failed sign-ins. Wait 15 minutes, then try again.';
        goto show_form;
    }

    /* Try admin login first */
    $st = $db->prepare("SELECT id, full_name, password_hash FROM users WHERE email = ? AND role = 'Admin' LIMIT 1");
    $st->execute([$email]);
    $admin = $st->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        /* The default password is public knowledge (it is in the code). On the
           live site it is refused outright; on this PC it must be changed now. */
        $isDefault = hash_equals(DEFAULT_ADMIN_PASSWORD, $password);
        if ($isDefault && APP_ENV === 'production') {
            recordLogin($db, $email, false);
            $error = 'The admin account still has the default password, which is not allowed on the live site. '
                   . 'Sign in on your own PC (launch.bat) and change it in Settings first.';
            goto show_form;
        }
        recordLogin($db, $email, true);
        session_regenerate_id(true);   /* a fresh id once signed in */
        $_SESSION['logged_in'] = true;
        $_SESSION['admin']     = $admin['full_name'];
        $_SESSION['admin_id']  = $admin['id'];
        $_SESSION['last_seen'] = time();
        if ($isDefault) {
            $_SESSION['must_change_pw'] = true;
            header('Location: settings.php?change_password=1');
            exit;
        }
        header('Location: dashboard.php');
        exit;
    }

    /* Check if the credentials belong to a manager - log them in directly */
    $st2 = $db->prepare("SELECT id, full_name, password_hash FROM users WHERE email = ? AND role = 'Manager' AND status = 'Active' LIMIT 1");
    $st2->execute([$email]);
    $mgr = $st2->fetch();
    if ($mgr && password_verify($password, $mgr['password_hash'])) {
        recordLogin($db, $email, true);
        session_regenerate_id(true);   /* a fresh id once signed in */
        $_SESSION['last_seen']     = time();
        $_SESSION['mgr_logged_in'] = true;
        $_SESSION['mgr_id']        = $mgr['id'];
        $_SESSION['mgr_name']      = $mgr['full_name'];
        /* the branch the manager handles, for page headings (blank when it is not one branch) */
        $bst = $db->prepare("SELECT b.name FROM user_scopes s JOIN branches b ON b.id = s.branch_id
                              WHERE s.user_id = ? AND s.scope_type = 'Branch' ORDER BY s.id LIMIT 1");
        $bst->execute([$mgr['id']]);
        $_SESSION['mgr_branch']    = (string)($bst->fetchColumn() ?: '');
        header('Location: /manager/dashboard.php');
        exit;
    }

    /* Check if the credentials belong to an employee - log them in directly */
    $st3 = $db->prepare("SELECT u.id, e.employee_code AS emp_id, u.full_name, u.password_hash FROM users u JOIN employees e ON e.id = u.employee_id
                          WHERE u.email = ? AND u.role = 'Employee' AND u.status = 'Active' LIMIT 1");
    $st3->execute([$email]);
    $earner = $st3->fetch();
    if ($earner && password_verify($password, $earner['password_hash'])) {
        recordLogin($db, $email, true);
        session_regenerate_id(true);   /* a fresh id once signed in */
        $_SESSION['last_seen']     = time();
        $_SESSION['emp_logged_in'] = true;
        $_SESSION['emp_id']        = $earner['emp_id'];
        $_SESSION['emp_user_id']   = $earner['id'];
        $_SESSION['emp_name']      = $earner['full_name'];
        header('Location: /employee/dashboard.php');
        exit;
    }

    recordLogin($db, $email, false);
    $error = 'Incorrect email or password.';
}
show_form:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Payroll System</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <h1>Payroll System</h1>
        <p>Automated Payroll with <span class="accent">Predictive Forecasting</span></p>
    </div>

    <div class="login-card">
        <h2>Welcome back</h2>
        <p class="sub">Sign in with your email and password.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="login-field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="you@company.com" autocomplete="username" autofocus required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="login-field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false">
                        <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                        <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="login-btn">Sign In &rarr;</button>
        </form>

    </div>
</div>
<script>
/* The eye in the password box shows or hides what was typed */
(function () {
    var box = document.getElementById('password');
    var btn = document.getElementById('pwToggle');
    btn.addEventListener('click', function () {
        var show = box.type === 'password';
        box.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        box.focus();
    });
})();
</script>
</body>
</html>
