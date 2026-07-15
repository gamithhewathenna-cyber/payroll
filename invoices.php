<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// AJAX: get expenses for a client+month
if ($action === 'get_expenses') {
    header('Content-Type: application/json');
    $cid   = (int)($_GET['client_id'] ?? 0);
    $month = $_GET['month'] ?? '';
    $sym   = getSetting('currency_symbol', 'Rs.');
    $stmt  = $db->prepare("SELECT id, project_name, expense_category, description, total_billable, cost_amount, currency, exchange_rate, markup_percentage, additional_fee FROM expenses WHERE client_id=? AND billing_month=? AND billing_type NOT IN ('internal','client_paid') ORDER BY expense_date");
    // Try by client_id first, fallback to client_name
    $stmt->execute([$cid, $month]);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        $cName = $db->prepare("SELECT company_name FROM clients WHERE id=?");
        $cName->execute([$cid]);
        $cn = $cName->fetchColumn();
        $stmt2 = $db->prepare("SELECT id, project_name, expense_category, description, total_billable, cost_amount, currency, exchange_rate, markup_percentage, additional_fee FROM expenses WHERE client_name=? AND billing_month=? AND billing_type NOT IN ('internal','client_paid') ORDER BY expense_date");
        $stmt2->execute([$cn, $month]);
        $rows = $stmt2->fetchAll();
    }
    $out = [];
    foreach ($rows as $r) {
        $origCost = h($r['currency']) . ' ' . number_format($r['cost_amount'], 2);
        $markup   = $r['markup_percentage'] > 0 ? ' + ' . $r['markup_percentage'] . '% markup' : '';
        $addFee   = $r['additional_fee'] > 0 ? ' + service fee ' . $sym . ' ' . number_format($r['additional_fee'], 2) : '';

        // Main desc: "Facebook Ads  |  Ford Mustang EcoBoost 2024 (USD 35.00)"
        $mainDesc = $r['expense_category'];
        if ($r['project_name']) $mainDesc .= '  |  ' . $r['project_name'] . ' (' . $origCost . ')';

        // Sub desc: cost breakdown summary
        $subParts = [];
        $subParts[] = 'Original cost: ' . $origCost;
        if ($r['exchange_rate'] && $r['exchange_rate'] != 1) $subParts[] = '@ ' . number_format($r['exchange_rate'], 2) . ' exchange rate';
        if ($r['markup_percentage'] > 0) $subParts[] = $r['markup_percentage'] . '% service markup';
        if ($r['additional_fee'] > 0) $subParts[] = 'Additional fee: ' . $sym . ' ' . number_format($r['additional_fee'], 2);
        if ($r['description']) $subParts[] = $r['description'];
        $subDesc = implode('  ·  ', $subParts);

        $out[] = [
            'id'      => $r['id'],
            'desc'    => $mainDesc,
            'subdesc' => $subDesc,
            'amount'  => (float)$r['total_billable'],
        ];
    }
    echo json_encode($out);
    exit;
}

// AJAX: suggest line items from a client's most recent invoice (for the "New Invoice" screen)
if ($action === 'get_recent_items') {
    header('Content-Type: application/json');
    $cid       = (int)($_GET['client_id'] ?? 0);
    $excludeId = (int)($_GET['exclude_id'] ?? 0);
    if (!$cid) { echo json_encode(['invoice_number' => null, 'items' => []]); exit; }

    $invStmt = $db->prepare("SELECT id, invoice_number FROM invoices WHERE client_id=? AND id!=? ORDER BY issue_date DESC, id DESC LIMIT 1");
    $invStmt->execute([$cid, $excludeId]);
    $recent = $invStmt->fetch();
    if (!$recent) { echo json_encode(['invoice_number' => null, 'items' => []]); exit; }

    $itemsStmt = $db->prepare("SELECT description, quantity, unit_price FROM invoice_items WHERE invoice_id=? AND item_type='service' ORDER BY sort_order");
    $itemsStmt->execute([$recent['id']]);
    $out = [];
    foreach ($itemsStmt->fetchAll() as $r) {
        $parts = explode('|||', $r['description'], 2);
        $out[] = [
            'desc'    => trim($parts[0]),
            'subdesc' => isset($parts[1]) ? trim($parts[1]) : '',
            'qty'     => (float)$r['quantity'],
            'price'   => (float)$r['unit_price'],
        ];
    }
    echo json_encode(['invoice_number' => $recent['invoice_number'], 'items' => $out]);
    exit;
}

// Generate invoice number
function nextInvoiceNumber($db, $type = 'invoice') {
    $prefix = getSetting($type === 'invoice' ? 'invoice_prefix' : 'quote_prefix', $type === 'invoice' ? 'INV' : 'QUO');
    $year   = date('Y');
    // Base the next number on the highest one actually used, not a row COUNT() —
    // COUNT() drifts out of sync (and collides with an existing number) once any
    // invoice/quotation from this year has ever been deleted.
    $stmt = $db->prepare("SELECT invoice_number FROM invoices WHERE invoice_type=? AND invoice_number LIKE ?");
    $stmt->execute([$type, $prefix.'-'.$year.'-%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $num) {
        $n = (int)substr(strrchr($num, '-'), 1);
        if ($n > $max) $max = $n;
    }
    return $prefix . '-' . $year . '-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
}

// DELETE
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM invoices WHERE id=?")->execute([$id]);
    setFlash('success', 'Deleted.');
    header('Location: ' . SITE_URL . '/invoices.php?tab=' . ($_GET['tab'] ?? 'invoices')); exit;
}

// STATUS UPDATE
if ($action === 'status' && $id) {
    $s = $_GET['s'] ?? 'draft';
    $pd = $s === 'paid' ? date('Y-m-d') : null;
    $db->prepare("UPDATE invoices SET status=?, paid_date=? WHERE id=?")->execute([$s, $pd, $id]);
    setFlash('success', 'Status updated.');
    header('Location: ' . SITE_URL . '/invoices.php?tab=' . ($_GET['tab'] ?? 'invoices')); exit;
}

