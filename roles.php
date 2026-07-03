<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();
if (!isSuperAdmin()) { header('Location: ' . SITE_URL . '/dashboard.php?denied=1'); exit; }
$db = getDB();

$pages = [
    'dashboard'  => '▦ Dashboard',
    'employees'  => '👤 Employees',
    'payroll'    => '💰 Payroll',
    'freelance'  => '🧑‍💻 Freelance',
    'commissions'=> '📈 Commissions',
    'allowances' => '🎁 Allowances',
    'expenses'   => '🧾 Expenses',
    'clients'    => '🏢 Clients',
    'payslips'   => '📄 Payslips',
    'reports'    => '📊 Reports',
];

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

if ($action === 'delete_role' && $id) {
    $db->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
    setFlash('success', 'Role deleted.');
    header('Location: ' . SITE_URL . '/roles.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'save_role') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($d['role_name'])));
        if ($id) {
            $db->prepare("UPDATE roles SET role_name=?, role_slug=?, description=? WHERE id=?")
               ->execute([trim($d['role_name']), $slug, trim($d['description']??''), $id]);
        } else {
            $db->prepare("INSERT INTO roles (role_name, role_slug, description) VALUES (?,?,?)")
               ->execute([trim($d['role_name']), $slug, trim($d['description']??'')]);
            $id = $db->lastInsertId();
        }
        // Save permissions
        $db->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$id]);
        foreach ($pages as $key => $label) {
            $view   = isset($d["perm_{$key}_view"])   ? 1 : 0;
            $add    = isset($d["perm_{$key}_add"])    ? 1 : 0;
            $edit   = isset($d["perm_{$key}_edit"])   ? 1 : 0;
            $delete = isset($d["perm_{$key}_delete"]) ? 1 : 0;
            if ($view || $add || $edit || $delete) {
                $db->prepare("INSERT INTO role_permissions (role_id,page_key,can_view,can_add,can_edit,can_delete) VALUES (?,?,?,?,?,?)")
                   ->execute([$id, $key, $view, $add, $edit, $delete]);
            }
        }
        setFlash('success', 'Role saved.');
        header('Location: ' . SITE_URL . '/roles.php'); exit;
    }
}

$roles = $db->query("SELECT r.*, COUNT(u.id) as user_count FROM roles r LEFT JOIN users u ON u.role_id=r.id GROUP BY r.id ORDER BY r.role_name")->fetchAll();

// Load role for editing
$editRole  = null;
$editPerms = [];
if (($action === 'edit') && $id) {
    $s = $db->prepare("SELECT * FROM roles WHERE id=?"); $s->execute([$id]); $editRole = $s->fetch();
    $s2 = $db->prepare("SELECT * FROM role_permissions WHERE role_id=?"); $s2->execute([$id]);
    foreach ($s2->fetchAll() as $p) $editPerms[$p['page_key']] = $p;
}

pageHeader('Roles & Permissions');
?>

<div class="section-header">
  <div style="font-size:13px;color:var(--text2)"><?= count($roles) ?> roles defined</div>
  <a href="?action=edit&id=0" class="btn btn-primary">+ New Role</a>
</div>

<!-- Roles List -->
<div class="card" style="padding:0;overflow:hidden;margin-bottom:20px">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Role Name</th><th>Description</th><th>Users</th><th>Pages</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($roles)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:30px">No roles yet. Create one above.</td></tr>
        <?php else: foreach ($roles as $r): ?>
          <?php
            $rPerms = $db->prepare("SELECT page_key FROM role_permissions WHERE role_id=? AND can_view=1");
            $rPerms->execute([$r['id']]);
            $pageList = array_column($rPerms->fetchAll(), 'page_key');
          ?>
          <tr>
            <td data-label="Role"><strong><?= h($r['role_name']) ?></strong></td>
            <td data-label="Description" style="color:var(--text2)"><?= h($r['description']) ?: '—' ?></td>
            <td data-label="Users"><span class="badge badge-blue"><?= $r['user_count'] ?> users</span></td>
            <td data-label="Pages" style="font-size:12px;color:var(--text2)"><?= implode(', ', array_map('ucfirst', $pageList)) ?: '—' ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit&id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
              <a href="?action=delete_role&id=<?= $r['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete role <?= h($r['role_name']) ?>?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit/Create Role Form -->
<?php if ($action === 'edit'): ?>
<div class="card">
  <div class="card-title"><?= $editRole ? 'Edit Role: '.h($editRole['role_name']) : 'Create New Role' ?></div>
  <form method="POST" action="?action=save_role<?= $id ? '&id='.$id : '' ?>">
    <div class="form-grid" style="margin-bottom:20px">
      <div class="form-group"><label>Role Name *</label><input name="role_name" required value="<?= h($editRole['role_name'] ?? '') ?>" placeholder="e.g. Accountant"></div>
      <div class="form-group"><label>Description</label><input name="description" value="<?= h($editRole['description'] ?? '') ?>" placeholder="Brief description"></div>
    </div>

    <!-- Permissions Matrix -->
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table style="min-width:520px">
        <thead>
          <tr>
            <th style="width:180px">Page</th>
            <th style="text-align:center;width:80px">👁 View</th>
            <th style="text-align:center;width:80px">➕ Add</th>
            <th style="text-align:center;width:80px">✏️ Edit</th>
            <th style="text-align:center;width:80px">🗑 Delete</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pages as $key => $label): ?>
            <?php $p = $editPerms[$key] ?? []; ?>
            <tr>
              <td style="font-weight:500"><?= $label ?></td>
              <td style="text-align:center"><input type="checkbox" name="perm_<?= $key ?>_view"   <?= !empty($p['can_view'])   ? 'checked' : '' ?> onchange="syncView('<?= $key ?>', this)"></td>
              <td style="text-align:center"><input type="checkbox" name="perm_<?= $key ?>_add"    <?= !empty($p['can_add'])    ? 'checked' : '' ?> class="dep_<?= $key ?>" onchange="syncDep('<?= $key ?>', this)"></td>
              <td style="text-align:center"><input type="checkbox" name="perm_<?= $key ?>_edit"   <?= !empty($p['can_edit'])   ? 'checked' : '' ?> class="dep_<?= $key ?>" onchange="syncDep('<?= $key ?>', this)"></td>
              <td style="text-align:center"><input type="checkbox" name="perm_<?= $key ?>_delete" <?= !empty($p['can_delete']) ? 'checked' : '' ?> class="dep_<?= $key ?>" onchange="syncDep('<?= $key ?>', this)"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:12px 16px;margin:16px 0;font-size:13px;color:var(--text2)">
      ⚠️ <strong style="color:var(--text)">All add/edit/delete actions</strong> by non-super-admin users go to a pending approval queue before being applied.
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save Role</button>
      <a href="<?= SITE_URL ?>/roles.php" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<script>
// Auto-check View when Add/Edit/Delete is checked
function syncDep(key, cb) {
    if (cb.checked) {
        const view = document.querySelector(`[name="perm_${key}_view"]`);
        if (view) view.checked = true;
    }
}
// Uncheck Add/Edit/Delete if View is unchecked
function syncView(key, cb) {
    if (!cb.checked) {
        document.querySelectorAll(`.dep_${key}`).forEach(el => el.checked = false);
    }
}
</script>
<?php endif; ?>

<?php pageFooter(); ?>
