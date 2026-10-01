<?php
// config.php - Edit these values to match your cPanel database
define('DB_HOST', 'localhost');
define('DB_USER', 'matsaqyg_payrolladmin'); // e.g. john_payroll
define('DB_PASS', '(q#k@jDL^?$H=ylr');
define('DB_NAME', 'matsaqyg_payroll'); // e.g. john_payrolldb

define('SITE_NAME', 'PayrollPro');
define('SITE_URL', 'https://business.creativelements.co'); // No trailing slash

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:40px;color:#c00"><h2>Database Connection Error</h2><p>Please check your config.php settings.</p><p style="color:#999;font-size:12px">'.$e->getMessage().'</p></div>');
        }
    }
    return $pdo;
}

// Auth helpers
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit;
    }
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function isAccountsManager() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'accounts_manager';
}

// True if the current request could change data. Every mutation in this app (add/edit/
// delete/status/approve/mark_paid/etc.) is triggered by a POST, or a GET carrying an
// `action` parameter — so blocking both (beyond any explicitly whitelisted safe action,
// e.g. chat.php's own "send a message" request) is enough to make a page read-only.
function isMutatingRequest($safeActions = []) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($action !== '') {
        return !in_array($action, $safeActions, true);
    }
    return $_SERVER['REQUEST_METHOD'] !== 'GET';
}

