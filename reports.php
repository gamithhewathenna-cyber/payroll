<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdminOrReadOnly();
$db = getDB();

$filterMonth = $_GET['month'] ?? date('Y-m');
$reportType = $_GET['type'] ?? 'monthly';
$tab = $_GET['tab'] ?? 'payroll';

// Monthly payroll summary
$monthlyReport = $db->prepare("SELECT p.*, e.full_name, e.position, e.department FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.month=? ORDER BY e.department, e.full_name");
$monthlyReport->execute([$filterMonth]);
$monthlyReport = $monthlyReport->fetchAll();

$totals = [
    'base' => array_sum(array_column($monthlyReport, 'base_salary')),
    'bonus' => array_sum(array_column($monthlyReport, 'bonus')),
    'comm' => array_sum(array_column($monthlyReport, 'total_commissions')),
    'allow' => array_sum(array_column($monthlyReport, 'total_allowances')),
    'deduct' => array_sum(array_column($monthlyReport, 'deductions')),
    'advance' => array_sum(array_column($monthlyReport, 'advance_payment')),
    'final' => array_sum(array_column($monthlyReport, 'final_salary')),
    'paid' => array_sum(array_column(array_filter($monthlyReport, fn($r) => $r['payment_status']==='paid'), 'final_salary')),
    'pending' => array_sum(array_column(array_filter($monthlyReport, fn($r) => $r['payment_status']==='pending'), 'final_salary')),
];

// Commission report
$commReport = $db->prepare("SELECT c.*, e.full_name FROM commissions c JOIN employees e ON e.id=c.employee_id WHERE c.month=? ORDER BY e.full_name");
$commReport->execute([$filterMonth]);
$commReport = $commReport->fetchAll();

// Allowance report
$allowReport = $db->prepare("SELECT a.*, e.full_name FROM allowances a JOIN employees e ON e.id=a.employee_id WHERE a.month=? ORDER BY e.full_name");
$allowReport->execute([$filterMonth]);
$allowReport = $allowReport->fetchAll();

