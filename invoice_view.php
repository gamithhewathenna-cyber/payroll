<?php
// Public, no-login invoice viewer for clients. Reached via the "View Invoice" /
// "View & Download Invoice" button in emails, which carries a secure per-invoice
// token (?t=). If the token is missing or doesn't match (e.g. a very old email,
// or the link was forwarded without the query string), the client can instead
// verify by typing the email address the invoice was sent to — no password.
require_once 'config.php';
require_once 'includes/invoice_render.php';
require_once 'includes/invoice_access.php';
$db = getDB();

$id    = (int)($_GET['id'] ?? 0);
$token = $_GET['t'] ?? '';
if (!$id) { http_response_code(404); exit('Invoice not found.'); }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.phone as c_phone, c.address, c.address_line2, c.city, c.country, c.vat_number FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { http_response_code(404); exit('Invoice not found.'); }

$unlocked = false;
$error    = '';

if ($token && $inv['access_token'] && hash_equals($inv['access_token'], $token)) {
    $unlocked = true;
    $_SESSION['invoice_unlock_' . $id] = true;
} elseif (!empty($_SESSION['invoice_unlock_' . $id])) {
    $unlocked = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_email'])) {
    $submitted = strtolower(trim($_POST['verify_email']));
    if ($submitted && $inv['c_email'] && strtolower(trim($inv['c_email'])) === $submitted) {
        $unlocked = true;
        $_SESSION['invoice_unlock_' . $id] = true;
    } else {
        $error = "That email address doesn't match our records for this invoice. Please try again or contact accounts@creativelements.co.";
    }
}

$isQuote  = $inv['invoice_type'] === 'quotation';
$docLabel = $isQuote ? 'Quotation' : 'Invoice';

if (!$unlocked) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $docLabel ?> <?= h($inv['invoice_number']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0d1117;--bg2:#161b22;--bg3:#21262d;--border:#30363d;--text:#e6edf3;--text2:#8b949e;--accent:#3b82f6;--red:#ff4d6d}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;font-size:14px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:32px;max-width:400px;width:100%}
.icon{font-size:34px;text-align:center;margin-bottom:10px}
h1{font-size:18px;text-align:center;margin-bottom:6px}
p.sub{color:var(--text2);font-size:13px;text-align:center;margin-bottom:22px;line-height:1.6}
label{font-size:12.5px;font-weight:600;color:var(--text2);display:block;margin-bottom:6px}
input{background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:11px 14px;border-radius:8px;font-family:'Poppins',sans-serif;font-size:13.5px;width:100%}
input:focus{outline:none;border-color:var(--accent)}
.btn{display:block;width:100%;padding:12px;border-radius:8px;border:none;cursor:pointer;font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;background:var(--accent);color:#fff;margin-top:16px}
.btn:hover{filter:brightness(1.1)}
.error-box{background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.3);border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:12.5px;color:var(--red)}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🔒</div>
  <h1><?= $docLabel ?> <?= h($inv['invoice_number']) ?></h1>
  <p class="sub">For your security, please confirm the email address this <?= strtolower($docLabel) ?> was sent to.</p>
  <?php if ($error): ?><div class="error-box">⚠️ <?= h($error) ?></div><?php endif; ?>
  <form method="POST">
    <label>Email Address</label>
    <input type="email" name="verify_email" required autofocus placeholder="you@example.com">
    <button type="submit" class="btn">View <?= $docLabel ?></button>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$S = [];
foreach ($db->query("SELECT setting_key,setting_value FROM settings")->fetchAll() as $r) $S[$r['setting_key']] = $r['setting_value'];

$invNo = $inv['invoice_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($invNo) ?></title>
<script>document.title = '<?= h($invNo) ?>';</script>
<style>
@media print { .no-print { display:none !important; } }
@media screen {
    body { background:#e8e8e8; }
    .page { background:#fff; box-shadow:0 2px 20px rgba(0,0,0,.12); margin:24px auto; }
    .toolbar { text-align:center; padding:14px; background:#333; }
    .toolbar button { background:#3b82f6; color:#fff; border:none; padding:9px 22px; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; }
}
</style>
</head>
<body>
<div class="toolbar no-print">
  <button onclick="document.title='<?= h($invNo) ?>';window.print();">🖨 Save as PDF / Print</button>
</div>
<?= renderInvoiceHtml($inv, $items, $S) ?>
</body>
</html>
