<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAccess('expenses');
$db = getDB();
$isAdminUser = isAdmin();

// ── Actions ────────────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

if ($action === 'delete' && $id) {
    if (!isAdmin()) {
        $db->prepare("INSERT INTO expense_change_requests (expense_id, change_type, payload, requested_by, status) VALUES (?,?,?,?,'pending')")
           ->execute([$id, 'delete', json_encode(['expense_id'=>$id]), $_SESSION['full_name']]);
        setFlash('success', '✅ Delete request submitted for Admin approval.');
        header('Location: ' . SITE_URL . '/expenses.php'); exit;
    }
    $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
    setFlash('success', 'Expense deleted.');
    header('Location: ' . SITE_URL . '/expenses.php'); exit;
}

if ($action === 'approve' && $id) {
    $db->prepare("UPDATE expenses SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([$_SESSION['full_name'], $id]);
    setFlash('success', 'Expense approved.');
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($_GET['month'] ?? date('Y-m')) . '&tab=expenses'); exit;
}
if ($action === 'reject' && $id) {
    $db->prepare("UPDATE expenses SET approval_status='rejected', approved_by=? WHERE id=?")->execute([$_SESSION['full_name'], $id]);
    setFlash('success', 'Expense rejected.');
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($_GET['month'] ?? date('Y-m')) . '&tab=expenses'); exit;
}
if ($action === 'reset_approval' && $id) {
    $db->prepare("UPDATE expenses SET approval_status='pending_approval', approved_by=NULL, approved_at=NULL WHERE id=?")->execute([$id]);
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($_GET['month'] ?? date('Y-m')) . '&tab=expenses'); exit;
}
if ($action === 'status' && $id) {
    $newStatus = $_GET['s'] ?? 'pending';
    $db->prepare("UPDATE expenses SET status=? WHERE id=?")->execute([$newStatus, $id]);
    setFlash('success', 'Status updated.');
    header('Location: ' . SITE_URL . '/expenses.php?' . http_build_query(['month'=>$_GET['month']??'','client'=>$_GET['client']??'','cat'=>$_GET['cat']??'','status'=>$_GET['status']??''])); exit;
}

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;
    $cost        = (float)($d['cost_amount'] ?? 0);
    $markup      = (float)($d['markup_percentage'] ?? 0);
    $addFee      = (float)($d['additional_fee'] ?? 0);
    $currency    = $d['currency'] ?? 'LKR';
    $billingType = $d['billing_type'] ?? 'internal';
    $clientName  = $billingType === 'internal' ? null : trim($d['client_name'] ?? '');
    $rateKey     = 'rate_' . strtolower($currency) . '_lkr';
    $exRate      = $currency === 'LKR' ? 1.0 : (float)(getSetting($rateKey, '1'));
    $costLKR     = $cost * $exRate;
    $total       = round($costLKR + ($costLKR * $markup / 100) + $addFee, 2);

    // Receipt upload (PDF only) — keep the existing one if editing and no new file is given
    $receiptPath = null;
    if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $dir = 'uploads/receipts/';
            if (!is_dir(__DIR__.'/'.$dir)) mkdir(__DIR__.'/'.$dir, 0755, true);
            $fname = 'receipt_' . time() . '_' . mt_rand(1000, 9999) . '.pdf';
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], __DIR__.'/'.$dir.$fname)) {
                $receiptPath = $dir . $fname;
            }
        }
    }
    if (!$receiptPath && $action === 'edit' && $id) {
        $er = $db->prepare("SELECT receipt_path FROM expenses WHERE id=?");
        $er->execute([$id]);
        $receiptPath = $er->fetchColumn() ?: null;
    }

    // Staff users — queue changes for admin approval
    if (!isAdmin()) {
        $payload = json_encode([
            'expense_date' => $d['expense_date'], 'billing_month' => $d['billing_month'],
            'client_name' => $clientName, 'billing_type' => $billingType,
            'expense_category' => $d['expense_category'], 'project_name' => trim($d['project_name']??''),
            'description' => trim($d['description']??''), 'cost_amount' => $cost,
            'currency' => $currency, 'exchange_rate' => $exRate,
            'markup_percentage' => $markup, 'additional_fee' => $addFee,
            'total_billable' => $total, 'status' => $d['status']??'pending',
            'notes' => trim($d['notes']??''), 'receipt_path' => $receiptPath,
        ]);
        $db->prepare("INSERT INTO expense_change_requests (expense_id, change_type, payload, requested_by, status) VALUES (?,?,?,?,'pending')")
           ->execute([$action === 'edit' ? $id : null, $action, $payload, $_SESSION['full_name']]);
        setFlash('success', '✅ Your request has been submitted and is awaiting Admin approval.');
        header('Location: ' . SITE_URL . '/expenses.php?month=' . ($d['billing_month'] ?? date('Y-m'))); exit;
    }

    if ($action === 'add') {
        $db->prepare("INSERT INTO expenses (expense_date,billing_month,client_name,billing_type,expense_category,project_name,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,status,notes,receipt_path,created_by,approval_status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,NOW())")
           ->execute([$d['expense_date'],$d['billing_month'],$clientName,$billingType,$d['expense_category'],trim($d['project_name']??''),trim($d['description']??''),$cost,$currency,$exRate,$markup,$addFee,$total,$d['status']??'pending',trim($d['notes']??''),$receiptPath,$_SESSION['full_name'],$_SESSION['full_name']]);
        setFlash('success', 'Expense added.');
    } elseif ($action === 'edit') {
        $stmt = $db->prepare("SELECT exchange_rate, currency FROM expenses WHERE id=?");
        $stmt->execute([$id]);
        $orig = $stmt->fetch();
        if ($orig && $orig['currency'] === $currency) {
            $exRate  = (float)$orig['exchange_rate'] ?: $exRate;
            $costLKR = $cost * $exRate;
            $total   = round($costLKR + ($costLKR * $markup / 100) + $addFee, 2);
        }
        $db->prepare("UPDATE expenses SET expense_date=?,billing_month=?,client_name=?,billing_type=?,expense_category=?,project_name=?,description=?,cost_amount=?,currency=?,exchange_rate=?,markup_percentage=?,additional_fee=?,total_billable=?,status=?,notes=?,receipt_path=?,approval_status='approved',approved_by=?,approved_at=NOW() WHERE id=?")
           ->execute([$d['expense_date'],$d['billing_month'],$clientName,$billingType,$d['expense_category'],trim($d['project_name']??''),trim($d['description']??''),$cost,$currency,$exRate,$markup,$addFee,$total,$d['status']??'pending',trim($d['notes']??''),$receiptPath,$_SESSION['full_name'],$id]);
        setFlash('success', 'Expense updated.');
    }
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($d['billing_month'] ?? date('Y-m'))); exit;
}