// Invoice & Freelance Cost report — ties each client invoice to the freelance
// payments billed under it, so project profitability (invoice vs freelance cost) is visible.
// Grouped by the invoice's issue month, not by when the client actually paid — an invoice
// issued in September that gets paid in October still belongs in the September report.
$invoiceCostReport = $db->prepare("
    SELECT i.id, i.invoice_number, i.total, i.status, c.company_name
    FROM invoices i JOIN clients c ON c.id = i.client_id
    WHERE i.invoice_type = 'invoice' AND DATE_FORMAT(i.issue_date, '%Y-%m') = ?
    ORDER BY i.issue_date ASC
");
$invoiceCostReport->execute([$filterMonth]);
$invoiceCostReport = $invoiceCostReport->fetchAll();

$freelanceLinks = $db->query("
    SELECT fpi.client_invoice_id, fp.project_name, fp.payment_amount, fp.payment_status, f.freelancer_name
    FROM freelance_payment_invoices fpi
    JOIN freelance_payments fp ON fp.id = fpi.freelance_payment_id
    JOIN freelancers f ON f.id = fp.freelancer_id
")->fetchAll();
$freelanceByInvoice = [];
foreach ($freelanceLinks as $fl) { $freelanceByInvoice[$fl['client_invoice_id']][] = $fl; }

// Bank Transfer expenses can now be linked to a client invoice too (expenses.php) —
// same profitability idea as freelance cost, so both count toward the cost side here.
$expenseLinks = $db->query("
    SELECT ei.client_invoice_id, e.expense_category, e.project_name, e.total_billable, e.status
    FROM expense_invoices ei
    JOIN expenses e ON e.id = ei.expense_id
")->fetchAll();
$expenseByInvoice = [];
foreach ($expenseLinks as $el) { $expenseByInvoice[$el['client_invoice_id']][] = $el; }

$invTotalSum = $freelanceCostSum = $expenseCostSum = 0;
foreach ($invoiceCostReport as $row) {
    $invTotalSum += $row['total'];
    foreach (($freelanceByInvoice[$row['id']] ?? []) as $fl) { $freelanceCostSum += $fl['payment_amount']; }
    foreach (($expenseByInvoice[$row['id']] ?? []) as $el) { $expenseCostSum += $el['total_billable']; }
}

// Yearly Trend — Revenue / Expenses / Profit per month, same conventions as the Dashboard
// (Revenue: invoices by issue month, excluding cancelled. Expenses: company costs by billing_month).
$filterYear = (int)($_GET['year'] ?? date('Y'));
$trendMonths = [];
$trendRevenue = []; $trendExpenses = []; $trendProfit = [];
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $filterYear, $m);
    $trendMonths[] = date('M', mktime(0,0,0,$m,1,$filterYear));

    $rev = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND invoice_type='invoice' AND status != 'cancelled'");
    $rev->execute([$ym]);
    $revVal = (float)$rev->fetchColumn();

    $exp = $db->prepare("SELECT COALESCE(SUM(cost_amount * exchange_rate),0) FROM expenses WHERE billing_month=? AND billing_type IN ('internal','client','shared') AND record_type != 'bank_transfer'");
    $exp->execute([$ym]);
    $expVal = (float)$exp->fetchColumn();

    $trendRevenue[]  = $revVal;
    $trendExpenses[] = $expVal;
    $trendProfit[]   = $revVal - $expVal;
}
$trendYearOptions = range((int)date('Y'), (int)date('Y') - 4);

pageHeader('Reports');
?>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Update</button>
  </form>
  <button class="btn btn-primary no-print" onclick="window.print()">🖨 Print / Export PDF</button>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
  <div class="stat-card"><div class="stat-label">Total Payroll</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['final']) ?></div></div>
  <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['paid']) ?></div></div>
  <div class="stat-card red"><div class="stat-label">Pending</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['pending']) ?></div></div>
  <div class="stat-card yellow"><div class="stat-label">Commissions</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['comm']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Allowances</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['allow']) ?></div></div>
  <div class="stat-card"><div class="stat-label">Deductions</div><div class="stat-value" style="font-size:20px"><?= formatMoney($totals['deduct'] + $totals['advance']) ?></div></div>
</div>

<!-- Tabs -->
<div class="no-print" style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border);flex-wrap:wrap">
  <a href="?tab=payroll&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='payroll'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">💰 Monthly Payroll</a>
  <a href="?tab=commissions&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='commissions'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">📈 Commissions</a>
  <a href="?tab=allowances&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='allowances'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">🎁 Allowances</a>
  <a href="?tab=invoice_cost&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='invoice_cost'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">💼 Invoice &amp; Freelance Cost</a>
  <a href="?tab=trend&year=<?= $filterYear ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='trend'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">📊 Yearly Trend</a>
</div>

<?php if ($tab === 'payroll'): ?>
<!-- Monthly Payroll Report -->
<div class="card">
  <div class="card-title">Monthly Payroll Report — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Department</th><th>Base</th><th>Bonus</th><th>Comm</th><th>Allow</th><th>Deduct</th><th>Net</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($monthlyReport as $r): ?>
        <tr>
          <td><strong><?= h($r['full_name']) ?></strong><br><span style="font-size:11px;color:var(--text2)"><?= h($r['position']) ?></span></td>
          <td><?= h($r['department']) ?></td>
          <td><?= formatMoney($r['base_salary']) ?></td>
          <td><?= formatMoney($r['bonus']) ?></td>
          <td><?= formatMoney($r['total_commissions']) ?></td>
          <td><?= formatMoney($r['total_allowances']) ?></td>
          <td style="color:var(--red)">-<?= formatMoney($r['deductions']+$r['advance_payment']) ?></td>
          <td><strong><?= formatMoney($r['final_salary']) ?></strong></td>
          <td><?= $r['payment_status']==='paid' ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-yellow">Pending</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!empty($monthlyReport)): ?>
        <tr style="background:var(--bg3)">
          <td data-label="" colspan="2"><strong>TOTALS</strong></td>
          <td><strong><?= formatMoney($totals['base']) ?></strong></td>
          <td><strong><?= formatMoney($totals['bonus']) ?></strong></td>
          <td><strong><?= formatMoney($totals['comm']) ?></strong></td>
          <td><strong><?= formatMoney($totals['allow']) ?></strong></td>
          <td style="color:var(--red)"><strong>-<?= formatMoney($totals['deduct']+$totals['advance']) ?></strong></td>
          <td><strong style="color:var(--green)"><?= formatMoney($totals['final']) ?></strong></td>
          <td></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'commissions'): ?>
