<?php
require_once 'config.php';
requireLogin();
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

$sym         = $S['currency_symbol'] ?? 'Rs.';
$companyName = $S['company_name']    ?? 'Creative Elements (Pvt) Ltd';
$logoPath    = $S['logo_path']       ?? '';
$isQuote     = $inv['invoice_type'] === 'quotation';
$docLabel    = $isQuote ? 'QUOTATION' : 'INVOICE';
$autoDownload = isset($_GET['download']);
$invCur      = $inv['inv_currency'] ?? 'LKR';
$invRate     = (float)($inv['inv_rate'] ?? 1) ?: 1;
$isForeign   = $invCur !== 'LKR';
$curSymbols  = ['LKR'=>'Rs.','USD'=>'$','AUD'=>'A$','EUR'=>'€','GBP'=>'£','SGD'=>'S$'];
$fSym        = $curSymbols[$invCur] ?? $invCur;
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
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:Arial,sans-serif; background:#fff; color:#111; font-size:13px; }
.page { max-width:850px; margin:0 auto; padding:28px 32px; }
.header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; padding-bottom:14px; border-bottom:3px solid #111; }
.logo { height:44px; margin-bottom:5px; display:block; }
.company-name { font-size:16px; font-weight:800; }
.company-sub { font-size:10px; color:#555; line-height:1.6; margin-top:3px; }
.doc-title { text-align:right; }
.doc-title h1 { font-size:28px; font-weight:900; letter-spacing:-1px; color:#111; }
.doc-title .inv-num { font-size:13px; color:#555; margin-top:3px; }
.doc-title .status-badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:11px; font-weight:700; margin-top:6px; }
.status-draft { background:#e3f0ff; color:#004085; }
.status-sent { background:#fff3cd; color:#856404; }
.status-paid { background:#d4edda; color:#155724; }
.status-overdue { background:#f8d7da; color:#721c24; }
.status-cancelled { background:#e2e3e5; color:#383d41; }
.meta-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.meta-box h3 { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#888; margin-bottom:6px; padding-bottom:3px; border-bottom:1px solid #eee; }
.meta-box p { font-size:12px; line-height:1.6; }
.meta-box strong { color:#111; }
.dates-box { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; background:#f8f8f8; border-radius:6px; padding:10px 14px; margin-bottom:16px; }
.date-item .label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#888; margin-bottom:2px; }
.date-item .val { font-size:12px; font-weight:600; }
table { width:100%; border-collapse:collapse; margin-bottom:14px; }
thead th { background:#111; color:#fff; padding:8px 10px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
thead th:last-child, thead th:nth-last-child(2), thead th:nth-last-child(3) { text-align:right; }
tbody td { padding:8px 10px; border-bottom:1px solid #f0f0f0; font-size:12px; vertical-align:top; }
tbody td:last-child, tbody td:nth-last-child(2), tbody td:nth-last-child(3) { text-align:right; }
tbody tr:last-child td { border-bottom:none; }
.totals { display:flex; justify-content:flex-end; margin-bottom:14px; }
.totals-table { width:260px; }
.totals-table tr td { padding:4px 0; font-size:12px; }
.totals-table tr td:last-child { text-align:right; font-weight:600; }
.totals-table .total-row td { font-size:15px; font-weight:800; padding-top:8px; border-top:2px solid #111; }
.totals-table .total-row td:last-child { color:#1a7c4e; }
.bank-box { background:#f8f8f8; border:1px solid #e0e0e0; border-radius:6px; padding:10px 14px; margin-bottom:14px; }
.bank-box h3 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#555; margin-bottom:8px; }
.bank-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:8px; }
.bank-item .label { font-size:9px; color:#888; margin-bottom:2px; }
.bank-item .val { font-size:11px; font-weight:600; }
.footer-notes { margin-bottom:10px; }
.footer-notes h3 { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#888; margin-bottom:4px; }
.footer-notes p { font-size:11px; color:#555; line-height:1.5; }
.page-footer { border-top:1px solid #ddd; padding-top:8px; display:flex; justify-content:space-between; font-size:9px; color:#999; }
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

<?php
$saveYear  = date('Y', strtotime($inv['issue_date']));
$saveMonth = date('F', strtotime($inv['issue_date']));
$suggestedPath = "Client Invoices / {$saveYear} / {$saveMonth} / {$invNo}.pdf";
?>
<div class="toolbar no-print">
  <button onclick="document.title='<?= h($invNo) ?>';window.print();" style="background:#3b82f6;color:#fff;border:none;padding:9px 22px;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer">🖨 Save as PDF</button>
  <a href="<?= SITE_URL ?>/invoices.php?action=edit&id=<?= $id ?>" style="color:#aaa;font-size:12px;text-decoration:none">✏️ Edit</a>
  <a href="<?= SITE_URL ?>/invoices.php" style="color:#aaa;font-size:12px;text-decoration:none">← Back</a>
</div>
<div class="save-hint no-print">
  💾 Save as: <strong><?= h($invNo) ?>.pdf</strong> &nbsp;|&nbsp; Suggested folder: <strong><?= h($suggestedPath) ?></strong>
</div>

<div class="page">

  <!-- Header -->
  <div class="header">
    <div>
      <?php if ($logoPath): ?>
        <img src="<?= h(SITE_URL.'/'.$logoPath) ?>" class="logo" alt="Logo">
      <?php endif; ?>
      <div class="company-name"><?= h($companyName) ?></div>
      <div class="company-sub">
        <?= h($S['company_address'] ?? '27/1, 1st Lane, Boralesgamuwa') ?><br>
        <?= h($S['company_email'] ?? 'accounts@creativelements.co') ?>
        <?php if (!empty($S['company_phone'])): ?> · <?= h($S['company_phone']) ?><?php endif; ?>
        <?php if (!empty($S['company_reg'])): ?><br>Reg: <?= h($S['company_reg']) ?><?php endif; ?>
        <?php if (!empty($S['company_vat'])): ?> · VAT: <?= h($S['company_vat']) ?><?php endif; ?>
      </div>
    </div>
    <div class="doc-title">
      <h1><?= $docLabel ?></h1>
      <div class="inv-num"><?= h($inv['invoice_number']) ?></div>
      <div><span class="status-badge status-<?= $inv['status'] ?>"><?= strtoupper($inv['status']) ?></span></div>
    </div>
  </div>

  <!-- Dates -->
  <div class="dates-box">
    <div class="date-item"><div class="label">Issue Date</div><div class="val"><?= date('d F Y',strtotime($inv['issue_date'])) ?></div></div>
    <?php if ($inv['due_date']): ?>
      <div class="date-item"><div class="label">Due Date</div><div class="val"><?= date('d F Y',strtotime($inv['due_date'])) ?></div></div>
    <?php endif; ?>
    <?php if ($inv['billing_month']): ?>
      <div class="date-item"><div class="label">Billing Period</div><div class="val"><?= date('F Y',strtotime($inv['billing_month'].'-01')) ?></div></div>
    <?php endif; ?>
    <?php if ($inv['paid_date']): ?>
      <div class="date-item"><div class="label">Paid On</div><div class="val" style="color:#1a7c4e"><?= date('d F Y',strtotime($inv['paid_date'])) ?></div></div>
    <?php endif; ?>
  </div>

  <!-- Bill To -->
  <?php $manualData = !empty($inv['manual_client_data']) ? json_decode($inv['manual_client_data'], true) : null; ?>
  <div class="meta-row">
    <div class="meta-box">
      <h3>Bill To</h3>
      <?php if ($manualData): ?>
      <p>
        <strong><?= h($manualData['company']) ?></strong><br>
        <?php if ($manualData['contact']): ?><?= h($manualData['contact']) ?><br><?php endif; ?>
        <?php if ($manualData['address']): ?><?= nl2br(h($manualData['address'])) ?><br><?php endif; ?>
        <?php if ($manualData['email']): ?><?= h($manualData['email']) ?><?php endif; ?>
        <?php if ($manualData['phone']): ?><br><?= h($manualData['phone']) ?><?php endif; ?>
      </p>
      <?php else: ?>
      <p>
        <strong><?= h($inv['company_name']) ?></strong><br>
        <?php if ($inv['contact_name']): ?><?= h($inv['contact_name']) ?><br><?php endif; ?>
        <?php if ($inv['address']): ?><?= nl2br(h($inv['address'])) ?><br><?php endif; ?>
        <?php if ($inv['address_line2']): ?><?= h($inv['address_line2']) ?><br><?php endif; ?>
        <?php if ($inv['city'] || $inv['country']): ?><?= h(implode(', ', array_filter([$inv['city'],$inv['country']]))) ?><br><?php endif; ?>
        <?php if ($inv['c_email']): ?><?= h($inv['c_email']) ?><?php endif; ?>
        <?php if ($inv['c_phone']): ?><br><?= h($inv['c_phone']) ?><?php endif; ?>
        <?php if ($inv['vat_number']): ?><br>VAT: <?= h($inv['vat_number']) ?><?php endif; ?>
      </p>
      <?php endif; ?>
    </div>
    <div class="meta-box">
      <h3>From</h3>
      <p>
        <strong><?= h($companyName) ?></strong><br>
        <?= h($S['company_address'] ?? '27/1, 1st Lane, Boralesgamuwa') ?><br>
        <?= h($S['company_email'] ?? 'accounts@creativelements.co') ?>
      </p>
    </div>
  </div>

  <!-- Line Items -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Description</th>
        <th style="text-align:right">Qty</th>
        <th style="text-align:right">Unit Price</th>
        <th style="text-align:right">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item):
        $dParts   = explode('|||', $item['description'], 2);
        $mainDesc = trim($dParts[0]);
        $subDesc  = isset($dParts[1]) ? trim($dParts[1]) : '';
        // For foreign currency invoices: show in foreign currency
        // Expense items are always in LKR regardless
        $isExpItem   = $item['item_type'] === 'expense';
        $displaySym  = ($isForeign && !$isExpItem) ? $fSym : $sym;
        $unitDisplay = ($isForeign && !$isExpItem) ? $item['unit_price'] : ($isExpItem ? $item['unit_price'] : $item['unit_price'] * $invRate);
        $amtDisplay  = ($isForeign && !$isExpItem) ? $item['quantity'] * $item['unit_price'] : $item['amount'];
      ?>
      <tr>
        <td style="color:#888;width:24px"><?= $i+1 ?></td>
        <td>
          <strong><?= h($mainDesc) ?></strong>
          <?php if ($subDesc): ?><br><span style="font-size:10px;color:#666;line-height:1.5"><?= nl2br(h($subDesc)) ?></span><?php endif; ?>
        </td>
        <td style="text-align:right;color:#555;white-space:nowrap"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></td>
        <td style="text-align:right;white-space:nowrap"><?= $displaySym ?> <?= number_format($unitDisplay,2) ?></td>
        <td style="text-align:right;white-space:nowrap"><strong><?= $displaySym ?> <?= number_format($amtDisplay,2) ?></strong></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals">
    <table class="totals-table">
      <?php
      $tSym   = $isForeign ? $fSym : $sym;
      $tTotal = $isForeign ? ($inv['total'] / $invRate) : $inv['total'];
      $tSub   = $isForeign ? ($inv['subtotal'] / $invRate) : $inv['subtotal'];
      $tDisc  = $isForeign ? ($inv['discount_amt'] / $invRate) : $inv['discount_amt'];
      $tTax   = $isForeign ? ($inv['tax_amt'] / $invRate) : $inv['tax_amt'];
      if ($isForeign): ?>
      <tr><td colspan="2" style="font-size:10px;color:#888;padding-bottom:6px">Exchange rate used: 1 <?= h($invCur) ?> = <?= $sym ?> <?= number_format($invRate,2) ?></td></tr>
      <?php endif; ?>
      <tr><td style="color:#555">Subtotal</td><td><?= $tSym ?> <?= number_format($tSub,2) ?></td></tr>
      <?php if ($inv['discount_pct'] > 0): ?>
        <tr><td style="color:#c0392b">Discount (<?= $inv['discount_pct'] ?>%)</td><td style="color:#c0392b">-<?= $tSym ?> <?= number_format($tDisc,2) ?></td></tr>
      <?php endif; ?>
      <?php if ($inv['tax_pct'] > 0): ?>
        <tr><td>Tax (<?= $inv['tax_pct'] ?>%)</td><td><?= $tSym ?> <?= number_format($tTax,2) ?></td></tr>
      <?php endif; ?>
      <tr class="total-row"><td>TOTAL</td><td><?= $tSym ?> <?= number_format($tTotal,2) ?></td></tr>
    </table>
  </div>

  <!-- Bank Details -->
  <?php if (!empty($S['bank_name']) || !empty($S['bank_account_number'])): ?>
  <div class="bank-box">
    <h3>Payment Details</h3>
    <div class="bank-grid">
      <?php if ($S['bank_name']): ?><div class="bank-item"><div class="label">Bank</div><div class="val"><?= h($S['bank_name']) ?></div></div><?php endif; ?>
      <?php if ($S['bank_account_name']): ?><div class="bank-item"><div class="label">Account Name</div><div class="val"><?= h($S['bank_account_name']) ?></div></div><?php endif; ?>
      <?php if ($S['bank_account_number']): ?><div class="bank-item"><div class="label">Account Number</div><div class="val"><?= h($S['bank_account_number']) ?></div></div><?php endif; ?>
      <?php if ($S['bank_branch']): ?><div class="bank-item"><div class="label">Branch</div><div class="val"><?= h($S['bank_branch']) ?></div></div><?php endif; ?>
      <?php if ($S['bank_swift']): ?><div class="bank-item"><div class="label">SWIFT</div><div class="val"><?= h($S['bank_swift']) ?></div></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Notes & Terms -->
  <?php if ($inv['terms']): ?>
  <div class="footer-notes"><h3>Terms & Conditions</h3><p><?= nl2br(h($inv['terms'])) ?></p></div>
  <?php endif; ?>
  <?php if ($inv['notes']): ?>
  <div class="footer-notes"><h3>Notes</h3><p><?= nl2br(h($inv['notes'])) ?></p></div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="page-footer">
    <span><?= h($companyName) ?> · <?= h($S['company_address']??'') ?> · <?= h($S['company_email']??'') ?></span>
    <span><?= $docLabel ?> <?= h($inv['invoice_number']) ?> · Generated <?= date('d M Y') ?></span>
  </div>

</div>
</body>
</html>
