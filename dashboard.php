<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();

$db    = getDB();
$sym   = getSetting('currency_symbol', 'Rs.');

if (isset($_GET['denied'])) {
    setFlash('error', '🔒 Access denied. You do not have permission to view that page.');
}

// ── Month / date range filter ──────────────────────────────
$month     = $_GET['month'] ?? date('Y-m');
$monthLabel = date('F Y', strtotime($month . '-01'));
$prevMonth  = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth  = date('Y-m', strtotime($month . '-01 +1 month'));

// ── Employee dashboard (non-admin) ─────────────────────────
if (!isAdmin()) {
    $empDbId = $_SESSION['employee_db_id'];
    $myPayslip = null;
    if ($empDbId) {
        $stmt = $db->prepare("SELECT * FROM payroll WHERE employee_id=? AND month=?");
        $stmt->execute([$empDbId, $month]);
        $myPayslip = $stmt->fetch();
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM payroll WHERE employee_id=?");
    $stmt->execute([$empDbId]);
    $totalPayslips = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM commissions WHERE employee_id=? AND month=?");
    $stmt->execute([$empDbId, $month]);
    $myCommissions = $stmt->fetchColumn();
    pageHeader('Dashboard');
    ?>
    <div style="margin-bottom:20px">
      <div style="font-size:22px;font-weight:800">Welcome, <?= h($_SESSION['full_name']) ?> 👋</div>
      <div style="color:var(--text2);font-size:13px"><?= $monthLabel ?></div>
    </div>
    <?php if ($myPayslip): ?>
    <div class="stats-grid">
      <div class="stat-card green"><div class="stat-label">Net Salary</div><div class="stat-value"><?= $sym ?> <?= number_format($myPayslip['final_salary'],2) ?></div><div class="stat-sub"><?= $monthLabel ?></div></div>
      <div class="stat-card blue"><div class="stat-label">Commissions</div><div class="stat-value"><?= $sym ?> <?= number_format($myCommissions,2) ?></div></div>
      <div class="stat-card"><div class="stat-label">Total Payslips</div><div class="stat-value"><?= $totalPayslips ?></div></div>
      <div class="stat-card <?= $myPayslip['payment_status']==='paid'?'green':'yellow' ?>"><div class="stat-label">Status</div><div class="stat-value" style="font-size:16px"><?= ucfirst($myPayslip['payment_status']) ?></div></div>
    </div>
    <?php else: ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--text2)">No payroll record for <?= $monthLabel ?>.</div>
    <?php endif; ?>
    <?php pageFooter(); return; ?>
<?php } ?>

<?php
// ══════════════════════════════════════════════════════════
//  ADMIN DASHBOARD
// ══════════════════════════════════════════════════════════

// ── SALARIES ──────────────────────────────────────────────
$empSalaries      = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=?");
$empSalaries->execute([$month]); $empSalaries = (float)$empSalaries->fetchColumn();

$empSalariesPaid  = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=? AND payment_status='paid'");
$empSalariesPaid->execute([$month]); $empSalariesPaid = (float)$empSalariesPaid->fetchColumn();

$freelanceSalaries = $db->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM freelance_payments WHERE month=?");
$freelanceSalaries->execute([$month]); $freelanceSalaries = (float)$freelanceSalaries->fetchColumn();

$freelanceSalariesPaid = $db->prepare("SELECT COALESCE(SUM(payment_amount),0) FROM freelance_payments WHERE month=? AND payment_status='paid'");
$freelanceSalariesPaid->execute([$month]); $freelanceSalariesPaid = (float)$freelanceSalariesPaid->fetchColumn();

$totalSalaries    = $empSalaries + $freelanceSalaries;
$totalSalariesPaid = $empSalariesPaid + $freelanceSalariesPaid;

// ── OPERATIONAL EXPENSES ──────────────────────────────────
// Company expenses (internal + client billing we pay) — exclude client_paid
$bizExpenses = $db->prepare("SELECT COALESCE(SUM(cost_amount * exchange_rate),0) FROM expenses WHERE billing_month=? AND billing_type IN ('internal','client','shared')");
$bizExpenses->execute([$month]); $bizExpenses = (float)$bizExpenses->fetchColumn();