<!-- Commissions -->
<div class="card">
  <div class="card-title">Commission Report — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Project</th><th>Type</th><th>Rate</th><th>Amount</th></tr></thead>
      <tbody>
        <?php if (empty($commReport)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:20px">No commissions this month.</td></tr>
        <?php else: foreach ($commReport as $c): ?>
          <tr>
            <td><?= h($c['full_name']) ?></td>
            <td><?= h($c['project_name']) ?></td>
            <td><span class="badge badge-blue"><?= ucfirst($c['commission_type']) ?></span></td>
            <td><?= $c['commission_type']==='percentage' ? h($c['commission_value']).'%' : '—' ?></td>
            <td><strong><?= formatMoney($c['commission_amount']) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'allowances'): ?>
<!-- Allowances -->
<div class="card">
  <div class="card-title">Allowance Report — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Type</th><th>Amount</th><th>Description</th></tr></thead>
      <tbody>
        <?php if (empty($allowReport)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--text2);padding:20px">No allowances this month.</td></tr>
        <?php else: foreach ($allowReport as $a): ?>
          <tr>
            <td><?= h($a['full_name']) ?></td>
            <td><?= ucfirst($a['allowance_type']) ?></td>
            <td><strong><?= formatMoney($a['amount']) ?></strong></td>
            <td style="color:var(--text2)"><?= h($a['description']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'invoice_cost'): ?>