// SAVE (add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add','edit'])) {
    $d        = $_POST;
    $type     = $d['invoice_type'] ?? 'invoice';
    $invNo    = $action === 'add' ? nextInvoiceNumber($db, $type) : trim($d['invoice_number']);

    // Client handling — quotations can use free-text client
    $customName = trim($d['custom_client_name'] ?? '');
    if ($type === 'quotation' && $customName) {
        // Find or create a client record for this name
        $ex = $db->prepare("SELECT id FROM clients WHERE company_name=?");
        $ex->execute([$customName]);
        $existingId = $ex->fetchColumn();
        if ($existingId) {
            $clientId = $existingId;
        } else {
            $db->prepare("INSERT INTO clients (company_name, status) VALUES (?, 'active')")->execute([$customName]);
            $clientId = $db->lastInsertId();
        }
    } else {
        $clientId = (int)$d['client_id'];
    }
    if (!$clientId) { setFlash('error', 'Please select or enter a client.'); header('Location: '.SITE_URL.'/invoices.php?tab='.$type.'s'); exit; }

    // Invoice currency & exchange rate
    $invCurrency = $d['inv_currency'] ?? 'LKR';
    $invRate     = $invCurrency === 'LKR' ? 1.0 : (float)(getSetting('rate_'.strtolower($invCurrency).'_lkr', '1') ?: 1);

    // Build line items
    $descs    = $d['item_desc']    ?? [];
    $subdescs = $d['item_subdesc'] ?? [];
    $qtys  = $d['item_qty']    ?? [];
    $prices= $d['item_price']  ?? [];
    $types = $d['item_type']   ?? [];
    $expIds= $d['item_exp_id'] ?? [];

    $subtotal = 0;
    $items = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $qty      = (float)($qtys[$i]   ?? 1);
        $price    = (float)($prices[$i] ?? 0); // in selected currency
        $priceLKR = round($price * $invRate, 2);
        $amt      = round($qty * $priceLKR, 2);
        $subtotal += $amt;
        $items[] = [trim($desc), trim($subdescs[$i]??''), $qty, $price, $priceLKR, $amt, $types[$i]??'service', (int)($expIds[$i]??0), $i];
    }

    $discPct = (float)($d['discount_pct'] ?? 0);
    $taxPct  = (float)($d['tax_pct'] ?? 0);
    $discAmt = round($subtotal * $discPct / 100, 2);
    $taxAmt  = round(($subtotal - $discAmt) * $taxPct / 100, 2);
    $total   = round($subtotal - $discAmt + $taxAmt, 2);

    if ($action === 'add') {
        $db->prepare("INSERT INTO invoices (invoice_number,invoice_type,client_id,issue_date,due_date,billing_month,subtotal,discount_pct,discount_amt,tax_pct,tax_amt,total,inv_currency,inv_rate,status,notes,terms,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$invNo,$type,$clientId,$d['issue_date'],$d['due_date']??null,$d['billing_month']??null,$subtotal,$discPct,$discAmt,$taxPct,$taxAmt,$total,$invCurrency,$invRate,$d['status']??'draft',trim($d['notes']??''),trim($d['terms']??''),$_SESSION['full_name']]);
        $invId = $db->lastInsertId();
    } else {
        $db->prepare("UPDATE invoices SET invoice_type=?,client_id=?,issue_date=?,due_date=?,billing_month=?,subtotal=?,discount_pct=?,discount_amt=?,tax_pct=?,tax_amt=?,total=?,inv_currency=?,inv_rate=?,status=?,notes=?,terms=? WHERE id=?")
           ->execute([$type,$clientId,$d['issue_date'],$d['due_date']??null,$d['billing_month']??null,$subtotal,$discPct,$discAmt,$taxPct,$taxAmt,$total,$invCurrency,$invRate,$d['status']??'draft',trim($d['notes']??''),trim($d['terms']??''),$id]);
        $invId = $id;
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$invId]);
    }
    foreach ($items as [$desc,$subdesc,$qty,$foreignPrice,$priceLKR,$amt,$itype,$expId,$ord]) {
        $fullDesc = $desc . ($subdesc ? '|||' . $subdesc : '');
        $db->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,quantity,unit_price,amount,expense_id,sort_order) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$invId,$itype,$fullDesc,$qty,$foreignPrice,$amt,$expId??null,$ord]);
    }
    setFlash('success', $action === 'add' ? "Invoice {$invNo} created." : 'Invoice updated.');
    header('Location: ' . SITE_URL . '/invoices.php?tab=' . ($type === 'quotation' ? 'quotations' : 'invoices')); exit;
}

// Load data
$tab          = $_GET['tab']    ?? 'invoices';
$filter       = $_GET['status'] ?? '';
$filterClient = (int)($_GET['client'] ?? 0);
$dateRange    = $_GET['range']  ?? 'month'; // month | week | lastmonth | custom
$dateFrom     = $_GET['from']   ?? '';
$dateTo       = $_GET['to']     ?? '';
$typeFilter   = $tab === 'quotations' ? 'quotation' : 'invoice';

// Compute date boundaries
$today = date('Y-m-d');
switch ($dateRange) {
    case 'week':
        $df = date('Y-m-d', strtotime('monday this week'));
        $dt = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'lastweek':
        $df = date('Y-m-d', strtotime('monday last week'));
        $dt = date('Y-m-d', strtotime('sunday last week'));
        break;
    case 'lastmonth':
        $df = date('Y-m-01', strtotime('first day of last month'));
        $dt = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'custom':
        $df = $dateFrom ?: date('Y-m-01');
        $dt = $dateTo   ?: $today;
        break;
    case 'all':
        $df = $dt = null;
        break;
    default: // 'month' — current month
        $df = date('Y-m-01');
        $dt = date('Y-m-t');
}

$searchInv    = trim($_GET['search'] ?? '');

