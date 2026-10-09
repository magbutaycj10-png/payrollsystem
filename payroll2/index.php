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
    $st = $db->prepare("SELECT id, full_name, password_hash FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
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
    $st2 = $db->prepare("SELECT id, full_name, branch, password_hash FROM users WHERE email = ? AND role = 'manager' AND status = 'Active' LIMIT 1");
    $st2->execute([$email]);
    $mgr = $st2->fetch();
    if ($mgr && password_verify($password, $mgr['password_hash'])) {
        recordLogin($db, $email, true);
        session_regenerate_id(true);   /* a fresh id once signed in */
        $_SESSION['last_seen']     = time();
        $_SESSION['mgr_logged_in'] = true;
        $_SESSION['mgr_id']        = $mgr['id'];
        $_SESSION['mgr_name']      = $mgr['full_name'];
        $_SESSION['mgr_branch']      = $mgr['branch'];
        header('Location: /manager/dashboard.php');
        exit;
    }

    /* Check if the credentials belong to an employee - log them in directly */
    $st3 = $db->prepare("SELECT id, emp_id, full_name, password_hash FROM users WHERE email = ? AND role = 'earner' AND status = 'Active' LIMIT 1");
    $st3->execute([$email]);
    $earner = $st3->fetch();
    if ($earner && password_verify($password, $earner['password_hash'])) {
        recordLogin($db, $email, true);
        session_regenerate_id(true);   /* a fresh id once signed in */
        $_SESSION['last_seen']     = time();
        $_SESSION['emp_logged_in'] = true;
        $_SESSION['emp_id']        = $earner['emp_id'];
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
        <p>Automated Payroll with Predictive Forecasting</p>
    </div>

    <div class="login-card">
        <h2>Welcome back</h2>
        <p class="sub">Sign in with your email and password.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="login-field">
                <label>Email</label>
                <input type="email" name="email" placeholder="you@company.com" autofocus required
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="login-field">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="login-btn">Sign In &rarr;</button>
        </form>

    </div>
</div>
</body>
</html>
