<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

// Handle actions
$action = $_REQUEST['action'] ?? '';
$id = (int)($_REQUEST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    if ($action === 'add' || $action === 'edit') {
        // Create/update user account
        $hashedPass = password_hash($d['password'] ?: 'password123', PASSWORD_DEFAULT);

        if ($action === 'add') {
            try {
                // Insert user
                $stmt = $db->prepare("INSERT INTO users (employee_id,full_name,email,password,role) VALUES (?,?,?,?,'employee')");
                $stmt->execute([$d['employee_id'], $d['full_name'], $d['email'], $hashedPass]);
                $userId = $db->lastInsertId();
                // Insert employee
                $stmt = $db->prepare("INSERT INTO employees (user_id,employee_id,full_name,email,phone,position,department,joining_date,salary_type,monthly_salary,payment_method,bank_name,bank_account,job_type,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')");
                $stmt->execute([$userId,$d['employee_id'],$d['full_name'],$d['email'],$d['phone'],$d['position'],$d['department'],$d['joining_date'],$d['salary_type'],$d['monthly_salary'],$d['payment_method'],$d['bank_name'],$d['bank_account'],$d['job_type']]);
                setFlash('success', 'Employee added successfully.');
            } catch (Exception $e) {
                setFlash('error', 'Error: ' . $e->getMessage());
            }
        } else {
            // Edit
            $stmt = $db->prepare("UPDATE employees SET full_name=?,email=?,phone=?,position=?,department=?,joining_date=?,salary_type=?,monthly_salary=?,payment_method=?,bank_name=?,bank_account=?,job_type=?,status=? WHERE id=?");
            $stmt->execute([$d['full_name'],$d['email'],$d['phone'],$d['position'],$d['department'],$d['joining_date'],$d['salary_type'],$d['monthly_salary'],$d['payment_method'],$d['bank_name'],$d['bank_account'],$d['job_type'],$d['status'],$id]);
            // Update user email/name
            $stmt = $db->prepare("UPDATE users SET full_name=?,email=? WHERE employee_id=?");
            $stmt->execute([$d['full_name'],$d['email'],$d['employee_id']]);
            if ($d['password']) {
                $stmt = $db->prepare("UPDATE users SET password=? WHERE employee_id=?");
                $stmt->execute([password_hash($d['password'], PASSWORD_DEFAULT), $d['employee_id']]);
            }
            setFlash('success', 'Employee updated.');
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("SELECT user_id, employee_id FROM employees WHERE id=?");
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
        if ($emp) {
            $db->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$emp['user_id']]);
        }
        setFlash('success', 'Employee deleted.');
    }
    header('Location: ' . SITE_URL . '/employees.php'); exit;
}

// Fetch employee for edit
$editEmp = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM employees WHERE id=?");
    $stmt->execute([$id]);
    $editEmp = $stmt->fetch();
}

$search = trim($_GET['q'] ?? '');
$employees = $db->query("SELECT * FROM employees " . ($search ? "WHERE full_name LIKE '%".addslashes($search)."%' OR employee_id LIKE '%".addslashes($search)."%' OR department LIKE '%".addslashes($search)."%'" : "") . " ORDER BY full_name")->fetchAll();

pageHeader('Employees');
?>

<div class="section-header">
  <div style="display:flex;gap:10px;align-items:center">
    <form method="GET" style="display:flex;gap:8px">
      <input type="text" name="q" placeholder="Search employees..." value="<?= h($search) ?>" style="width:220px">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
    </form>
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Employee</button>
</div>