// ── Filters ────────────────────────────────────────────────
$filterMonth    = $_GET['month']    ?? date('Y-m');
$filterClient   = trim($_GET['client'] ?? '');
$filterCat      = trim($_GET['cat'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');
$filterProject  = trim($_GET['project'] ?? '');
$filterApproval = trim($_GET['approval'] ?? '');
$tab            = $_GET['tab'] ?? 'expenses';

// Build query
$where  = ["billing_month = ?"];
$params = [$filterMonth];
if ($filterClient)   { $where[] = "client_name LIKE ?";   $params[] = "%$filterClient%"; }
if ($filterCat)      { $where[] = "expense_category = ?"; $params[] = $filterCat; }
if ($filterStatus)   { $where[] = "status = ?";           $params[] = $filterStatus; }
if ($filterProject)  { $where[] = "project_name LIKE ?";  $params[] = "%$filterProject%"; }
if ($filterApproval) { $where[] = "approval_status = ?";  $params[] = $filterApproval; }
$whereSQL = implode(' AND ', $where);

$expenses = $db->prepare("SELECT * FROM expenses WHERE $whereSQL ORDER BY expense_date DESC");
$expenses->execute($params);
$expenses = $expenses->fetchAll();

// Dashboard stats — all in LKR using exchange_rate
$stats = $db->prepare("SELECT
    COALESCE(SUM(cost_amount * exchange_rate),0) as total_cost,
    COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable ELSE 0 END),0) as total_billable,
    COALESCE(SUM(CASE WHEN billing_type = 'internal' THEN cost_amount * exchange_rate ELSE 0 END),0) as internal_cost,
    COALESCE(SUM(CASE WHEN billing_type = 'client_paid' THEN cost_amount * exchange_rate ELSE 0 END),0) as client_paid_cost,
    COALESCE(SUM(CASE WHEN status IN ('pending','invoiced') AND billing_type != 'client_paid' THEN total_billable ELSE 0 END),0) as outstanding,
    COALESCE(SUM(CASE WHEN status = 'paid' AND billing_type != 'client_paid' THEN total_billable ELSE 0 END),0) as paid_amt,
    COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable - (cost_amount * exchange_rate) ELSE 0 END),0) as markup_earned,
    COALESCE(SUM(CASE WHEN approval_status = 'pending_approval' THEN 1 ELSE 0 END),0) as pending_approvals
FROM expenses WHERE billing_month = ?");
$stats->execute([$filterMonth]);
$stats = $stats->fetch();

// Edit record
$editRow = null;
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM expenses WHERE id=?");
    $s->execute([$id]);
    $editRow = $s->fetch();
}

// Load registered clients
$clients = $db->query("SELECT id, company_name FROM clients WHERE status='active' ORDER BY company_name")->fetchAll();

$categories = ['Facebook Ads','Instagram Ads','Google Ads','TikTok Ads','ChatGPT','Canva','Adobe Creative Cloud','Google Workspace','Hosting','Domain Renewals','Freelancer Costs','Printing','Software Subscription','Other'];
$currencies  = ['LKR','USD','EUR','GBP','AUD','SGD'];
$rates = [
    'LKR' => 1,
    'USD' => (float)(getSetting('rate_usd_lkr', '325.00')),
    'EUR' => (float)(getSetting('rate_eur_lkr', '355.00')),
    'GBP' => (float)(getSetting('rate_gbp_lkr', '415.00')),
    'AUD' => (float)(getSetting('rate_aud_lkr', '215.00')),
    'SGD' => (float)(getSetting('rate_sgd_lkr', '242.00')),
];
$ratesJson = json_encode($rates);
$ratesUpdated = getSetting('rates_updated', 'Not set');
$statusColors = ['pending'=>'yellow','invoiced'=>'blue','paid'=>'green','cancelled'=>'red'];

// ── Admin: handle change request approvals (must be before pageHeader) ──
if (isAdmin()) {
    $reqAction = $_GET['req_action'] ?? '';
    $reqId     = (int)($_GET['req_id'] ?? 0);

    if ($reqAction === 'approve_req' && $reqId) {
        $req = $db->prepare("SELECT * FROM expense_change_requests WHERE id=?");
        $req->execute([$reqId]);
        $req = $req->fetch();
        if ($req) {
            $p = json_decode($req['payload'], true);
            if ($req['change_type'] === 'add') {
                $db->prepare("INSERT INTO expenses (expense_date,billing_month,client_name,billing_type,expense_category,project_name,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,status,notes,receipt_path,created_by,approval_status,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,NOW())")
                   ->execute([$p['expense_date'],$p['billing_month'],$p['client_name'],$p['billing_type'],$p['expense_category'],$p['project_name'],$p['description'],$p['cost_amount'],$p['currency'],$p['exchange_rate'],$p['markup_percentage'],$p['additional_fee'],$p['total_billable'],$p['status'],$p['notes'],$p['receipt_path']??null,$req['requested_by'],$_SESSION['full_name']]);
            } elseif ($req['change_type'] === 'edit') {
                $db->prepare("UPDATE expenses SET expense_date=?,billing_month=?,client_name=?,billing_type=?,expense_category=?,project_name=?,description=?,cost_amount=?,currency=?,exchange_rate=?,markup_percentage=?,additional_fee=?,total_billable=?,status=?,notes=?,receipt_path=?,approval_status='approved',approved_by=?,approved_at=NOW() WHERE id=?")
                   ->execute([$p['expense_date'],$p['billing_month'],$p['client_name'],$p['billing_type'],$p['expense_category'],$p['project_name'],$p['description'],$p['cost_amount'],$p['currency'],$p['exchange_rate'],$p['markup_percentage'],$p['additional_fee'],$p['total_billable'],$p['status'],$p['notes'],$p['receipt_path']??null,$_SESSION['full_name'],$req['expense_id']]);
            } elseif ($req['change_type'] === 'delete') {
                $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$req['expense_id']]);
            }
            $db->prepare("UPDATE expense_change_requests SET status='approved', reviewed_at=NOW() WHERE id=?")->execute([$reqId]);
            setFlash('success', '✅ Change approved and applied successfully.');
        }
        header('Location: ' . SITE_URL . '/expenses.php?tab=expenses&month=' . $filterMonth); exit;
    }

    if ($reqAction === 'reject_req' && $reqId) {
        $db->prepare("UPDATE expense_change_requests SET status='rejected', reviewed_at=NOW() WHERE id=?")->execute([$reqId]);
        setFlash('success', 'Change request rejected.');
        header('Location: ' . SITE_URL . '/expenses.php?tab=expenses&month=' . $filterMonth); exit;
    }
}

pageHeader('Expenses & Client Rebilling');

