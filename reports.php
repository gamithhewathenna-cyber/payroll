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

<?php pageFooter(); ?>