// Admins get full access. Accounts Managers get read-only access to every page that calls
// this instead of requireAdmin() — they can open and view the page, but any action that
// would change data is blocked outright. Use on pages Accounts Manager should see; leave
// Users/Settings/Roles & Permissions on requireAdmin()/isSuperAdmin() as-is. Pass
// $safeActions for a page whose own read-only operations are themselves POST+action
// (e.g. chat.php sending a message is action=chat; only its action=execute writes data).
function requireAdminOrReadOnly($safeActions = []) {
    requireLogin();
    if (isAdmin()) return;
    if (!isAccountsManager()) {
        header('Location: ' . SITE_URL . '/dashboard.php?denied=1');
        exit;
    }
    if (isMutatingRequest($safeActions)) {
        header('Location: ' . SITE_URL . '/dashboard.php?denied=1');
        exit;
    }
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function currentEmployeeId() {
    return $_SESSION['employee_id'] ?? null;
}

// Flash messages
function setFlash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// Sanitize
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Get a setting value from DB (cached in session)
function getSetting($key, $default = '') {
    if (!isset($_SESSION['settings'])) {
        try {
            $db = getDB();
            $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $_SESSION['settings'] = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) {
            return $default;
        }
    }
    return $_SESSION['settings'][$key] ?? $default;
}

function clearSettingsCache() {
    unset($_SESSION['settings']);
}

// Central CC email — always reads from Settings
function getCCEmail() {
    return getSetting('email_cc', '');
}

// Check if current user can access a page
function canAccess($page) {
    if (!isset($_SESSION['user_id'])) return false;
    if ($_SESSION['role'] === 'admin') return true;
    if (!isset($_SESSION['permissions'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT page FROM user_permissions WHERE user_id=?");
            $stmt->execute([$_SESSION['user_id']]);
            $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $_SESSION['permissions'] = [];
        }
    }
    return in_array($page, $_SESSION['permissions']);
}

function requireAccess($page) {
    requireLogin();
    if ($_SESSION['role'] === 'admin') return; // admin always allowed
    if ($_SESSION['role'] === 'staff' && canAccess($page)) return; // staff with permission
    if (isAccountsManager() && !isMutatingRequest()) return; // accounts manager: view-only, every page
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

// ── RBAC Helpers ──────────────────────────────────────────

function isSuperAdmin() {
    return isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1;
}

function getPermissions() {
    if (!isset($_SESSION['permissions'])) {
        if (isSuperAdmin()) {
            $_SESSION['permissions'] = 'super';
            return 'super';
        }
        try {
            $db = getDB();
            $roleId = $_SESSION['role_id'] ?? 0;
            if (!$roleId) { $_SESSION['permissions'] = []; return []; }
            $stmt = $db->prepare("SELECT page_key, can_view, can_add, can_edit, can_delete FROM role_permissions WHERE role_id=?");
            $stmt->execute([$roleId]);
            $perms = [];
            foreach ($stmt->fetchAll() as $p) {
                $perms[$p['page_key']] = [
                    'view'   => (bool)$p['can_view'],
                    'add'    => (bool)$p['can_add'],
                    'edit'   => (bool)$p['can_edit'],
                    'delete' => (bool)$p['can_delete'],
                ];
            }
            $_SESSION['permissions'] = $perms;
        } catch (Exception $e) {
            $_SESSION['permissions'] = [];
        }
    }
    return $_SESSION['permissions'];
}

function canView($page) {
    if (isSuperAdmin()) return true;
    $p = getPermissions();
    return isset($p[$page]) && $p[$page]['view'];
}
function canAdd($page) {
    if (isSuperAdmin()) return true;
    $p = getPermissions();
    return isset($p[$page]) && $p[$page]['add'];
}
function canEdit($page) {
    if (isSuperAdmin()) return true;
    $p = getPermissions();
    return isset($p[$page]) && $p[$page]['edit'];
}
function canDelete($page) {
    if (isSuperAdmin()) return true;
    $p = getPermissions();
    return isset($p[$page]) && $p[$page]['delete'];
}

function requirePage($page) {
    requireLogin();
    if (!canView($page)) {
        header('Location: ' . SITE_URL . '/dashboard.php?denied=1');
        exit;
    }
}

function clearPermissionsCache() {
    unset($_SESSION['permissions']);
}

// Submit action for approval instead of executing directly
function submitForApproval($page, $action, $table, $data, $description, $recordId = null, $originalData = null) {
    $db = getDB();
    $db->prepare("INSERT INTO pending_approvals (submitted_by, submitted_name, page_key, action_type, record_id, record_table, record_data, original_data, description) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute([
           currentUserId(),
           $_SESSION['full_name'] ?? '',
           $page,
           $action,
           $recordId,
           $table,
           json_encode($data),
           $originalData ? json_encode($originalData) : null,
           $description
       ]);
    // Notify super admin via email
    $db2 = getDB();
    $admins = $db2->query("SELECT email FROM users WHERE is_super_admin=1")->fetchAll();
    $companyName = getSetting('company_name', 'PayrollPro');
    $fromEmail   = getSetting('email_from', 'payroll@creativelements.co');
    $siteUrl     = SITE_URL;
    foreach ($admins as $admin) {
        if (!$admin['email']) continue;
        $subject = "Action Pending Approval: {$description} | {$companyName}";
        $body    = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;padding:20px'>
            <h2 style='color:#3b82f6'>New Action Pending Approval</h2>
            <p><strong>Submitted by:</strong> " . htmlspecialchars($_SESSION['full_name'] ?? '') . "</p>
            <p><strong>Action:</strong> " . strtoupper($action) . " on " . strtoupper($page) . "</p>
            <p><strong>Description:</strong> " . htmlspecialchars($description) . "</p>
            <p><a href='{$siteUrl}/approvals.php' style='background:#3b82f6;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>Review Now →</a></p>
            </body></html>";
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$companyName} <{$fromEmail}>\r\nX-Mailer: PHP/".phpversion();
        @mail($admin['email'], $subject, $body, $headers, "-f{$fromEmail}");
    }
}

function formatMoney($amount) {
    $symbol = getSetting('currency_symbol', 'Rs.');
    return $symbol . ' ' . number_format((float)$amount, 2);
}

// Recalculate payroll final salary
function recalcPayroll($db, $payrollId) {
    $stmt = $db->prepare("SELECT * FROM payroll WHERE id = ?");
    $stmt->execute([$payrollId]);
    $p = $stmt->fetch();
    if ($p) {
        $final = $p['base_salary'] + $p['bonus'] + $p['total_allowances'] + $p['total_commissions'] - $p['deductions'] - $p['advance_payment'];
        $db->prepare("UPDATE payroll SET final_salary = ? WHERE id = ?")->execute([$final, $payrollId]);
    }
}
