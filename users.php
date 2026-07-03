<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// All available pages with labels
$allPages = [
    'employees'   => ['👤', 'Employees'],
    'payroll'     => ['💰', 'Payroll'],
    'freelance'   => ['🧑‍💻', 'Freelance'],
    'commissions' => ['📈', 'Commissions'],
    'allowances'  => ['🎁', 'Allowances'],
    'expenses'    => ['🧾', 'Expenses'],
    'clients'     => ['🏢', 'Clients'],
    'payslips'    => ['📄', 'Payslips'],
    'reports'     => ['📊', 'Reports'],
];

if ($action === 'delete' && $id) {
    if ($id === (int)currentUserId()) { setFlash('error', 'You cannot delete your own account.'); header('Location: ' . SITE_URL . '/users.php'); exit; }
    $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    $db->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$id]);
    setFlash('success', 'User deleted.');
    header('Location: ' . SITE_URL . '/users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d     = $_POST;
    $email = trim($d['email'] ?? '');
    $name  = trim($d['full_name'] ?? '');
    $role  = $d['role'] ?? 'staff';
    $pass  = $d['password'] ?? '';
    $pages = $d['pages'] ?? [];

    if ($action === 'add') {
        if (!$email || !$name || !$pass) { setFlash('error', 'Name, email and password are required.'); header('Location: ' . SITE_URL . '/users.php'); exit; }
        try {
            $db->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?,?,?,?)")
               ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            $newId = $db->lastInsertId();
            // Save permissions
            $db->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$newId]);
            foreach ($pages as $page) {
                $db->prepare("INSERT INTO user_permissions (user_id, page) VALUES (?,?)")->execute([$newId, $page]);
            }
            setFlash('success', "User {$name} created.");
        } catch (Exception $e) { setFlash('error', 'Email already exists.'); }
    } elseif ($action === 'edit') {
        $db->prepare("UPDATE users SET full_name=?, email=?, role=? WHERE id=?")->execute([$name, $email, $role, $id]);
        if ($pass) $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        // Update permissions
        $db->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$id]);
        foreach ($pages as $page) {
            $db->prepare("INSERT INTO user_permissions (user_id, page) VALUES (?,?)")->execute([$id, $page]);
        }
        setFlash('success', 'User updated.');
    }
    header('Location: ' . SITE_URL . '/users.php'); exit;
}

$editRow = null;
$editPerms = [];
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM users WHERE id=?"); $s->execute([$id]); $editRow = $s->fetch();
    $p = $db->prepare("SELECT page FROM user_permissions WHERE user_id=?"); $p->execute([$id]);
    $editPerms = $p->fetchAll(PDO::FETCH_COLUMN);
}

// Only show system users (not linked to employees)
$users = $db->query("SELECT u.* FROM users u ORDER BY u.role DESC, u.full_name")->fetchAll();

// Get permissions per user for display
$allPerms = [];
$permRows = $db->query("SELECT user_id, page FROM user_permissions")->fetchAll();
foreach ($permRows as $r) $allPerms[$r['user_id']][] = $r['page'];

pageHeader('System Users');
?>