// ── TOTAL EXPENSES ────────────────────────────────────────
$totalExpenses = $totalSalaries + $bizExpenses;

// ── REVENUE ───────────────────────────────────────────────
// All invoices this month (regardless of payment status)
$invoiceRevenue = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND invoice_type='invoice' AND status != 'cancelled'");
$invoiceRevenue->execute([$month]); $invoiceRevenue = (float)$invoiceRevenue->fetchColumn();

// All client billable expenses (approved, what we charge clients)
$billableRevenue = $db->prepare("SELECT COALESCE(SUM(total_billable),0) FROM expenses WHERE billing_month=? AND billing_type IN ('client','shared') AND approval_status='approved'");
$billableRevenue->execute([$month]); $billableRevenue = (float)$billableRevenue->fetchColumn();

$totalRevenue = $invoiceRevenue + $billableRevenue;

// ── PROFIT ────────────────────────────────────────────────
$totalProfit = $totalRevenue - $totalExpenses;

// ── COUNTS ────────────────────────────────────────────────
$totalEmp         = $db->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$totalFreelancers = $db->query("SELECT COUNT(*) FROM freelancers WHERE status='active'")->fetchColumn();
$pendingPayroll   = $db->prepare("SELECT COUNT(*) FROM payroll WHERE month=? AND payment_status='pending'"); $pendingPayroll->execute([$month]); $pendingPayroll = $pendingPayroll->fetchColumn();
$pendingExpenses  = $db->prepare("SELECT COUNT(*) FROM expenses WHERE billing_month=? AND approval_status='pending_approval'"); $pendingExpenses->execute([$month]); $pendingExpenses = $pendingExpenses->fetchColumn();
$pendingInvoices  = $db->prepare("SELECT COUNT(*) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND status='sent'"); $pendingInvoices->execute([$month]); $pendingInvoices = $pendingInvoices->fetchColumn();
$overdueInvoices  = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();

// ── PREV MONTH COMPARISON ─────────────────────────────────
$prevRev = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE_FORMAT(issue_date,'%Y-%m')=? AND invoice_type='invoice' AND status != 'cancelled'");
$prevRev->execute([$prevMonth]); $prevRev = (float)$prevRev->fetchColumn();

$prevSal = $db->prepare("SELECT COALESCE(SUM(final_salary),0) FROM payroll WHERE month=?");
$prevSal->execute([$prevMonth]); $prevSal = (float)$prevSal->fetchColumn();

$prevExp = $db->prepare("SELECT COALESCE(SUM(cost_amount * exchange_rate),0) FROM expenses WHERE billing_month=? AND billing_type IN ('internal','client','shared')");
$prevExp->execute([$prevMonth]); $prevExp = (float)$prevExp->fetchColumn();

// ── RECENT ACTIVITY ───────────────────────────────────────
$recentInvoices  = $db->prepare("SELECT i.*, c.company_name FROM invoices i JOIN clients c ON c.id=i.client_id ORDER BY i.created_at DESC LIMIT 5");
$recentInvoices->execute(); $recentInvoices = $recentInvoices->fetchAll();

$recentExpenses  = $db->prepare("SELECT * FROM expenses ORDER BY created_at DESC LIMIT 5");
$recentExpenses->execute(); $recentExpenses = $recentExpenses->fetchAll();

// Month-over-month helper
function momArrow($current, $prev) {
    if ($prev == 0) return '';
    $pct = round((($current - $prev) / $prev) * 100, 1);
    $up  = $pct >= 0;
    $color = $up ? 'var(--green)' : 'var(--red)';
    $arrow = $up ? '▲' : '▼';
    return "<span style='font-size:11px;color:{$color};margin-left:6px'>{$arrow} " . abs($pct) . "% vs last month</span>";
}

pageHeader('Dashboard');
?>

