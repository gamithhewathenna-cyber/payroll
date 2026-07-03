<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();
if (isAdmin()) { header('Location:'.SITE_URL.'/dashboard.php'); exit; }
$db = getDB();

$empDbId = $_SESSION['employee_db_id'];

$payrolls = $db->prepare("SELECT * FROM payroll WHERE employee_id=? ORDER BY month DESC");
$payrolls->execute([$empDbId]);
$payrolls = $payrolls->fetchAll();

$commissions = $db->prepare("SELECT * FROM commissions WHERE employee_id=? ORDER BY month DESC");
$commissions->execute([$empDbId]);
$commissions = $commissions->fetchAll();

pageHeader('Salary History');
?>

<div class="card">
  <div class="card-title">Salary History</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Month</th><th>Base</th><th>Bonus</th><th>Comm</th><th>Allowances</th><th>Deductions</th><th>Net</th><th>Status</th></tr></thead>
      <tbody>
        <?php if (empty($payrolls)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:30px">No salary history yet.</td></tr>
        <?php else: foreach ($payrolls as $p): ?>
          <tr>
            <td><strong><?= date('F Y', strtotime($p['month'].'-01')) ?></strong></td>
            <td><?= formatMoney($p['base_salary']) ?></td>
            <td><?= formatMoney($p['bonus']) ?></td>
            <td><?= formatMoney($p['total_commissions']) ?></td>
            <td><?= formatMoney($p['total_allowances']) ?></td>
            <td style="color:var(--red)">-<?= formatMoney($p['deductions']+$p['advance_payment']) ?></td>
            <td><strong style="color:var(--green)"><?= formatMoney($p['final_salary']) ?></strong></td>
            <td><?= $p['payment_status']==='paid'?'<span class="badge badge-green">Paid</span>':'<span class="badge badge-yellow">Pending</span>' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-title">Commission History</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Month</th><th>Project</th><th>Type</th><th>Amount</th></tr></thead>
      <tbody>
        <?php if (empty($commissions)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--text2);padding:20px">No commissions yet.</td></tr>
        <?php else: foreach ($commissions as $c): ?>
          <tr>
            <td><?= date('F Y', strtotime($c['month'].'-01')) ?></td>
            <td><?= h($c['project_name']) ?></td>
            <td><span class="badge badge-blue"><?= ucfirst($c['commission_type']) ?></span></td>
            <td><strong style="color:var(--green)"><?= formatMoney($c['commission_amount']) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php pageFooter(); ?>
