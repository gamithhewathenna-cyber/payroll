<?php
require_once 'config.php';

// Check "Remember Me" cookie
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $db    = getDB();
    $stmt  = $db->prepare("SELECT u.*, e.id as emp_db_id FROM users u LEFT JOIN employees e ON e.user_id=u.id WHERE u.remember_token=? AND u.token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id']        = $user['id'];
        $_SESSION['full_name']      = $user['full_name'];
        $_SESSION['role']           = $user['role'];
        $_SESSION['employee_db_id'] = $user['emp_db_id'];
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    } else {
        setcookie('remember_token', '', time()-3600, '/');
    }
}

if (isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email      = trim($_POST['email'] ?? '');
    $pass       = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);

    if ($email && $pass) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT u.*, e.id as emp_db_id FROM users u LEFT JOIN employees e ON e.user_id=u.id WHERE u.email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, $user['password'])) {
            $_SESSION['user_id']        = $user['id'];
            $_SESSION['full_name']      = $user['full_name'];
            $_SESSION['role']           = $user['role'];
            $_SESSION['employee_db_id'] = $user['emp_db_id'];
            $_SESSION['role_id']        = $user['role_id'] ?? null;
            $_SESSION['job_title']      = $user['job_title'] ?? '';
            $_SESSION['is_super_admin'] = $user['is_super_admin'] ?? 0;

            // Remember Me — 60 days
            if ($rememberMe) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+60 days'));
                $db->prepare("UPDATE users SET remember_token=?, token_expires=? WHERE id=?")->execute([$token, $expires, $user['id']]);
                setcookie('remember_token', $token, time() + (60 * 24 * 3600), '/', '', true, true);
            }

            setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
            header('Location: ' . SITE_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}

// Load settings for logo & company name
$db          = getDB();
$rows        = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings    = array_column($rows, 'setting_value', 'setting_key');
$companyName = $settings['company_name'] ?? SITE_NAME;
$logoPath    = $settings['logo_path'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= h($companyName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --bg:#0d1117; --bg2:#161b22; --bg3:#21262d; --border:#30363d; --text:#e6edf3; --text2:#8b949e; --accent:#3b82f6; --accent2:#1d4ed8; --red:#ff4d6d; --green:#00c48c; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14px; }
.login-page { min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); background-image:radial-gradient(ellipse at 20% 50%,rgba(59,130,246,.08) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(0,196,140,.05) 0%,transparent 50%); }
.login-box { width:100%; max-width:380px; padding:0 20px; }
.login-logo { text-align:center; margin-bottom:32px; }
.brand-icon { width:64px; height:64px; background:var(--accent); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:800; margin:0 auto 12px; }
.brand-img { width:64px; height:64px; border-radius:12px; object-fit:cover; margin:0 auto 12px; display:block; }
h1 { font-family:'Poppins',sans-serif; font-size:26px; font-weight:800; }
.login-logo p { color:var(--text2); font-size:13px; margin-top:4px; }
.login-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:28px; }
.form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
label { font-size:12.5px; font-weight:500; color:var(--text2); }
input[type=email], input[type=password] { background:var(--bg3); border:1px solid var(--border); color:var(--text); padding:10px 12px; border-radius:7px; font-family:'Poppins',sans-serif; font-size:13.5px; width:100%; transition:border-color .15s; }
input:focus { outline:none; border-color:var(--accent); }
.remember-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
.checkbox-label { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:var(--text2); user-select:none; }
.checkbox-label input[type=checkbox] { width:16px; height:16px; accent-color:var(--accent); cursor:pointer; }
.forgot-link { font-size:12.5px; color:var(--accent); text-decoration:none; transition:color .15s; }
.forgot-link:hover { color:#fff; }
.btn { display:flex; align-items:center; justify-content:center; width:100%; padding:11px; border-radius:7px; border:none; cursor:pointer; font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; background:var(--accent); color:#fff; transition:background .15s; }
.btn:hover { background:var(--accent2); }
.error-box { background:rgba(255,77,109,.15); border:1px solid rgba(255,77,109,.3); color:var(--red); padding:10px 14px; border-radius:7px; margin-bottom:14px; font-size:13px; }
</style>
</head>
<body>
<div class="login-page">
  <div class="login-box">
    <div class="login-logo">
      <?php if ($logoPath): ?>
        <img src="<?= h(SITE_URL.'/'.$logoPath) ?>" class="brand-img" alt="<?= h($companyName) ?>">
      <?php else: ?>
        <div class="brand-icon"><?= strtoupper(substr($companyName,0,1)) ?></div>
      <?php endif; ?>
      <h1><?= h($companyName) ?></h1>
      <p>Workforce Payroll System</p>
    </div>

    <div class="login-card">
      <?php if ($error): ?>
        <div class="error-box">⚠️ <?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="email" placeholder="you@company.com" required value="<?= h($_POST['email'] ?? '') ?>" autofocus>
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" placeholder="••••••••" required>
        </div>

        <div class="remember-row">
          <label class="checkbox-label">
            <input type="checkbox" name="remember_me" <?= isset($_POST['remember_me']) ? 'checked' : '' ?>>
            Remember me for 60 days
          </label>
          <a href="<?= SITE_URL ?>/forgot_password.php" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn">Sign In →</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