<!-- Month Navigator -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-size:22px;font-weight:800">Good <?= date('H')<12?'Morning':( date('H')<17?'Afternoon':'Evening') ?>, <?= h(explode(' ',$_SESSION['full_name'])[0]) ?> 👋</div>
    <div style="color:var(--text2);font-size:13px;margin-top:2px">Here's your business overview for <?= $monthLabel ?></div>
  </div>
  <div style="display:flex;align-items:center;gap:8px">
    <a href="?month=<?= $prevMonth ?>" class="btn btn-ghost btn-sm">← <?= date('M', strtotime($prevMonth.'-01')) ?></a>
    <form method="GET" style="display:inline">
      <input type="month" name="month" value="<?= $month ?>" onchange="this.form.submit()" style="font-size:13px;padding:6px 10px;border-radius:7px;background:var(--bg3);border:1px solid var(--border);color:var(--text)">
    </form>
    <a href="?month=<?= $nextMonth ?>" class="btn btn-ghost btn-sm"><?= date('M', strtotime($nextMonth.'-01')) ?> →</a>
    <a href="?month=<?= date('Y-m') ?>" class="btn btn-ghost btn-sm" style="<?= $month===date('Y-m')?'opacity:.4;pointer-events:none':'' ?>">Today</a>
  </div>
</div>

<!-- ══ KEY METRICS ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px">

  <!-- Revenue -->
  <div class="stat-card green" style="padding:20px 22px">
    <div class="stat-label">💰 Total Revenue</div>
    <div style="font-size:26px;font-weight:800;margin:8px 0;color:var(--green)"><?= $sym ?> <?= number_format($totalRevenue,2) ?></div>
    <?= momArrow($totalRevenue, $prevRev) ?>
    <div style="margin-top:10px;font-size:11px;color:var(--text2);border-top:1px solid rgba(255,255,255,.08);padding-top:8px">
      All invoices: <strong style="color:var(--text)"><?= $sym ?> <?= number_format($invoiceRevenue,2) ?></strong><br>
      Client expenses: <strong style="color:var(--text)"><?= $sym ?> <?= number_format($billableRevenue,2) ?></strong>
    </div>
  </div>

  <!-- Expenses -->
  <div class="stat-card red" style="padding:20px 22px">
    <div class="stat-label">📊 Total Expenses</div>
    <div style="font-size:26px;font-weight:800;margin:8px 0;color:var(--red)"><?= $sym ?> <?= number_format($totalExpenses,2) ?></div>
    <?= momArrow($totalExpenses, $prevSal + $prevExp) ?>
    <div style="margin-top:10px;font-size:11px;color:var(--text2);border-top:1px solid rgba(255,255,255,.08);padding-top:8px">
      Salaries: <strong style="color:var(--text)"><?= $sym ?> <?= number_format($totalSalaries,2) ?></strong><br>
      Operational: <strong style="color:var(--text)"><?= $sym ?> <?= number_format($bizExpenses,2) ?></strong>
    </div>
  </div>

  <!-- Profit -->
  <div class="stat-card <?= $totalProfit >= 0 ? 'green' : 'red' ?>" style="padding:20px 22px">
    <div class="stat-label">📈 Net Profit</div>
    <div style="font-size:26px;font-weight:800;margin:8px 0;color:<?= $totalProfit>=0?'var(--green)':'var(--red)' ?>"><?= $totalProfit < 0 ? '-' : '' ?><?= $sym ?> <?= number_format(abs($totalProfit),2) ?></div>
    <div style="margin-top:10px;font-size:11px;color:var(--text2);border-top:1px solid rgba(255,255,255,.08);padding-top:8px">
      <?php $margin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0; ?>
      Profit margin: <strong style="color:<?= $margin>=0?'var(--green)':'var(--red)' ?>"><?= $margin ?>%</strong><br>
      Revenue − Expenses
    </div>
  </div>

  <!-- Employee Salaries -->
  <div class="stat-card blue" style="padding:20px 22px">
    <div class="stat-label">👤 Employee Salaries</div>
    <div style="font-size:26px;font-weight:800;margin:8px 0"><?= $sym ?> <?= number_format($empSalaries,2) ?></div>
    <div style="margin-top:10px;font-size:11px;color:var(--text2);border-top:1px solid rgba(255,255,255,.08);padding-top:8px">
      Paid: <strong style="color:var(--green)"><?= $sym ?> <?= number_format($empSalariesPaid,2) ?></strong><br>
      Pending: <strong style="color:var(--yellow)"><?= $sym ?> <?= number_format($empSalaries - $empSalariesPaid,2) ?></strong>
    </div>
  </div>

  <!-- Freelancer Payments -->
  <div class="stat-card" style="padding:20px 22px;border-top-color:var(--yellow)">
    <div class="stat-label" style="color:var(--yellow)">🧑‍💻 Freelancer Payments</div>
    <div style="font-size:26px;font-weight:800;margin:8px 0"><?= $sym ?> <?= number_format($freelanceSalaries,2) ?></div>
    <div style="margin-top:10px;font-size:11px;color:var(--text2);border-top:1px solid rgba(255,255,255,.08);padding-top:8px">
      Paid: <strong style="color:var(--green)"><?= $sym ?> <?= number_format($freelanceSalariesPaid,2) ?></strong><br>
      Pending: <strong style="color:var(--yellow)"><?= $sym ?> <?= number_format($freelanceSalaries - $freelanceSalariesPaid,2) ?></strong>
    </div>
  </div>

