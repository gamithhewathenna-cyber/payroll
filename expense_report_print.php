<?php
require_once 'config.php';
requireAccess('expenses');
$db = getDB();

$filterMonth  = $_GET['month']  ?? date('Y-m');
$reportClient = trim($_GET['rclient'] ?? '');

if (!$reportClient) { header('Location: ' . SITE_URL . '/expenses.php?tab=report'); exit; }

// Load settings
$companyName = getSetting('company_name', 'Creative Elements');
$logoPath    = getSetting('logo_path', '');
$symbol      = getSetting('currency_symbol', 'Rs.');

function fm($amount) {
    global $symbol;
    return $symbol . ' ' . number_format((float)$amount, 2);
}

// Fetch all expenses for this client + month
$stmt = $db->prepare("SELECT * FROM expenses WHERE billing_month=? AND client_name=? ORDER BY expense_date ASC");
$stmt->execute([$filterMonth, $reportClient]);
$rows = $stmt->fetchAll();

// Totals
$totalCost = $totalBillable = $totalMarkup = $totalFee = 0;
foreach ($rows as $r) {
    $lkr = $r['cost_amount'] * $r['exchange_rate'];
    $totalCost     += $lkr;
    $totalBillable += $r['total_billable'];
    $totalMarkup   += $lkr * $r['markup_percentage'] / 100;
    $totalFee      += $r['additional_fee'];
}

