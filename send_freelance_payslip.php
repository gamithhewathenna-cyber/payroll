<?php
require_once 'config.php';
requireAdmin();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error','Invalid request.'); header('Location:'.SITE_URL.'/freelance.php'); exit; }

$stmt = $db->prepare("SELECT fp.*, f.freelancer_name, f.email, f.phone, f.bank_name, f.bank_account, f.bank_branch FROM freelance_payments fp JOIN freelancers f ON f.id=fp.freelancer_id WHERE fp.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p || !$p['email']) { setFlash('error','No email address for this freelancer.'); header('Location:'.SITE_URL.'/freelance.php'); exit; }

$companyName    = getSetting('company_name', SITE_NAME);
$companyAddress = getSetting('company_address', '');
$period         = date('F Y', strtotime($p['month'].'-01'));

function fm2($amount) { return getSetting('currency_symbol','Rs.') . ' ' . number_format((float)$amount,2); }

ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#333}
.wrap{max-width:620px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.header{background:#1a1f2e;color:#fff;padding:28px 32px}
.header h1{margin:0 0 4px;font-size:22px}
.header p{margin:0;opacity:.7;font-size:13px}
.badge-fl{display:inline-block;background:rgba(245,166,35,.2);color:#f5a623;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;margin-top:8px}
.meta{background:#f5a623;color:#fff;padding:14px 32px;display:flex;justify-content:space-between;font-size:13px}
.body{padding:28px 32px}
.section{margin-bottom:22px}
.section h3{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#888;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:10px}
.row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px;border-bottom:1px solid #f5f5f5}
.row:last-child{border-bottom:none}
.row .label{color:#666}
.row .value{font-weight:600;color:#222}
.total-box{background:#f0fdf4;border:2px solid #00c48c;border-radius:8px;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;margin-top:20px}
.total-label{font-size:15px;font-weight:700;color:#333}
.total-amount{font-size:26px;font-weight:800;color:#00c48c}
.status-paid{background:#00c48c;color:#fff;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.status-pending{background:#f5a623;color:#fff;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.notice{background:#fff8e1;border-left:4px solid #f5a623;padding:12px 16px;border-radius:4px;font-size:13px;color:#666;margin-bottom:20px}
.footer{background:#f8f8f8;padding:18px 32px;text-align:center;font-size:12px;color:#999;border-top:1px solid #eee}
</style></head><body>
<div class="wrap">
  <div class="header">
    <h1><?= htmlspecialchars($companyName) ?></h1>
    <p>Freelance/Outsource Payment Slip — <?= $period ?></p>
    <span class="badge-fl">🧑‍💻 FREELANCE/OUTSOURCE PAYMENT</span>
  </div>
  <div class="meta">
    <span>📅 Period: <strong><?= $period ?></strong></span>
    <?php if ($p['invoice_number']): ?><span>Invoice: <strong><?= htmlspecialchars($p['invoice_number']) ?></strong></span><?php endif; ?>
    <span><?= $p['payment_status']==='paid' ? '<span class="status-paid">✓ PAID</span>' : '<span class="status-pending">⏳ PENDING</span>' ?></span>
  </div>
  <div class="body">
    <p style="font-size:15px;margin-bottom:20px">Dear <strong><?= htmlspecialchars($p['freelancer_name']) ?></strong>,<br>
    Please find your Freelance/Outsource payment slip for <strong><?= $period ?></strong> below.</p>
    <div class="notice">📌 This is a Freelance/Outsource payment slip from <?= htmlspecialchars($companyName) ?>. Please keep it for your records.</div>

    <div class="section">
      <h3>Project Details</h3>
      <div class="row"><span class="label">Project Name</span><span class="value"><?= htmlspecialchars($p['project_name']) ?></span></div>
      <?php if ($p['invoice_number']): ?><div class="row"><span class="label">Invoice Number</span><span class="value"><?= htmlspecialchars($p['invoice_number']) ?></span></div><?php endif; ?>
      <div class="row"><span class="label">Payment Method</span><span class="value"><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></span></div>
      <?php if ($p['notes']): ?><div class="row"><span class="label">Notes</span><span class="value"><?= htmlspecialchars($p['notes']) ?></span></div><?php endif; ?>
    </div>

    <?php if ($p['bank_name'] || $p['bank_account']): ?>
    <div class="section">
      <h3>Bank Details</h3>
      <?php if ($p['bank_name']): ?><div class="row"><span class="label">Bank</span><span class="value"><?= htmlspecialchars($p['bank_name']) ?></span></div><?php endif; ?>
      <?php if ($p['bank_account']): ?><div class="row"><span class="label">Account No.</span><span class="value"><?= htmlspecialchars($p['bank_account']) ?></span></div><?php endif; ?>
      <?php if ($p['bank_branch']): ?><div class="row"><span class="label">Branch</span><span class="value"><?= htmlspecialchars($p['bank_branch']) ?></span></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="total-box">
      <span class="total-label">Payment Amount</span>
      <span class="total-amount"><?= fm2($p['payment_amount']) ?></span>
    </div>
  </div>
  <div class="footer">
    <p><?= htmlspecialchars($companyName) ?><?= $companyAddress ? ' · '.htmlspecialchars($companyAddress) : '' ?></p>
    <p style="margin-top:4px">This is a system-generated payment slip. Please do not reply to this email.</p>
  </div>
</div>
</body></html>
<?php
$emailBody = ob_get_clean();

$toEmail   = $p['email'];
$fromEmail = getSetting('email_from') ?: (getSetting('company_email') ?: 'payroll@creativelements.co');
$fromName  = $companyName;
$subject   = "Payment Slip: {$period} | {$companyName}";
$msgId     = '<'.time().'.'.md5($toEmail).'@creativelements.co>';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: {$fromName} <{$fromEmail}>\r\n";
$ccEmail = getCCEmail();
if ($ccEmail) $headers .= "Cc: {$ccEmail}\r\n";
$headers .= "Reply-To: {$fromEmail}\r\n";
$headers .= "Return-Path: {$fromEmail}\r\n";
$headers .= "Message-ID: {$msgId}\r\n";
$headers .= "Date: ".date('r')."\r\n";
$headers .= "X-Mailer: PHP/".phpversion();

$sent = mail($toEmail, $subject, $emailBody, $headers, "-f{$fromEmail}");

if ($sent) {
    $ccNote = $ccEmail ? " (CC: {$ccEmail})" : '';
    setFlash('success', "✅ Payment slip emailed to {$p['freelancer_name']} ({$toEmail}){$ccNote}!");
} else {
    setFlash('error', "❌ Failed to send email to {$toEmail}.");
}
header('Location: '.SITE_URL.'/freelance_payslip.php?id='.$id);
exit;