// ── Admin: show pending requests banner ──
if (isAdmin()) {
    $pendingReqs = $db->query("SELECT * FROM expense_change_requests WHERE status='pending' ORDER BY created_at DESC")->fetchAll();
    if (!empty($pendingReqs)):
?>
<div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.3);border-radius:10px;margin-bottom:20px;overflow:hidden">
  <div style="padding:12px 18px;background:rgba(245,166,35,.12);display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(245,166,35,.2)">
    <span style="font-size:16px">⏳</span>
    <span style="font-weight:700;color:var(--yellow)">PENDING CHANGE REQUESTS</span>
    <span style="background:var(--red);color:#fff;font-size:11px;font-weight:700;border-radius:20px;padding:2px 8px"><?= count($pendingReqs) ?></span>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Requested By</th><th>Action</th><th>Details</th><th>Time</th><th>Decision</th></tr></thead>
      <tbody>
        <?php foreach ($pendingReqs as $r):
          $p = json_decode($r['payload'], true);
          $typeLabel = ['add'=>'➕ Add','edit'=>'✏️ Edit','delete'=>'🗑️ Delete'][$r['change_type']] ?? $r['change_type'];
        ?>
        <tr>
          <td data-label="By"><strong><?= h($r['requested_by']) ?></strong></td>
          <td data-label="Action"><span class="badge badge-<?= $r['change_type']==='delete'?'red':($r['change_type']==='edit'?'blue':'green') ?>"><?= $typeLabel ?></span></td>
          <td data-label="Details" style="font-size:12px;color:var(--text2)">
            <?php if ($r['change_type'] !== 'delete'): ?>
              <?= h($p['expense_category']??'') ?> — <?= h($p['client_name']??'Internal') ?><br>
              Amount: <strong style="color:var(--text)"><?= isset($p['total_billable']) ? formatMoney($p['total_billable']) : '—' ?></strong>
              <?php if (!empty($p['receipt_path'])): ?>
                <br><a href="<?= SITE_URL ?>/<?= h($p['receipt_path']) ?>" target="_blank" style="color:var(--accent)">📄 View Receipt</a>
              <?php endif; ?>
            <?php else: ?>
              Delete expense ID #<?= $r['expense_id'] ?>
            <?php endif; ?>
          </td>
          <td data-label="Time" style="font-size:12px;color:var(--text2)"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
          <td data-label=""><div class="mob-actions">
            <a href="?req_action=approve_req&req_id=<?= $r['id'] ?>&tab=expenses&month=<?= $filterMonth ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this change?')">✅ Approve</a>
            <a href="?req_action=reject_req&req_id=<?= $r['id'] ?>&tab=expenses&month=<?= $filterMonth ?>" class="btn btn-danger btn-sm" onclick="return confirm('Reject this request?')">❌ Reject</a>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; } ?>

<!-- ── Stats ── -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Total Expenses</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['total_cost']) ?></div><div class="stat-sub"><?= date('M Y', strtotime($filterMonth.'-01')) ?></div></div>
  <div class="stat-card blue"><div class="stat-label">Client Billable</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['total_billable']) ?></div><div class="stat-sub">Incl. markup</div></div>
  <div class="stat-card"><div class="stat-label">Internal Costs</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['internal_cost']) ?></div><div class="stat-sub">Not billable</div></div>
  <div class="stat-card" style="border-color:rgba(168,85,247,.3)">
    <div class="stat-label" style="color:#a855f7">💳 Client-Paid</div>
    <div class="stat-value" style="font-size:18px"><?= formatMoney($stats['client_paid_cost']) ?></div>
    <div class="stat-sub">Excluded from company costs</div>
  </div>
  <div class="stat-card red"><div class="stat-label">Outstanding</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['outstanding']) ?></div><div class="stat-sub">Pending + Invoiced</div></div>
  <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['paid_amt']) ?></div><div class="stat-sub">Collected</div></div>
  <div class="stat-card yellow"><div class="stat-label">Markup Earned</div><div class="stat-value" style="font-size:18px"><?= formatMoney($stats['markup_earned']) ?></div><div class="stat-sub">Profit from rebilling</div></div>
  <?php if ($stats['pending_approvals'] > 0): ?>
  <div class="stat-card red" style="cursor:pointer" onclick="window.location='?tab=expenses&month=<?= $filterMonth ?>&approval=pending_approval'">
    <div class="stat-label">⚠️ Needs Approval</div>
    <div class="stat-value"><?= $stats['pending_approvals'] ?></div>
    <div class="stat-sub">Click to filter</div>
  </div>
  <?php endif; ?>
</div>

<!-- ── Tabs ── -->
<?php
$myPendingCount = 0;
if (!isAdmin()) {
    $mpc = $db->prepare("SELECT COUNT(*) FROM expense_change_requests WHERE requested_by=? AND status='pending'");
    $mpc->execute([$_SESSION['full_name']]);
    $myPendingCount = (int)$mpc->fetchColumn();
}
?>
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border)">
  <a href="?tab=expenses&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='expenses'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">💰 Expenses</a>
  <?php if (!isAdmin()): ?>
  <a href="?tab=my_requests&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;position:relative;<?= $tab==='my_requests'?'background:var(--yellow);color:#000':'background:var(--bg3);color:var(--text2)' ?>">
    ⏳ My Requests
    <?php if ($myPendingCount > 0): ?>
      <span style="position:absolute;top:-6px;right:-6px;background:var(--red);color:#fff;font-size:10px;font-weight:700;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center"><?= $myPendingCount ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if (isAdmin()): ?>
  <a href="?tab=report&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='report'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">📊 Report</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'expenses'): ?>

