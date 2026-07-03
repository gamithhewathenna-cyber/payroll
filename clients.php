<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// DELETE
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM clients WHERE id=?")->execute([$id]);
    setFlash('success', 'Client deleted.');
    header('Location: ' . SITE_URL . '/clients.php'); exit;
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add') {
        try {
            $db->prepare("INSERT INTO clients (company_name,contact_name,email,phone,address,address_line2,city,country,vat_number,industry,status,notes,default_currency) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([trim($d['company_name']),trim($d['contact_name']??''),trim($d['email']??''),trim($d['phone']??''),trim($d['address']??''),trim($d['address_line2']??''),trim($d['city']??''),trim($d['country']??'Sri Lanka'),trim($d['vat_number']??''),trim($d['industry']??''),$d['status']??'active',trim($d['notes']??''),$d['default_currency']??'LKR']);
            setFlash('success', 'Client added.');
        } catch (Exception $e) { setFlash('error', 'Error: ' . $e->getMessage()); }
    } elseif ($action === 'edit') {
        $db->prepare("UPDATE clients SET company_name=?,contact_name=?,email=?,phone=?,address=?,address_line2=?,city=?,country=?,vat_number=?,industry=?,status=?,notes=?,default_currency=? WHERE id=?")
           ->execute([trim($d['company_name']),trim($d['contact_name']??''),trim($d['email']??''),trim($d['phone']??''),trim($d['address']??''),trim($d['address_line2']??''),trim($d['city']??''),trim($d['country']??'Sri Lanka'),trim($d['vat_number']??''),trim($d['industry']??''),$d['status']??'active',trim($d['notes']??''),$d['default_currency']??'LKR',$id]);
        setFlash('success', 'Client updated.');
    }
    header('Location: ' . SITE_URL . '/clients.php'); exit;
}

$editRow = null;
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM clients WHERE id=?");
    $s->execute([$id]);
    $editRow = $s->fetch();
}

$search  = trim($_GET['q'] ?? '');
$clients = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM expenses e WHERE e.client_id = c.id) as expense_count FROM clients c " . ($search ? "WHERE c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ?" : "") . " ORDER BY c.company_name");
if ($search) $clients->execute(["%$search%", "%$search%", "%$search%"]);
else $clients->execute([]);
$clients = $clients->fetchAll();

$totalActive   = $db->query("SELECT COUNT(*) FROM clients WHERE status='active'")->fetchColumn();
$totalInactive = $db->query("SELECT COUNT(*) FROM clients WHERE status='inactive'")->fetchColumn();

pageHeader('Clients');
?>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:20px">
  <div class="stat-card green"><div class="stat-label">Active Clients</div><div class="stat-value"><?= $totalActive ?></div></div>
  <div class="stat-card"><div class="stat-label">Inactive</div><div class="stat-value"><?= $totalInactive ?></div></div>
  <div class="stat-card blue"><div class="stat-label">Total</div><div class="stat-value"><?= $totalActive + $totalInactive ?></div></div>
