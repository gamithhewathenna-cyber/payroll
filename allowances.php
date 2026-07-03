<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// Handle DELETE via GET
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM allowances WHERE id=?")->execute([$id]);
    setFlash('success', 'Allowance deleted.');
    header('Location: ' . SITE_URL . '/allowances.php'); exit;
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    if ($action === 'add' || $action === 'edit') {
        if ($action === 'add') {
            $db->prepare("INSERT INTO allowances (employee_id,allowance_type,amount,month,description) VALUES (?,?,?,?,?)")
               ->execute([$d['employee_id'],$d['allowance_type'],$d['amount'],$d['month'],$d['description']]);
            setFlash('success', 'Allowance added.');
        } else {
            $db->prepare("UPDATE allowances SET employee_id=?,allowance_type=?,amount=?,month=?,description=? WHERE id=?")
               ->execute([$d['employee_id'],$d['allowance_type'],$d['amount'],$d['month'],$d['description'],$id]);
            setFlash('success', 'Allowance updated.');
        }

        // Sync payroll totals
        $pRow = $db->prepare("SELECT id FROM payroll WHERE employee_id=? AND month=?");
        $pRow->execute([$d['employee_id'], $d['month']]);
        $pRow = $pRow->fetch();
        if ($pRow) {
            $ta = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM allowances WHERE employee_id=? AND month=?");
            $ta->execute([$d['employee_id'], $d['month']]);
            $db->prepare("UPDATE payroll SET total_allowances=? WHERE id=?")->execute([$ta->fetchColumn(), $pRow['id']]);
            recalcPayroll($db, $pRow['id']);
        }
    }
    header('Location: ' . SITE_URL . '/allowances.php?month=' . ($d['month'] ?? date('Y-m'))); exit;
}

$filterMonth = $_GET['month'] ?? date('Y-m');

// Load record for editing
$editRow = null;
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM allowances WHERE id=?");
    $s->execute([$id]);
    $editRow = $s->fetch();
}

$allowances = $db->prepare("SELECT a.*, e.full_name FROM allowances a JOIN employees e ON e.id=a.employee_id WHERE a.month=? ORDER BY e.full_name");
$allowances->execute([$filterMonth]);
$allowances = $allowances->fetchAll();
$employees = $db->query("SELECT * FROM employees WHERE status='active' ORDER BY full_name")->fetchAll();
$types = ['internet'=>'🌐 Internet','electricity'=>'⚡ Electricity','software'=>'💻 Software','equipment'=>'🖥️ Equipment','mobile'=>'📱 Mobile','other'=>'📦 Other'];

pageHeader('Allowances');
?>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Allowance</button>
</div>

<div class="card">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Type</th><th>Amount</th><th>Description</th><th>Month</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($allowances)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:30px">No allowances for <?= h($filterMonth) ?>.</td></tr>
        <?php else: foreach ($allowances as $a): ?>
          <tr>
            <td data-label="Employee"><strong><?= h($a['full_name']) ?></strong></td>
            <td data-label="Type"><?= $types[$a['allowance_type']] ?? $a['allowance_type'] ?></td>
            <td data-label="Amount"><strong><?= formatMoney($a['amount']) ?></strong></td>
            <td data-label="Description" style="color:var(--text2)"><?= h($a['description']) ?></td>
            <td data-label="Month"><?= h($a['month']) ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit&id=<?= $a['id'] ?>&month=<?= h($filterMonth) ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $a['id'] ?>&month=<?= h($filterMonth) ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete this allowance?')">Delete</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add Allowance</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add">
        <div class="form-grid">
          <div class="form-group full">
            <label>Employee *</label>
            <select name="employee_id" required>
              <option value="">— Select —</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>"><?= h($e['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Allowance Type *</label>
            <select name="allowance_type" required>
              <?php foreach ($types as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Amount *</label><input type="number" name="amount" step="0.01" required placeholder="e.g. 2500"></div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= $filterMonth ?>" required></div>
          <div class="form-group full"><label>Description</label><input name="description" placeholder="e.g. March internet reimbursement"></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Allowance</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<?php if ($editRow): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Allowance</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/allowances.php?month=<?= h($filterMonth) ?>'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit&id=<?= $editRow['id'] ?>">
        <div class="form-grid">
          <div class="form-group full">
            <label>Employee *</label>
            <select name="employee_id" required>
              <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $e['id'] == $editRow['employee_id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Allowance Type *</label>
            <select name="allowance_type" required>
              <?php foreach ($types as $k => $v): ?>
                <option value="<?= $k ?>" <?= $k === $editRow['allowance_type'] ? 'selected' : '' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Amount *</label><input type="number" name="amount" step="0.01" required value="<?= h($editRow['amount']) ?>"></div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= h($editRow['month']) ?>" required></div>
          <div class="form-group full"><label>Description</label><input name="description" value="<?= h($editRow['description']) ?>"></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/allowances.php?month=<?= h($filterMonth) ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php pageFooter(); ?>
