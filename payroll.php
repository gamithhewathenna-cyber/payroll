<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// ── GET actions ───────────────────────────────────────────
if ($action === 'mark_paid' && $id) {
    $db->prepare("UPDATE payroll SET payment_status='paid', payment_date=CURDATE() WHERE id=?")->execute([$id]);
    setFlash('success', 'Marked as paid.');
    header('Location: ' . SITE_URL . '/payroll.php?month=' . ($_GET['month'] ?? date('Y-m'))); exit;
}
if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $db->prepare("DELETE FROM payroll WHERE id=?")->execute([$id]);
    setFlash('success', 'Payroll record deleted.');
    header('Location: ' . SITE_URL . '/payroll.php?month=' . ($_GET['month'] ?? date('Y-m'))); exit;
}

// ── Edit: load record ─────────────────────────────────────
$editRecord = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT p.*, e.full_name, e.monthly_salary FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.id=?");
    $stmt->execute([$id]);
    $editRecord = $stmt->fetch();
}

// ── POST actions ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add') {
        try {
            $empId = (int)$d['employee_id'];
            $emp = $db->prepare("SELECT * FROM employees WHERE id=?");
            $emp->execute([$empId]);
            $empData = $emp->fetch();
            $base = $empData ? (float)$empData['monthly_salary'] : 0;

            $commStmt = $db->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM commissions WHERE employee_id=? AND month=?");
            $commStmt->execute([$empId, $d['month']]);
            $totalComm = (float)$commStmt->fetchColumn();

            $allowStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM allowances WHERE employee_id=? AND month=?");
            $allowStmt->execute([$empId, $d['month']]);
            $totalAllow = (float)$allowStmt->fetchColumn();

            $bonus   = (float)($d['bonus'] ?? 0);
            $deduct  = (float)($d['deductions'] ?? 0);
            $advance = (float)($d['advance_payment'] ?? 0);
            $final   = $base + $bonus + $totalAllow + $totalComm - $deduct - $advance;

            $stmt = $db->prepare("INSERT INTO payroll (employee_id,month,base_salary,bonus,deductions,advance_payment,total_allowances,total_commissions,final_salary,payment_status,payment_method,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bonus=VALUES(bonus),deductions=VALUES(deductions),advance_payment=VALUES(advance_payment),total_allowances=VALUES(total_allowances),total_commissions=VALUES(total_commissions),final_salary=VALUES(final_salary),payment_method=VALUES(payment_method),notes=VALUES(notes)");
            $stmt->execute([$empId,$d['month'],$base,$bonus,$deduct,$advance,$totalAllow,$totalComm,$final,$d['payment_status'],$d['payment_method'],$d['notes']]);
            setFlash('success', 'Payroll saved for '.$empData['full_name'].'. Base: '.getSetting('currency_symbol','Rs.').' '.number_format($base,2));
        } catch (Exception $e) {
            setFlash('error', 'Error: ' . $e->getMessage());
        }
    } elseif ($action === 'update' && $id) {
        $d      = $_POST;
        $bonus   = (float)($d['bonus'] ?? 0);
        $deduct  = (float)($d['deductions'] ?? 0);
        $advance = (float)($d['advance_payment'] ?? 0);
        // Recalculate final salary
        $baseStmt = $db->prepare("SELECT base_salary, total_allowances, total_commissions FROM payroll WHERE id=?");
        $baseStmt->execute([$id]);
        $row  = $baseStmt->fetch();
        $final = $row['base_salary'] + $bonus + $row['total_allowances'] + $row['total_commissions'] - $deduct - $advance;
        $db->prepare("UPDATE payroll SET bonus=?,deductions=?,advance_payment=?,final_salary=?,payment_status=?,payment_method=?,notes=? WHERE id=?")
           ->execute([$bonus,$deduct,$advance,$final,$d['payment_status'],$d['payment_method'],trim($d['notes']??''),$id]);
        setFlash('success', 'Payroll updated. Final salary: '.getSetting('currency_symbol','Rs.').' '.number_format($final,2));
    } elseif ($action === 'delete') {
        $db->prepare("DELETE FROM payroll WHERE id=?")->execute([$id]);
        setFlash('success', 'Payroll record deleted.');
    }
    header('Location: ' . SITE_URL . '/payroll.php?month=' . ($d['month'] ?? date('Y-m'))); exit;
}

$filterMonth = $_GET['month'] ?? date('Y-m');
$payrolls = $db->prepare("SELECT p.*, e.full_name, e.position, e.employee_id as emp_code FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.month=? ORDER BY e.full_name");
$payrolls->execute([$filterMonth]);
$payrolls = $payrolls->fetchAll();

$employees = $db->query("SELECT id, employee_id, full_name, position, monthly_salary FROM employees WHERE status='active' ORDER BY full_name")->fetchAll();

$sym = getSetting('currency_symbol', 'Rs.');