<div class="card">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>ID</th><th>Name</th><th>Position</th><th>Department</th><th>Job Type</th><th>Salary</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($employees)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:30px">No employees found.</td></tr>
        <?php else: foreach ($employees as $e): ?>
          <tr>
            <td data-label="ID" style="color:var(--text2);font-size:12px"><?= h($e['employee_id']) ?></td>
            <td data-label="Name">
              <strong><?= h($e['full_name']) ?></strong><br>
              <span style="color:var(--text2);font-size:12px"><?= h($e['email']) ?></span>
            </td>
            <td data-label="Position"><?= h($e['position']) ?></td>
            <td data-label="Department"><?= h($e['department']) ?></td>
            <td data-label="Job Type">
              <?php if (($e['job_type'] ?? '') === 'remote'): ?>
                <span class="badge badge-blue">🌐 Remote</span>
              <?php elseif (($e['job_type'] ?? '') === 'onsite'): ?>
                <span class="badge badge-green">🏢 On-site</span>
              <?php else: ?>
                <span style="color:var(--text2);font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td data-label="Salary"><strong><?= formatMoney($e['monthly_salary']) ?></strong></td>
            <td data-label="Status">
              <?php if ($e['status'] === 'active'): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-red">Inactive</span>
              <?php endif; ?>
            </td>
            <td data-label="">
              <div class="mob-actions">
              <a href="?action=edit&id=<?= $e['id'] ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $e['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($e['full_name']) ?>?')">Del</a>
              </div>
            </td>
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
      <div class="modal-title">Add Employee</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add">
        <div class="form-grid">
          <div class="form-group"><label>Employee ID *</label><input name="employee_id" required placeholder="EMP001"></div>
          <div class="form-group"><label>Full Name *</label><input name="full_name" required></div>
          <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
          <div class="form-group"><label>Phone</label><input name="phone" type="tel"></div>
          <div class="form-group"><label>Position</label><input name="position"></div>
          <div class="form-group"><label>Department</label><input name="department"></div>
          <div class="form-group"><label>Joining Date</label><input type="date" name="joining_date"></div>
          <div class="form-group"><label>Job Type</label>
            <select name="job_type">
              <option value="remote">🌐 Remote Job</option>
              <option value="onsite">🏢 On-site Job</option>
            </select>
          </div>
          <div class="form-group"><label>Salary Type</label>
            <select name="salary_type"><option value="monthly">Monthly</option><option value="hourly">Hourly</option></select>
          </div>
          <div class="form-group"><label>Monthly Salary ($)</label><input type="number" name="monthly_salary" step="0.01" value="0"></div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cash">Cash</option>
              <option value="online">Online Payment</option>
            </select>
          </div>
          <div class="form-group"><label>Bank Name</label><input name="bank_name"></div>
          <div class="form-group"><label>Bank Account</label><input name="bank_account"></div>
          <div class="form-group"><label>Login Password</label><input type="password" name="password" placeholder="Default: password123"></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Employee</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($editEmp): ?>
<!-- Edit Modal (auto-open) -->
<div class="modal-overlay open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Employee</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/employees.php'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit&id=<?= $editEmp['id'] ?>">
        <input type="hidden" name="employee_id" value="<?= h($editEmp['employee_id']) ?>">
        <div class="form-grid">
          <div class="form-group"><label>Employee ID</label><input value="<?= h($editEmp['employee_id']) ?>" disabled></div>
          <div class="form-group"><label>Full Name *</label><input name="full_name" required value="<?= h($editEmp['full_name']) ?>"></div>
          <div class="form-group"><label>Email *</label><input type="email" name="email" required value="<?= h($editEmp['email']) ?>"></div>
          <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($editEmp['phone']) ?>"></div>
          <div class="form-group"><label>Position</label><input name="position" value="<?= h($editEmp['position']) ?>"></div>
          <div class="form-group"><label>Department</label><input name="department" value="<?= h($editEmp['department']) ?>"></div>
          <div class="form-group"><label>Joining Date</label><input type="date" name="joining_date" value="<?= h($editEmp['joining_date']) ?>"></div>
          <div class="form-group"><label>Job Type</label>
            <select name="job_type">
              <option value="remote" <?= ($editEmp['job_type'] ?? '')==='remote'?'selected':'' ?>>🌐 Remote Job</option>
              <option value="onsite" <?= ($editEmp['job_type'] ?? '')==='onsite'?'selected':'' ?>>🏢 On-site Job</option>
            </select>
          </div>
          <div class="form-group"><label>Salary Type</label>
            <select name="salary_type">
              <option value="monthly" <?= $editEmp['salary_type']==='monthly'?'selected':'' ?>>Monthly</option>
              <option value="hourly" <?= $editEmp['salary_type']==='hourly'?'selected':'' ?>>Hourly</option>
            </select>
          </div>
          <div class="form-group"><label>Monthly Salary ($)</label><input type="number" name="monthly_salary" step="0.01" value="<?= $editEmp['monthly_salary'] ?>"></div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <option value="bank_transfer" <?= $editEmp['payment_method']==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
              <option value="cash" <?= $editEmp['payment_method']==='cash'?'selected':'' ?>>Cash</option>
              <option value="online" <?= $editEmp['payment_method']==='online'?'selected':'' ?>>Online Payment</option>
            </select>
          </div>
          <div class="form-group"><label>Bank Name</label><input name="bank_name" value="<?= h($editEmp['bank_name']) ?>"></div>
          <div class="form-group"><label>Bank Account</label><input name="bank_account" value="<?= h($editEmp['bank_account']) ?>"></div>
          <div class="form-group"><label>Status</label>
            <select name="status">
              <option value="active" <?= $editEmp['status']==='active'?'selected':'' ?>>Active</option>
              <option value="inactive" <?= $editEmp['status']==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
          <div class="form-group"><label>New Password (leave blank to keep)</label><input type="password" name="password"></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/employees.php" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php pageFooter(); ?>