$where = ["i.invoice_type=?"]; $params = [$typeFilter];
if ($filter)       { $where[] = 'i.status=?';                          $params[] = $filter; }
if ($filterClient) { $where[] = 'i.client_id=?';                       $params[] = $filterClient; }
if ($df)           { $where[] = 'i.issue_date >= ?';                   $params[] = $df; }
if ($dt)           { $where[] = 'i.issue_date <= ?';                   $params[] = $dt; }
if ($searchInv)    { $where[] = 'i.invoice_number LIKE ?';             $params[] = "%$searchInv%"; }
$wSQL = 'WHERE '.implode(' AND ', $where);

$invoices = $db->prepare("SELECT i.*, c.company_name FROM invoices i JOIN clients c ON c.id=i.client_id {$wSQL} ORDER BY i.issue_date DESC, i.created_at DESC");
$invoices->execute($params);
$invoices = $invoices->fetchAll();

$clients = $db->query("SELECT * FROM clients WHERE status='active' ORDER BY company_name")->fetchAll();

// Stats per type
$stats = $db->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as drafts,
    SUM(CASE WHEN status='sent' THEN total ELSE 0 END) as sent_amt,
    SUM(CASE WHEN status='paid' THEN total ELSE 0 END) as paid_amt,
    SUM(CASE WHEN status='overdue' THEN total ELSE 0 END) as overdue_amt
FROM invoices WHERE invoice_type=?");
$stats->execute([$typeFilter]);
$stats = $stats->fetch();

// Edit
$editInv = null; $editItems = [];
if ($action === 'edit' && $id) {
    $s = $db->prepare("SELECT * FROM invoices WHERE id=?"); $s->execute([$id]); $editInv = $s->fetch();
    $s2 = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order"); $s2->execute([$id]); $editItems = $s2->fetchAll();
}

// Settings
$S = [];
$sRows = $db->query("SELECT setting_key,setting_value FROM settings")->fetchAll();
foreach ($sRows as $r) $S[$r['setting_key']] = $r['setting_value'];
$sym = $S['currency_symbol'] ?? 'Rs.';

$statusColor = ['draft'=>'blue','sent'=>'yellow','paid'=>'green','overdue'=>'red','cancelled'=>'red'];

pageHeader('Invoices');
?>

<!-- Tabs -->
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border)">
  <a href="?tab=invoices" style="padding:10px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='invoices'?'background:var(--accent);color:#fff':'background:var(--bg3);color:var(--text2)' ?>">🧾 Invoices</a>
  <a href="?tab=quotations" style="padding:10px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='quotations'?'background:var(--yellow);color:#000':'background:var(--bg3);color:var(--text2)' ?>">📋 Quotations</a>
</div>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?= $stats['total'] ?></div></div>
  <div class="stat-card blue"><div class="stat-label">Drafts</div><div class="stat-value"><?= $stats['drafts'] ?></div></div>
  <div class="stat-card yellow"><div class="stat-label">Sent / Pending</div><div class="stat-value" style="font-size:18px"><?= $sym ?> <?= number_format($stats['sent_amt'],2) ?></div></div>
  <?php if ($tab === 'invoices'): ?>
  <div class="stat-card green"><div class="stat-label">Paid</div><div class="stat-value" style="font-size:18px"><?= $sym ?> <?= number_format($stats['paid_amt'],2) ?></div></div>
  <div class="stat-card red"><div class="stat-label">Overdue</div><div class="stat-value" style="font-size:18px"><?= $sym ?> <?= number_format($stats['overdue_amt'],2) ?></div></div>
  <?php endif; ?>
</div>