</div>

<!-- ══ SECONDARY STATS ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px">
  <div class="stat-card"><div class="stat-label">Active Employees</div><div class="stat-value"><?= $totalEmp ?></div></div>
  <div class="stat-card"><div class="stat-label">Freelancers</div><div class="stat-value"><?= $totalFreelancers ?></div></div>
  <div class="stat-card <?= $pendingPayroll>0?'yellow':'' ?>"><div class="stat-label">Pending Payroll</div><div class="stat-value"><?= $pendingPayroll ?></div><div class="stat-sub"><?= $pendingPayroll>0?"<a href='payroll.php' style='color:var(--yellow)'>Process →</a>":'' ?></div></div>
  <div class="stat-card <?= $pendingExpenses>0?'yellow':'' ?>"><div class="stat-label">Expense Approvals</div><div class="stat-value"><?= $pendingExpenses ?></div><div class="stat-sub"><?= $pendingExpenses>0?"<a href='expenses.php' style='color:var(--yellow)'>Review →</a>":'' ?></div></div>
  <div class="stat-card <?= $pendingInvoices>0?'blue':'' ?>"><div class="stat-label">Sent Invoices</div><div class="stat-value"><?= $pendingInvoices ?></div><div class="stat-sub"><?= $pendingInvoices>0?"<a href='invoices.php' style='color:var(--accent)'>View →</a>":'' ?></div></div>
  <div class="stat-card <?= $overdueInvoices>0?'red':'' ?>"><div class="stat-label">Overdue Invoices</div><div class="stat-value"><?= $overdueInvoices ?></div><div class="stat-sub"><?= $overdueInvoices>0?"<a href='invoices.php' style='color:var(--red)'>View →</a>":'' ?></div></div>
</div>