<div class="section-header">
  <div style="font-size:13px;color:var(--text2)"><?= count($users) ?> users</div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add User</button>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Page Access</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td data-label="Name">
              <strong><?= h($u['full_name']) ?></strong>
              <?php if ($u['id'] == currentUserId()): ?> <span class="badge badge-blue" style="font-size:10px">You</span><?php endif; ?>
            </td>
            <td data-label="Email" style="color:var(--text2)"><?= h($u['email']) ?></td>
            <td data-label="Role">
              <?php if ($u['role'] === 'admin'): ?>
                <span class="badge badge-yellow">Admin</span>
              <?php else: ?>
                <span class="badge badge-blue">Staff</span>
              <?php endif; ?>
            </td>
            <td data-label="Access">
              <?php if ($u['role'] === 'admin'): ?>
                <span style="font-size:12px;color:var(--text2)">All pages</span>
              <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:4px">
                  <?php
                  $perms = $allPerms[$u['id']] ?? [];
                  if (empty($perms)): ?>
                    <span style="font-size:12px;color:var(--text2)">No access</span>
                  <?php else: foreach ($perms as $pg):
                    $icon = $allPages[$pg][0] ?? '📄';
                    $label = $allPages[$pg][1] ?? $pg;
                  ?>
                    <span style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:11px"><?= $icon ?> <?= h($label) ?></span>
                  <?php endforeach; endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <?php if ($u['id'] != currentUserId()): ?>
                <a href="?action=delete&id=<?= $u['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($u['full_name']) ?>?')">Del</a>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── ADD MODAL ── -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <div class="modal-title">Add System User</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add">
        <div class="form-grid cols-1">
          <div class="form-group">
            <label>Full Name *</label>
            <input name="full_name" required placeholder="e.g. Accounts Manager">
          </div>
          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" required placeholder="user@company.com">
          </div>
          <div class="form-group">
            <label>Password *</label>
            <input type="password" name="password" required minlength="6" placeholder="Min 6 characters">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role" id="addRole" onchange="togglePermissions('add')">
              <option value="admin">Admin — Full access to everything</option>
              <option value="staff" selected>Staff — Custom page access</option>
            </select>
          </div>
          <div class="form-group" id="addPermissions">
            <label>Page Access <span style="color:var(--text2);font-weight:400">(select pages this user can see)</span></label>
            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
              <?php foreach ($allPages as $key => [$icon, $label]): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--text);font-size:13px;font-weight:400">
                  <input type="checkbox" name="pages[]" value="<?= $key ?>" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
                  <?= $icon ?> <?= h($label) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="button" onclick="selectAll('add',true)" class="btn btn-ghost btn-sm">Select All</button>
              <button type="button" onclick="selectAll('add',false)" class="btn btn-ghost btn-sm">Clear All</button>
            </div>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create User</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── EDIT MODAL ── -->
<?php if ($editRow): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-header">
      <div class="modal-title">Edit User</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/users.php'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit&id=<?= $editRow['id'] ?>">
        <div class="form-grid cols-1">
          <div class="form-group">
            <label>Full Name *</label>
            <input name="full_name" required value="<?= h($editRow['full_name']) ?>">
          </div>
          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" required value="<?= h($editRow['email']) ?>">
          </div>
          <div class="form-group">
            <label>New Password <span style="color:var(--text2);font-weight:400">(leave blank to keep)</span></label>
            <input type="password" name="password" minlength="6" placeholder="Leave blank to keep current">
          </div>
          <div class="form-group">
            <label>Role</label>
            <select name="role" id="editRole" onchange="togglePermissions('edit')">
              <option value="admin" <?= $editRow['role']==='admin'?'selected':'' ?>>Admin — Full access</option>
              <option value="staff" <?= $editRow['role']==='staff'?'selected':'' ?>>Staff — Custom page access</option>
            </select>
          </div>
          <div class="form-group" id="editPermissions" style="<?= $editRow['role']==='admin'?'display:none':'' ?>">
            <label>Page Access</label>
            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
              <?php foreach ($allPages as $key => [$icon, $label]): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--text);font-size:13px;font-weight:400">
                  <input type="checkbox" name="pages[]" value="<?= $key ?>"
                    <?= in_array($key, $editPerms) ? 'checked' : '' ?>
                    style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
                  <?= $icon ?> <?= h($label) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button type="button" onclick="selectAll('edit',true)" class="btn btn-ghost btn-sm">Select All</button>
              <button type="button" onclick="selectAll('edit',false)" class="btn btn-ghost btn-sm">Clear All</button>
            </div>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/users.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function togglePermissions(prefix) {
    const role = document.getElementById(prefix + 'Role').value;
    const perms = document.getElementById(prefix + 'Permissions');
    if (perms) perms.style.display = role === 'admin' ? 'none' : '';
}
function selectAll(prefix, check) {
    const perms = document.getElementById(prefix + 'Permissions');
    if (perms) perms.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = check);
}
// Init add modal - staff is default so show permissions
togglePermissions('add');
</script>

<?php pageFooter(); ?>
