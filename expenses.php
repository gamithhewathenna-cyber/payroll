<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAccess('expenses');
$db = getDB();
$isAdminUser = isAdmin();

// ── Actions ────────────────────────────────────────────────
// This page only ever holds Bank Transfer records now — managed by Admins only.
// "Not Bank Transfer" normal expenses, and their staff approval queue, live on the
// separate Ads Expenses page instead.
$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

if (($action !== '' || $_SERVER['REQUEST_METHOD'] === 'POST') && !isAdmin()) {
    setFlash('error', 'Only Admins can manage Bank Transfer records.');
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($_REQUEST['month'] ?? date('Y-m'))); exit;
}

if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
    setFlash('success', 'Expense deleted.');
    header('Location: ' . SITE_URL . '/expenses.php?month=' . ($_REQUEST['month'] ?? date('Y-m'))); exit;
}

if ($action === 'status' && $id) {
    $newStatus = $_GET['s'] ?? 'pending';
    $db->prepare("UPDATE expenses SET status=? WHERE id=?")->execute([$newStatus, $id]);
    setFlash('success', 'Status updated.');
    header('Location: ' . SITE_URL . '/expenses.php?' . http_build_query(['month'=>$_GET['month']??'','client'=>$_GET['client']??'','cat'=>$_GET['cat']??'','status'=>$_GET['status']??''])); exit;
}
if ($action === 'mark_paid' && $id) {
    $paidDate = !empty($_REQUEST['payment_date']) ? $_REQUEST['payment_date'] : date('Y-m-d');
    $method   = ($_REQUEST['payment_method'] ?? '') === 'not_bank_transfer' ? 'not_bank_transfer' : 'bank_transfer';
    $bankRef  = trim($_REQUEST['bank_reference'] ?? '') ?: null;
    $backTo   = SITE_URL . '/expenses.php?month=' . ($_REQUEST['month'] ?? date('Y-m'));
    if ($method === 'bank_transfer' && !$bankRef) {
        setFlash('error', 'Bank Reference Number is required for bank transfer payments.');
        header('Location: ' . $backTo); exit;
    }

    // Payment receipt upload (PDF/JPG/PNG) — keep the existing one if none is given
    $paymentReceiptPath = null;
    if (!empty($_FILES['payment_receipt']['name']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $dir = 'uploads/payment_receipts/';
            if (!is_dir(__DIR__.'/'.$dir)) mkdir(__DIR__.'/'.$dir, 0755, true);
            $fname = 'payrcpt_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], __DIR__.'/'.$dir.$fname)) {
                $paymentReceiptPath = $dir . $fname;
            }
        }
    }
    if (!$paymentReceiptPath) {
        $pr = $db->prepare("SELECT payment_receipt_path FROM expenses WHERE id=?");
        $pr->execute([$id]);
        $paymentReceiptPath = $pr->fetchColumn() ?: null;
    }

    $db->prepare("UPDATE expenses SET status='paid', payment_date=?, payment_method=?, bank_reference=?, payment_receipt_path=? WHERE id=?")
       ->execute([$paidDate, $method, $bankRef, $paymentReceiptPath, $id]);
    setFlash('success', 'Expense marked as paid.');
    header('Location: ' . $backTo); exit;
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

    // This page only holds Bank Transfer records. Two flavors:
    //  - "bank_transfer"         a pure bank-transaction log entry — excluded from
    //                            billing/revenue/profit, tracked only in the Payment Report.
    //  - "bank_transfer_expense" a normal billable expense (still counts toward Revenue/
    //                            Profit/expense totals) that's also recorded as paid via
    //                            bank transfer at creation, so it shows in the Payment Report too.
    // record_type is only ever set on add — edit never changes it.
    $recordTypeInput = $action === 'add' ? ($d['record_type'] ?? 'bank_transfer_expense') : 'bank_transfer_expense';
    $recordType      = $recordTypeInput === 'bank_transfer' ? 'bank_transfer' : 'bank_transfer_expense';

    if ($action === 'add' && $recordType === 'bank_transfer') {
        $billingType = 'internal';
        $clientName  = null;
        $markup      = 0;
        $addFee      = 0;
    }
    $bankRef = trim($d['bank_reference'] ?? '');
    if ($action === 'add' && $bankRef === '') {
        setFlash('error', 'Bank Reference Number is required for a Bank Transfer record.');
        header('Location: ' . SITE_URL . '/expenses.php?month=' . ($d['billing_month'] ?? date('Y-m'))); exit;
    }
    $paymentDate   = $d['expense_date'];
    $paymentMethod = 'bank_transfer';

    $paymentReceiptPath = null;
    if (!empty($_FILES['payment_receipt']['name']) && $_FILES['payment_receipt']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['payment_receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $dir = 'uploads/payment_receipts/';
            if (!is_dir(__DIR__.'/'.$dir)) mkdir(__DIR__.'/'.$dir, 0755, true);
            $fname = 'payrcpt_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['payment_receipt']['tmp_name'], __DIR__.'/'.$dir.$fname)) {
                $paymentReceiptPath = $dir . $fname;
            }
        }
    }

    $costLKR = $cost * $exRate;
    $total   = round($costLKR + ($costLKR * $markup / 100) + $addFee, 2);
    $status  = $action === 'add' ? 'paid' : ($d['status'] ?? 'pending');

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

    if ($action === 'add') {
        $db->prepare("INSERT INTO expenses (expense_date,billing_month,client_name,billing_type,record_type,expense_category,project_name,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,status,notes,receipt_path,created_by,approval_status,approved_by,approved_at,payment_date,payment_method,bank_reference,payment_receipt_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'approved',?,NOW(),?,?,?,?)")
           ->execute([$d['expense_date'],$d['billing_month'],$clientName,$billingType,$recordType,$d['expense_category'],trim($d['project_name']??''),trim($d['description']??''),$cost,$currency,$exRate,$markup,$addFee,$total,$status,trim($d['notes']??''),$receiptPath,$_SESSION['full_name'],$_SESSION['full_name'],$paymentDate,$paymentMethod,$bankRef,$paymentReceiptPath]);
        setFlash('success', $recordType === 'bank_transfer' ? '🏦 Bank transfer recorded.' : '🏦 Expense recorded as paid via bank transfer.');
    } elseif ($action === 'edit') {
        $stmt = $db->prepare("SELECT exchange_rate, currency FROM expenses WHERE id=?");
        $stmt->execute([$id]);
        $orig = $stmt->fetch();
        if ($orig && $orig['currency'] === $currency) {
            $exRate  = (float)$orig['exchange_rate'] ?: $exRate;
            $costLKR = $cost * $exRate;
            $total   = round($costLKR + ($costLKR * $markup / 100) + $addFee, 2);
        }
        $db->prepare("UPDATE expenses SET expense_date=?,billing_month=?,client_name=?,billing_type=?,expense_category=?,project_name=?,description=?,cost_amount=?,currency=?,exchange_rate=?,markup_percentage=?,additional_fee=?,total_billable=?,status=?,notes=?,receipt_path=?,approved_by=?,approved_at=NOW() WHERE id=?")
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
$tab            = $_GET['tab'] ?? 'expenses';

// This page only ever holds Bank Transfer records — "Not Bank Transfer" normal
// expenses live on the separate Ads Expenses page instead.
$where  = ["billing_month = ?", "record_type != 'expense'"];
$params = [$filterMonth];
if ($filterClient)  { $where[] = "client_name LIKE ?";   $params[] = "%$filterClient%"; }
if ($filterCat)     { $where[] = "expense_category = ?"; $params[] = $filterCat; }
if ($filterStatus)  { $where[] = "status = ?";           $params[] = $filterStatus; }
if ($filterProject) { $where[] = "project_name LIKE ?";  $params[] = "%$filterProject%"; }
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
    COALESCE(SUM(CASE WHEN billing_type IN ('client','shared') THEN total_billable - (cost_amount * exchange_rate) ELSE 0 END),0) as markup_earned
FROM expenses WHERE billing_month = ? AND record_type != 'expense'");
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

pageHeader('Expenses & Client Rebilling');
?>

<!-- ── Stats ── -->
<?php if ($tab === 'expenses'): ?>
<div style="font-size:12px;color:var(--text2);margin-bottom:6px">💰 Expenses totals (Bank Transfer records)</div>
<?php endif; ?>
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
</div>

<!-- ── Tabs ── -->
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border)">
  <a href="?tab=expenses&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='expenses'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">💰 Expenses</a>
  <?php if (isAdmin()): ?>
  <a href="?tab=payment_report&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='payment_report'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">💵 Payment Report</a>
  <?php endif; ?>