</div>

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex:1">
    <input type="text" name="q" placeholder="Search clients..." value="<?= h($search) ?>" style="max-width:260px">
    <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    <?php if ($search): ?><a href="<?= SITE_URL ?>/clients.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
  </form>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Client</button>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th><th>Industry</th><th>Currency</th><th>Expenses</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($clients)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:32px">No clients found.</td></tr>
        <?php else: foreach ($clients as $c): ?>
          <tr>
            <td data-label="Company"><strong><?= h($c['company_name']) ?></strong><?php if ($c['address']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h(mb_strimwidth($c['address'],0,40,'…')) ?></span><?php endif; ?></td>
            <td data-label="Contact"><?= h($c['contact_name']) ?: '—' ?></td>
            <td data-label="Email" style="color:var(--text2)"><?= h($c['email']) ?: '—' ?></td>
            <td data-label="Phone" style="color:var(--text2)"><?= h($c['phone']) ?: '—' ?></td>
            <td data-label="Industry"><?= h($c['industry']) ?: '—' ?></td>
            <td data-label="Currency"><span class="badge badge-blue" style="font-family:monospace"><?= h($c['default_currency']??'LKR') ?></span></td>
            <td data-label="Expenses"><span class="badge badge-blue"><?= $c['expense_count'] ?></span></td>
            <td data-label="Status"><?= $c['status']==='active' ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $c['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($c['company_name']) ?>?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:580px">
    <div class="modal-header">
      <div class="modal-title">Add Client</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add">
        <div class="form-grid">
          <div class="form-group full"><label>Company Name *</label><input name="company_name" required placeholder="e.g. ABC Holdings"></div>
          <div class="form-group"><label>Contact Person</label><input name="contact_name" placeholder="e.g. John Smith"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="contact@company.com"></div>
          <div class="form-group"><label>Phone</label><input name="phone" placeholder="+94 11 234 5678"></div>
          <div class="form-group"><label>Industry</label><input name="industry" placeholder="e.g. Retail, Finance, Tech"></div>
          <div class="form-group"><label>Status</label>
            <select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
          </div>
          <div class="form-group"><label>Invoice Currency</label>
            <select name="default_currency">
              <option value="LKR">LKR — Sri Lankan Rupee</option>
              <option value="USD">USD — US Dollar</option>
              <option value="AUD">AUD — Australian Dollar</option>
              <option value="EUR">EUR — Euro</option>
              <option value="GBP">GBP — British Pound</option>
              <option value="SGD">SGD — Singapore Dollar</option>
            </select>
          </div>
          <div class="form-group full"><label>Address Line 1</label><textarea name="address" rows="2" placeholder="Street address..."></textarea></div>
          <div class="form-group full"><label>Address Line 2</label><input name="address_line2" placeholder="Apartment, suite, unit, etc."></div>
          <div class="form-group"><label>City</label><input name="city" placeholder="e.g. Colombo"></div>
          <div class="form-group"><label>Country</label><input name="country" value="Sri Lanka"></div>
          <div class="form-group"><label>VAT / Tax Number</label><input name="vat_number" placeholder="e.g. VAT123456789"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Internal notes about this client..."></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Client</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<?php if ($editRow): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal" style="max-width:580px">
    <div class="modal-header">
      <div class="modal-title">Edit Client</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/clients.php'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit&id=<?= $editRow['id'] ?>">
        <div class="form-grid">
          <div class="form-group full"><label>Company Name *</label><input name="company_name" required value="<?= h($editRow['company_name']) ?>"></div>
          <div class="form-group"><label>Contact Person</label><input name="contact_name" value="<?= h($editRow['contact_name']) ?>"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= h($editRow['email']) ?>"></div>
          <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($editRow['phone']) ?>"></div>
          <div class="form-group"><label>Industry</label><input name="industry" value="<?= h($editRow['industry']) ?>"></div>
          <div class="form-group"><label>Status</label>
            <select name="status">
              <option value="active" <?= $editRow['status']==='active'?'selected':'' ?>>Active</option>
              <option value="inactive" <?= $editRow['status']==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
          <div class="form-group"><label>Invoice Currency</label>
            <select name="default_currency">
              <?php foreach (['LKR','USD','AUD','EUR','GBP','SGD'] as $cur): ?>
                <option value="<?= $cur ?>" <?= ($editRow['default_currency']??'LKR')===$cur?'selected':'' ?>><?= $cur ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full"><label>Address Line 1</label><textarea name="address" rows="2"><?= h($editRow['address']) ?></textarea></div>
          <div class="form-group full"><label>Address Line 2</label><input name="address_line2" value="<?= h($editRow['address_line2']??'') ?>" placeholder="Apartment, suite, unit, etc."></div>
          <div class="form-group"><label>City</label><input name="city" value="<?= h($editRow['city']??'') ?>" placeholder="e.g. Colombo"></div>
          <div class="form-group"><label>Country</label><input name="country" value="<?= h($editRow['country']??'Sri Lanka') ?>"></div>
          <div class="form-group"><label>VAT / Tax Number</label><input name="vat_number" value="<?= h($editRow['vat_number']??'') ?>" placeholder="e.g. VAT123456789"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2"><?= h($editRow['notes']) ?></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/clients.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php pageFooter(); ?>
