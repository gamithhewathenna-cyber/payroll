<?php
// Shared invoice HTML renderer — single source of truth for both the on-screen/print
// view (invoice_print.php) and the PDF attached to emails (send_invoice.php via mPDF).
function renderInvoiceHtml($inv, $items, $S) {
    $sym         = $S['currency_symbol'] ?? 'Rs.';
    $companyName = $S['company_name']    ?? 'Creative Elements (Pvt) Ltd';
    $logoPath    = $S['logo_path']       ?? '';
    $isQuote     = $inv['invoice_type'] === 'quotation';
    $docLabel    = $isQuote ? 'QUOTATION' : 'INVOICE';
    $invCur      = $inv['inv_currency'] ?? 'LKR';
    $invRate     = (float)($inv['inv_rate'] ?? 1) ?: 1;
    $isForeign   = $invCur !== 'LKR';
    $curSymbols  = ['LKR'=>'Rs.','USD'=>'$','AUD'=>'A$','EUR'=>'€','GBP'=>'£','SGD'=>'S$'];
    $fSym        = $curSymbols[$invCur] ?? $invCur;

    // Embed the logo as a data URI so it renders regardless of how the HTML is consumed (browser or PDF engine)
    $logoSrc = '';
    if ($logoPath && file_exists(__DIR__ . '/../' . $logoPath)) {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp'][$ext] ?? null;
        if ($mime) $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents(__DIR__ . '/../' . $logoPath));
    }

    ob_start();
?>
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
</style>

<div class="page">

  <!-- Header -->
  <div class="header">
    <div>
      <?php if ($logoSrc): ?>
        <img src="<?= $logoSrc ?>" class="logo" alt="Logo">
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
      <div class="date-item"><div class="label"><?= $isQuote?'Valid Until':'Due Date' ?></div><div class="val"><?= date('d F Y',strtotime($inv['due_date'])) ?></div></div>
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
        <?php if (!empty($inv['contact_name'])): ?><?= h($inv['contact_name']) ?><br><?php endif; ?>
        <?php if (!empty($inv['address'])): ?><?= nl2br(h($inv['address'])) ?><br><?php endif; ?>
        <?php if (!empty($inv['address_line2'])): ?><?= h($inv['address_line2']) ?><br><?php endif; ?>
        <?php if (!empty($inv['city']) || !empty($inv['country'])): ?><?= h(implode(', ', array_filter([$inv['city'] ?? '',$inv['country'] ?? '']))) ?><br><?php endif; ?>
        <?php if (!empty($inv['c_email'])): ?><?= h($inv['c_email']) ?><?php endif; ?>
        <?php if (!empty($inv['c_phone'])): ?><br><?= h($inv['c_phone']) ?><?php endif; ?>
        <?php if (!empty($inv['vat_number'])): ?><br>VAT: <?= h($inv['vat_number']) ?><?php endif; ?>
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
      <?php if (!empty($S['bank_name'])): ?><div class="bank-item"><div class="label">Bank</div><div class="val"><?= h($S['bank_name']) ?></div></div><?php endif; ?>
      <?php if (!empty($S['bank_account_name'])): ?><div class="bank-item"><div class="label">Account Name</div><div class="val"><?= h($S['bank_account_name']) ?></div></div><?php endif; ?>
      <?php if (!empty($S['bank_account_number'])): ?><div class="bank-item"><div class="label">Account Number</div><div class="val"><?= h($S['bank_account_number']) ?></div></div><?php endif; ?>
      <?php if (!empty($S['bank_branch'])): ?><div class="bank-item"><div class="label">Branch</div><div class="val"><?= h($S['bank_branch']) ?></div></div><?php endif; ?>
      <?php if (!empty($S['bank_swift'])): ?><div class="bank-item"><div class="label">SWIFT</div><div class="val"><?= h($S['bank_swift']) ?></div></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Notes & Terms -->
  <?php if (!empty($inv['terms'])): ?>
  <div class="footer-notes"><h3>Terms & Conditions</h3><p><?= nl2br(h($inv['terms'])) ?></p></div>
  <?php endif; ?>
  <?php if (!empty($inv['notes'])): ?>
  <div class="footer-notes"><h3>Notes</h3><p><?= nl2br(h($inv['notes'])) ?></p></div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="page-footer">
    <span><?= h($companyName) ?> · <?= h($S['company_address']??'') ?> · <?= h($S['company_email']??'') ?></span>
    <span><?= $docLabel ?> <?= h($inv['invoice_number']) ?> · Generated <?= date('d M Y') ?></span>
  </div>

</div>
<?php
    return ob_get_clean();
}