</div>

<?php if ($tab === 'expenses'): ?>

<!-- ── Filters ── -->
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:16px">
  <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
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
    <div style="display:flex;gap:8px;align-items:flex-end">
      <button type="submit" class="btn btn-primary btn-sm" style="flex:1">Filter</button>
      <a href="?tab=expenses&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Clear</a>
    </div>
  </form>
</div>

<div class="section-header">
  <div style="font-size:13px;color:var(--text2)"><?= count($expenses) ?> records</div>
  <?php if (isAdmin()): ?>
  <button class="btn btn-primary" onclick="openAddModal()">+ Add Expense</button>
  <?php endif; ?>
</div>

<!-- ── Expenses Table ── -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table>
      <thead>
        <tr>
          <th>Date</th><th>Category</th><th>Client</th>
          <th>Original Cost</th><th>LKR Amount</th><th>Markup</th><th>Total Billable</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($expenses)): ?>
          <tr><td colspan="9" style="text-align:center;color:var(--text2);padding:36px">No expenses for <?= date('F Y', strtotime($filterMonth.'-01')) ?>.</td></tr>
        <?php else: foreach ($expenses as $e): ?>
          <tr>
            <td data-label="Date" style="white-space:nowrap"><?= date('d M Y', strtotime($e['expense_date'])) ?></td>
            <td data-label="Category">
              <span style="font-weight:600"><?= h($e['expense_category']) ?></span>
              <?php if (!empty($e['project_name'])): ?><br><span style="font-size:12px;color:var(--accent);font-weight:500"><?= h($e['project_name']) ?></span><?php endif; ?>
              <?php if ($e['description']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h(mb_strimwidth($e['description'],0,40,'…')) ?></span><?php endif; ?>
            </td>
            <td data-label="Client">
              <?php if ($e['record_type'] === 'bank_transfer'): ?>
                <span class="badge" style="background:rgba(59,130,246,.15);color:var(--accent)">🏦 Bank Transfer</span>
              <?php elseif ($e['billing_type'] === 'internal'): ?>
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
            <td data-label="Status">
              <select data-prev="<?= $e['status'] ?>" data-billing="<?= h($e['billing_type']) ?>" onchange="handleStatusChange(this, <?= $e['id'] ?>, '<?= $filterMonth ?>')"
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
              <?php if (isAdmin()): ?>
              <a href="?action=edit&id=<?= $e['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete&id=<?= $e['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete this expense?')">Del</a>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Mark as Paid Modal -->
<style>
/* Keep this one modal centered on all screen sizes, overriding the site-wide mobile bottom-sheet style */
#markPaidModal { align-items: center !important; }
#markPaidModal .modal { border-radius: 14px !important; max-height: 90vh !important; }
</style>
<div class="modal-overlay" id="markPaidModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <div class="modal-title">✓ Mark as Paid</div>
      <button class="modal-close" onclick="cancelMarkPaid()">×</button>
    </div>
    <form method="POST" action="?action=mark_paid" enctype="multipart/form-data" id="markPaidForm" onsubmit="return validateMarkPaid()">
      <input type="hidden" name="id" id="markPaidIdInput">
      <input type="hidden" name="month" id="markPaidMonthInput">
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:14px">
          <label>Payment Date *</label>
          <input type="date" name="payment_date" id="markPaidDate" required>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label>Payment Method</label>
          <div style="display:flex;gap:16px;margin-top:6px">
            <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;cursor:pointer;color:var(--text)">
              <input type="radio" name="payment_method" value="bank_transfer" id="payMethodBank" checked onchange="toggleBankRefRequired()"> Bank Transfer
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;cursor:pointer;color:var(--text)">
              <input type="radio" name="payment_method" value="not_bank_transfer" id="payMethodNotBank" onchange="toggleBankRefRequired()"> Not Bank Transfer
            </label>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:14px" id="markPaidRefGroup">
          <label id="markPaidRefLabel">Bank Reference Number *</label>
          <input type="text" name="bank_reference" id="markPaidRef" placeholder="e.g. TXN123456789">
        </div>
        <div class="form-group" style="margin-bottom:16px" id="markPaidReceiptGroup">
          <label>Payment Receipt (optional)</label>
          <input type="file" name="payment_receipt" id="markPaidReceipt" accept=".pdf,.jpg,.jpeg,.png">
          <span style="font-size:11px;color:var(--text2)">PDF, JPG or PNG</span>
        </div>
        <div style="display:flex;gap:10px">
          <button type="submit" class="btn btn-success" style="width:auto;padding:8px 20px">✓ Confirm Paid</button>
          <button type="button" class="btn btn-ghost" style="width:auto;padding:8px 20px;background:var(--bg3);color:var(--text2)" onclick="cancelMarkPaid()">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
let markPaidSelect = null;

function handleStatusChange(sel, id, month) {
    // Client-Paid expenses are settled directly by the client — no bank ref/receipt to collect,
    // so skip the Mark as Paid popup entirely and just update the status like any other change.
    if (sel.value === 'paid' && sel.dataset.billing !== 'client_paid') {
        openMarkPaid(id, month, sel);
    } else {
        updateStatus(id, sel.value, month);
    }
}

function openMarkPaid(id, month, selectEl) {
    markPaidSelect = selectEl || null;
    document.getElementById('markPaidIdInput').value = id;
    document.getElementById('markPaidMonthInput').value = month;
    document.getElementById('markPaidDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('markPaidRef').value = '';
    document.getElementById('markPaidReceipt').value = '';
    document.getElementById('payMethodBank').checked = true;
    toggleBankRefRequired();
    openModal('markPaidModal');
}

function toggleBankRefRequired() {
    const notBank      = document.getElementById('payMethodNotBank').checked;
    const refGroup     = document.getElementById('markPaidRefGroup');
    const receiptGroup = document.getElementById('markPaidReceiptGroup');
    refGroup.style.opacity = notBank ? '.5' : '1';
    document.getElementById('markPaidRefLabel').textContent = notBank ? 'Bank Reference Number (optional)' : 'Bank Reference Number *';
    receiptGroup.style.display = notBank ? 'none' : '';
    if (notBank) document.getElementById('markPaidReceipt').value = '';
}

function validateMarkPaid() {
    const notBank  = document.getElementById('payMethodNotBank').checked;
    const refInput = document.getElementById('markPaidRef');
    if (!notBank && !refInput.value.trim()) {
        alert('Bank Reference Number is required for bank transfer payments.');
        refInput.focus();
        return false;
    }
    return true;
}

function cancelMarkPaid() {
    if (markPaidSelect) markPaidSelect.value = markPaidSelect.dataset.prev;
    closeModal('markPaidModal');
}

document.getElementById('markPaidModal').addEventListener('click', (e) => {
    if (e.target.id === 'markPaidModal') cancelMarkPaid();
});
</script>

<?php elseif ($tab === 'payment_report' && isAdmin()): ?>

<!-- ── PAYMENT REPORT TAB ── -->
<?php
$prPeriod = $_GET['pr_period'] ?? 'current';
$prToday  = new DateTime();

switch ($prPeriod) {
    case 'previous':
        $prFrom  = (clone $prToday)->modify('first day of last month')->format('Y-m-d');
        $prTo    = (clone $prToday)->modify('last day of last month')->format('Y-m-d');
        $prLabel = 'Previous Month (' . (clone $prToday)->modify('last month')->format('F Y') . ')';
        break;
    case 'custom':
        $prFrom  = $_GET['pr_from'] ?? date('Y-m-01');
        $prTo    = $_GET['pr_to']   ?? date('Y-m-d');
        $prLabel = 'Custom Range: ' . date('d M Y', strtotime($prFrom)) . ' — ' . date('d M Y', strtotime($prTo));
        break;
    case 'all':
        $prFrom = null; $prTo = null;
        $prLabel = 'All Time';
        break;
    case 'current':
    default:
        $prPeriod = 'current';
        $prFrom  = (clone $prToday)->modify('first day of this month')->format('Y-m-d');
        $prTo    = (clone $prToday)->modify('last day of this month')->format('Y-m-d');
        $prLabel = 'Current Month (' . $prToday->format('F Y') . ')';
        break;
}

// Client-Paid expenses are collected directly by the client via their own client card —
// not a payment we make/track, so they never belong in this report. Likewise, only actual
// bank transfers belong here — a "Not Bank Transfer" payment (cash/other) has no bank
// transaction to reconcile, so it's excluded too. This is the single company-wide Payment
// Report — it shows every paid-via-bank-transfer record regardless of record_type (both
// Bank Transfer Expenses/Report-Only entries and paid Ads Expenses).
$prWhere  = ["status = 'paid'", "billing_type != 'client_paid'", "payment_method = 'bank_transfer'"];
$prParams = [];
if ($prFrom && $prTo) {
    $prWhere[] = "payment_date BETWEEN ? AND ?";
    $prParams[] = $prFrom;
    $prParams[] = $prTo;
}
$prStmt = $db->prepare("SELECT * FROM expenses WHERE " . implode(' AND ', $prWhere) . " ORDER BY payment_date ASC, id ASC");
$prStmt->execute($prParams);
$prRows  = $prStmt->fetchAll();
$prTotal = array_sum(array_column($prRows, 'total_billable'));
$methodLabels = ['bank_transfer' => 'Bank Transfer', 'not_bank_transfer' => 'Not Bank Transfer'];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="form-group" style="margin:0">
      <label style="font-size:11px">Period</label>
      <select name="pr_period" id="prPeriodSelect" onchange="togglePrCustomRange()">
        <option value="all"      <?= $prPeriod==='all'?'selected':'' ?>>All Time</option>
        <option value="previous" <?= $prPeriod==='previous'?'selected':'' ?>>Previous Month</option>
        <option value="current"  <?= $prPeriod==='current'?'selected':'' ?>>Current Month</option>
        <option value="custom"   <?= $prPeriod==='custom'?'selected':'' ?>>Custom Range</option>
      </select>
    </div>
    <div class="form-group" id="prFromGroup" style="margin:0;display:<?= $prPeriod==='custom'?'':'none' ?>">
      <label style="font-size:11px">From</label>
      <input type="date" name="pr_from" value="<?= h($prFrom ?: date('Y-m-01')) ?>">
    </div>
    <div class="form-group" id="prToGroup" style="margin:0;display:<?= $prPeriod==='custom'?'':'none' ?>">
      <label style="font-size:11px">To</label>
      <input type="date" name="pr_to" value="<?= h($prTo ?: date('Y-m-d')) ?>">
    </div>
    <button type="submit" class="btn btn-ghost btn-sm">Apply</button>
  </form>
</div>

<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <strong><?= h($prLabel) ?></strong>
      <span style="color:var(--text2);font-size:12.5px"> — <?= count($prRows) ?> paid expense<?= count($prRows)===1?'':'s' ?></span>
    </div>
    <strong style="color:var(--green);font-size:16px"><?= formatMoney($prTotal) ?></strong>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Payment Date</th><th>Expense Details</th><th>Amount</th><th>Status</th><th>Payment Method</th><th>Bank Reference</th><th>Receipt</th></tr></thead>
      <tbody>
        <?php if (empty($prRows)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text2);padding:32px">No paid expenses found for this period.</td></tr>
        <?php else: foreach ($prRows as $r): ?>
          <tr>
            <td data-label="Payment Date" style="white-space:nowrap"><?= $r['payment_date'] ? date('d M Y', strtotime($r['payment_date'])) : '—' ?></td>
            <td data-label="Expense Details">
              <span style="font-weight:600"><?= h($r['expense_category']) ?></span>
              <?php if (!empty($r['client_name'])): ?><br><span style="font-size:11px;color:var(--accent)"><?= h($r['client_name']) ?></span><?php endif; ?>
              <?php if ($r['description']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h(mb_strimwidth($r['description'],0,40,'…')) ?></span><?php endif; ?>
            </td>
            <td data-label="Amount"><strong style="color:var(--green)"><?= formatMoney($r['total_billable']) ?></strong></td>
            <td data-label="Status"><span class="badge badge-green">Paid</span></td>
            <td data-label="Payment Method"><?= h($methodLabels[$r['payment_method']] ?? '—') ?></td>
            <td data-label="Bank Reference"><?= $r['bank_reference'] ? h($r['bank_reference']) : '—' ?></td>
            <td data-label="Receipt"><?php if (!empty($r['payment_receipt_path'])): ?><a href="<?= SITE_URL ?>/<?= h($r['payment_receipt_path']) ?>" target="_blank" style="color:var(--accent)">📄 View</a><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function togglePrCustomRange() {
    const isCustom = document.getElementById('prPeriodSelect').value === 'custom';
    document.getElementById('prFromGroup').style.display = isCustom ? '' : 'none';
    document.getElementById('prToGroup').style.display   = isCustom ? '' : 'none';
}
</script>

<?php endif; ?>


<!-- ══ ADD MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header">
      <div class="modal-title">💰 Add Expense</div>
      <button class="modal-close" onclick="closeModal('addModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add" enctype="multipart/form-data" onsubmit="return validateAddExpense()">
        <div class="form-grid">
          <div class="form-group full" style="margin-bottom:4px">
            <label>Record Type</label>
            <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;cursor:pointer;color:var(--text)">
                <input type="radio" name="record_type" value="bank_transfer_expense" id="addRecordBankTransferExpense" checked onchange="toggleRecordType('add')"> Bank Transfer (Normal Expense)
              </label>
              <label style="display:flex;align-items:center;gap:6px;font-weight:400;font-size:13px;cursor:pointer;color:var(--text)">
                <input type="radio" name="record_type" value="bank_transfer" id="addRecordBankTransfer" onchange="toggleRecordType('add')"> Bank Transfer - Report Only
              </label>
            </div>
            <span style="font-size:11px;color:var(--text2)">"Bank Transfer (Normal Expense)" still counts toward Revenue/Profit and expense totals like a normal expense, and is recorded as paid — it also appears in the Payment Report. "Bank Transfer - Report Only" is excluded from Revenue/Profit and all expense totals — it's only tracked in the Payment Report.</span>
          </div>
          <div class="form-group"><label>Expense Date *</label><input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label>Billing Month *</label><input type="month" name="billing_month" required value="<?= $filterMonth ?>"></div>
          <div class="form-group"><label>Expense Category *</label>
            <select name="expense_category" required>
              <option value="">— Select —</option>
              <?php foreach ($categories as $cat): ?><option value="<?= h($cat) ?>"><?= h($cat) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Project / Work Name</label><input name="project_name" placeholder="e.g. BMW Engine Build"></div>
          <div class="form-group" id="addBillingTypeGroup"><label>Billing Type</label>
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
          <div class="form-group" id="addMarkupGroup"><label>Markup % (optional)</label><input type="number" name="markup_percentage" id="addMarkup" step="0.01" value="0" placeholder="0" oninput="calcTotal('add')"></div>
          <div class="form-group" id="addFeeGroup"><label>Additional Service Fee</label><input type="number" name="additional_fee" id="addFee" step="0.01" value="0" placeholder="0.00" oninput="calcTotal('add')"></div>
          <div class="form-group full">
            <label>Total Billable Amount (Auto Calculated — in LKR)</label>
            <div style="background:var(--bg3);border:2px solid var(--green);border-radius:8px;padding:10px 14px;font-size:20px;font-weight:800;color:var(--green)" id="addTotal">0.00</div>
            <input type="hidden" name="total_billable" id="addTotalHidden" value="0">
          </div>
          <div class="form-group full"><label>Description</label><textarea name="description" rows="2" placeholder="Brief description of the expense..."></textarea></div>
          <div class="form-group" id="addStatusGroup"><label>Status</label>
            <select name="status">
              <option value="pending">Pending</option>
              <option value="invoiced">Invoiced</option>
              <option value="paid">Paid</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="form-group" id="addOrigReceiptGroup"><label>Receipt (PDF)</label><input type="file" name="receipt" accept="application/pdf"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Internal notes..."></textarea></div>
          <div class="form-group" id="addBankRefGroup" style="display:none"><label id="addBankRefLabel">Bank Reference Number *</label><input type="text" name="bank_reference" id="addBankRef" placeholder="e.g. TXN123456789"></div>
          <div class="form-group" id="addPaymentReceiptGroup" style="display:none"><label>Payment Receipt (optional)</label><input type="file" name="payment_receipt" accept=".pdf,.jpg,.jpeg,.png"><span style="font-size:11px;color:var(--text2)">PDF, JPG or PNG</span></div>
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
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
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

function openAddModal() {
    toggleRecordType('add');
    openModal('addModal');
}

// Add Expense: two record types (this page is Bank Transfer records only) —
//  "bank_transfer_expense"  normal expense (billing fields stay, counts in Revenue/Profit)
//                           but paid via bank transfer at creation — bank ref/receipt shown,
//                           status forced to Paid, appears in Payment Report
//  "bank_transfer"          pure tracking record — billing fields hidden, excluded from
//                           Revenue/Profit/totals, only appears in Payment Report
function toggleRecordType(prefix) {
    const pureBankTransferRadio = document.getElementById(prefix + 'RecordBankTransfer');
    const bankTransferExpenseRadio = document.getElementById(prefix + 'RecordBankTransferExpense');
    if (!pureBankTransferRadio) return;
    const isPureBankTransfer = pureBankTransferRadio.checked;
    const isBankTransferExpense = !!(bankTransferExpenseRadio && bankTransferExpenseRadio.checked);
    const isAnyBankTransfer = isPureBankTransfer || isBankTransferExpense;

    // Billing fields only hide for the pure (non-billable) tracking record
    ['BillingTypeGroup', 'MarkupGroup', 'FeeGroup'].forEach(suffix => {
        const el = document.getElementById(prefix + suffix);
        if (el) el.style.display = isPureBankTransfer ? 'none' : '';
    });
    const clientField = document.getElementById(prefix + 'ClientField');
    if (clientField) clientField.style.display = isPureBankTransfer ? 'none' : '';
    if (isPureBankTransfer) {
        const notice = document.getElementById(prefix + 'ClientPaidNotice');
        if (notice) notice.style.display = 'none';
    } else {
        toggleClient(prefix);
    }

    // Status is forced to Paid for either bank-transfer flavor
    const statusGroup = document.getElementById(prefix + 'StatusGroup');
    if (statusGroup) statusGroup.style.display = isAnyBankTransfer ? 'none' : '';

    // Original expense receipt only makes sense for a real (billable) expense
    const origReceiptGroup = document.getElementById(prefix + 'OrigReceiptGroup');
    if (origReceiptGroup) origReceiptGroup.style.display = isPureBankTransfer ? 'none' : '';

    // Bank reference + payment receipt apply to either bank-transfer flavor
    const bankRefGroup = document.getElementById(prefix + 'BankRefGroup');
    const receiptGroup = document.getElementById(prefix + 'PaymentReceiptGroup');
    if (bankRefGroup) bankRefGroup.style.display = isAnyBankTransfer ? '' : 'none';
    if (receiptGroup) receiptGroup.style.display = isAnyBankTransfer ? '' : 'none';
}

function validateAddExpense() {
    const pureBankTransferRadio = document.getElementById('addRecordBankTransfer');
    const bankTransferExpenseRadio = document.getElementById('addRecordBankTransferExpense');
    const isAnyBankTransfer = (pureBankTransferRadio && pureBankTransferRadio.checked) || (bankTransferExpenseRadio && bankTransferExpenseRadio.checked);
    if (isAnyBankTransfer) {
        const ref = document.getElementById('addBankRef');
        if (!ref.value.trim()) {
            alert('Bank Reference Number is required for a Bank Transfer record.');
            ref.focus();
            return false;
        }
    }
    return true;
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
    window.location = `?action=status&id=${id}&s=${status}&month=${month}`;
}

// Init edit modal calculations
document.addEventListener('DOMContentLoaded', () => {
    const ec = document.getElementById('editCost');
    if (ec) calcTotal('edit');
});
</script>

<?php pageFooter(); ?>
