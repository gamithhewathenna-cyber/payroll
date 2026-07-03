<?php
require_once 'config.php';

if (isLoggedIn()) { header('Location: ' . SITE_URL . '/dashboard.php'); exit; }

$db          = getDB();
$rows        = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings    = array_column($rows, 'setting_value', 'setting_key');
$companyName = $settings['company_name'] ?? SITE_NAME;
$logoPath    = $settings['logo_path'] ?? '';
$fromEmail   = $settings['email_from'] ?? 'payroll@creativelements.co';

$step    = $_GET['step'] ?? 'request'; // request | sent | reset | done
$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = '';

// Step 1 — Request reset
if ($step === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = 'Please enter your email address.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE email=?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $db->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")->execute([$token, $expires, $user['id']]);

            $resetLink  = SITE_URL . '/forgot_password.php?step=reset&token=' . $token;
            $msgId      = '<'.time().'.reset@creativelements.co>';
            $subject    = "Password Reset | {$companyName}";
            $emailBody  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.wrap{max-width:520px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.header{background:#1a1f2e;padding:24px 32px;text-align:center}
.header h1{color:#fff;margin:0;font-size:20px}
.body{padding:28px 32px}
.msg{font-size:14px;line-height:1.7;color:#444;margin-bottom:24px}
.btn-link{display:block;background:#3b82f6;color:#fff;text-align:center;padding:14px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;margin-bottom:20px}
.notice{font-size:12px;color:#999;border-top:1px solid #eee;padding-top:16px}
.footer{background:#f8f8f8;padding:14px 32px;text-align:center;font-size:12px;color:#aaa}
</style></head><body><div class="wrap">
<div class="header"><h1>'.htmlspecialchars($companyName).'</h1></div>
<div class="body">
<p class="msg">Hi <strong>'.htmlspecialchars($user['full_name']).'</strong>,<br><br>
We received a request to reset your password. Click the button below to set a new password.<br>
This link will expire in <strong>1 hour</strong>.</p>
<a href="'.htmlspecialchars($resetLink).'" class="btn-link">Reset My Password</a>
<p class="notice">If you did not request a password reset, you can safely ignore this email.<br>
Your password will not change until you click the link above.</p>
</div>
<div class="footer">'.htmlspecialchars($companyName).' · This is an automated message.</div>
</div></body></html>';

            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$companyName} <{$fromEmail}>\r\n";
            $headers .= "Reply-To: {$fromEmail}\r\nMessage-ID: {$msgId}\r\nDate: ".date('r')."\r\nX-Priority: 3\r\nX-Mailer: PHP/".phpversion();
            mail($email, $subject, $emailBody, $headers, "-f{$fromEmail}");
        }
        // Always show success (don't reveal if email exists)
        header('Location: ' . SITE_URL . '/forgot_password.php?step=sent'); exit;
    }
}

// Step 3 — Submit new password
if ($step === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token   = trim($_POST['token'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$token || !$newPass || !$confirm) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($newPass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($newPass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE reset_token=? AND reset_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if (!$user) {
            $error = 'This reset link has expired or is invalid. Please request a new one.';
        } else {
            $db->prepare("UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $user['id']]);
            header('Location: ' . SITE_URL . '/forgot_password.php?step=done'); exit;
        }
    }
}

// Validate reset token for step=reset
$validToken = false;
if ($step === 'reset' && $token) {
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $validToken = (bool)$stmt->fetch();
    if (!$validToken) $error = 'This reset link has expired or is invalid.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — <?= h($companyName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
:root { --bg:#0d1117; --bg2:#161b22; --bg3:#21262d; --border:#30363d; --text:#e6edf3; --text2:#8b949e; --accent:#3b82f6; --accent2:#1d4ed8; --red:#ff4d6d; --green:#00c48c; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }
.page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; background-image:radial-gradient(ellipse at 20% 50%,rgba(59,130,246,.08) 0%,transparent 60%); }
.box { width:100%; max-width:380px; }
.logo { text-align:center; margin-bottom:28px; }
.brand-icon { width:56px; height:56px; background:var(--accent); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800; margin:0 auto 10px; }
.brand-img { width:56px; height:56px; border-radius:12px; object-fit:cover; display:block; margin:0 auto 10px; }
.logo h1 { font-size:22px; font-weight:800; }
.logo p { color:var(--text2); font-size:12px; margin-top:3px; }
.card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:28px; }
.card h2 { font-size:16px; font-weight:700; margin-bottom:6px; }
.card .sub { color:var(--text2); font-size:13px; margin-bottom:22px; line-height:1.6; }
.form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:14px; }
label { font-size:12px; font-weight:500; color:var(--text2); }
input { background:var(--bg3); border:1px solid var(--border); color:var(--text); padding:10px 12px; border-radius:7px; font-family:'Poppins',sans-serif; font-size:13.5px; width:100%; transition:border-color .15s; }
input:focus { outline:none; border-color:var(--accent); }
.btn { width:100%; padding:11px; border-radius:7px; border:none; cursor:pointer; font-family:'Poppins',sans-serif; font-size:14px; font-weight:600; background:var(--accent); color:#fff; transition:background .15s; margin-top:4px; }
.btn:hover { background:var(--accent2); }
.error-box { background:rgba(255,77,109,.12); border:1px solid rgba(255,77,109,.3); color:var(--red); padding:10px 14px; border-radius:7px; margin-bottom:16px; font-size:13px; }
.success-box { background:rgba(0,196,140,.12); border:1px solid rgba(0,196,140,.3); color:var(--green); padding:10px 14px; border-radius:7px; margin-bottom:16px; font-size:13px; }
.back-link { display:block; text-align:center; margin-top:16px; font-size:13px; color:var(--text2); text-decoration:none; }
.back-link:hover { color:var(--text); }
.icon-big { font-size:48px; text-align:center; margin-bottom:12px; }
</style>
</head>
<body>
<div class="page">
  <div class="box">
    <div class="logo">
      <?php if ($logoPath): ?>
        <img src="<?= h(SITE_URL.'/'.$logoPath) ?>" class="brand-img" alt="<?= h($companyName) ?>">
      <?php else: ?>
        <div class="brand-icon"><?= strtoupper(substr($companyName,0,1)) ?></div>
      <?php endif; ?>
      <h1><?= h($companyName) ?></h1>
      <p>Password Recovery</p>
    </div>

    <div class="card">

      <?php if ($step === 'request'): ?>
        <h2>Forgot your password?</h2>
        <p class="sub">Enter your email address and we'll send you a link to reset your password.</p>
        <?php if ($error): ?><div class="error-box">⚠️ <?= h($error) ?></div><?php endif; ?>
        <form method="POST">
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="you@company.com" autofocus value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <button type="submit" class="btn">Send Reset Link →</button>
        </form>

      <?php elseif ($step === 'sent'): ?>
        <div class="icon-big">📧</div>
        <h2 style="text-align:center">Check your email</h2>
        <p class="sub" style="text-align:center">If that email address is registered, we've sent a password reset link. Check your inbox and spam folder.<br><br>The link expires in <strong style="color:var(--text)">1 hour</strong>.</p>

      <?php elseif ($step === 'reset'): ?>
        <h2>Set new password</h2>
        <p class="sub">Choose a strong password with at least 6 characters.</p>
        <?php if ($error): ?><div class="error-box">⚠️ <?= h($error) ?></div><?php endif; ?>
        <?php if ($validToken): ?>
        <form method="POST">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="6" autofocus>
          </div>
          <div class="form-group" style="margin-bottom:18px">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required minlength="6">
          </div>
          <button type="submit" class="btn">Update Password →</button>
        </form>
        <?php endif; ?>

      <?php elseif ($step === 'done'): ?>
        <div class="icon-big">✅</div>
        <h2 style="text-align:center">Password updated!</h2>
        <p class="sub" style="text-align:center">Your password has been successfully changed. You can now sign in with your new password.</p>
        <a href="<?= SITE_URL ?>/index.php" class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none;margin-top:8px">Go to Sign In →</a>
      <?php endif; ?>

    </div>
    <?php if ($step !== 'done'): ?>
      <a href="<?= SITE_URL ?>/index.php" class="back-link">← Back to Sign In</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
