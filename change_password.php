<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();
$db = getDB();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All fields are required.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([currentUserId()]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, currentUserId()]);
            setFlash('success', 'Password changed successfully!');
            header('Location: ' . SITE_URL . '/change_password.php');
            exit;
        }
    }
}

pageHeader('Change Password');
?>

<div style="max-width:420px">
  <div class="card">
    <div class="card-title">Change Your Password</div>

    <?php if ($error): ?>
      <div class="flash-msg" style="background:var(--red);margin-bottom:16px"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group" style="margin-bottom:14px">
        <label>Current Password</label>
        <input type="password" name="current_password" required autofocus>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label>New Password</label>
        <input type="password" name="new_password" required minlength="6">
      </div>
      <div class="form-group" style="margin-bottom:20px">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" required minlength="6">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Password</button>
        <a href="<?= SITE_URL ?>/dashboard.php" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>

  <p style="font-size:12px;color:var(--text2);margin-top:12px;text-align:center">
    Password must be at least 6 characters long.
  </p>
</div>

<?php pageFooter(); ?>
