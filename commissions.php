<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

// Handle DELETE via GET
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM commissions WHERE id=?")->execute([$id]);
    setFlash('success', 'Commission deleted.');
    header('Location: ' . SITE_URL . '/commissions.php'); exit;
}

// Handle POST (add / edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    if ($action === 'add' || $action === 'edit') {
        $amount = $d['commission_type'] === 'percentage'
            ? round((float)$d['commission_value'] / 100 * (float)$d['project_value'], 2)
            : (float)$d['commission_value'];

        if ($action === 'add') {
            $db->prepare("INSERT INTO commissions (employee_id,project_name,commission_type,commission_value,project_value,commission_amount,month,notes) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$d['employee_id'],$d['project_name'],$d['commission_type'],$d['commission_value'],$d['project_value'],$amount,$d['month'],$d['notes']]);
            setFlash('success', 'Commission added.');
        } else {
            $db->prepare("UPDATE commissions SET employee_id=?,project_name=?,commission_type=?,commission_value=?,project_value=?,commission_amount=?,month=?,notes=? WHERE id=?")
               ->execute([$d['employee_id'],$d['project_name'],$d['commission_type'],$d['commission_value'],$d['project_value'],$amount,$d['month'],$d['notes'],$id]);
            setFlash('success', 'Commission updated.');
        }

        // Sync payroll totals
        $empId = $d['employee_id'];
        $mon   = $d['month'];
        $pRow  = $db->prepare("SELECT id FROM payroll WHERE employee_id=? AND month=?");
        $pRow->execute([$empId, $mon]);
        $pRow = $pRow->fetch();
        if ($pRow) {
            $tc = $db->prepare("SELECT COALESCE(SUM(commission_amount),0) FROM commissions WHERE employee_id=? AND month=?");
            $tc->execute([$empId, $mon]);
            $db->prepare("UPDATE payroll SET total_commissions=? WHERE id=?")->execute([$tc->fetchColumn(), $pRow['id']]);
            recalcPayroll($db, $pRow['id']);
        }
    }
    header('Location: ' . SITE_URL . '/commissions.php?month=' . ($d['month'] ?? date('Y-m'))); exit;
}

$filterMonth = $_GET['month'] ?? date('Y-m');

// Load record for editing
$editRow = null;
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM commissions WHERE id=?");
    $s->execute([$id]);
    $editRow = $s->fetch();
}

$commissions = $db->prepare("SELECT c.*, e.full_name FROM commissions c JOIN employees e ON e.id=c.employee_id WHERE c.month=? ORDER BY e.full_name");
$commissions->execute([$filterMonth]);
$commissions = $commissions->fetchAll();
$employees = $db->query("SELECT * FROM employees WHERE status='active' ORDER BY full_name")->fetchAll();

pageHeader('Commissions');
?>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Commission</button>
</div>

<div class="card">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Employee</th><th>Project</th><th>Type</th><th>Value</th><th>Project Value</th><th>Commission</th><th>Month</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($commissions)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:30px">No commissions for <?= h($filterMonth) ?>.</td></tr>
        <?php else: foreach ($commissions as $c): ?>
          <tr>
            <td data-label="Employee"><strong><?= h($c['full_name']) ?></strong></td>
            <td data-label="Project"><?= h($c['project_name']) ?></td>
            <td data-label="Type"><span class="badge badge-blue"><?= ucfirst($c['commission_type']) ?></span></td>
            <td data-label="Value"><?= $c['commission_type'] === 'percentage' ? h($c['commission_value']).'%' : formatMoney($c['commission_value']) ?></td>
            <td data-label="Proj. Value"><?= $c['project_value'] > 0 ? formatMoney($c['project_value']) : '—' ?></td>
            <td data-label="Commission"><strong style="color:var(--green)"><?= formatMoney($c['commission_amount']) ?></strong></td>
            <td data-label="Month"><?= h($c['month']) ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit&id=<?= $c['id'] ?>&month=<?= h($filterMonth) ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $c['id'] ?>&month=<?= h($filterMonth) ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete this commission?')">Delete</a>
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
      <div class="modal-title">Add Commission</div>
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
          <div class="form-group full"><label>Project Name *</label><input name="project_name" required placeholder="e.g. SEO Campaign Q2"></div>
          <div class="form-group">
            <label>Commission Type</label>
            <select name="commission_type" id="addCommType" onchange="toggleProjVal('add')">
              <option value="fixed">Fixed Amount</option>
              <option value="percentage">Percentage</option>
            </select>
          </div>
          <div class="form-group"><label>Commission Value *</label><input type="number" name="commission_value" step="0.01" required placeholder="e.g. 5000 or 10 (%)"></div>
          <div class="form-group" id="addProjValField" style="display:none"><label>Project Value</label><input type="number" name="project_value" step="0.01" value="0"></div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= $filterMonth ?>" required></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes"></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Commission</button>
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
      <div class="modal-title">Edit Commission</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/commissions.php?month=<?= h($filterMonth) ?>'">×</button>
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
          <div class="form-group full"><label>Project Name *</label><input name="project_name" required value="<?= h($editRow['project_name']) ?>"></div>
          <div class="form-group">
            <label>Commission Type</label>
            <select name="commission_type" id="editCommType" onchange="toggleProjVal('edit')">
              <option value="fixed" <?= $editRow['commission_type']==='fixed'?'selected':'' ?>>Fixed Amount</option>
              <option value="percentage" <?= $editRow['commission_type']==='percentage'?'selected':'' ?>>Percentage</option>
            </select>
          </div>
          <div class="form-group"><label>Commission Value *</label><input type="number" name="commission_value" step="0.01" required value="<?= h($editRow['commission_value']) ?>"></div>
          <div class="form-group" id="editProjValField" style="<?= $editRow['commission_type']==='percentage'?'':'display:none' ?>">
            <label>Project Value</label><input type="number" name="project_value" step="0.01" value="<?= h($editRow['project_value']) ?>">
          </div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= h($editRow['month']) ?>" required></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes"><?= h($editRow['notes']) ?></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/commissions.php?month=<?= h($filterMonth) ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function toggleProjVal(prefix) {
    const t = document.getElementById(prefix+'CommType').value;
    document.getElementById(prefix+'ProjValField').style.display = t === 'percentage' ? '' : 'none';
}
</script>
<?php pageFooter(); ?>