<!-- Filters + Add -->
<?php
$searchInv   = trim($_GET['search'] ?? '');
$activeRange = $dateRange;
?>
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:16px">
  <form method="GET" id="filterForm">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">

    <!-- Invoice search -->
    <div style="margin-bottom:12px">
      <div style="position:relative;max-width:320px">
        <input type="text" name="search" id="invSearch" value="<?= h($searchInv) ?>"
          placeholder="🔍 Search invoice number..."
          style="width:100%;padding-right:36px"
          oninput="liveSearch(this.value)">
        <?php if ($searchInv): ?>
          <a href="?tab=<?= h($tab) ?>&range=<?= h($activeRange) ?>" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text2);text-decoration:none;font-size:16px">×</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick range buttons — each is a plain link, no form conflict -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <?php foreach (['month'=>'This Month','week'=>'This Week','lastweek'=>'Last Week','lastmonth'=>'Last Month','all'=>'All Time'] as $r=>$label): ?>
        <a href="?tab=<?= h($tab) ?>&range=<?= $r ?>&status=<?= h($filter) ?>&client=<?= $filterClient ?>&search=<?= h($searchInv) ?>"
           class="btn btn-sm <?= $activeRange===$r?'btn-primary':'btn-ghost' ?>"
           style="font-size:12px"><?= $label ?></a>
      <?php endforeach; ?>
      <button type="button" onclick="toggleCustomDate()"
        class="btn btn-sm <?= $activeRange==='custom'?'btn-primary':'btn-ghost' ?>"
        style="font-size:12px">📅 Custom</button>
    </div>

    <!-- Custom date range -->
    <div id="customDateWrap" style="display:<?= $activeRange==='custom'?'flex':'none' ?>;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">
      <div class="form-group" style="margin:0">
        <label style="font-size:11px">From</label>
        <input type="date" name="from" value="<?= h($df??'') ?>" style="width:150px">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:11px">To</label>
        <input type="date" name="to" value="<?= h($dt??'') ?>" style="width:150px">
      </div>
      <input type="hidden" name="range" value="custom">
      <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    </div>

    <!-- Status + Client dropdowns -->
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="range" value="<?= $activeRange !== 'custom' ? h($activeRange) : '' ?>">
      <select name="status" style="width:130px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $filter===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="client" style="width:180px" onchange="this.form.submit()">
        <option value="">All Clients</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClient===$c['id']?'selected':'' ?>><?= h($c['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filter || $filterClient || $searchInv || $activeRange !== 'month'): ?>
        <a href="?tab=<?= h($tab) ?>" class="btn btn-ghost btn-sm">✕ Clear All</a>
      <?php endif; ?>
      <span style="font-size:12px;color:var(--text2)">
        <?php if ($df && $dt && $activeRange !== 'all'): ?>
          <?= date('d M Y',strtotime($df)) ?> – <?= date('d M Y',strtotime($dt)) ?> ·
        <?php endif; ?>
        <strong style="color:var(--text)" id="recordCount"><?= count($invoices) ?> records</strong>
      </span>
    </div>
  </form>
</div>

<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
  <?php if ($tab === 'invoices'): ?>
    <a href="<?= SITE_URL ?>/invoice_form.php?type=invoice&tab=invoices" class="btn btn-primary">+ New Invoice</a>
  <?php else: ?>
    <a href="<?= SITE_URL ?>/invoice_form.php?type=quotation&tab=quotations" class="btn btn-primary" style="background:var(--yellow);color:#000">+ New Quotation</a>
  <?php endif; ?>
</div>

<!-- List -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="table-wrap mob-card-table">
    <table id="invoiceTable">
      <thead><tr><th>Number</th><th>Client</th><th>Date</th><?= $tab==='invoices'?'<th>Due</th>':'' ?><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:32px">No <?= $tab ?> found for this period.</td></tr>
        <?php else: foreach ($invoices as $inv): ?>
          <tr>
            <td data-label="Number" class="inv-num-cell"><strong><?= h($inv['invoice_number']) ?></strong></td>
            <td data-label="Client" class="inv-client-cell"><?= h($inv['company_name']) ?></td>
            <td data-label="Date" style="font-size:12px;color:var(--text2)"><?= date('d M Y',strtotime($inv['issue_date'])) ?></td>
            <?php if ($tab === 'invoices'): ?>
            <td data-label="Due" style="font-size:12px;color:<?= $inv['status']==='overdue'?'var(--red)':'var(--text2)' ?>"><?= $inv['due_date'] ? date('d M Y',strtotime($inv['due_date'])) : '—' ?></td>
            <?php endif; ?>
            <td data-label="Total"><strong style="color:var(--green)"><?= $sym ?> <?= number_format($inv['total'],2) ?></strong></td>
            <td data-label="Status">
              <select onchange="window.location='?action=status&id=<?= $inv['id'] ?>&s='+this.value+'&tab=<?= $tab ?>'"
                style="background:transparent;border:none;font-size:12px;font-weight:600;cursor:pointer;color:var(--<?= ['draft'=>'accent','sent'=>'yellow','paid'=>'green','overdue'=>'red','cancelled'=>'text2'][$inv['status']]??'text2' ?>)">
                <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
                  <option value="<?= $st ?>" <?= $inv['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td data-label=""><div class="mob-actions">
              <?php if (!in_array($inv['status'], ['paid','cancelled'])): ?>
              <a href="?action=status&id=<?= $inv['id'] ?>&s=paid&tab=<?= $tab ?>" class="btn btn-success btn-sm" title="Mark as Paid" onclick="return confirm('Mark <?= h($inv['invoice_number']) ?> as paid?')">✓ Paid</a>
              <?php endif; ?>
              <a href="<?= SITE_URL ?>/invoice_form.php?id=<?= $inv['id'] ?>&tab=<?= $tab ?>" class="btn btn-ghost btn-sm">View</a>
              <a href="<?= SITE_URL ?>/invoice_form.php?id=<?= $inv['id'] ?>&edit=1&tab=<?= $tab ?>" class="btn btn-ghost btn-sm">Edit</a>
              <button onclick="openPDF('<?= SITE_URL ?>/invoice_print.php?id=<?= $inv['id'] ?>','<?= h($inv['invoice_number']) ?>')" class="btn btn-primary btn-sm">⬇️ PDF</button>
              <a href="?action=delete&id=<?= $inv['id'] ?>&tab=<?= $tab ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($inv['invoice_number']) ?>?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD/EDIT MODAL -->
<?php
$isEdit = $editInv !== null;
$modalTitle = $isEdit ? 'Edit ' . ucfirst($editInv['invoice_type']) : 'New Invoice / Quotation';
$defTerms = $S['invoice_terms'] ?? 'Payment due within 30 days of invoice date.';
$defNotes = $S['invoice_notes'] ?? 'Thank you for your business.';
?>
<div class="modal-overlay <?= $isEdit?'open':'' ?>" id="invoiceModal">
  <div class="modal" style="max-width:720px">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle"><?= $modalTitle ?></div>
      <button class="modal-close" onclick="<?= $isEdit ? "window.location='".SITE_URL."/invoices.php'" : "closeModal('invoiceModal')" ?>">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=<?= $isEdit?'edit':'add' ?><?= $isEdit?"&id={$editInv['id']}":'' ?>" id="invoiceForm">
        <?php if ($isEdit): ?><input type="hidden" name="invoice_number" value="<?= h($editInv['invoice_number']) ?>"><?php endif; ?>

        <div class="form-grid" style="margin-bottom:16px">
          <div class="form-group">
            <label>Type</label>
            <select name="invoice_type" id="invType" onchange="toggleClientMode()">
              <option value="invoice" <?= (!$isEdit||$editInv['invoice_type']==='invoice')?'selected':'' ?>>Invoice</option>
              <option value="quotation" <?= ($isEdit&&$editInv['invoice_type']==='quotation')?'selected':'' ?>>Quotation</option>
            </select>
          </div>
          <!-- Registered client (invoices) -->
          <div class="form-group" id="clientSelectWrap">
            <label>Client *</label>
            <select name="client_id" id="invClient" onchange="loadExpenses()">
              <option value="">— Select Client —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= $c['id'] ?>" data-currency="<?= h($c['default_currency']??'LKR') ?>" <?= ($isEdit&&$editInv['client_id']==$c['id'])?'selected':'' ?>><?= h($c['company_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <!-- Free-text client (quotations) -->
          <div class="form-group" id="clientFreeWrap" style="display:none">
            <label>Client Name *</label>
            <input type="text" name="custom_client_name" id="customClientName"
              placeholder="Enter client / company name"
              value="<?= ($isEdit && $editInv['invoice_type']==='quotation') ? h($editInv['company_name']) : '' ?>">
            <span style="font-size:11px;color:var(--text2);margin-top:3px">Free-text — no need to be a registered client</span>
          </div>
          <div class="form-group">
            <label>Issue Date *</label>
            <input type="date" name="issue_date" required value="<?= $isEdit?h($editInv['issue_date']):date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label>Valid Until</label>
            <input type="date" name="due_date" value="<?= $isEdit?h($editInv['due_date']??''):date('Y-m-d', strtotime('+12 days')) ?>">
          </div>
          <div class="form-group" id="billingMonthWrap">
            <label>Billing Month <span style="color:var(--text2);font-weight:400">(for expense import)</span></label>
            <input type="month" name="billing_month" id="invBillingMonth" value="<?= $isEdit?h($editInv['billing_month']??''):date('Y-m') ?>">
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status">
              <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= ($isEdit&&$editInv['status']===$st)?'selected':'' ?>><?= ucfirst($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Invoice Currency</label>
            <select name="inv_currency" id="invCurrency" onchange="updateCurrencyRate()">
              <option value="LKR" <?= (!$isEdit||($editInv['inv_currency']??'LKR')==='LKR')?'selected':'' ?>>LKR — Sri Lankan Rupee</option>
              <option value="USD" <?= ($isEdit&&($editInv['inv_currency']??'')==='USD')?'selected':'' ?>>USD — US Dollar</option>
              <option value="AUD" <?= ($isEdit&&($editInv['inv_currency']??'')==='AUD')?'selected':'' ?>>AUD — Australian Dollar</option>
              <option value="EUR" <?= ($isEdit&&($editInv['inv_currency']??'')==='EUR')?'selected':'' ?>>EUR — Euro</option>
              <option value="GBP" <?= ($isEdit&&($editInv['inv_currency']??'')==='GBP')?'selected':'' ?>>GBP — British Pound</option>
              <option value="SGD" <?= ($isEdit&&($editInv['inv_currency']??'')==='SGD')?'selected':'' ?>>SGD — Singapore Dollar</option>
            </select>
          </div>
          <div class="form-group" id="rateInfoWrap" style="display:none">
            <label>Exchange Rate (to LKR)</label>
            <div id="rateInfo" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);border-radius:7px;padding:9px 12px;font-size:13px;color:var(--accent)">
              1 USD = <strong id="rateDisplay">—</strong> LKR &nbsp;<span style="color:var(--text2);font-size:11px">(from Settings → Exchange Rates)</span>
            </div>
          </div>
        </div>

        <!-- Line Items -->
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <label style="font-size:13px;font-weight:600;color:var(--text)">Line Items</label>
            <div style="display:flex;gap:8px">
              <button type="button" onclick="addItem()" class="btn btn-ghost btn-sm">+ Add Line</button>
              <button type="button" onclick="importExpenses()" class="btn btn-ghost btn-sm" id="importBtn" style="display:none">📥 Sync Expenses</button>
            </div>
          </div>
          <div id="syncNotice" style="display:none;background:rgba(0,196,140,.1);border:1px solid rgba(0,196,140,.3);color:var(--green);padding:8px 12px;border-radius:6px;font-size:12.5px;margin-bottom:8px"></div>
          <div id="lineItems" style="display:flex;flex-direction:column;gap:6px">
            <?php if ($isEdit && !empty($editItems)): ?>
              <?php
              // Sort: service items first, expense items last
              usort($editItems, fn($a,$b) => ($a['item_type']==='expense'?1:0) - ($b['item_type']==='expense'?1:0));
              $hasExpItems = array_filter($editItems, fn($x) => $x['item_type']==='expense');
              $shownSep = false;
              ?>
              <?php foreach ($editItems as $i => $item): ?>
              <?php $isExp = $item['item_type'] === 'expense'; ?>
              <?php if ($isExp && !$shownSep): $shownSep=true; ?>
              <div style="display:flex;align-items:center;gap:8px;margin:6px 0;padding:0 4px">
                <div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div>
                <span style="font-size:11px;color:var(--accent);font-weight:600;white-space:nowrap">📥 AUTO-SYNCED EXPENSES</span>
                <div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div>
              </div>
              <?php endif; ?>
              <div class="line-item <?= $isExp?'locked-expense':'' ?>" style="display:grid;grid-template-columns:1fr auto auto auto;gap:6px;align-items:center<?= $isExp?';background:rgba(59,130,246,.04);border-radius:7px;padding:6px 8px;border:1px solid rgba(59,130,246,.15)':'' ?>">
                <input type="hidden" name="item_type[]" value="<?= h($item['item_type']) ?>">
                <input type="hidden" name="item_exp_id[]" value="<?= $item['expense_id'] ?>">
<?php
  $descParts = explode('|||', $item['description'], 2);
  $iMainDesc = trim($descParts[0]);
  $iSubDesc  = isset($descParts[1]) ? trim($descParts[1]) : '';
?>
                <div style="display:flex;flex-direction:column;gap:4px">
                  <div style="display:flex;align-items:center;gap:4px">
                    <?php if ($isExp): ?><span style="font-size:13px">🔒</span><?php endif; ?>
                    <input name="item_desc[]" value="<?= h(trim($iMainDesc)) ?>" placeholder="Service / Item name" style="font-size:13px;font-weight:600<?= $isExp?';pointer-events:none;opacity:.85;background:transparent;border-color:transparent':'' ?>" <?= $isExp?'readonly':'' ?>>
                  </div>
                  <input name="item_subdesc[]" value="<?= h(trim($iSubDesc)) ?>" placeholder="Description of work (optional)" style="font-size:12px<?= $isExp?';pointer-events:none;opacity:.7;background:transparent;border-color:transparent':'' ?>" <?= $isExp?'readonly':'' ?>>
                </div>
                <input name="item_qty[]" type="number" step="0.01" value="<?= $item['quantity'] ?>" style="width:65px;font-size:13px<?= $isExp?';pointer-events:none;opacity:.8;background:transparent;border-color:transparent':'' ?>" <?= $isExp?'readonly':'' ?> <?= !$isExp?'oninput="calcLine(this)"':'' ?>>
                <input name="item_price[]" type="number" step="0.01" value="<?= $item['unit_price'] ?>" style="width:110px;font-size:13px<?= $isExp?';pointer-events:none;opacity:.8;background:transparent;border-color:transparent':'' ?>" <?= $isExp?'readonly':'' ?> <?= !$isExp?'oninput="calcLine(this)"':'' ?>>
                <div style="display:flex;align-items:center;gap:4px">
                  <span class="item-amt" style="width:100px;font-size:13px;font-weight:600;color:var(--<?= $isExp?'accent':'green' ?>);text-align:right"><?= $sym ?> <?= number_format($item['amount'],2) ?></span>
                  <?php if ($isExp): ?>
                    <span style="width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text2)">🔒</span>
                  <?php else: ?>
                    <button type="button" onclick="this.closest('.line-item').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px">×</button>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <!-- Default empty line -->
              <div class="line-item" style="display:grid;grid-template-columns:1fr auto auto auto;gap:6px;align-items:center">
                <input type="hidden" name="item_type[]" value="service">
                <input type="hidden" name="item_exp_id[]" value="0">
                <div style="display:flex;flex-direction:column;gap:4px">
                  <input name="item_desc[]" placeholder="Service / Item name" style="font-size:13px;font-weight:600">
                  <input name="item_subdesc[]" placeholder="Description of work (optional)" style="font-size:12px;color:var(--text2)">
                </div>
                <input name="item_qty[]" type="number" step="0.01" value="1" style="width:65px;font-size:13px" oninput="calcLine(this)">
                <input name="item_price[]" type="number" step="0.01" value="0" style="width:110px;font-size:13px" oninput="calcLine(this)">
                <div style="display:flex;align-items:center;gap:4px">
                  <span class="item-amt" style="width:100px;font-size:13px;font-weight:600;color:var(--green);text-align:right"><?= $sym ?> 0.00</span>
                  <button type="button" onclick="this.closest('.line-item').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px">×</button>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Totals -->
          <div style="margin-top:14px;padding:14px;background:var(--bg3);border-radius:8px">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
              <div class="form-group" style="margin:0">
                <label>Discount %</label>
                <input type="number" name="discount_pct" id="discPct" step="0.01" value="<?= $isEdit?h($editInv['discount_pct']):0 ?>" oninput="calcTotals()">
              </div>
              <div class="form-group" style="margin:0">
                <label>Tax / VAT %</label>
                <input type="number" name="tax_pct" id="taxPct" step="0.01" value="<?= $isEdit?h($editInv['tax_pct']):0 ?>" oninput="calcTotals()">
              </div>
            </div>
            <div style="text-align:right;font-size:13px;display:flex;flex-direction:column;gap:4px">
              <div>Subtotal: <strong id="dispSubtotal"><?= $sym ?> 0.00</strong></div>
              <div id="discRow" style="color:var(--red)">Discount: <strong id="dispDiscount"><?= $sym ?> 0.00</strong></div>
              <div id="taxRow">Tax: <strong id="dispTax"><?= $sym ?> 0.00</strong></div>
              <div style="font-size:16px;font-weight:800;color:var(--green);margin-top:6px;padding-top:6px;border-top:1px solid var(--border)">Total: <strong id="dispTotal"><?= $sym ?> 0.00</strong></div>
            </div>
          </div>
        </div>

        <div class="form-grid cols-1" style="margin-top:12px">
          <div class="form-group"><label>Terms & Conditions</label><textarea name="terms" rows="2"><?= $isEdit?h($editInv['terms']??$defTerms):$defTerms ?></textarea></div>
          <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"><?= $isEdit?h($editInv['notes']??$defNotes):$defNotes ?></textarea></div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><?= $isEdit?'Save Changes':'Create '.($isEdit?ucfirst($editInv['invoice_type']):'Invoice') ?></button>
          <button type="button" class="btn btn-ghost" onclick="<?= $isEdit?"window.location='".SITE_URL."/invoices.php'":'closeModal("invoiceModal")' ?>">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const sym = '<?= $sym ?>';
const siteUrl = '<?= SITE_URL ?>';
const rates = {
    'LKR': 1,
    'USD': <?= (float)(getSetting('rate_usd_lkr','1') ?: 1) ?>,
    'AUD': <?= (float)(getSetting('rate_aud_lkr','1') ?: 1) ?>,
    'EUR': <?= (float)(getSetting('rate_eur_lkr','1') ?: 1) ?>,
    'GBP': <?= (float)(getSetting('rate_gbp_lkr','1') ?: 1) ?>,
    'SGD': <?= (float)(getSetting('rate_sgd_lkr','1') ?: 1) ?>,
};
const currencySymbols = { 'LKR':'Rs.', 'USD':'$', 'AUD':'A$', 'EUR':'€', 'GBP':'£', 'SGD':'S$' };

function getRate() {
    const cur = document.getElementById('invCurrency')?.value || 'LKR';
    return rates[cur] || 1;
}
function getInvCurrency() {
    return document.getElementById('invCurrency')?.value || 'LKR';
}

function updateCurrencyRate() {
    const cur = getInvCurrency();
    const wrap = document.getElementById('rateInfoWrap');
    const display = document.getElementById('rateDisplay');
    const rateInfo = document.getElementById('rateInfo');
    if (cur === 'LKR') {
        if (wrap) wrap.style.display = 'none';
    } else {
        if (wrap) wrap.style.display = '';
        if (display) display.textContent = rates[cur]?.toLocaleString('en', {minimumFractionDigits:2}) || '—';
        if (rateInfo) rateInfo.querySelector('strong') && (rateInfo.innerHTML = `1 ${cur} = <strong id="rateDisplay">${(rates[cur]||1).toLocaleString('en',{minimumFractionDigits:2})}</strong> LKR &nbsp;<span style="color:var(--text2);font-size:11px">(from Settings → Exchange Rates)</span>`);
    }
    // Update all line item amount displays
    document.querySelectorAll('#lineItems .line-item').forEach(row => {
        if (!row.querySelector('[name="item_type[]"]')?.value === 'expense') calcLine(row.querySelector('[name="item_qty[]"]'));
    });
    calcTotals();
}

function toggleClientMode() {
    const type = document.getElementById('invType').value;
    const isQuote = type === 'quotation';
    const selWrap  = document.getElementById('clientSelectWrap');
    const freeWrap = document.getElementById('clientFreeWrap');
    const bmWrap   = document.getElementById('billingMonthWrap');
    const sel      = document.getElementById('invClient');
    const free     = document.getElementById('customClientName');
    if (isQuote) {
        selWrap.style.display  = 'none';
        freeWrap.style.display = '';
        bmWrap.style.display   = 'none';
        sel.removeAttribute('required');
        if (free) free.setAttribute('required','required');
    } else {
        selWrap.style.display  = '';
        freeWrap.style.display = 'none';
        bmWrap.style.display   = '';
        if (free) free.removeAttribute('required');
    }
    loadExpenses();
}

function openInvoiceModal(type) {
    document.getElementById('invType').value = type;
    document.getElementById('modalTitle').textContent = 'New ' + (type === 'invoice' ? 'Invoice' : 'Quotation');
    toggleClientMode();
    openModal('invoiceModal');
}

function addItem(desc='', qty=1, price=0, type='service', expId=0, subdesc='') {
    const isExpense = type === 'expense';
    const amt = (qty * price).toFixed(2);
    const div = document.createElement('div');
    div.className = 'line-item' + (isExpense ? ' locked-expense' : '');
    div.style.cssText = 'display:grid;grid-template-columns:1fr auto auto auto;gap:6px;align-items:center' + (isExpense ? ';background:rgba(59,130,246,.04);border-radius:7px;padding:6px 8px;border:1px solid rgba(59,130,246,.15)' : '');
    const lockIcon = isExpense ? '<span title="Auto-synced — locked" style="font-size:14px;margin-right:4px">🔒</span>' : '';
    div.innerHTML = `
        <input type="hidden" name="item_type[]" value="${type}">
        <input type="hidden" name="item_exp_id[]" value="${expId}">
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0">
            <div style="display:flex;align-items:center;gap:4px">
                ${lockIcon}
                <input name="item_desc[]" placeholder="Service / Item name" value="${desc}" style="font-size:13px;font-weight:600;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''}>
            </div>
            <input name="item_subdesc[]" placeholder="Description of work (optional)" value="${subdesc}" style="font-size:12px;${isExpense?'pointer-events:none;opacity:.7;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''}>
        </div>
        <input name="item_qty[]" type="number" step="0.01" value="${qty}" style="width:65px;font-size:13px;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''} oninput="${isExpense?'':' calcLine(this)'}">
        <input name="item_price[]" type="number" step="0.01" value="${price}" style="width:110px;font-size:13px;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''} oninput="${isExpense?'':' calcLine(this)'}">
        <div style="display:flex;align-items:center;gap:4px">
            <span class="item-amt" style="width:100px;font-size:13px;font-weight:600;color:var(--${isExpense?'accent':'green'});text-align:right">${sym} ${parseFloat(amt).toLocaleString('en',{minimumFractionDigits:2})}</span>
            ${isExpense
                ? '<span title="Auto-synced from Expenses" style="width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text2)">🔒</span>'
                : '<button type="button" onclick="this.closest(\'.line-item\').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px">×</button>'
            }
        </div>`;

    // Expenses always go to bottom, with a separator if needed
    if (isExpense) {
        let sep = document.getElementById('expenseSeparator');
        if (!sep) {
            sep = document.createElement('div');
            sep.id = 'expenseSeparator';
            sep.style.cssText = 'grid-column:1/-1;display:flex;align-items:center;gap:8px;margin:6px 0';
            sep.innerHTML = '<div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div><span style="font-size:11px;color:var(--accent);font-weight:600;white-space:nowrap">📥 AUTO-SYNCED EXPENSES</span><div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div>';
            // Insert separator as a wrapper
            const sepWrap = document.createElement('div');
            sepWrap.id = 'expenseSeparator';
            sepWrap.style.cssText = 'display:flex;align-items:center;gap:8px;margin:6px 0;padding:0 4px';
            sepWrap.innerHTML = sep.innerHTML;
            document.getElementById('lineItems').appendChild(sepWrap);
        }
        document.getElementById('lineItems').appendChild(div);
    } else {
        // Service items go before the separator
        const sep = document.getElementById('expenseSeparator');
        if (sep) {
            document.getElementById('lineItems').insertBefore(div, sep);
        } else {
            document.getElementById('lineItems').appendChild(div);
        }
    }
    calcTotals();
}

function calcLine(input) {
    const row   = input.closest('.line-item');
    if (!row) return;
    const qty   = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
    const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
    const rate  = getRate();
    const cur   = getInvCurrency();
    const amtLKR = qty * price * rate;
    const display = cur === 'LKR'
        ? `${sym} ${amtLKR.toLocaleString('en',{minimumFractionDigits:2})}`
        : `${sym} ${amtLKR.toLocaleString('en',{minimumFractionDigits:2})} <span style="font-size:10px;color:var(--text2)">(${currencySymbols[cur]||cur} ${(qty*price).toLocaleString('en',{minimumFractionDigits:2})})</span>`;
    row.querySelector('.item-amt').innerHTML = display;
    calcTotals();
}

function calcTotals() {
    const rate = getRate();
    const cur  = getInvCurrency();
    let sub = 0;
    document.querySelectorAll('#lineItems .line-item').forEach(row => {
        const qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]')?.value) || 0;
        const type  = row.querySelector('[name="item_type[]"]')?.value || 'service';
        // Expense items are already in LKR, service items need conversion
        const amtLKR = type === 'expense' ? qty * price : qty * price * rate;
        sub += amtLKR;
    });
    const discPct = parseFloat(document.getElementById('discPct').value) || 0;
    const taxPct  = parseFloat(document.getElementById('taxPct').value) || 0;
    const disc = sub * discPct / 100;
    const tax  = (sub - disc) * taxPct / 100;
    const total = sub - disc + tax;
    const fmt = n => `${sym} ${n.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    const fmtFx = n => cur !== 'LKR' ? ` <span style="font-size:11px;color:var(--text2)">(${currencySymbols[cur]||cur} ${(n/rate).toLocaleString('en',{minimumFractionDigits:2})})</span>` : '';
    document.getElementById('dispSubtotal').innerHTML = fmt(sub) + fmtFx(sub);
    document.getElementById('dispDiscount').innerHTML = fmt(disc);
    document.getElementById('dispTax').innerHTML      = fmt(tax);
    document.getElementById('dispTotal').innerHTML    = fmt(total) + fmtFx(total);
    document.getElementById('discRow').style.display = discPct > 0 ? '' : 'none';
    document.getElementById('taxRow').style.display  = taxPct  > 0 ? '' : 'none';
}

function loadExpenses() {
    const clientSel = document.getElementById('invClient');
    const clientId  = clientSel?.value;
    document.getElementById('importBtn').style.display = clientId ? '' : 'none';
    // Auto-set currency from client's default
    if (clientId && clientSel) {
        const opt = clientSel.options[clientSel.selectedIndex];
        const clientCur = opt?.getAttribute('data-currency') || 'LKR';
        const curSel = document.getElementById('invCurrency');
        if (curSel && curSel.value === 'LKR') { // only auto-set if not already changed
            curSel.value = clientCur;
            updateCurrencyRate();
        }
    }
    autoSyncIfReady();
}

function autoSyncIfReady() {
    const clientId = document.getElementById('invClient').value;
    const month    = document.getElementById('invBillingMonth').value;
    if (!clientId || !month) return;
    // Only auto-sync if no items have a price yet (fresh invoice)
    const hasItems = [...document.querySelectorAll('#lineItems [name="item_price[]"]')]
        .some(i => parseFloat(i.value) > 0);
    if (hasItems) return; // don't overwrite existing items
    syncExpenses(clientId, month, false);
}

function importExpenses() {
    const clientId = document.getElementById('invClient').value;
    const month    = document.getElementById('invBillingMonth').value;
    if (!clientId || !month) { alert('Please select a client and billing month first.'); return; }
    if (document.querySelectorAll('#lineItems .line-item').length > 1 ||
        parseFloat(document.querySelector('#lineItems [name="item_price[]"]')?.value) > 0) {
        if (!confirm('This will add expense items to your existing line items. Continue?')) return;
    }
    syncExpenses(clientId, month, true);
}

function syncExpenses(clientId, month, showAlert) {
    const btn = document.getElementById('importBtn');
    if (btn) { btn.textContent = '⏳ Loading...'; btn.disabled = true; }
    fetch(`${siteUrl}/invoices.php?action=get_expenses&client_id=${clientId}&month=${month}`)
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.textContent = '📥 Sync Expenses'; btn.disabled = false; }
            if (!data.length) {
                if (showAlert) alert('No expenses found for this client and month.');
                return;
            }
            // Remove existing expense-type items to avoid duplicates
            document.querySelectorAll('#lineItems .line-item').forEach(row => {
                const typeInput = row.querySelector('[name="item_type[]"]');
                if (typeInput && typeInput.value === 'expense') row.remove();
            });
            // Add synced expenses
            data.forEach(e => addItem(e.desc, 1, e.amount, 'expense', e.id, e.subdesc || ''));
            // Show sync notice
            const notice = document.getElementById('syncNotice');
            if (notice) {
                notice.textContent = `✅ ${data.length} expense${data.length>1?'s':''} synced from Expenses & Client Rebilling`;
                notice.style.display = 'block';
                setTimeout(() => notice.style.display = 'none', 4000);
            }
        })
        .catch(() => {
            if (btn) { btn.textContent = '📥 Sync Expenses'; btn.disabled = false; }
        });
}

// AJAX expense loader — auto-sync when month changes too
document.addEventListener('DOMContentLoaded', () => {
    toggleClientMode();
    updateCurrencyRate();
    loadExpenses();
    calcTotals();
    const monthInput = document.getElementById('invBillingMonth');
    if (monthInput) monthInput.addEventListener('change', autoSyncIfReady);
});
</script>

<script>
function toggleCustomDate() {
    const wrap = document.getElementById('customDateWrap');
    wrap.style.display = wrap.style.display === 'none' ? 'flex' : 'none';
}

function liveSearch(val) {
    // Client-side instant filter on invoice number column
    const rows = document.querySelectorAll('#invoiceTable tbody tr');
    const q = val.toLowerCase();
    rows.forEach(row => {
        const num = row.querySelector('.inv-num-cell')?.textContent?.toLowerCase() || '';
        const client = row.querySelector('.inv-client-cell')?.textContent?.toLowerCase() || '';
        row.style.display = (!q || num.includes(q) || client.includes(q)) ? '' : 'none';
    });
    // Show count
    const visible = [...rows].filter(r => r.style.display !== 'none').length;
    const counter = document.getElementById('recordCount');
    if (counter) counter.textContent = visible + ' records';
}

function openPDF(url, invNo) {
    const w = window.open(url,'_blank');
    if (w) w.addEventListener('load', ()=>{ w.document.title=invNo; setTimeout(()=>w.print(),300); });
}
</script>

<?php pageFooter(); ?>
