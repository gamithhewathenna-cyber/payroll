<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();
if (isAdmin()) { header('Location:'.SITE_URL.'/payslips.php'); exit; }
$db = getDB();

$empDbId = $_SESSION['employee_db_id'];
$viewId = (int)($_GET['view'] ?? 0);

if ($viewId) {
    $stmt = $db->prepare("SELECT p.*, e.full_name, e.email, e.position, e.department, e.job_type, e.employee_id as emp_code, e.payment_method, e.bank_name FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.id=? AND e.id=?");
    $stmt->execute([$viewId, $empDbId]);
    $p = $stmt->fetch();
    if (!$p) { setFlash('error','Not found.'); header('Location:'.SITE_URL.'/my_payslips.php'); exit; }

    $comms = $db->prepare("SELECT * FROM commissions WHERE employee_id=? AND month=?");
    $comms->execute([$empDbId, $p['month']]);
    $comms = $comms->fetchAll();
    $allows = $db->prepare("SELECT * FROM allowances WHERE employee_id=? AND month=?");
    $allows->execute([$empDbId, $p['month']]);
    $allows = $allows->fetchAll();

    pageHeader('My Payslip');
    ?>
<div class="no-print" style="margin-bottom:16px;display:flex;gap:10px">
  <a href="<?= SITE_URL ?>/my_payslips.php" class="btn btn-ghost">← Back</a>
  <button class="btn btn-primary" onclick="window.print()">🖨 Print / Save PDF</button>
</div>
<div class="payslip-doc">
  <div class="payslip-header">
    <div class="payslip-company"><h2><?= h(getSetting("company_name", SITE_NAME)) ?></h2><p><?= h(getSetting("company_address")) ?></p><p><?= h(getSetting("company_email")) ?></p></div>
    <div class="payslip-meta">
      <h3>PAYSLIP</h3>
      <p>Period: <?= date('F Y', strtotime($p['month'].'-01')) ?></p>
      <p>Status: <strong style="color:<?= $p['payment_status']==='paid'?'var(--green)':'var(--yellow)' ?>"><?= strtoupper($p['payment_status']) ?></strong></p>
    </div>
  </div>
  <div class="payslip-grid">
    <div class="payslip-section">
      <h4>Employee Details</h4>
      <div class="payslip-row"><span>Name</span><span><?= h($p['full_name']) ?></span></div>
      <div class="payslip-row"><span>ID</span><span><?= h($p['emp_code']) ?></span></div>
      <div class="payslip-row"><span>Position</span><span><?= h($p['position']) ?></span></div>
      <div class="payslip-row"><span>Job Type</span><span><?= ($p['job_type'] ?? '') === 'remote' ? '🌐 Remote Job' : '🏢 On-site Job' ?></span></div>
    </div>
  </div>
  <div class="payslip-grid">
    <div class="payslip-section">
      <h4>Earnings</h4>
      <div class="payslip-row"><span>Base Salary</span><span><?= formatMoney($p['base_salary']) ?></span></div>
      <?php if ($p['bonus']>0): ?><div class="payslip-row"><span>Bonus</span><span><?= formatMoney($p['bonus']) ?></span></div><?php endif; ?>
      <?php foreach ($allows as $a): ?><div class="payslip-row"><span><?= ucfirst($a['allowance_type']) ?> Allowance</span><span><?= formatMoney($a['amount']) ?></span></div><?php endforeach; ?>
      <?php foreach ($comms as $c): ?><div class="payslip-row"><span>Commission: <?= h($c['project_name']) ?></span><span><?= formatMoney($c['commission_amount']) ?></span></div><?php endforeach; ?>
    </div>
    <div class="payslip-section">
      <h4>Deductions</h4>
      <?php if ($p['deductions']>0): ?><div class="payslip-row"><span>Deductions</span><span style="color:var(--red)">-<?= formatMoney($p['deductions']) ?></span></div><?php endif; ?>
      <?php if ($p['advance_payment']>0): ?><div class="payslip-row"><span>Advance Recovery</span><span style="color:var(--red)">-<?= formatMoney($p['advance_payment']) ?></span></div><?php endif; ?>
      <?php if (!$p['deductions'] && !$p['advance_payment']): ?><div class="payslip-row" style="color:var(--text2)"><span>No deductions</span><span>$0.00</span></div><?php endif; ?>
    </div>
  </div>
  <div class="payslip-total">
    <div class="payslip-total-label">Net Salary</div>
    <div class="payslip-total-amount"><?= formatMoney($p['final_salary']) ?></div>
  </div>
</div>
    <?php pageFooter(); exit;
}

// List
$payrolls = $db->prepare("SELECT * FROM payroll WHERE employee_id=? ORDER BY month DESC");
$payrolls->execute([$empDbId]);
$payrolls = $payrolls->fetchAll();

pageHeader('My Payslips');
?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Month</th><th>Base</th><th>Commissions</th><th>Allowances</th><th>Net Salary</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($payrolls)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text2);padding:30px">No payslips yet.</td></tr>
        <?php else: foreach ($payrolls as $p): ?>
          <tr>
            <td><strong><?= date('F Y', strtotime($p['month'].'-01')) ?></strong></td>
            <td><?= formatMoney($p['base_salary']) ?></td>
            <td><?= formatMoney($p['total_commissions']) ?></td>
            <td><?= formatMoney($p['total_allowances']) ?></td>
            <td><strong style="color:var(--green)"><?= formatMoney($p['final_salary']) ?></strong></td>
            <td><?= $p['payment_status']==='paid'?'<span class="badge badge-green">Paid</span>':'<span class="badge badge-yellow">Pending</span>' ?></td>
            <td><a href="?view=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php pageFooter(); ?>