<!-- ══ BREAKDOWN ══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px">

  <!-- Salary breakdown -->
  <div class="card">
    <div class="card-title">👥 Salary Breakdown — <?= $monthLabel ?></div>
    <div style="display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg3);border-radius:8px">
        <div><div style="font-size:12px;color:var(--text2)">Employee Salaries</div><div style="font-size:11px;color:var(--text2)"><?= $db->prepare("SELECT COUNT(*) FROM payroll WHERE month='$month'")->execute()||true?$db->query("SELECT COUNT(*) FROM payroll WHERE month='$month'")->fetchColumn():0 ?> employees</div></div>
        <div style="text-align:right"><div style="font-weight:700"><?= $sym ?> <?= number_format($empSalaries,2) ?></div><div style="font-size:11px;color:var(--green)">Paid: <?= $sym ?> <?= number_format($empSalariesPaid,2) ?></div></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg3);border-radius:8px">
        <div><div style="font-size:12px;color:var(--text2)">Freelancer Payments</div><div style="font-size:11px;color:var(--text2)"><?= $db->query("SELECT COUNT(*) FROM freelance_payments WHERE month='$month'")->fetchColumn() ?> payments</div></div>
        <div style="text-align:right"><div style="font-weight:700"><?= $sym ?> <?= number_format($freelanceSalaries,2) ?></div><div style="font-size:11px;color:var(--green)">Paid: <?= $sym ?> <?= number_format($freelanceSalariesPaid,2) ?></div></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px">
        <div style="font-weight:700">Total Payroll</div>
        <div style="text-align:right"><div style="font-weight:800;font-size:16px"><?= $sym ?> <?= number_format($totalSalaries,2) ?></div><div style="font-size:11px;color:var(--green)">Paid: <?= $sym ?> <?= number_format($totalSalariesPaid,2) ?></div></div>
      </div>
    </div>
  </div>

  <!-- Expense breakdown -->
  <div class="card">
    <div class="card-title">📊 Expense Breakdown — <?= $monthLabel ?></div>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php
      $expByType = $db->prepare("SELECT billing_type, COALESCE(SUM(cost_amount * exchange_rate),0) as total FROM expenses WHERE billing_month=? GROUP BY billing_type");
      $expByType->execute([$month]);
      $expByType = $expByType->fetchAll();
      $typeLabels = ['internal'=>'🏢 Internal','client'=>'👤 Client (We Pay)','shared'=>'🔗 Shared','client_paid'=>'💳 Client-Paid'];
      foreach ($expByType as $r):
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg3);border-radius:8px">
        <div style="font-size:12px;color:var(--text2)"><?= $typeLabels[$r['billing_type']] ?? $r['billing_type'] ?></div>
        <div style="font-weight:700"><?= $sym ?> <?= number_format($r['total'],2) ?></div>
      </div>
      <?php endforeach; if (empty($expByType)): ?>
      <div style="text-align:center;color:var(--text2);padding:20px;font-size:13px">No expenses this month</div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:rgba(255,77,109,.08);border:1px solid rgba(255,77,109,.2);border-radius:8px">
        <div style="font-weight:700">Total Operational</div>
        <div style="font-weight:800;font-size:16px"><?= $sym ?> <?= number_format($bizExpenses,2) ?></div>
      </div>
    </div>
  </div>

</div>

<!-- ══ RECENT ACTIVITY ══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">

  <!-- Recent Invoices -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="card-title" style="margin:0">Recent Invoices</div>
      <a href="invoices.php" style="font-size:12px;color:var(--accent);text-decoration:none">View all →</a>
    </div>
    <?php if (empty($recentInvoices)): ?>
      <div style="text-align:center;color:var(--text2);padding:16px;font-size:13px">No invoices yet.</div>
    <?php else: foreach ($recentInvoices as $inv): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:13px;font-weight:600"><?= h($inv['company_name']) ?></div>
          <div style="font-size:11px;color:var(--text2)"><?= h($inv['invoice_number']) ?> · <?= date('d M Y',strtotime($inv['issue_date'])) ?></div>
        </div>
        <div style="text-align:right">
          <div style="font-size:13px;font-weight:700;color:var(--green)"><?= $sym ?> <?= number_format($inv['total'],2) ?></div>
          <span class="badge badge-<?= ['draft'=>'blue','sent'=>'yellow','paid'=>'green','overdue'=>'red','cancelled'=>'red'][$inv['status']]??'blue' ?>" style="font-size:10px"><?= ucfirst($inv['status']) ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Recent Expenses -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="card-title" style="margin:0">Recent Expenses</div>
      <a href="expenses.php" style="font-size:12px;color:var(--accent);text-decoration:none">View all →</a>
    </div>
    <?php if (empty($recentExpenses)): ?>
      <div style="text-align:center;color:var(--text2);padding:16px;font-size:13px">No expenses yet.</div>
    <?php else: foreach ($recentExpenses as $exp): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)">
        <div>
          <div style="font-size:13px;font-weight:600"><?= h($exp['expense_category']) ?><?= $exp['project_name']?' — '.h($exp['project_name']):'' ?></div>
          <div style="font-size:11px;color:var(--text2)"><?= $exp['client_name']?h($exp['client_name']):'Internal' ?> · <?= date('d M Y',strtotime($exp['expense_date'])) ?></div>
        </div>
        <div style="text-align:right">
          <div style="font-size:13px;font-weight:700"><?= $sym ?> <?= number_format($exp['total_billable'],2) ?></div>
          <span style="font-size:10px;color:var(--text2)"><?= h($exp['currency']) ?> <?= number_format($exp['cost_amount'],2) ?></span>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

</div>

<?php pageFooter(); ?>
