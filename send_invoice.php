<?php
require_once 'config.php';
requireAdmin();
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'invoices';
if (!$id) { setFlash('error','Invalid request.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.cc_emails as c_cc_emails FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { setFlash('error','Invoice not found.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }
if (!$inv['c_email']) { setFlash('error','This client has no email address on file.'); header('Location: '.SITE_URL.'/invoice_form.php?id='.$id.'&tab='.$tab); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$S = [];
foreach ($db->query("SELECT setting_key,setting_value FROM settings")->fetchAll() as $r) $S[$r['setting_key']] = $r['setting_value'];

$sym         = $S['currency_symbol'] ?? 'Rs.';
$companyName = $S['company_name']    ?? SITE_NAME;
$isQuote     = $inv['invoice_type'] === 'quotation';
$docLabel    = $isQuote ? 'Quotation' : 'Invoice';
$period      = date('d F Y', strtotime($inv['issue_date']));

function fm2($amount, $sym) { return $sym . ' ' . number_format((float)$amount, 2); }

ob_start();
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
  .wrap { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
  .header { background: #1a1f2e; color: #fff; padding: 28px 32px; }
  .header h1 { margin: 0 0 4px; font-size: 22px; }
  .header p { margin: 0; opacity: .7; font-size: 13px; }
  .meta { background: #3b82f6; color: #fff; padding: 14px 32px; display: flex; justify-content: space-between; font-size: 13px; }
  .body { padding: 28px 32px; }
  .greeting { font-size: 15px; margin-bottom: 20px; color: #444; }
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  table.items th { background: #f8f8f8; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: #888; padding: 8px 10px; }
  table.items th.r, table.items td.r { text-align: right; }
  table.items td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #f0f0f0; }
  .total-box { background: #f0fdf4; border: 2px solid #00c48c; border-radius: 8px; padding: 18px 24px; display: flex; justify-content: space-between; align-items: center; margin-top: 10px; }
  .total-label { font-size: 15px; font-weight: 700; color: #333; }
  .total-amount { font-size: 26px; font-weight: 800; color: #00c48c; }
  .btn-view { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 7px; font-weight: 700; font-size: 14px; margin-top: 20px; }
  .footer { background: #f8f8f8; padding: 18px 32px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1><?= htmlspecialchars($companyName) ?></h1>
    <p><?= $docLabel ?> <?= htmlspecialchars($inv['invoice_number']) ?></p>
  </div>
  <div class="meta">
    <span>📅 Issued: <strong><?= $period ?></strong></span>
    <?php if ($inv['due_date']): ?><span><?= $isQuote?'Valid Until':'Due' ?>: <strong><?= date('d M Y', strtotime($inv['due_date'])) ?></strong></span><?php endif; ?>
  </div>
  <div class="body">
    <p class="greeting">Dear <strong><?= htmlspecialchars($inv['contact_name'] ?: $inv['company_name']) ?></strong>,<br>
    Please find your <?= strtolower($docLabel) ?> <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong> below.</p>

    <table class="items">
      <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($items as $item): $desc = explode('|||', $item['description'], 2)[0]; ?>
        <tr><td><?= htmlspecialchars(trim($desc)) ?></td><td class="r"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td><td class="r"><?= fm2($item['amount'], $sym) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="total-box">
      <span class="total-label">Total <?= $isQuote?'Amount':'Due' ?></span>
      <span class="total-amount"><?= fm2($inv['total'], $sym) ?></span>
    </div>

    <div style="text-align:center">
      <a class="btn-view" href="<?= SITE_URL ?>/invoice_print.php?id=<?= $id ?>"><?= $isQuote?'View Quotation':'View & Download Invoice' ?></a>
    </div>
  </div>
  <div class="footer">
    <p><?= htmlspecialchars($companyName) ?></p>
    <p style="margin-top:4px">This is a system-generated <?= strtolower($docLabel) ?>. Please do not reply to this email.</p>
  </div>
</div>
</body>
</html>
<?php
$emailBody = ob_get_clean();

// Recipients
$toEmail  = $inv['c_email'];
$ccList   = array_filter(array_map('trim', explode(',', ($inv['c_cc_emails'] ?? '') . ',' . (getSetting('invoice_cc_emails','')))));
$ccList   = array_unique($ccList);

$fromEmail = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');
$fromName  = $companyName;
$subject   = "{$docLabel} {$inv['invoice_number']} from {$companyName}";
$msgId     = '<' . time() . '.' . md5($toEmail) . '@creativelements.co>';

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: {$fromName} <{$fromEmail}>\r\n";
if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
$headers .= "Reply-To: {$fromEmail}\r\n";
$headers .= "Return-Path: {$fromEmail}\r\n";
$headers .= "Message-ID: {$msgId}\r\n";
$headers .= "Date: " . date('r') . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$params = "-f{$fromEmail}";
$sent = mail($toEmail, $subject, $emailBody, $headers, $params);

if ($sent) {
    $allRecipients = array_merge([$toEmail], $ccList);
    $db->prepare("INSERT INTO invoice_emails (invoice_id, email_type, sent_to) VALUES (?,?,?)")
       ->execute([$id, 'invoice', implode(', ', $allRecipients)]);
    if ($inv['status'] === 'draft') {
        $db->prepare("UPDATE invoices SET status='sent' WHERE id=?")->execute([$id]);
    }
    $ccNote = $ccList ? ' (CC: ' . implode(', ', $ccList) . ')' : '';
    setFlash('success', "✅ {$docLabel} emailed to {$toEmail}{$ccNote}!");
} else {
    setFlash('error', "❌ Failed to send email to {$toEmail}. Check your cPanel email settings.");
}

header('Location: ' . SITE_URL . '/invoice_form.php?id=' . $id . '&tab=' . $tab);
exit;