<!-- ── Filters ── -->
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:16px">
  <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end">
    <input type="hidden" name="tab" value="expenses">
    <div class="form-group" style="margin:0">
      <label>Month</label>
      <input type="month" name="month" value="<?= h($filterMonth) ?>">
    </div>
    <div class="form-group" style="margin:0">
      <label>Client</label>
      <select name="client">
        <option value="">All Clients</option>
        <?php foreach ($clients as $cl): ?>
          <option value="<?= h($cl['company_name']) ?>" <?= $filterClient===$cl['company_name']?'selected':'' ?>><?= h($cl['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0">
      <label>Category</label>
      <select name="cat">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= h($cat) ?>" <?= $filterCat===$cat?'selected':'' ?>><?= h($cat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin:0">
      <label>Project / Work</label>
      <input type="text" name="project" value="<?= h($_GET['project'] ?? '') ?>" placeholder="Filter by project...">
    </div>
    <div class="form-group" style="margin:0">
      <label>Status</label>
      <select name="status">
        <option value="">All Status</option>
        <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending</option>
        <option value="invoiced" <?= $filterStatus==='invoiced'?'selected':'' ?>>Invoiced</option>
        <option value="paid" <?= $filterStatus==='paid'?'selected':'' ?>>Paid</option>
        <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled</option>
      </select>
    </div>
    <div class="form-group" style="margin:0">
      <label>Approval</label>
      <select name="approval">
        <option value="">All</option>
        <option value="pending_approval" <?= $filterApproval==='pending_approval'?'selected':'' ?>>⏳ Pending</option>
        <option value="approved"         <?= $filterApproval==='approved'        ?'selected':'' ?>>✅ Approved</option>
        <option value="rejected"         <?= $filterApproval==='rejected'        ?'selected':'' ?>>❌ Rejected</option>
      </select>
    </div>
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button type="submit" class="btn btn-primary btn-sm" style="flex:1">Filter</button>
      <a href="?tab=expenses&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Clear</a>
    </div>
  </form>
</div>

<div class="section-header">
  <div style="font-size:13px;color:var(--text2)"><?= count($expenses) ?> records</div>
  <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Expense</button>
</div>

<!-- ── Expenses Table ── -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Category</th><th>Client</th>
          <th>Original Cost</th><th>LKR Amount</th><th>Markup</th><th>Total Billable</th><th>Approval</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($expenses)): ?>
          <tr><td colspan="10" style="text-align:center;color:var(--text2);padding:36px">No expenses for <?= date('F Y', strtotime($filterMonth.'-01')) ?>.</td></tr>
        <?php else: foreach ($expenses as $e): ?>
          <tr>
            <td data-label="Date" style="white-space:nowrap"><?= date('d M Y', strtotime($e['expense_date'])) ?></td>
            <td data-label="Category">
              <span style="font-weight:600"><?= h($e['expense_category']) ?></span>
              <?php if (!empty($e['project_name'])): ?><br><span style="font-size:12px;color:var(--accent);font-weight:500"><?= h($e['project_name']) ?></span><?php endif; ?>
              <?php if ($e['description']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h(mb_strimwidth($e['description'],0,40,'…')) ?></span><?php endif; ?>
            </td>
            <td data-label="Client">
              <?php if ($e['billing_type'] === 'internal'): ?>
                <span class="badge badge-blue">Internal</span>
              <?php elseif ($e['billing_type'] === 'shared'): ?>
                <span class="badge badge-yellow">Shared</span>
                <?php if ($e['client_name']): ?><br><span style="font-size:11px"><?= h($e['client_name']) ?></span><?php endif; ?>
              <?php elseif ($e['billing_type'] === 'client_paid'): ?>
                <span class="badge" style="background:rgba(168,85,247,.15);color:#a855f7">💳 Client-Paid</span>
                <?php if ($e['client_name']): ?><br><span style="font-size:11px"><?= h($e['client_name']) ?></span><?php endif; ?>
              <?php else: ?>
                <span style="font-size:13px"><?= h($e['client_name']) ?: '—' ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Original Cost">
              <strong><?= $e['currency'] !== 'LKR' ? h($e['currency']).' '.number_format($e['cost_amount'],2) : formatMoney($e['cost_amount']) ?></strong>
            </td>
            <td data-label="LKR Amount">
              <?php if ($e['currency'] !== 'LKR'): ?>
                <?php $storedRate = (float)($e['exchange_rate'] ?? 1); ?>
                <span style="color:var(--text2)"><?= formatMoney($e['cost_amount'] * $storedRate) ?></span>
                <br><span style="font-size:10px;color:var(--text2)">@ <?= number_format($storedRate,2) ?> (locked)</span>
              <?php else: ?>
                <span style="color:var(--text2)">—</span>
              <?php endif; ?>
            </td>
            <td data-label="Markup" style="color:var(--text2)"><?= $e['markup_percentage'] > 0 ? h($e['markup_percentage']).'%' : '—' ?></td>
            <td data-label="Billable"><strong style="color:var(--green)"><?= formatMoney($e['total_billable']) ?></strong></td>
            <td data-label="Approval">
              <?php $appr = $e['approval_status'] ?? 'pending_approval'; ?>
              <?php if ($appr === 'approved'): ?>
                <span class="badge badge-green">✅ Approved</span>
                <?php if (!empty($e['approved_by'])): ?><br><span style="font-size:10px;color:var(--text2)"><?= h($e['approved_by']) ?></span><?php endif; ?>
              <?php elseif ($appr === 'rejected'): ?>
                <span class="badge badge-red">❌ Rejected</span>
                <br><a href="?action=reset_approval&id=<?= $e['id'] ?>&month=<?= $filterMonth ?>" style="font-size:10px;color:var(--accent)">Reset</a>
              <?php else: ?>
                <span class="badge badge-yellow">⏳ Pending</span>
              <?php endif; ?>
            </td>
            <td data-label="Status">
              <select onchange="updateStatus(<?= $e['id'] ?>, this.value, '<?= $filterMonth ?>')"
                style="background:transparent;border:none;font-size:12px;font-weight:600;cursor:pointer;padding:3px 6px;border-radius:12px;
                color:<?= ['pending'=>'var(--yellow)','invoiced'=>'var(--accent)','paid'=>'var(--green)','cancelled'=>'var(--red)'][$e['status']] ?>">
                <option value="pending"   <?= $e['status']==='pending'  ?'selected':'' ?>>Pending</option>
                <option value="invoiced"  <?= $e['status']==='invoiced' ?'selected':'' ?>>Invoiced</option>
                <option value="paid"      <?= $e['status']==='paid'     ?'selected':'' ?>>Paid</option>
                <option value="cancelled" <?= $e['status']==='cancelled'?'selected':'' ?>>Cancelled</option>
              </select>
            </td>
            <td data-label=""><div class="mob-actions">
              <?php if (isAdmin() && !empty($e['receipt_path'])): ?>
                <a href="<?= SITE_URL ?>/<?= h($e['receipt_path']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View receipt">📄 Receipt</a>
              <?php endif; ?>
              <a href="?action=edit&id=<?= $e['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $e['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete this expense?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'my_requests' && !isAdmin()): ?>

<!-- ── MY REQUESTS TAB ── -->
<?php
$myRequests = $db->prepare("SELECT * FROM expense_change_requests WHERE requested_by=? ORDER BY created_at DESC");
$myRequests->execute([$_SESSION['full_name']]);
$myRequests = $myRequests->fetchAll();
$typeLabels  = ['add'=>'➕ Add Expense','edit'=>'✏️ Edit Expense','delete'=>'🗑️ Delete Expense'];
$statusStyle = ['pending'=>['yellow','⏳ Pending Review'],'approved'=>['green','✅ Approved'],'rejected'=>['red','❌ Rejected']];
?>

<div class="card" style="margin-bottom:16px;background:rgba(245,166,35,.05);border-color:rgba(245,166,35,.2)">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
    <span style="font-size:16px">💡</span>
    <span style="font-size:13px;color:var(--text2)">Changes you submit go to the Admin for approval before being applied. You can track the status of all your requests here.</span>
  </div>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Type</th><th>Details</th><th>Submitted</th><th>Status</th><th>Reviewed</th></tr></thead>
      <tbody>
        <?php if (empty($myRequests)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:36px">
            <div style="font-size:32px;margin-bottom:10px">📋</div>
            No requests submitted yet.
          </td></tr>
        <?php else: foreach ($myRequests as $r):
          $p = json_decode($r['payload'], true);
          [$badgeColor, $statusLabel] = $statusStyle[$r['status']] ?? ['blue', $r['status']];
        ?>
          <tr>
            <td data-label="Type">
              <span class="badge badge-<?= $r['change_type']==='delete'?'red':($r['change_type']==='edit'?'blue':'green') ?>">
                <?= $typeLabels[$r['change_type']] ?? $r['change_type'] ?>
              </span>
            </td>
            <td data-label="Details" style="font-size:13px">
              <?php if ($r['change_type'] !== 'delete'): ?>
                <strong><?= h($p['expense_category'] ?? '—') ?></strong><br>
                <span style="color:var(--text2);font-size:12px">
                  <?= h($p['client_name'] ?? 'Internal') ?>
                  <?php if (!empty($p['total_billable'])): ?> · <?= formatMoney($p['total_billable']) ?><?php endif; ?>
                  <?php if (!empty($p['expense_date'])): ?> · <?= date('d M Y', strtotime($p['expense_date'])) ?><?php endif; ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text2)">Delete expense record #<?= $r['expense_id'] ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Submitted" style="font-size:12px;color:var(--text2)">
              <?= date('d M Y', strtotime($r['created_at'])) ?><br>
              <?= date('H:i', strtotime($r['created_at'])) ?>
            </td>
            <td data-label="Status">
              <span class="badge badge-<?= $badgeColor ?>"><?= $statusLabel ?></span>
            </td>
            <td data-label="Reviewed" style="font-size:12px;color:var(--text2)">
              <?= $r['reviewed_at'] ? date('d M Y H:i', strtotime($r['reviewed_at'])) : '—' ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: // REPORT TAB ?>

<!-- ── Report ── -->
<?php $reportClient = trim($_GET['rclient'] ?? ''); ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="tab" value="report">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <select name="rclient" style="width:200px">
      <option value="">All Clients</option>
      <?php foreach ($clients as $cl): ?>
        <option value="<?= h($cl['company_name']) ?>" <?= $reportClient===$cl['company_name']?'selected':'' ?>><?= h($cl['company_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-ghost btn-sm">Update</button>
  </form>
  <?php if ($reportClient): ?><a href="<?= SITE_URL ?>/expense_report_print.php?month=<?= $filterMonth ?>&rclient=<?= urlencode($reportClient) ?>" target="_blank" class="btn btn-primary no-print">🖨 Print / PDF</a><?php endif; ?>
</div>

<?php
// Build report WHERE clause
$rWhere  = "billing_month=?";
$rParams = [$filterMonth];
if ($reportClient) { $rWhere .= " AND client_name=?"; $rParams[] = $reportClient; }

// Report data — all amounts in LKR
$byCategory = $db->prepare("SELECT expense_category, COUNT(*) as cnt,
    SUM(cost_amount * exchange_rate) as total_cost,
    SUM(total_billable) as total_bill,
    SUM(total_billable - (cost_amount * exchange_rate)) as markup
    FROM expenses WHERE {$rWhere} AND billing_type != 'client_paid'
    GROUP BY expense_category ORDER BY total_cost DESC");
$byCategory->execute($rParams);
$byCategory = $byCategory->fetchAll();

$byClient = $db->prepare("SELECT client_name, billing_type, COUNT(*) as cnt,
    SUM(cost_amount * exchange_rate) as total_cost,
    SUM(total_billable) as total_bill
    FROM expenses WHERE {$rWhere} AND billing_type IN ('client','shared')
    GROUP BY client_name ORDER BY total_bill DESC");
$byClient->execute($rParams);
$byClient = $byClient->fetchAll();

$byStatus = $db->prepare("SELECT status, COUNT(*) as cnt, SUM(total_billable) as total FROM expenses WHERE {$rWhere} AND billing_type != 'client_paid' GROUP BY status");
$byStatus->execute($rParams);
$byStatus = $byStatus->fetchAll();

// Report stats for selected client/month
$rStats = $db->prepare("SELECT
    COALESCE(SUM(cost_amount * exchange_rate),0) as total_cost,
    COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable ELSE 0 END),0) as total_billable,
    COALESCE(SUM(CASE WHEN billing_type = 'internal' THEN cost_amount * exchange_rate ELSE 0 END),0) as internal_cost,
    COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable - (cost_amount * exchange_rate) ELSE 0 END),0) as markup_earned,
    COALESCE(SUM(CASE WHEN status IN ('pending','invoiced') THEN total_billable ELSE 0 END),0) as outstanding,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN total_billable ELSE 0 END),0) as paid_amt
FROM expenses WHERE {$rWhere} AND billing_type != 'client_paid'");
$rStats->execute($rParams);
$rStats = $rStats->fetch();
?>

<div style="max-width:960px">

<?php if ($reportClient): ?>
  <!-- ══ CLIENT-SPECIFIC REPORT ══ -->

  <!-- Report Header -->
  <div class="card no-print" style="margin-bottom:16px;background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.2)">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="font-size:28px">🏢</div>
      <div>
        <div style="font-size:18px;font-weight:800"><?= h($reportClient) ?></div>
        <div style="font-size:13px;color:var(--text2)">Client Report — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
      </div>
      <a href="<?= SITE_URL ?>/expense_report_print.php?month=<?= $filterMonth ?>&rclient=<?= urlencode($reportClient) ?>" target="_blank" class="btn btn-primary btn-sm" style="margin-left:auto">🖨 Print / PDF</a>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:20px">
    <div class="stat-card blue"><div class="stat-label">Total Campaigns</div><div class="stat-value"><?= count($byCategory) ?></div><div class="stat-sub">Types of expenses</div></div>
    <div class="stat-card"><div class="stat-label">Total Ad Spend</div><div class="stat-value" style="font-size:18px"><?= formatMoney($rStats['total_cost']) ?></div><div class="stat-sub">All campaigns</div></div>
    <div class="stat-card green"><div class="stat-label">Total Billable</div><div class="stat-value" style="font-size:18px"><?= formatMoney($rStats['total_billable']) ?></div><div class="stat-sub">Incl. markup & fees</div></div>
    <div class="stat-card yellow"><div class="stat-label">Service Markup</div><div class="stat-value" style="font-size:18px"><?= formatMoney($rStats['markup_earned']) ?></div><div class="stat-sub">Our service fee</div></div>
    <div class="stat-card red"><div class="stat-label">Outstanding</div><div class="stat-value" style="font-size:18px"><?= formatMoney($rStats['outstanding']) ?></div><div class="stat-sub">Pending payment</div></div>
    <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value" style="font-size:18px"><?= formatMoney($rStats['paid_amt']) ?></div><div class="stat-sub">Collected</div></div>
  </div>

  <!-- Detailed Campaign List -->
  <?php
  $detailStmt = $db->prepare("SELECT * FROM expenses WHERE billing_month=? AND client_name=? ORDER BY expense_date ASC, expense_category ASC");
  $detailStmt->execute([$filterMonth, $reportClient]);
  $details = $detailStmt->fetchAll();
  $grandCost = 0; $grandBill = 0; $grandFee = 0; $grandMarkup = 0;
  ?>
  <div class="card" style="margin-bottom:20px;padding:0;overflow:hidden">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div class="card-title" style="margin:0">📋 Campaign Details — <?= h($reportClient) ?></div>
      <div style="font-size:12px;color:var(--text2)"><?= count($details) ?> records · <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
    </div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Ad / Campaign</th>
            <th>Category</th>
            <th>Original Cost</th>
            <th>LKR Cost</th>
            <th>Markup %</th>
            <th>Service Fee</th>
            <th>Total Billable</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($details)): ?>
            <tr><td colspan="9" style="text-align:center;color:var(--text2);padding:32px">No expenses found for <?= h($reportClient) ?> in <?= date('F Y', strtotime($filterMonth.'-01')) ?>.</td></tr>
          <?php else: foreach ($details as $d):
            $lkrCost  = $d['cost_amount'] * $d['exchange_rate'];
            $markupAmt = $lkrCost * $d['markup_percentage'] / 100;
            $grandCost   += $lkrCost;
            $grandBill   += $d['total_billable'];
            $grandFee    += $d['additional_fee'];
            $grandMarkup += $markupAmt;
          ?>
            <tr>
              <td data-label="Date" style="white-space:nowrap;color:var(--text2);font-size:12px"><?= date('d M Y', strtotime($d['expense_date'])) ?></td>
              <td data-label="Campaign">
                <strong><?= h($d['project_name'] ?: '—') ?></strong>
                <?php if ($d['description']): ?>
                  <br><span style="font-size:11px;color:var(--text2)"><?= h(mb_strimwidth($d['description'],0,60,'…')) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Category">
                <span class="badge badge-blue" style="font-size:11px"><?= h($d['expense_category']) ?></span>
                <?php if ($d['billing_type']==='client_paid'): ?>
                  <br><span style="font-size:10px;color:#a855f7">💳 Client-Paid</span>
                <?php endif; ?>
              </td>
              <td data-label="Original" style="white-space:nowrap">
                <strong><?= h($d['currency']) ?> <?= number_format($d['cost_amount'],2) ?></strong>
                <?php if ($d['currency'] !== 'LKR'): ?>
                  <br><span style="font-size:10px;color:var(--text2)">@ <?= number_format($d['exchange_rate'],2) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="LKR Cost"><?= formatMoney($lkrCost) ?></td>
              <td data-label="Markup" style="color:var(--text2)"><?= $d['markup_percentage'] > 0 ? h($d['markup_percentage']).'%' : '—' ?></td>
              <td data-label="Svc Fee" style="color:var(--text2)"><?= $d['additional_fee'] > 0 ? formatMoney($d['additional_fee']) : '—' ?></td>
              <td data-label="Billable"><strong style="color:var(--green)"><?= formatMoney($d['total_billable']) ?></strong></td>
              <td data-label="Status">
                <?php $sc = ['pending'=>'yellow','invoiced'=>'blue','paid'=>'green','cancelled'=>'red']; ?>
                <span class="badge badge-<?= $sc[$d['status']] ?? 'blue' ?>"><?= ucfirst($d['status']) ?></span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($details)): ?>
        <tfoot>
          <tr style="background:var(--bg3);font-weight:700">
            <td colspan="4" style="padding:12px 12px;font-size:13px">TOTALS</td>
            <td data-label="LKR Cost"><strong><?= formatMoney($grandCost) ?></strong></td>
            <td>—</td>
            <td><strong><?= formatMoney($grandFee) ?></strong></td>
            <td><strong style="color:var(--green)"><?= formatMoney($grandBill) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <!-- Category Summary for this client -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">📊 Spend by Category — <?= h($reportClient) ?></div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Category</th><th>Campaigns</th><th>Total Cost (LKR)</th><th>Total Billable</th><th>Markup Earned</th></tr></thead>
        <tbody>
          <?php if (empty($byCategory)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:20px">No data.</td></tr>
          <?php else: foreach ($byCategory as $r): ?>
            <tr>
              <td data-label="Category"><strong><?= h($r['expense_category']) ?></strong></td>
              <td data-label="Count"><?= $r['cnt'] ?></td>
              <td data-label="Cost"><?= formatMoney($r['total_cost']) ?></td>
              <td data-label="Billable"><strong style="color:var(--accent)"><?= formatMoney($r['total_bill']) ?></strong></td>
              <td data-label="Markup" style="color:var(--green)"><?= formatMoney($r['markup']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Client-Paid for this client -->
  <?php
  $cpStmt = $db->prepare("SELECT * FROM expenses WHERE billing_month=? AND client_name=? AND billing_type='client_paid' ORDER BY expense_date ASC");
  $cpStmt->execute([$filterMonth, $reportClient]);
  $cpRows = $cpStmt->fetchAll();
  if (!empty($cpRows)):
    $cpTotal = array_sum(array_map(fn($r) => $r['cost_amount'] * $r['exchange_rate'], $cpRows));
  ?>
  <div class="card" style="margin-bottom:20px;border-color:rgba(168,85,247,.3);background:rgba(168,85,247,.04)">
    <div class="card-title" style="color:#a855f7">💳 Client-Paid Expenses <span style="font-size:12px;font-weight:400;color:var(--text2)">(Paid directly by client — for reference only)</span></div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Date</th><th>Campaign</th><th>Category</th><th>Original</th><th>LKR Amount</th></tr></thead>
        <tbody>
          <?php foreach ($cpRows as $r): ?>
          <tr>
            <td data-label="Date"><?= date('d M Y', strtotime($r['expense_date'])) ?></td>
            <td data-label="Campaign"><?= h($r['project_name'] ?: '—') ?></td>
            <td data-label="Category"><?= h($r['expense_category']) ?></td>
            <td data-label="Original"><?= h($r['currency']) ?> <?= number_format($r['cost_amount'],2) ?></td>
            <td data-label="LKR"><strong style="color:#a855f7"><?= formatMoney($r['cost_amount'] * $r['exchange_rate']) ?></strong></td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:var(--bg3)">
            <td colspan="4"><strong>Total Client-Paid</strong></td>
            <td><strong style="color:#a855f7"><?= formatMoney($cpTotal) ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php else: ?>
  <!-- ══ ALL CLIENTS SUMMARY REPORT ══ -->

  <!-- Summary -->
  <div class="card" style="background:linear-gradient(135deg,rgba(59,130,246,.1),rgba(0,196,140,.06));border-color:rgba(59,130,246,.25);margin-bottom:20px">
    <div class="card-title">📊 Monthly Summary — <?= date('F Y', strtotime($filterMonth.'-01')) ?></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px">
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Total Spend</div><div style="font-size:22px;font-weight:800"><?= formatMoney($rStats['total_cost']) ?></div></div>
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Total Billable</div><div style="font-size:22px;font-weight:800;color:var(--accent)"><?= formatMoney($rStats['total_billable']) ?></div></div>
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Internal</div><div style="font-size:22px;font-weight:800"><?= formatMoney($rStats['internal_cost']) ?></div></div>
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Markup Earned</div><div style="font-size:22px;font-weight:800;color:var(--green)"><?= formatMoney($rStats['markup_earned']) ?></div></div>
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Outstanding</div><div style="font-size:22px;font-weight:800;color:var(--red)"><?= formatMoney($rStats['outstanding']) ?></div></div>
      <div style="text-align:center"><div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text2);margin-bottom:6px">Paid</div><div style="font-size:22px;font-weight:800;color:var(--green)"><?= formatMoney($rStats['paid_amt']) ?></div></div>
    </div>
  </div>

  <!-- By Category -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">By Expense Category</div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Category</th><th>Count</th><th>Total Cost</th><th>Total Billable</th><th>Markup</th></tr></thead>
        <tbody>
          <?php if (empty($byCategory)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:20px">No data.</td></tr>
          <?php else: foreach ($byCategory as $r): ?>
            <tr>
              <td data-label="Category"><strong><?= h($r['expense_category']) ?></strong></td>
              <td data-label="Count"><?= $r['cnt'] ?></td>
              <td data-label="Cost"><?= formatMoney($r['total_cost']) ?></td>
              <td data-label="Billable"><strong style="color:var(--accent)"><?= formatMoney($r['total_bill']) ?></strong></td>
              <td data-label="Markup" style="color:var(--green)"><?= formatMoney($r['markup']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- By Client -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-title">By Client</div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Client</th><th>Type</th><th>Expenses</th><th>Cost</th><th>Billable</th></tr></thead>
        <tbody>
          <?php if (empty($byClient)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:20px">No client expenses.</td></tr>
          <?php else: foreach ($byClient as $r): ?>
            <tr>
              <td data-label="Client">
                <strong><?= h($r['client_name'] ?: 'Unassigned') ?></strong>
                <br><a href="?tab=report&month=<?= $filterMonth ?>&rclient=<?= urlencode($r['client_name']) ?>" style="font-size:11px;color:var(--accent)">View detail →</a>
              </td>
              <td data-label="Type"><span class="badge badge-<?= $r['billing_type']==='shared'?'yellow':'blue' ?>"><?= ucfirst($r['billing_type']) ?></span></td>
              <td data-label="Count"><?= $r['cnt'] ?></td>
              <td data-label="Cost"><?= formatMoney($r['total_cost']) ?></td>
              <td data-label="Billable"><strong style="color:var(--accent)"><?= formatMoney($r['total_bill']) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Client-Paid Summary -->
  <?php
  $clientPaidReport = $db->prepare("SELECT client_name, COUNT(*) as cnt, SUM(cost_amount * exchange_rate) as total_cost, expense_category FROM expenses WHERE billing_month=? AND billing_type='client_paid' GROUP BY client_name, expense_category ORDER BY client_name");
  $clientPaidReport->execute([$filterMonth]);
  $clientPaidReport = $clientPaidReport->fetchAll();
  $totalClientPaid  = array_sum(array_column($clientPaidReport, 'total_cost'));
  ?>
  <?php if (!empty($clientPaidReport)): ?>
  <div class="card" style="margin-bottom:20px;border-color:rgba(168,85,247,.3);background:rgba(168,85,247,.04)">
    <div class="card-title" style="color:#a855f7">💳 Client-Paid Expenses</div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Client</th><th>Category</th><th>Count</th><th>Total (LKR)</th></tr></thead>
        <tbody>
          <?php foreach ($clientPaidReport as $r): ?>
          <tr>
            <td data-label="Client"><strong><?= h($r['client_name'] ?: 'Unassigned') ?></strong></td>
            <td data-label="Category"><?= h($r['expense_category']) ?></td>
            <td data-label="Count"><?= $r['cnt'] ?></td>
            <td data-label="Cost"><strong style="color:#a855f7"><?= formatMoney($r['total_cost']) ?></strong></td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:var(--bg3)">
            <td colspan="3"><strong>Total Client-Paid</strong></td>
            <td><strong style="color:#a855f7"><?= formatMoney($totalClientPaid) ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- By Status -->
  <div class="card">
    <div class="card-title">By Status</div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Status</th><th>Count</th><th>Total Amount</th></tr></thead>
        <tbody>
          <?php foreach ($byStatus as $r): ?>
            <tr>
              <td data-label="Status"><span class="badge badge-<?= $statusColors[$r['status']] ?? 'blue' ?>"><?= ucfirst($r['status']) ?></span></td>
              <td data-label="Count"><?= $r['cnt'] ?></td>
              <td data-label="Amount"><strong><?= formatMoney($r['total']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; // end client-specific vs all-clients report ?>

</div>

<?php endif; ?>


<!-- ══ ADD MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <div class="modal-title">💰 Add Expense</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group"><label>Expense Date *</label><input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label>Billing Month *</label><input type="month" name="billing_month" required value="<?= $filterMonth ?>"></div>
          <div class="form-group"><label>Expense Category *</label>
            <select name="expense_category" required>
              <option value="">— Select —</option>
              <?php foreach ($categories as $cat): ?><option value="<?= h($cat) ?>"><?= h($cat) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Project / Work Name</label><input name="project_name" placeholder="e.g. BMW Engine Build"></div>
          <div class="form-group"><label>Billing Type</label>
            <select name="billing_type" id="addBillingType" onchange="toggleClient('add')">
              <option value="client" selected>Client Expense (We Pay)</option>
              <option value="shared">Shared (Multiple Clients)</option>
              <option value="internal">Internal Company</option>
              <option value="client_paid">💳 Client-Paid (Client Pays Directly)</option>
            </select>
          </div>
          <div id="addClientPaidNotice" style="display:none;background:rgba(168,85,247,.1);border:1px solid rgba(168,85,247,.3);border-radius:8px;padding:10px 14px;font-size:12.5px;color:#a855f7;grid-column:1/-1">
            💳 <strong>Client-Paid Expense</strong> — This cost was paid directly by the client. It will be recorded for reporting purposes only and <strong>excluded from your company's expense totals</strong>.
          </div>
          <div class="form-group" id="addClientField"><label>Client *</label>
            <?php if (empty($clients)): ?>
              <div style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);border-radius:7px;padding:10px 12px;font-size:13px;color:var(--yellow)">
                ⚠️ No clients registered yet. <a href="<?= SITE_URL ?>/clients.php" target="_blank" style="color:var(--accent)">Add clients first →</a>
              </div>
              <input type="hidden" name="client_name" id="addClientName">
            <?php else: ?>
              <select name="client_id" id="addClientId" onchange="syncClientName('add')">
                <option value="">— Select Client —</option>
                <?php foreach ($clients as $cl): ?><option value="<?= $cl['id'] ?>" data-name="<?= h($cl['company_name']) ?>"><?= h($cl['company_name']) ?></option><?php endforeach; ?>
              </select>
              <input type="hidden" name="client_name" id="addClientName">
            <?php endif; ?>
          </div>
          <div class="form-group"><label>Cost Amount *</label><input type="number" name="cost_amount" id="addCost" step="0.01" required placeholder="0.00" oninput="calcTotal('add')"></div>
          <div class="form-group"><label>Currency</label>
            <select name="currency" id="addCurrency" onchange="calcTotal('add')">
              <?php foreach ($currencies as $cur): ?>
                <option value="<?= $cur ?>" <?= $cur==='USD'?'selected':'' ?> data-rate="<?= $rates[$cur] ?? 1 ?>"><?= $cur ?><?= $cur!=='LKR' && isset($rates[$cur]) ? ' (Rs.'.$rates[$cur].')' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <span style="font-size:11px;color:var(--text2)">Rate is locked at entry time. <a href="<?= SITE_URL ?>/settings.php#rates" style="color:var(--accent)" target="_blank">Update rates →</a></span>
          </div>
          <div class="form-group" id="addLkrBox" style="display:none">
            <label>LKR Equivalent</label>
            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:14px;font-weight:600;color:var(--text2)" id="addLkrVal">—</div>
            <span style="font-size:11px;color:var(--text2)" id="addRateLabel"></span>
          </div>
          <div class="form-group"><label>Markup % (optional)</label><input type="number" name="markup_percentage" id="addMarkup" step="0.01" value="0" placeholder="0" oninput="calcTotal('add')"></div>
          <div class="form-group"><label>Additional Service Fee</label><input type="number" name="additional_fee" id="addFee" step="0.01" value="0" placeholder="0.00" oninput="calcTotal('add')"></div>
          <div class="form-group full">
            <label>Total Billable Amount (Auto Calculated — in LKR)</label>
            <div style="background:var(--bg3);border:2px solid var(--green);border-radius:8px;padding:10px 14px;font-size:20px;font-weight:800;color:var(--green)" id="addTotal">0.00</div>
            <input type="hidden" name="total_billable" id="addTotalHidden" value="0">
          </div>
          <div class="form-group full"><label>Description</label><textarea name="description" rows="2" placeholder="Brief description of the expense..."></textarea></div>
          <div class="form-group"><label>Status</label>
            <select name="status">
              <option value="pending">Pending</option>
              <option value="invoiced">Invoiced</option>
              <option value="paid">Paid</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="form-group"><label>Receipt (PDF)</label><input type="file" name="receipt" accept="application/pdf"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Internal notes..."></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Expense</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ══ EDIT MODAL ══ -->
<?php if ($editRow): ?>
<div class="modal-overlay open" id="editModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <div class="modal-title">Edit Expense</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/expenses.php?month=<?= $filterMonth ?>'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit&id=<?= $editRow['id'] ?>" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group"><label>Expense Date *</label><input type="date" name="expense_date" required value="<?= h($editRow['expense_date']) ?>"></div>
          <div class="form-group"><label>Billing Month *</label><input type="month" name="billing_month" required value="<?= h($editRow['billing_month']) ?>"></div>
          <div class="form-group"><label>Expense Category *</label>
            <select name="expense_category" required>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= h($cat) ?>" <?= $cat===$editRow['expense_category']?'selected':'' ?>><?= h($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Project / Work Name</label><input name="project_name" value="<?= h($editRow['project_name'] ?? '') ?>" placeholder="e.g. BMW Engine Build"></div>
          <div class="form-group"><label>Billing Type</label>
            <select name="billing_type" id="editBillingType" onchange="toggleClient('edit')">
              <option value="client"      <?= $editRow['billing_type']==='client'     ?'selected':'' ?>>Client Expense (We Pay)</option>
              <option value="shared"      <?= $editRow['billing_type']==='shared'     ?'selected':'' ?>>Shared (Multiple Clients)</option>
              <option value="internal"    <?= $editRow['billing_type']==='internal'   ?'selected':'' ?>>Internal Company</option>
              <option value="client_paid" <?= $editRow['billing_type']==='client_paid'?'selected':'' ?>>💳 Client-Paid (Client Pays Directly)</option>
            </select>
          </div>
          <div id="editClientPaidNotice" style="<?= $editRow['billing_type']==='client_paid'?'':'display:none' ?>;background:rgba(168,85,247,.1);border:1px solid rgba(168,85,247,.3);border-radius:8px;padding:10px 14px;font-size:12.5px;color:#a855f7;grid-column:1/-1">
            💳 <strong>Client-Paid Expense</strong> — This cost was paid directly by the client. Recorded for reporting only — excluded from company expense totals.
          </div>
          <div class="form-group" id="editClientField" style="<?= $editRow['billing_type']==='internal'?'display:none':'' ?>">
            <label>Client Name</label><input name="client_name" value="<?= h($editRow['client_name']) ?>">
          </div>
          <div class="form-group"><label>Cost Amount *</label><input type="number" name="cost_amount" id="editCost" step="0.01" required value="<?= h($editRow['cost_amount']) ?>" oninput="calcTotal('edit')"></div>
          <div class="form-group"><label>Currency</label>
            <select name="currency" id="editCurrency" onchange="calcTotal('edit')">
              <?php foreach ($currencies as $cur): ?>
                <option value="<?= $cur ?>" <?= $cur===$editRow['currency']?'selected':'' ?> data-rate="<?= $rates[$cur] ?? 1 ?>"><?= $cur ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="editLkrBox" style="<?= ($editRow['currency']??'LKR')==='LKR'?'display:none':'' ?>">
            <label>LKR Equivalent</label>
            <div style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:14px;font-weight:600;color:var(--text2)" id="editLkrVal">—</div>
            <span style="font-size:11px;color:var(--text2)" id="editRateLabel"></span>
          </div>
          <div class="form-group"><label>Markup %</label><input type="number" name="markup_percentage" id="editMarkup" step="0.01" value="<?= h($editRow['markup_percentage']) ?>" oninput="calcTotal('edit')"></div>
          <div class="form-group"><label>Additional Service Fee</label><input type="number" name="additional_fee" id="editFee" step="0.01" value="<?= h($editRow['additional_fee']) ?>" oninput="calcTotal('edit')"></div>
          <div class="form-group full">
            <label>Total Billable Amount (in LKR)</label>
            <div style="background:var(--bg3);border:2px solid var(--green);border-radius:8px;padding:10px 14px;font-size:20px;font-weight:800;color:var(--green)" id="editTotal"><?= number_format($editRow['total_billable'],2) ?></div>
            <input type="hidden" name="total_billable" id="editTotalHidden" value="<?= h($editRow['total_billable']) ?>">
          </div>
          <div class="form-group full"><label>Description</label><textarea name="description" rows="2"><?= h($editRow['description']) ?></textarea></div>
          <div class="form-group"><label>Status</label>
            <select name="status">
              <option value="pending"   <?= $editRow['status']==='pending'  ?'selected':'' ?>>Pending</option>
              <option value="invoiced"  <?= $editRow['status']==='invoiced' ?'selected':'' ?>>Invoiced</option>
              <option value="paid"      <?= $editRow['status']==='paid'     ?'selected':'' ?>>Paid</option>
              <option value="cancelled" <?= $editRow['status']==='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
          </div>
          <div class="form-group">
            <label>Receipt (PDF)</label>
            <input type="file" name="receipt" accept="application/pdf">
            <?php if (isAdmin() && !empty($editRow['receipt_path'])): ?>
              <span style="font-size:11px;color:var(--text2)">Current: <a href="<?= SITE_URL ?>/<?= h($editRow['receipt_path']) ?>" target="_blank" style="color:var(--accent)">📄 View</a> — upload a new file to replace it</span>
            <?php endif; ?>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2"><?= h($editRow['notes']) ?></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/expenses.php?month=<?= $filterMonth ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>


<script>
const EXCHANGE_RATES = <?= $ratesJson ?>;
const RATES_UPDATED  = "<?= h($ratesUpdated) ?>";

// Sync client name from select
function syncClientName(prefix) {
    const sel = document.getElementById(prefix + 'ClientId');
    const hidden = document.getElementById(prefix + 'ClientName');
    const opt = sel.options[sel.selectedIndex];
    if (hidden) hidden.value = opt ? opt.getAttribute('data-name') || '' : '';
}

// Toggle client name field
function toggleClient(prefix) {
    const type  = document.getElementById(prefix + 'BillingType').value;
    const field = document.getElementById(prefix + 'ClientField');
    if (field) field.style.display = type === 'internal' ? 'none' : '';
    // Show client-paid notice
    const notice = document.getElementById(prefix + 'ClientPaidNotice');
    if (notice) notice.style.display = type === 'client_paid' ? 'block' : 'none';
}

// Auto-calculate total billable with currency conversion
function calcTotal(prefix) {
    const cost      = parseFloat(document.getElementById(prefix + 'Cost').value) || 0;
    const markup    = parseFloat(document.getElementById(prefix + 'Markup').value) || 0;
    const fee       = parseFloat(document.getElementById(prefix + 'Fee').value) || 0;
    const curSel    = document.getElementById(prefix + 'Currency');
    const currency  = curSel ? curSel.value : 'LKR';
    const rate      = EXCHANGE_RATES[currency] || 1;
    const lkrBox    = document.getElementById(prefix + 'LkrBox');
    const lkrVal    = document.getElementById(prefix + 'LkrVal');
    const rateLabel = document.getElementById(prefix + 'RateLabel');

    if (lkrBox) {
        if (currency !== 'LKR' && cost > 0) {
            lkrBox.style.display = '';
            lkrVal.textContent = 'Rs. ' + (cost * rate).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            if (rateLabel) rateLabel.textContent = '1 ' + currency + ' = Rs. ' + rate.toFixed(2) + ' · Updated: ' + RATES_UPDATED;
        } else {
            lkrBox.style.display = 'none';
        }
    }

    const costLKR = cost * rate;
    const total   = costLKR + (costLKR * markup / 100) + fee;
    document.getElementById(prefix + 'Total').textContent = total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById(prefix + 'TotalHidden').value = total.toFixed(2);
}

// Update status via AJAX-style redirect
function updateStatus(id, status, month) {
    window.location = `?action=status&id=${id}&s=${status}&month=${month}&tab=expenses`;
}

// Init edit modal calculations
document.addEventListener('DOMContentLoaded', () => {
    const ec = document.getElementById('editCost');
    if (ec) calcTotal('edit');
});
</script>

<?php pageFooter(); ?>
