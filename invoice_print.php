<?php
require_once 'config.php';
requireLogin();
require_once 'includes/invoice_render.php';
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location:'.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.phone as c_phone, c.address, c.address_line2, c.city, c.country, c.vat_number FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { header('Location:'.SITE_URL.'/invoices.php'); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

// Settings
$S = [];
$sRows = $db->query("SELECT setting_key,setting_value FROM settings")->fetchAll();
foreach ($sRows as $r) $S[$r['setting_key']] = $r['setting_value'];

$invNo         = $inv['invoice_number'];
$saveYear      = date('Y', strtotime($inv['issue_date']));
$saveMonth     = date('F', strtotime($inv['issue_date']));
$suggestedPath = "Client Invoices / {$saveYear} / {$saveMonth} / {$invNo}.pdf";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($invNo) ?></title>
<script>
// Force the document title (filename) before any print dialog opens
document.title = '<?= h($invNo) ?>';
</script>
<style>
@media print {
    .no-print { display:none !important; }
    body { font-size:11px; }
    .page { padding:16px 20px; max-width:100%; }
    .header { margin-bottom:14px; padding-bottom:10px; }
    .logo { height:36px; margin-bottom:4px; }
    .company-name { font-size:13px; }
    .company-sub { font-size:9px; }
    .doc-title h1 { font-size:22px; }
    .doc-title .inv-num { font-size:11px; }
    .dates-box { padding:8px 10px; margin-bottom:12px; gap:8px; }
    .date-item .label { font-size:8px; }
    .date-item .val { font-size:11px; }
    .meta-row { gap:14px; margin-bottom:14px; }
    .meta-box h3 { font-size:8px; margin-bottom:4px; padding-bottom:2px; }
    .meta-box p { font-size:11px; line-height:1.5; }
    thead th { padding:6px 8px; font-size:9px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    tbody td { padding:6px 8px; font-size:11px; }
    table { margin-bottom:10px; }
    .totals { margin-bottom:10px; }
    .totals-table tr td { padding:3px 0; font-size:11px; }
    .totals-table .total-row td { font-size:13px; padding-top:6px; }
    .bank-box { padding:8px 10px; margin-bottom:10px; }
    .bank-box h3 { font-size:8px; margin-bottom:6px; }
    .bank-item .label { font-size:8px; }
    .bank-item .val { font-size:10px; }
    .footer-notes { margin-bottom:8px; }
    .footer-notes h3 { font-size:8px; margin-bottom:3px; }
    .footer-notes p { font-size:10px; line-height:1.4; }
    .page-footer { padding-top:6px; font-size:9px; }
    .status-badge { display:none; }
}
@media screen {
    body { background:#e8e8e8; }
    .page { background:#fff; box-shadow:0 2px 20px rgba(0,0,0,.12); margin:24px auto; }
    .toolbar { text-align:center; padding:14px; background:#333; display:flex; gap:12px; justify-content:center; align-items:center; }
    .toolbar button { background:#fff; color:#111; border:none; padding:8px 20px; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; }
    .toolbar a { color:#aaa; font-size:12px; text-decoration:none; }
    .toolbar a:hover { color:#fff; }
    .save-hint { background:#1e293b; color:#94a3b8; text-align:center; padding:8px 16px; font-size:12px; font-family:monospace; border-bottom:1px solid #334155; }
    .save-hint strong { color:#e2e8f0; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="document.title='<?= h($invNo) ?>';window.print();" style="background:#3b82f6;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer">🖨 Save as PDF</button>
  <a href="<?= SITE_URL ?>/invoices.php?action=edit&id=<?= $id ?>" style="color:#aaa;font-size:12px;text-decoration:none">✏️ Edit</a>
  <a href="<?= SITE_URL ?>/invoices.php" style="color:#aaa;font-size:12px;text-decoration:none">← Back</a>
</div>
<div class="save-hint no-print">
  💾 Save as: <strong><?= h($invNo) ?>.pdf</strong> &nbsp;|&nbsp; Suggested folder: <strong><?= h($suggestedPath) ?></strong>
</div>

<?= renderInvoiceHtml($inv, $items, $S) ?>

</body>
</html>
