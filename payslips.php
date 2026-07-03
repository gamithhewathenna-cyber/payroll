<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$filterMonth = $_GET['month'] ?? date('Y-m');
$viewId = (int)($_GET['id'] ?? 0);

// List mode or view mode
if ($viewId) {
    $stmt = $db->prepare("SELECT p.*, e.full_name, e.email, e.position, e.department, e.job_type, e.employee_id as emp_code, e.payment_method, e.bank_name FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.id=?");
    $stmt->execute([$viewId]);
    $p = $stmt->fetch();

    if (!$p) { setFlash('error','Payslip not found.'); header('Location:'.SITE_URL.'/payslips.php'); exit; }

    // Get commissions
    $comms = $db->prepare("SELECT * FROM commissions WHERE employee_id=? AND month=?");
    $comms->execute([$p['employee_id'], $p['month']]);
    $comms = $comms->fetchAll();

    // Get allowances
    $allows = $db->prepare("SELECT * FROM allowances WHERE employee_id=? AND month=?");
    $allows->execute([$p['employee_id'], $p['month']]);
    $allows = $allows->fetchAll();

    pageHeader('Payslip');
    ?>
<div class="no-print" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">
  <a href="<?= SITE_URL ?>/payslips.php?month=<?= $filterMonth ?>" class="btn btn-ghost">← Back</a>
  <button class="btn btn-primary" onclick="window.print()">🖨 Print / Save PDF</button>
  <a href="<?= SITE_URL ?>/send_payslip.php?id=<?= $viewId ?>" class="btn btn-success" onclick="return confirm('Send payslip to <?= h($p['full_name']) ?> at <?= h($p['email']) ?>?')">
    📧 Send to Email
  </a>
</div>

<div class="payslip-doc">
  <div class="payslip-header">
    <div class="payslip-company">
      <h2><?= h(getSetting("company_name", SITE_NAME)) ?></h2>
      <p><?= h(getSetting("company_address")) ?></p><p><?= h(getSetting("company_email")) ?></p>
    </div>
    <div class="payslip-meta">
      <h3>PAYSLIP</h3>
      <p>Period: <?= date('F Y', strtotime($p['month'].'-01')) ?></p>
      <p>Status: <strong style="color:<?= $p['payment_status']==='paid' ? 'var(--green)' : 'var(--yellow)' ?>"><?= strtoupper($p['payment_status']) ?></strong></p>
      <?php if ($p['payment_date']): ?><p>Paid: <?= $p['payment_date'] ?></p><?php endif; ?>
    </div>
  </div>

  <div class="payslip-grid">
    <div class="payslip-section">
      <h4>Employee Details</h4>
      <div class="payslip-row"><span>Name</span><span><?= h($p['full_name']) ?></span></div>
      <div class="payslip-row"><span>ID</span><span><?= h($p['emp_code']) ?></span></div>
      <div class="payslip-row"><span>Position</span><span><?= h($p['position']) ?></span></div>
      <div class="payslip-row"><span>Department</span><span><?= h($p['department']) ?></span></div>
      <div class="payslip-row"><span>Job Type</span><span><?= ($p['job_type'] ?? '') === 'remote' ? '🌐 Remote Job' : '🏢 On-site Job' ?></span></div>
      <div class="payslip-row"><span>Email</span><span><?= h($p['email']) ?></span></div>
    </div>
    <div class="payslip-section">
      <h4>Payment Details</h4>
      <div class="payslip-row"><span>Method</span><span><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></span></div>
      <?php if ($p['bank_name']): ?><div class="payslip-row"><span>Bank</span><span><?= h($p['bank_name']) ?></span></div><?php endif; ?>
    </div>
  </div>

  <div class="payslip-grid">
    <div class="payslip-section">
      <h4>Earnings</h4>
      <div class="payslip-row"><span>Base Salary</span><span><?= formatMoney($p['base_salary']) ?></span></div>
      <?php if ($p['bonus'] > 0): ?><div class="payslip-row"><span>Bonus</span><span><?= formatMoney($p['bonus']) ?></span></div><?php endif; ?>
      <?php foreach ($allows as $a): ?>
        <div class="payslip-row"><span><?= ucfirst($a['allowance_type']) ?> Allowance</span><span><?= formatMoney($a['amount']) ?></span></div>
      <?php endforeach; ?>
      <?php foreach ($comms as $c): ?>
        <div class="payslip-row"><span>Commission: <?= h($c['project_name']) ?></span><span><?= formatMoney($c['commission_amount']) ?></span></div>
      <?php endforeach; ?>
    </div>
    <div class="payslip-section">
      <h4>Deductions</h4>
      <?php if ($p['deductions'] > 0): ?><div class="payslip-row"><span>Deductions</span><span style="color:var(--red)">-<?= formatMoney($p['deductions']) ?></span></div><?php endif; ?>
      <?php if ($p['advance_payment'] > 0): ?><div class="payslip-row"><span>Advance Recovery</span><span style="color:var(--red)">-<?= formatMoney($p['advance_payment']) ?></span></div><?php endif; ?>
      <?php if ($p['deductions'] == 0 && $p['advance_payment'] == 0): ?><div class="payslip-row" style="color:var(--text2)"><span>No deductions</span><span>$0.00</span></div><?php endif; ?>
    </div>
  </div>

  <?php if ($p['notes']): ?>
  <div style="margin-top:16px;padding:12px;background:var(--bg3);border-radius:8px;font-size:13px;color:var(--text2)">
    <strong style="color:var(--text)">Notes:</strong> <?= h($p['notes']) ?>
  </div>
  <?php endif; ?>

  <div class="payslip-total">
    <div class="payslip-total-label">Net Salary</div>
    <div class="payslip-total-amount"><?= formatMoney($p['final_salary']) ?></div>
  </div>
</div>

    <?php
    pageFooter();
    exit;
}

// List all payslips for month
$payrolls = $db->prepare("SELECT p.*, e.full_name, e.position FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.month=? ORDER BY e.full_name");
$payrolls->execute([$filterMonth]);
$payrolls = $payrolls->fetchAll();

pageHeader('Payslips');
?>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
</div>

<div class="card">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Position</th><th>Month</th><th>Final Salary</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($payrolls)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:30px">No payslips for <?= h($filterMonth) ?>. Process payroll first.</td></tr>
        <?php else: foreach ($payrolls as $p): ?>
          <tr>
            <td data-label="Employee"><strong><?= h($p['full_name']) ?></strong></td>
            <td data-label="Position"><?= h($p['position']) ?></td>
            <td data-label="Month"><?= $p['month'] ?></td>
            <td data-label="Salary"><strong style="color:var(--green)"><?= formatMoney($p['final_salary']) ?></strong></td>
            <td data-label="Status"><?= $p['payment_status']==='paid' ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-yellow">Pending</span>' ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">View</a>
              <a href="<?= SITE_URL ?>/send_payslip.php?id=<?= $p['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Send payslip email to <?= h($p['full_name']) ?>?')">📧 Email</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php pageFooter(); ?>
