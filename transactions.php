<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

// ── Period filter ──────────────────────────────────────────
$period = $_GET['period'] ?? 'monthly';
$tdate  = $_GET['tdate']  ?? date('Y-m-d');
$tmonth = $_GET['tmonth'] ?? date('Y-m');
$tyear  = $_GET['tyear']  ?? date('Y');

$dateFrom = $dateTo = null;
switch ($period) {
    case 'daily':
        $dateFrom = $dateTo = $tdate;
        $periodLabel = date('d M Y', strtotime($tdate));
        break;
    case 'yearly':
        $dateFrom = $tyear . '-01-01';
        $dateTo   = $tyear . '-12-31';
        $periodLabel = 'Year ' . $tyear;
        break;
    case 'all':
        $dateFrom = $dateTo = null;
        $periodLabel = 'All Transactions';
        break;
    case 'monthly':
    default:
        $period   = 'monthly';
        $dateFrom = date('Y-m-01', strtotime($tmonth . '-01'));
        $dateTo   = date('Y-m-t',  strtotime($tmonth . '-01'));
        $periodLabel = date('F Y', strtotime($tmonth . '-01'));
        break;
}

$sym = getSetting('currency_symbol', 'Rs.');

// ── Freelance payments (paid) ───────────────────────────────
$fWhere  = ["fp.payment_status = 'paid'", "fp.payment_date IS NOT NULL"];
$fParams = [];
if ($dateFrom && $dateTo) { $fWhere[] = "fp.payment_date BETWEEN ? AND ?"; $fParams[] = $dateFrom; $fParams[] = $dateTo; }
$freelanceRows = $db->prepare("SELECT fp.payment_date, fp.invoice_number, fp.project_name, f.freelancer_name, fp.bank_reference, fp.payment_amount, fp.invoice_file, fp.invoice_file_name
    FROM freelance_payments fp JOIN freelancers f ON f.id = fp.freelancer_id
    WHERE " . implode(' AND ', $fWhere) . " ORDER BY fp.payment_date DESC");
$freelanceRows->execute($fParams);
$freelanceRows = $freelanceRows->fetchAll();

$transactions = [];
foreach ($freelanceRows as $r) {
    $details = $r['freelancer_name'];
    if ($r['project_name'])    $details .= ' — ' . $r['project_name'];
    if ($r['invoice_number'])  $details .= ' (' . $r['invoice_number'] . ')';
    $transactions[] = [
        'date'           => $r['payment_date'],
        'type'           => 'Freelance',
        'details'        => $details,
        'amount'         => (float)$r['payment_amount'],
        'bank_reference' => $r['bank_reference'],
        'file_path'      => $r['invoice_file'] ?: null,
        'file_label'     => 'Invoice',
    ];
}

// ── Expense payments (paid) ─────────────────────────────────
$eWhere  = ["status = 'paid'", "payment_date IS NOT NULL"];
$eParams = [];
if ($dateFrom && $dateTo) { $eWhere[] = "payment_date BETWEEN ? AND ?"; $eParams[] = $dateFrom; $eParams[] = $dateTo; }
$expenseRows = $db->prepare("SELECT payment_date, expense_category, project_name, client_name, description, bank_reference, total_billable, receipt_path, payment_receipt_path
    FROM expenses WHERE " . implode(' AND ', $eWhere) . " ORDER BY payment_date DESC");
$expenseRows->execute($eParams);
$expenseRows = $expenseRows->fetchAll();

foreach ($expenseRows as $r) {
    $details = $r['expense_category'];
    if ($r['client_name'])          $details .= ' — ' . $r['client_name'];
    elseif ($r['project_name'])     $details .= ' — ' . $r['project_name'];
    if ($r['description'])          $details .= ' (' . mb_strimwidth($r['description'], 0, 40, '…') . ')';
    $transactions[] = [
        'date'           => $r['payment_date'],
        'type'           => 'Expense',
        'details'        => $details,
        'amount'         => (float)$r['total_billable'],
        'bank_reference' => $r['bank_reference'],
        'file_path'      => $r['payment_receipt_path'] ?: ($r['receipt_path'] ?: null),
        'file_label'     => 'Receipt',
    ];
}

// Merge, newest first
usort($transactions, fn($a, $b) => strcmp($b['date'], $a['date']));

$totalAmount     = array_sum(array_column($transactions, 'amount'));
$freelanceTotal  = array_sum(array_column($freelanceRows, 'payment_amount'));
$expenseTotal    = array_sum(array_column($expenseRows, 'total_billable'));

// Year options for the yearly picker — from the earliest transaction year to this year
$earliestYear = (int)date('Y');
foreach ($transactions as $t) { $y = (int)date('Y', strtotime($t['date'])); if ($y < $earliestYear) $earliestYear = $y; }
$yearOptions = range((int)date('Y'), $earliestYear);

pageHeader('Transactions');
?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Total Transactions</div><div class="stat-value"><?= count($transactions) ?></div><div class="stat-sub"><?= h($periodLabel) ?></div></div>
  <div class="stat-card blue"><div class="stat-label">🧑‍💻 Freelance Paid</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($freelanceTotal,2) ?></div><div class="stat-sub"><?= count($freelanceRows) ?> transactions</div></div>
  <div class="stat-card yellow"><div class="stat-label">🧾 Expenses Paid</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($expenseTotal,2) ?></div><div class="stat-sub"><?= count($expenseRows) ?> transactions</div></div>
  <div class="stat-card green"><div class="stat-label">Combined Total</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($totalAmount,2) ?></div><div class="stat-sub">Freelance + Expenses</div></div>
</div>

<!-- Filter -->
<div class="card" style="margin-bottom:20px">
  <div class="card-title">🔎 Filter</div>
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0">
      <label>View</label>
      <select name="period" id="periodSelect" onchange="toggleTxPeriod()">
        <option value="all"     <?= $period==='all'?'selected':'' ?>>All Transactions</option>
        <option value="daily"   <?= $period==='daily'?'selected':'' ?>>Daily</option>
        <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Monthly</option>
        <option value="yearly"  <?= $period==='yearly'?'selected':'' ?>>Yearly</option>
      </select>
    </div>
    <div class="form-group" id="tdateGroup" style="margin:0;display:<?= $period==='daily'?'':'none' ?>">
      <label>Date</label>
      <input type="date" name="tdate" value="<?= h($tdate) ?>">
    </div>
    <div class="form-group" id="tmonthGroup" style="margin:0;display:<?= $period==='monthly'?'':'none' ?>">
      <label>Month</label>
      <input type="month" name="tmonth" value="<?= h($tmonth) ?>">
    </div>
    <div class="form-group" id="tyearGroup" style="margin:0;display:<?= $period==='yearly'?'':'none' ?>">
      <label>Year</label>
      <select name="tyear">
        <?php foreach ($yearOptions as $y): ?>
          <option value="<?= $y ?>" <?= (string)$tyear===(string)$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Apply</button>
  </form>
</div>

<!-- Results -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <strong><?= h($periodLabel) ?></strong>
      <span style="color:var(--text2);font-size:12.5px"> — <?= count($transactions) ?> transaction<?= count($transactions)===1?'':'s' ?></span>
    </div>
    <strong style="color:var(--green);font-size:16px"><?= h($sym) ?> <?= number_format($totalAmount,2) ?></strong>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Description / Details</th><th>Amount</th><th>Bank Reference</th><th>Document</th></tr></thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:32px">No transactions found for this period.</td></tr>
        <?php else: foreach ($transactions as $t): ?>
          <tr>
            <td data-label="Date" style="white-space:nowrap"><?= date('d M Y', strtotime($t['date'])) ?></td>
            <td data-label="Type">
              <?php if ($t['type'] === 'Freelance'): ?>
                <span class="badge badge-blue">🧑‍💻 Freelance</span>
              <?php else: ?>
                <span class="badge badge-yellow">🧾 Expense</span>
              <?php endif; ?>
            </td>
            <td data-label="Description / Details"><?= h($t['details']) ?></td>
            <td data-label="Amount"><strong style="color:var(--green)"><?= h($sym) ?> <?= number_format($t['amount'],2) ?></strong></td>
            <td data-label="Bank Reference"><?= $t['bank_reference'] ? h($t['bank_reference']) : '—' ?></td>
            <td data-label="Document">
              <?php if (!empty($t['file_path'])): ?>
                <a href="<?= SITE_URL ?>/<?= h($t['file_path']) ?>" target="_blank" class="btn btn-ghost btn-sm">📄 <?= h($t['file_label']) ?></a>
              <?php else: ?>
                <span style="color:var(--text2)">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleTxPeriod() {
    const period = document.getElementById('periodSelect').value;
    document.getElementById('tdateGroup').style.display  = period === 'daily'   ? '' : 'none';
    document.getElementById('tmonthGroup').style.display = period === 'monthly' ? '' : 'none';
    document.getElementById('tyearGroup').style.display   = period === 'yearly'  ? '' : 'none';
}
</script>

<?php pageFooter(); ?>
