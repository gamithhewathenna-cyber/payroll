<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$rows = $db->query("
    SELECT id, invoice_number, client_id, issue_date, status, subtotal, discount_amt, tax_amt, total, inv_currency, inv_rate
    FROM invoices
    WHERE invoice_type='invoice' AND DATE_FORMAT(issue_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
    ORDER BY issue_date
")->fetchAll();

$dupes = $db->query("
    SELECT invoice_number, COUNT(*) as cnt
    FROM invoices
    WHERE invoice_type='invoice'
    GROUP BY invoice_number
    HAVING cnt > 1
")->fetchAll();

$itemCheck = [];
foreach ($rows as $r) {
    $items = $db->prepare("SELECT item_type, description, quantity, unit_price, amount FROM invoice_items WHERE invoice_id=?");
    $items->execute([$r['id']]);
    $itemCheck[$r['id']] = $items->fetchAll();
}

$sum = array_sum(array_column($rows, 'total'));

pageHeader('Invoice Audit (Temporary)');
?>
<div class="card" style="margin-bottom:20px">
  <div class="card-title">🔍 October Invoice Audit</div>
  <p style="font-size:13px;color:var(--text2)">This is a temporary diagnostic page — delete <code>debug_invoice_audit.php</code> once we're done.</p>
  <p style="font-size:14px;margin-top:10px"><strong><?= count($rows) ?></strong> invoices this month, summing to <strong><?= number_format($sum, 2) ?></strong></p>
</div>

<?php if (!empty($dupes)): ?>
<div class="card" style="margin-bottom:20px;border-color:var(--red)">
  <div class="card-title" style="color:var(--red)">⚠️ Duplicate Invoice Numbers Found</div>
  <table>
    <thead><tr><th>Invoice #</th><th>Count</th></tr></thead>
    <tbody>
      <?php foreach ($dupes as $d): ?>
        <tr><td><?= h($d['invoice_number']) ?></td><td><?= $d['cnt'] ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:20px">
  <div style="color:var(--green)">✅ No duplicate invoice numbers found.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-title">This Month's Invoices</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Invoice #</th><th>Date</th><th>Status</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th><th>Currency</th><th>Rate</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= h($r['invoice_number']) ?></td>
            <td><?= h($r['issue_date']) ?></td>
            <td><?= h($r['status']) ?></td>
            <td><?= number_format($r['subtotal'],2) ?></td>
            <td><?= number_format($r['discount_amt'],2) ?></td>
            <td><?= number_format($r['tax_amt'],2) ?></td>
            <td><strong><?= number_format($r['total'],2) ?></strong></td>
            <td><?= h($r['inv_currency']) ?></td>
            <td><?= number_format($r['inv_rate'],2) ?></td>
          </tr>
          <?php foreach ($itemCheck[$r['id']] as $it): ?>
            <tr style="background:var(--bg3);font-size:12px;color:var(--text2)">
              <td></td>
              <td colspan="2">↳ [<?= h($it['item_type']) ?>] <?= h($it['description']) ?></td>
              <td colspan="3">qty <?= h($it['quantity']) ?> × <?= number_format($it['unit_price'],2) ?></td>
              <td colspan="4">amount: <strong><?= number_format($it['amount'],2) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php pageFooter(); ?>