$period = date('F Y', strtotime($filterMonth.'-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expense Report — <?= h($reportClient) ?> — <?= $period ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Arial', sans-serif; background: #fff; color: #111; font-size: 13px; }

.page { max-width: 900px; margin: 0 auto; padding: 36px 40px; }

/* Header */
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 3px solid #111; }
.company-info { }
.company-logo { height: 48px; margin-bottom: 8px; display: block; }
.company-name { font-size: 18px; font-weight: 800; color: #111; }
.company-details { font-size: 11px; color: #555; margin-top: 4px; line-height: 1.6; }
.report-meta { text-align: right; }
.report-meta h2 { font-size: 22px; font-weight: 800; color: #111; }
.report-meta .period { font-size: 13px; color: #555; margin-top: 4px; }
.report-meta .client { font-size: 14px; font-weight: 700; color: #333; margin-top: 6px; }

/* Summary boxes */
.summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
.summary-box { background: #f8f8f8; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px 14px; }
.summary-box .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 5px; }
.summary-box .value { font-size: 17px; font-weight: 800; color: #111; }
.summary-box.green .value { color: #1a7c4e; }
.summary-box.red .value { color: #c0392b; }

/* Table */
.section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #555; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 6px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 28px; font-size: 12px; }
thead th { background: #111; color: #fff; padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
tbody td { padding: 9px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
tbody tr:nth-child(even) td { background: #fafafa; }
tfoot td { padding: 10px; background: #111; color: #fff; font-weight: 700; font-size: 13px; }
.green { color: #1a7c4e; }
.muted { color: #888; }
.badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.badge-paid { background: #d4edda; color: #1a7c4e; }
.badge-pending { background: #fff3cd; color: #856404; }
.badge-invoiced { background: #cce5ff; color: #004085; }
.badge-cancelled { background: #f8d7da; color: #721c24; }
.badge-client-paid { background: #ede9fe; color: #6d28d9; }

/* Footer */
.footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; font-size: 10px; color: #999; }

/* Print */
@media print {
  body { font-size: 11px; }
  .no-print { display: none !important; }
  .page { padding: 20px; }
  thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  tfoot td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  summary-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

/* Screen only */
@media screen {
  body { background: #e8e8e8; }
  .page { background: #fff; box-shadow: 0 2px 20px rgba(0,0,0,.12); margin: 24px auto; }
  .toolbar { text-align: center; padding: 16px; background: #333; color: #fff; display: flex; gap: 12px; justify-content: center; align-items: center; }
  .toolbar button { background: #fff; color: #111; border: none; padding: 8px 20px; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer; }
  .toolbar a { color: #aaa; font-size: 12px; text-decoration: none; }
  .toolbar a:hover { color: #fff; }
}
</style>
</head>
<body>

<!-- Toolbar (screen only) -->
<div class="toolbar no-print">
  <button onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="<?= SITE_URL ?>/expenses.php?tab=report&month=<?= $filterMonth ?>&rclient=<?= urlencode($reportClient) ?>">← Back to Reports</a>
</div>

<div class="page">

  <!-- Header -->
  <div class="header">
    <div class="company-info">
      <?php if ($logoPath): ?>
        <img src="<?= h(SITE_URL.'/'.$logoPath) ?>" class="company-logo" alt="Logo">
      <?php endif; ?>
      <div class="company-name">Creative Elements (Pvt) Ltd</div>
      <div class="company-details">
        27/1, 1st Lane, Boralesgamuwa<br>
        accounts@creativelements.co
      </div>
    </div>
    <div class="report-meta">
      <h2>EXPENSE REPORT</h2>
      <div class="period"><?= $period ?></div>
      <div class="client"><?= h($reportClient) ?></div>
      <div class="period" style="margin-top:4px">Generated: <?= date('d M Y') ?></div>
    </div>
  </div>

  <!-- Summary -->
  <div class="summary">
    <div class="summary-box">
      <div class="label">Total Ad Spend</div>
      <div class="value"><?= fm($totalCost) ?></div>
    </div>
    <div class="summary-box">
      <div class="label">Service Fee</div>
      <div class="value"><?= fm($totalFee + $totalMarkup) ?></div>
    </div>
    <div class="summary-box green">
      <div class="label">Total Billable</div>
      <div class="value"><?= fm($totalBillable) ?></div>
    </div>
    <div class="summary-box">
      <div class="label">Records</div>
      <div class="value"><?= count($rows) ?></div>
    </div>
  </div>

  <!-- Campaign Detail Table -->
  <div class="section-title">Campaign Details</div>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Ad / Campaign</th>
        <th>Category</th>
        <th>Original Cost</th>
        <th>LKR Amount</th>
        <th>Markup</th>
        <th>Service Fee</th>
        <th>Total Billable</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9" style="text-align:center;color:#888;padding:24px">No records found.</td></tr>
      <?php else: foreach ($rows as $r):
        $lkr      = $r['cost_amount'] * $r['exchange_rate'];
        $markupAmt = $lkr * $r['markup_percentage'] / 100;
        $badgeClass = 'badge-' . $r['status'];
        $isCP = $r['billing_type'] === 'client_paid';
      ?>
        <tr>
          <td style="white-space:nowrap;color:#555"><?= date('d M Y', strtotime($r['expense_date'])) ?></td>
          <td>
            <strong><?= h($r['project_name'] ?: '—') ?></strong>
            <?php if ($r['description']): ?>
              <br><span class="muted" style="font-size:10px"><?= h(mb_strimwidth($r['description'],0,60,'…')) ?></span>
            <?php endif; ?>
          </td>
          <td><?= h($r['expense_category']) ?><?php if ($isCP): ?><br><span class="badge badge-client-paid">Client-Paid</span><?php endif; ?></td>
          <td style="white-space:nowrap">
            <strong><?= h($r['currency']) ?> <?= number_format($r['cost_amount'],2) ?></strong>
            <?php if ($r['currency'] !== 'LKR'): ?>
              <br><span class="muted" style="font-size:10px">@ <?= number_format($r['exchange_rate'],2) ?></span>
            <?php endif; ?>
          </td>
          <td><?= fm($lkr) ?></td>
          <td><?= $r['markup_percentage'] > 0 ? h($r['markup_percentage']).'%' : '<span class="muted">—</span>' ?></td>
          <td><?= $r['additional_fee'] > 0 ? fm($r['additional_fee']) : '<span class="muted">—</span>' ?></td>
          <td><strong class="green"><?= fm($r['total_billable']) ?></strong></td>
          <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($r['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if (!empty($rows)): ?>
    <tfoot>
      <tr>
        <td colspan="4">TOTALS</td>
        <td><?= fm($totalCost) ?></td>
        <td>—</td>
        <td><?= fm($totalFee) ?></td>
        <td><?= fm($totalBillable) ?></td>
        <td></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- Footer -->
  <div class="footer">
    <span>Creative Elements (Pvt) Ltd · 27/1, 1st Lane, Boralesgamuwa · accounts@creativelements.co</span>
    <span>Generated on <?= date('d M Y, H:i') ?> · <?= h($reportClient) ?> · <?= $period ?></span>
  </div>

</div>
</body>
</html>