pageHeader('Payroll');
?>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <label style="color:var(--text2);font-size:13px">Month:</label>
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px" onchange="this.form.submit()">
  </form>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Process Payroll</button>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Base Salary</th><th>Bonus</th><th>Commissions</th><th>Allowances</th><th>Deductions</th><th>Final</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($payrolls)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--text2);padding:30px">No payroll for <?= date('F Y',strtotime($filterMonth.'-01')) ?>. Click "Process Payroll" to add.</td></tr>
        <?php else: foreach ($payrolls as $p): ?>
          <tr>
            <td data-label="Employee">
              <strong><?= h($p['full_name']) ?></strong>
              <div style="font-size:11px;color:var(--text2)"><?= h($p['emp_code']) ?></div>
            </td>
            <td data-label="Base"><?= $sym ?> <?= number_format($p['base_salary'],2) ?></td>
            <td data-label="Bonus"><?= $sym ?> <?= number_format($p['bonus'],2) ?></td>
            <td data-label="Commissions"><?= $sym ?> <?= number_format($p['total_commissions'],2) ?></td>
            <td data-label="Allowances"><?= $sym ?> <?= number_format($p['total_allowances'],2) ?></td>
            <td data-label="Deductions" style="color:var(--red)">-<?= $sym ?> <?= number_format($p['deductions'] + $p['advance_payment'],2) ?></td>
            <td data-label="Final"><strong style="color:var(--green)"><?= $sym ?> <?= number_format($p['final_salary'],2) ?></strong></td>
            <td data-label="Status">
              <?php if ($p['payment_status'] === 'paid'): ?>
                <span class="badge badge-green">Paid</span>
                <?php if ($p['payment_date']): ?><div style="font-size:11px;color:var(--text2)"><?= date('d M Y',strtotime($p['payment_date'])) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="badge badge-yellow">Pending</span>
              <?php endif; ?>
            </td>
            <td data-label=""><div class="mob-actions">
              <a href="<?= SITE_URL ?>/payslips.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Payslip</a>
              <a href="?action=edit&id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($p['payment_status'] !== 'paid'): ?>
                <a href="?action=mark_paid&id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-primary btn-sm" onclick="return confirm('Mark as paid?')">✓ Paid</a>
              <?php endif; ?>
              <a href="?action=delete&id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete payroll record?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($payrolls)): ?>
      <tfoot>
        <tr style="background:var(--bg3)">
          <td><strong>TOTALS</strong></td>
          <td><?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'base_salary')),2) ?></td>
          <td><?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'bonus')),2) ?></td>
          <td><?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'total_commissions')),2) ?></td>
          <td><?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'total_allowances')),2) ?></td>
          <td style="color:var(--red)">-<?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'deductions'))+array_sum(array_column($payrolls,'advance_payment')),2) ?></td>
          <td><strong style="color:var(--green)"><?= $sym ?> <?= number_format(array_sum(array_column($payrolls,'final_salary')),2) ?></strong></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<!-- Process Payroll Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Process Payroll</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add">
        <div class="form-grid">

          <!-- Employee select with live salary preview -->
          <div class="form-group full">
            <label>Employee *</label>
            <select name="employee_id" id="payEmp" required onchange="loadEmpSalary(this)">
              <option value="">— Select Employee —</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>"
                  data-salary="<?= $e['monthly_salary'] ?>"
                  data-name="<?= h($e['full_name']) ?>"
                  data-pos="<?= h($e['position'] ?? '') ?>">
                  <?= h($e['full_name']) ?> (<?= h($e['employee_id']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Salary Preview -->
          <div id="salaryPreview" style="display:none;grid-column:1/-1">
            <div style="background:rgba(0,196,140,.08);border:1px solid rgba(0,196,140,.25);border-radius:10px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
              <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:4px">📋 Base Salary (auto-pulled from Employee Record)</div>
                <div style="font-size:26px;font-weight:800;color:var(--green)" id="previewSalary"><?= $sym ?> 0.00</div>
                <div style="font-size:12px;color:var(--text2);margin-top:3px" id="previewName"></div>
              </div>
              <div style="font-size:11px;color:var(--text2);text-align:right;line-height:1.7">
                ✅ Salary is pulled automatically<br>
                📈 Commissions for the month auto-added<br>
                🎁 Allowances for the month auto-added
              </div>
            </div>
          </div>

          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= $filterMonth ?>" required></div>
          <div class="form-group"><label>Bonus (<?= $sym ?>)</label><input type="number" name="bonus" step="0.01" value="0"></div>
          <div class="form-group"><label>Deductions (<?= $sym ?>)</label><input type="number" name="deductions" step="0.01" value="0"></div>
          <div class="form-group"><label>Advance Payment (<?= $sym ?>)</label><input type="number" name="advance_payment" step="0.01" value="0"></div>
          <div class="form-group"><label>Payment Status</label>
            <select name="payment_status">
              <option value="pending">Pending</option>
              <option value="paid">Paid</option>
            </select>
          </div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cash">Cash</option>
              <option value="online">Online Payment</option>
            </select>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional notes..."></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Payroll</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($editRecord): ?>
<!-- Edit Payroll Modal -->
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Payroll — <?= h($editRecord['full_name']) ?></div>
      <a href="?month=<?= $filterMonth ?>" class="modal-close">×</a>
    </div>
    <div class="modal-body">
      <div style="background:rgba(0,196,140,.08);border:1px solid rgba(0,196,140,.2);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px">
        <div style="color:var(--text2);font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Base Salary (fixed from employee record)</div>
        <div style="font-size:22px;font-weight:800;color:var(--green)"><?= $sym ?> <?= number_format($editRecord['base_salary'],2) ?></div>
        <div style="font-size:11px;color:var(--text2);margin-top:2px">Commissions: <?= $sym ?> <?= number_format($editRecord['total_commissions'],2) ?> &nbsp;·&nbsp; Allowances: <?= $sym ?> <?= number_format($editRecord['total_allowances'],2) ?></div>
      </div>
      <form method="POST" action="?action=update&id=<?= $id ?>">
        <input type="hidden" name="month" value="<?= h($editRecord['month']) ?>">
        <div class="form-grid">
          <div class="form-group"><label>Bonus (<?= $sym ?>)</label><input type="number" name="bonus" step="0.01" value="<?= h($editRecord['bonus']) ?>"></div>
          <div class="form-group"><label>Deductions (<?= $sym ?>)</label><input type="number" name="deductions" step="0.01" value="<?= h($editRecord['deductions']) ?>"></div>
          <div class="form-group"><label>Advance Payment (<?= $sym ?>)</label><input type="number" name="advance_payment" step="0.01" value="<?= h($editRecord['advance_payment']) ?>"></div>
          <div class="form-group"><label>Payment Status</label>
            <select name="payment_status">
              <option value="pending" <?= $editRecord['payment_status']==='pending'?'selected':'' ?>>Pending</option>
              <option value="paid" <?= $editRecord['payment_status']==='paid'?'selected':'' ?>>Paid</option>
            </select>
          </div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <?php foreach (['bank_transfer'=>'Bank Transfer','cash'=>'Cash','online'=>'Online Payment'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $editRecord['payment_method']===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2"><?= h($editRecord['notes']) ?></textarea></div>
        </div>
        <div style="background:var(--bg3);border-radius:8px;padding:12px 14px;margin:12px 0;font-size:13px">
          <span style="color:var(--text2)">Recalculated Final Salary: </span>
          <strong style="color:var(--green);font-size:16px" id="editFinal"><?= $sym ?> <?= number_format($editRecord['final_salary'],2) ?></strong>
          <span style="font-size:11px;color:var(--text2)"> (updates when you change values above)</span>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">💾 Save Changes</button>
          <a href="?month=<?= $filterMonth ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Live recalculate final salary in edit modal
(function() {
    const base  = <?= $editRecord['base_salary'] ?>;
    const comm  = <?= $editRecord['total_commissions'] ?>;
    const allow = <?= $editRecord['total_allowances'] ?>;
    const sym   = '<?= addslashes($sym) ?>';
    function recalc() {
        const bonus   = parseFloat(document.querySelector('[name="bonus"]')?.value)          || 0;
        const deduct  = parseFloat(document.querySelector('[name="deductions"]')?.value)     || 0;
        const advance = parseFloat(document.querySelector('[name="advance_payment"]')?.value) || 0;
        const final   = base + bonus + allow + comm - deduct - advance;
        const el = document.getElementById('editFinal');
        if (el) el.textContent = sym + ' ' + final.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
    }
    document.querySelectorAll('[name="bonus"],[name="deductions"],[name="advance_payment"]').forEach(i => i.addEventListener('input', recalc));
})();
</script>
<?php endif; ?>

<script>
const sym = '<?= addslashes($sym) ?>';

function loadEmpSalary(sel) {
    const opt     = sel.options[sel.selectedIndex];
    const salary  = parseFloat(opt.getAttribute('data-salary')) || 0;
    const name    = opt.getAttribute('data-name') || '';
    const pos     = opt.getAttribute('data-pos') || '';
    const preview = document.getElementById('salaryPreview');
    const salEl   = document.getElementById('previewSalary');
    const nameEl  = document.getElementById('previewName');

    if (sel.value && salary >= 0) {
        preview.style.display = '';
        salEl.textContent  = sym + ' ' + salary.toLocaleString('en', {minimumFractionDigits:2});
        nameEl.textContent = name + (pos ? ' · ' + pos : '');
    } else {
        preview.style.display = 'none';
    }
}
</script>

<?php pageFooter(); ?>
