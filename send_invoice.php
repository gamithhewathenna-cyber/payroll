<?php
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/invoice_render.php';
requireAdmin();
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'invoices';
if (!$id) { setFlash('error','Invalid request.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.cc_emails as c_cc_emails, c.phone as c_phone, c.address, c.address_line2, c.city, c.country, c.vat_number FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
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
$greetName   = $inv['contact_name'] ?: $inv['company_name'];
$monthLabel  = $inv['billing_month'] ? date('F Y', strtotime($inv['billing_month'].'-01')) : date('F Y', strtotime($inv['issue_date']));

// ── Render the exact same branded invoice used on invoice_print.php, as a real PDF ──
$tmpDir = __DIR__ . '/tmp/mpdf';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

$mpdf = new \Mpdf\Mpdf([
    'format'     => 'A4',
    'margin_left'   => 10,
    'margin_right'  => 10,
    'margin_top'    => 10,
    'margin_bottom' => 12,
    'tempDir'    => $tmpDir,
]);
$mpdf->WriteHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . renderInvoiceHtml($inv, $items, $S) . '</body></html>');
$pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
$pdfName  = preg_replace('/[^A-Za-z0-9._-]/', '_', $inv['invoice_number']) . '.pdf';

// ── Simple, left-aligned, mobile-friendly email body ──
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin:0; padding:0; background:#fff; font-family:Arial,Helvetica,sans-serif; }
  .wrap { max-width:100%; padding:24px; text-align:left; color:#222; font-size:14px; line-height:1.6; }
  p { margin:0 0 14px; }
</style>
</head>
<body>
<div class="wrap">
  <p>Hi <?= htmlspecialchars($greetName) ?>,</p>
  <p>Please find the attached <?= strtolower($docLabel) ?> for <?= htmlspecialchars($monthLabel) ?>.</p>
  <p>If you have any questions, please let us know.</p>
  <p>Thank you!</p>
  <p><?= htmlspecialchars($companyName) ?></p>
</div>
</body>
</html>
<?php
$emailBody = ob_get_clean();

// Recipients
$toEmail = $inv['c_email'];
$ccList  = array_unique(array_filter(array_map('trim', explode(',', ($inv['c_cc_emails'] ?? '') . ',' . getSetting('invoice_cc_emails','')))));

$fromEmail = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');
$fromName  = $companyName;
$subject   = "{$docLabel} {$inv['invoice_number']} from {$companyName}";
$msgId     = '<' . time() . '.' . md5($toEmail) . '@creativelements.co>';
$boundary  = 'PR_' . md5($msgId);

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "From: {$fromName} <{$fromEmail}>\r\n";
if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
$headers .= "Reply-To: {$fromEmail}\r\n";
$headers .= "Return-Path: {$fromEmail}\r\n";
$headers .= "Message-ID: {$msgId}\r\n";
$headers .= "Date: " . date('r') . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $emailBody . "\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: application/pdf; name=\"{$pdfName}\"\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n";
$body .= "Content-Disposition: attachment; filename=\"{$pdfName}\"\r\n\r\n";
$body .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
$body .= "--{$boundary}--";

$params = "-f{$fromEmail}";
$sent = mail($toEmail, $subject, $body, $headers, $params);

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