<!-- Invoice & Freelance Cost Report -->
<div class="card">
  <div class="card-title">💼 Invoice &amp; Freelance Cost Report — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:16px">
    <div class="stat-card blue"><div class="stat-label">Total Invoiced</div><div class="stat-value" style="font-size:18px"><?= formatMoney($invTotalSum) ?></div></div>
    <div class="stat-card red"><div class="stat-label">Freelance Cost</div><div class="stat-value" style="font-size:18px"><?= formatMoney($freelanceCostSum) ?></div></div>
    <div class="stat-card red"><div class="stat-label">Expense Cost</div><div class="stat-value" style="font-size:18px"><?= formatMoney($expenseCostSum) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Margin</div><div class="stat-value" style="font-size:18px"><?= formatMoney($invTotalSum - $freelanceCostSum - $expenseCostSum) ?></div></div>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Invoice #</th><th>Client</th><th>Invoice Total</th><th>Status</th><th>Linked Costs (Freelance + Expense)</th><th>Margin</th></tr></thead>
      <tbody>
        <?php if (empty($invoiceCostReport)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:20px">No invoices for <?= date('F Y', strtotime($filterMonth.'-01')) ?>.</td></tr>
        <?php else: $invStatusColors = ['draft'=>'yellow','sent'=>'blue','paid'=>'green','overdue'=>'red','cancelled'=>'red']; foreach ($invoiceCostReport as $row):
          $flLinks = $freelanceByInvoice[$row['id']] ?? [];
          $expLinks = $expenseByInvoice[$row['id']] ?? [];
          $cost   = array_sum(array_column($flLinks, 'payment_amount')) + array_sum(array_column($expLinks, 'total_billable'));
          $margin = $row['total'] - $cost;
        ?>
          <tr>
            <td><a href="<?= SITE_URL ?>/invoice_form.php?id=<?= $row['id'] ?>" style="color:var(--accent)"><?= h($row['invoice_number']) ?></a></td>
            <td><?= h($row['company_name']) ?></td>
            <td><strong><?= formatMoney($row['total']) ?></strong></td>
            <td><span class="badge badge-<?= $invStatusColors[$row['status']] ?? 'blue' ?>"><?= ucfirst($row['status']) ?></span></td>
            <td>
              <?php if (empty($flLinks) && empty($expLinks)): ?>
                <span style="color:var(--text2)">—</span>
              <?php else: ?>
                <strong style="color:var(--red)"><?= formatMoney($cost) ?></strong>
                <?php foreach ($flLinks as $fl): ?>
                  <div style="font-size:11px;color:var(--text2)">🧑‍💻 <?= h($fl['freelancer_name']) ?> — <?= h($fl['project_name']) ?> (<?= formatMoney($fl['payment_amount']) ?><?= $fl['payment_status']!=='paid' ? ', pending' : '' ?>)</div>
                <?php endforeach; ?>
                <?php foreach ($expLinks as $el): ?>
                  <div style="font-size:11px;color:var(--text2)">🏦 <?= h($el['expense_category']) ?><?= $el['project_name'] ? ' — '.h($el['project_name']) : '' ?> (<?= formatMoney($el['total_billable']) ?><?= $el['status']!=='paid' ? ', '.h($el['status']) : '' ?>)</div>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td><strong style="color:<?= $margin >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= formatMoney($margin) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($tab === 'trend'):
    // Chart colors are a validated dark-surface categorical triple (not this app's
    // --accent/--green/--yellow, which fail the colorblind-separation check at this
    // lightness) — see dataviz skill palette.md slots 1/2/3.
    $cRevenue = '#3987e5'; $cExpenses = '#d95926'; $cProfit = '#199e70';

    $chartW = 900; $chartH = 360; $padL = 70; $padR = 20; $padT = 20; $padB = 40;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;

    function trendCompact($n) {
        $neg = $n < 0; $n = abs($n);
        if ($n >= 1000000) $s = round($n/1000000, 1) . 'M';
        elseif ($n >= 1000) $s = round($n/1000, 1) . 'K';
        else $s = number_format($n, 0);
        return ($neg ? '-' : '') . $s;
    }

    // "Nice numbers" axis rounding (Heckbert) so gridlines land on clean values
    // (0 / 500K / 1M / ...) instead of arbitrary fractions of the data range.
    function trendNiceNum($range, $round) {
        $exponent = floor(log10($range ?: 1));
        $fraction = $range / (10 ** $exponent);
        if ($round) {
            $nice = $fraction < 1.5 ? 1 : ($fraction < 3 ? 2 : ($fraction < 7 ? 5 : 10));
        } else {
            $nice = $fraction <= 1 ? 1 : ($fraction <= 2 ? 2 : ($fraction <= 5 ? 5 : 10));
        }
        return $nice * (10 ** $exponent);
    }

    $allVals = array_merge($trendRevenue, $trendExpenses, $trendProfit);
    $rawMax  = max(max($allVals), 1);
    $rawMin  = min(0, min($allVals));
    $tickCount = 5;
    $niceRange = trendNiceNum($rawMax - $rawMin, false);
    $tickStep  = trendNiceNum($niceRange / ($tickCount - 1), true);
    $minVal = floor($rawMin / $tickStep) * $tickStep;
    $maxVal = ceil($rawMax / $tickStep) * $tickStep;
    $range  = max($maxVal - $minVal, 1);

    $ticks = [];
    for ($v = $minVal; $v <= $maxVal + $tickStep * 0.001; $v += $tickStep) { $ticks[] = $v; }

    $xAt = fn($i) => $padL + ($i / 11) * $plotW;
    $yAt = fn($v) => $padT + $plotH - (($v - $minVal) / $range) * $plotH;
    $zeroY = $yAt(0);

    $series = [
        ['key' => 'revenue',  'label' => 'Revenue',  'color' => $cRevenue,  'data' => $trendRevenue],
        ['key' => 'expenses', 'label' => 'Expenses', 'color' => $cExpenses, 'data' => $trendExpenses],
        ['key' => 'profit',   'label' => 'Profit',   'color' => $cProfit,   'data' => $trendProfit],
    ];
?>
<div class="card" id="trendCard">
  <div class="section-header" style="margin-bottom:16px">
    <div class="card-title" style="margin:0">📊 Yearly Trend — Revenue, Expenses &amp; Profit</div>
    <form method="GET" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="tab" value="trend">
      <select name="year" onchange="this.form.submit()">
        <?php foreach ($trendYearOptions as $y): ?>
          <option value="<?= $y ?>" <?= $y===$filterYear?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- Legend -->
  <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px">
    <?php foreach ($series as $s): ?>
      <div style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--text2)">
        <svg width="18" height="8" aria-hidden="true"><line x1="0" y1="4" x2="18" y2="4" stroke="<?= $s['color'] ?>" stroke-width="2.5" stroke-linecap="round"/></svg>
        <?= h($s['label']) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Chart -->
  <div style="position:relative">
    <svg id="trendSvg" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" style="width:100%;height:auto;display:block;font-family:inherit">
      <!-- Gridlines + Y labels -->
      <?php foreach ($ticks as $tv): $ty = $yAt($tv); ?>
        <line x1="<?= $padL ?>" y1="<?= $ty ?>" x2="<?= $chartW - $padR ?>" y2="<?= $ty ?>" stroke="var(--border)" stroke-width="1"/>
        <text x="<?= $padL - 10 ?>" y="<?= $ty + 4 ?>" text-anchor="end" font-size="11" fill="var(--text2)"><?= trendCompact($tv) ?></text>
      <?php endforeach; ?>
      <!-- Zero baseline, slightly stronger -->
      <line x1="<?= $padL ?>" y1="<?= $zeroY ?>" x2="<?= $chartW - $padR ?>" y2="<?= $zeroY ?>" stroke="var(--text2)" stroke-width="1"/>

      <!-- X labels -->
      <?php foreach ($trendMonths as $i => $mLabel): ?>
        <text x="<?= $xAt($i) ?>" y="<?= $chartH - 14 ?>" text-anchor="middle" font-size="11" fill="var(--text2)"><?= h($mLabel) ?></text>
      <?php endforeach; ?>

      <!-- Series lines + markers -->
      <?php foreach ($series as $s):
        $pts = [];
        foreach ($s['data'] as $i => $v) { $pts[] = $xAt($i) . ',' . $yAt($v); }
      ?>
        <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="<?= $s['color'] ?>" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        <?php foreach ($s['data'] as $i => $v): ?>
          <circle class="trend-pt" data-series="<?= $s['key'] ?>" data-i="<?= $i ?>" cx="<?= $xAt($i) ?>" cy="<?= $yAt($v) ?>" r="4" fill="<?= $s['color'] ?>" stroke="var(--bg2)" stroke-width="2"/>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <!-- No direct end-labels: with 3 series this close (Profit = Revenue − Expenses),
           they collide constantly and become unreadable — legend + hover tooltip + the
           table view below carry the values instead (see dataviz skill, label collisions). -->

      <!-- Crosshair (hidden until hover) -->
      <line id="trendCrosshair" x1="0" y1="<?= $padT ?>" x2="0" y2="<?= $chartH - $padB ?>" stroke="var(--text2)" stroke-width="1" stroke-dasharray="3,3" style="display:none"/>

      <!-- Invisible hit columns, one per month -->
      <?php foreach ($trendMonths as $i => $mLabel): ?>
        <rect class="trend-hit" data-i="<?= $i ?>" x="<?= $xAt($i) - ($plotW/11/2) ?>" y="<?= $padT ?>" width="<?= $plotW/11 ?>" height="<?= $plotH ?>" fill="transparent"/>
      <?php endforeach; ?>
    </svg>
    <div id="trendTooltip" style="position:absolute;display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:12px;pointer-events:none;box-shadow:0 6px 20px rgba(0,0,0,.35);white-space:nowrap;z-index:5"></div>
  </div>

  <div style="margin-top:10px">
    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('trendTableWrap').style.display = document.getElementById('trendTableWrap').style.display==='none' ? '' : 'none'">📋 View as Table</button>
  </div>
  <div id="trendTableWrap" style="display:none;margin-top:12px" class="table-wrap">
    <table>
      <thead><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Profit</th></tr></thead>
      <tbody>
        <?php foreach ($trendMonths as $i => $mLabel): ?>
          <tr>
            <td><?= h($mLabel) ?> <?= $filterYear ?></td>
            <td><?= formatMoney($trendRevenue[$i]) ?></td>
            <td><?= formatMoney($trendExpenses[$i]) ?></td>
            <td style="color:<?= $trendProfit[$i]>=0?'var(--green)':'var(--red)' ?>"><?= formatMoney($trendProfit[$i]) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function() {
    const months   = <?= json_encode($trendMonths) ?>;
    const data     = {
        revenue:  <?= json_encode($trendRevenue) ?>,
        expenses: <?= json_encode($trendExpenses) ?>,
        profit:   <?= json_encode($trendProfit) ?>
    };
    const colors = { revenue: '<?= $cRevenue ?>', expenses: '<?= $cExpenses ?>', profit: '<?= $cProfit ?>' };
    const labels = { revenue: 'Revenue', expenses: 'Expenses', profit: 'Profit' };
    const sym    = '<?= h(getSetting('currency_symbol','Rs.')) ?>';
    const fmt    = n => sym + ' ' + n.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});

    const svg       = document.getElementById('trendSvg');
    const crosshair = document.getElementById('trendCrosshair');
    const tooltip   = document.getElementById('trendTooltip');
    if (!svg) return;

    function showAt(i, clientX, clientY) {
        const pt = svg.querySelectorAll('.trend-pt[data-i="' + i + '"]')[0];
        const x = pt ? pt.getAttribute('cx') : 0;
        crosshair.setAttribute('x1', x);
        crosshair.setAttribute('x2', x);
        crosshair.style.display = '';

        let html = '<div style="font-weight:700;margin-bottom:6px;color:var(--text)">' + months[i] + ' <?= $filterYear ?></div>';
        ['revenue','expenses','profit'].forEach(key => {
            html += '<div style="display:flex;align-items:center;gap:6px;justify-content:space-between;gap:16px">' +
                '<span style="display:flex;align-items:center;gap:6px;color:var(--text2)">' +
                '<svg width="12" height="8"><line x1="0" y1="4" x2="12" y2="4" stroke="' + colors[key] + '" stroke-width="2.5"/></svg>' +
                labels[key] + '</span>' +
                '<strong style="color:var(--text)">' + fmt(data[key][i]) + '</strong></div>';
        });
        tooltip.innerHTML = html;
        tooltip.style.display = '';

        const wrapRect = svg.parentElement.getBoundingClientRect();
        let left = clientX - wrapRect.left + 14;
        let top  = clientY - wrapRect.top - 10;
        if (left + 190 > wrapRect.width) left = clientX - wrapRect.left - 200;
        tooltip.style.left = left + 'px';
        tooltip.style.top  = Math.max(0, top) + 'px';
    }

    function hide() {
        crosshair.style.display = 'none';
        tooltip.style.display = 'none';
    }

    svg.querySelectorAll('.trend-hit').forEach(rect => {
        const i = parseInt(rect.getAttribute('data-i'), 10);
        rect.addEventListener('pointermove', e => showAt(i, e.clientX, e.clientY));
        rect.addEventListener('pointerenter', e => showAt(i, e.clientX, e.clientY));
    });
    svg.addEventListener('pointerleave', hide);
})();
</script>
<?php endif; ?>

<?php pageFooter(); ?>
