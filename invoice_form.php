<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$id     = (int)($_GET['id'] ?? 0);
$mode   = $id ? 'edit' : 'new';
$tab    = $_GET['tab'] ?? 'invoices';
$type   = $_GET['type'] ?? 'invoice';

// Load settings
$S = [];
foreach ($db->query("SELECT setting_key,setting_value FROM settings")->fetchAll() as $r) $S[$r['setting_key']] = $r['setting_value'];
$sym = $S['currency_symbol'] ?? 'Rs.';

function nextInvNo($db, $type) {
    $prefix = getSetting($type==='invoice'?'invoice_prefix':'quote_prefix', $type==='invoice'?'INV':'QUO');
    $year   = date('Y');
    $count  = $db->query("SELECT COUNT(*) FROM invoices WHERE invoice_type='{$type}' AND YEAR(issue_date)={$year}")->fetchColumn();
    return $prefix.'-'.$year.'-'.str_pad($count+1,4,'0',STR_PAD_LEFT);
}

// ── ACTIONS ───────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM invoices WHERE id=?")->execute([$id]);
    setFlash('success','Invoice deleted.');
    header('Location:'.SITE_URL.'/invoices.php?tab='.$tab); exit;
}

if ($action === 'status' && $id) {
    $s = $_GET['s'] ?? 'draft';
    $pd = $s==='paid' ? date('Y-m-d') : null;
    $db->prepare("UPDATE invoices SET status=?,paid_date=? WHERE id=?")->execute([$s,$pd,$id]);
    header('Location:'.SITE_URL.'/invoice_form.php?id='.$id.'&tab='.$tab); exit;
}

// ── SAVE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['save','save_new'])) {
    $d = $_POST;
    $invType     = $d['invoice_type'] ?? $type;
    $invNo       = $id ? trim($d['invoice_number']) : nextInvNo($db, $invType);
    $invCurrency = $d['inv_currency'] ?? 'LKR';
    $invRate     = $invCurrency==='LKR' ? 1.0 : (float)(getSetting('rate_'.strtolower($invCurrency).'_lkr','1') ?: 1);

    // Client handling
    $clientMode = $d['client_mode'] ?? 'existing';
    if ($clientMode === 'manual') {
        // Manual client — store details as JSON on the invoice, use a temp client record
        $manualName    = trim($d['manual_company'] ?? 'One-Time Client');
        $manualContact = trim($d['manual_contact'] ?? '');
        $manualEmail   = trim($d['manual_email']   ?? '');
        $manualPhone   = trim($d['manual_phone']   ?? '');
        $manualAddr    = trim($d['manual_address']  ?? '');
        // Check if a temp client already exists for this name
        $ex = $db->prepare("SELECT id FROM clients WHERE company_name=? AND status='temp'");
        $ex->execute([$manualName]);
        $cid = $ex->fetchColumn();
        if (!$cid) {
            $db->prepare("INSERT INTO clients (company_name,contact_name,email,phone,address,status) VALUES (?,?,?,?,?,'temp')")
               ->execute([$manualName,$manualContact,$manualEmail,$manualPhone,$manualAddr]);
            $cid = $db->lastInsertId();
        } else {
            // Update temp client details
            $db->prepare("UPDATE clients SET contact_name=?,email=?,phone=?,address=? WHERE id=?")
               ->execute([$manualContact,$manualEmail,$manualPhone,$manualAddr,$cid]);
        }
        $manualJson = json_encode(['company'=>$manualName,'contact'=>$manualContact,'email'=>$manualEmail,'phone'=>$manualPhone,'address'=>$manualAddr]);
    } else {
        $cid = (int)$d['client_id'];
        $manualJson = null;
    }
    if (!$cid) { setFlash('error','Please select or enter a client.'); header('Location:'.$_SERVER['REQUEST_URI']); exit; }

    // Line items
    $descs    = $d['item_desc']    ?? [];
    $subdescs = $d['item_subdesc'] ?? [];
    $qtys     = $d['item_qty']     ?? [];
    $prices   = $d['item_price']   ?? [];
    $types    = $d['item_type']    ?? [];
    $expIds   = $d['item_exp_id']  ?? [];

    $subtotal = 0; $items = [];
    foreach ($descs as $i => $desc) {
        if (!trim($desc)) continue;
        $qty   = (float)($qtys[$i]??1);
        $price = (float)($prices[$i]??0);
        $itype = $types[$i]??'service';
        $amtLKR = $itype==='expense' ? $qty*$price : round($qty*$price*$invRate,2);
        $subtotal += $amtLKR;
        $items[] = [trim($desc),trim($subdescs[$i]??''),$qty,$price,$amtLKR,$itype,(int)($expIds[$i]??0),$i];
    }
    $discPct = (float)($d['discount_pct']??0);
    $taxPct  = (float)($d['tax_pct']??0);
    $discAmt = round($subtotal*$discPct/100,2);
    $taxAmt  = round(($subtotal-$discAmt)*$taxPct/100,2);
    $total   = round($subtotal-$discAmt+$taxAmt,2);

    if ($id) {
        $db->prepare("UPDATE invoices SET invoice_type=?,client_id=?,issue_date=?,due_date=?,billing_month=?,subtotal=?,discount_pct=?,discount_amt=?,tax_pct=?,tax_amt=?,total=?,inv_currency=?,inv_rate=?,status=?,notes=?,terms=?,manual_client_data=? WHERE id=?")
           ->execute([$invType,$cid,$d['issue_date'],$d['due_date']??null,$d['billing_month']??null,$subtotal,$discPct,$discAmt,$taxPct,$taxAmt,$total,$invCurrency,$invRate,$d['status']??'draft',trim($d['notes']??''),trim($d['terms']??''),$manualJson,$id]);
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);
        $invId = $id;
    } else {
        $db->prepare("INSERT INTO invoices (invoice_number,invoice_type,client_id,issue_date,due_date,billing_month,subtotal,discount_pct,discount_amt,tax_pct,tax_amt,total,inv_currency,inv_rate,status,notes,terms,created_by,manual_client_data) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$invNo,$invType,$cid,$d['issue_date'],$d['due_date']??null,$d['billing_month']??null,$subtotal,$discPct,$discAmt,$taxPct,$taxAmt,$total,$invCurrency,$invRate,$d['status']??'draft',trim($d['notes']??''),trim($d['terms']??''),$_SESSION['full_name'],$manualJson]);
        $invId = $db->lastInsertId();
    }
    foreach ($items as [$desc,$subdesc,$qty,$price,$amtLKR,$itype,$expId,$ord]) {
        $db->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,quantity,unit_price,amount,expense_id,sort_order) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$invId,$itype,$desc.($subdesc?'|||'.$subdesc:''),$qty,$price,$amtLKR,$expId??null,$ord]);
    }
    setFlash('success', $id ? 'Invoice updated.' : "Invoice {$invNo} created.");
    header('Location:'.SITE_URL.'/invoice_form.php?id='.$invId.'&tab='.$tab); exit;
}

// ── LOAD ──────────────────────────────────────────────────
$inv = null; $invItems = [];
if ($id) {
    $s = $db->prepare("SELECT i.*,c.company_name,c.contact_name,c.email as c_email,c.phone as c_phone,c.address,c.address_line2,c.city,c.country,c.vat_number,c.default_currency FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
    $s->execute([$id]); $inv = $s->fetch();
    if (!$inv) { header('Location:'.SITE_URL.'/invoices.php'); exit; }
    $s2 = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
    $s2->execute([$id]); $invItems = $s2->fetchAll();
    $type = $inv['invoice_type'];
}

$clients  = $db->query("SELECT id,company_name,default_currency FROM clients WHERE status='active' ORDER BY company_name")->fetchAll();
$isQuote  = $type==='quotation';
$pageTitle = $id ? ($isQuote?'Quotation':'Invoice').' '.$inv['invoice_number'] : 'New '.($isQuote?'Quotation':'Invoice');
$currencies = ['LKR','USD','AUD','EUR','GBP','SGD'];
$curSymbols = ['LKR'=>'Rs.','USD'=>'$','AUD'=>'A$','EUR'=>'€','GBP'=>'£','SGD'=>'S$'];

// Build client currency map for JS
$clientCurrencies = [];
foreach ($clients as $cl) $clientCurrencies[$cl['id']] = $cl['default_currency'] ?? 'LKR';

$defTerms = $S['invoice_terms'] ?? 'Payment due within 30 days.';
$defNotes = $S['invoice_notes'] ?? 'Thank you for your business.';
$statusColors = ['draft'=>'blue','sent'=>'yellow','paid'=>'green','overdue'=>'red','cancelled'=>'red'];

pageHeader($pageTitle);
?>

<style>
.inv-page { max-width:960px; margin:0 auto; }
.inv-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.inv-toolbar-left { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.inv-toolbar-right { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.inv-header-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.inv-section { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:16px; }
.inv-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text2); margin-bottom:14px; }
.line-items-table { width:100%; border-collapse:collapse; }
.line-items-table th { background:var(--bg3); padding:8px 10px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--text2); }
.line-items-table th.r { text-align:right; }
.line-item-row td { padding:6px 4px; border-bottom:1px solid rgba(255,255,255,.04); vertical-align:middle; }
.line-item-row:last-child td { border-bottom:none; }
.li-desc { width:100%; }
.li-desc-main { width:100%; background:var(--bg3); border:1px solid var(--border); color:var(--text); padding:7px 10px; border-radius:6px; font-family:Poppins,sans-serif; font-size:13px; font-weight:600; }
.li-desc-sub { width:100%; background:transparent; border:none; border-bottom:1px solid rgba(255,255,255,.06); color:var(--text2); padding:4px 10px; font-family:Poppins,sans-serif; font-size:12px; margin-top:3px; }
.li-desc-sub:focus { outline:none; border-bottom-color:var(--accent); }
.li-desc-main:focus { outline:none; border-color:var(--accent); }
.li-num { background:var(--bg3); border:1px solid var(--border); color:var(--text); padding:7px 8px; border-radius:6px; font-family:Poppins,sans-serif; font-size:13px; text-align:right; width:100%; }
.li-num:focus { outline:none; border-color:var(--accent); }
.li-amt { font-weight:700; font-size:13px; color:var(--green); text-align:right; white-space:nowrap; padding:0 8px; }
.li-amt.expense-amt { color:var(--accent); }
.lock-row { background:rgba(59,130,246,.04); border:1px solid rgba(59,130,246,.15); border-radius:8px; }
.totals-grid { display:flex; flex-direction:column; gap:6px; max-width:320px; margin-left:auto; }
.total-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; font-size:13px; }
.total-row.big { font-size:18px; font-weight:800; padding-top:10px; border-top:2px solid var(--border); color:var(--green); }
.total-row.big span:first-child { color:var(--text); }
.rate-badge { background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.2); border-radius:6px; padding:6px 12px; font-size:12px; color:var(--accent); margin-bottom:14px; }
.sep-line { display:flex; align-items:center; gap:8px; margin:8px 0; }
.sep-line div { flex:1; height:1px; background:rgba(59,130,246,.3); }
.sep-line span { font-size:11px; color:var(--accent); font-weight:600; white-space:nowrap; }
.status-bar { display:flex; align-items:center; gap:8px; padding:10px 16px; background:var(--bg3); border-radius:8px; margin-bottom:16px; flex-wrap:wrap; }
.view-only input, .view-only select, .view-only textarea { pointer-events:none; opacity:.8; }
.view-only .li-desc-main, .view-only .li-desc-sub, .view-only .li-num { pointer-events:none; opacity:.8; }
@media (max-width:768px) {
  .inv-header-grid { grid-template-columns:1fr; }
  .inv-toolbar { flex-direction:column; align-items:flex-start; }
}
</style>

<div class="inv-page">

<!-- Toolbar -->
<div class="inv-toolbar">
  <div class="inv-toolbar-left">
    <a href="<?= SITE_URL ?>/invoices.php?tab=<?= $tab ?>" class="btn btn-ghost btn-sm">← Back</a>
    <?php if ($id): ?>
      <span style="font-size:15px;font-weight:800"><?= h($inv['invoice_number']) ?></span>
      <span class="badge badge-<?= $statusColors[$inv['status']]??'blue' ?>"><?= ucfirst($inv['status']) ?></span>
    <?php else: ?>
      <span style="font-size:15px;font-weight:700;color:var(--text2)">New <?= $isQuote?'Quotation':'Invoice' ?></span>
    <?php endif; ?>
  </div>
  <div class="inv-toolbar-right">
    <?php if ($id): ?>
      <!-- Status quick-change -->
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach (['draft'=>'📝','sent'=>'📤','paid'=>'✅','overdue'=>'⚠️','cancelled'=>'❌'] as $st=>$ico): ?>
          <?php if ($st !== $inv['status']): ?>
          <a href="?action=status&id=<?= $id ?>&s=<?= $st ?>&tab=<?= $tab ?>" class="btn btn-ghost btn-sm" style="font-size:11px"><?= $ico ?> <?= ucfirst($st) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <a href="<?= SITE_URL ?>/invoice_print.php?id=<?= $id ?>" target="_blank" class="btn btn-ghost btn-sm">👁 View</a>
      <button onclick="openPDF('<?= SITE_URL ?>/invoice_print.php?id=<?= $id ?>','<?= h($inv['invoice_number']) ?>')" class="btn btn-primary btn-sm">⬇️ PDF</button>
      <?php if (!empty($inv['c_email'])): ?>
        <a href="<?= SITE_URL ?>/send_invoice.php?id=<?= $id ?>&tab=<?= $tab ?>" class="btn btn-success btn-sm" onclick="return confirm('Send this <?= $isQuote?'quotation':'invoice' ?> to <?= h($inv['c_email']) ?>?')">📧 Send <?= $isQuote?'Quotation':'Invoice' ?></a>
      <?php else: ?>
        <span class="btn btn-ghost btn-sm" style="opacity:.5;cursor:not-allowed" title="This client has no email address on file">📧 No Client Email</span>
      <?php endif; ?>
      <?php if (!$isQuote && !empty($inv['c_email']) && !empty($inv['due_date']) && !in_array($inv['status'], ['paid','cancelled'])): ?>
        <a href="<?= SITE_URL ?>/send_invoice_reminder.php?id=<?= $id ?>&tab=<?= $tab ?>" class="btn btn-ghost btn-sm" onclick="return confirm('Send a payment reminder for <?= h($inv['invoice_number']) ?> to <?= h($inv['c_email']) ?>?')">🔔 Send Reminder</a>
      <?php endif; ?>
      <?php if (!isset($_GET['edit'])): ?>
        <a href="?id=<?= $id ?>&edit=1&tab=<?= $tab ?>" class="btn btn-primary">✏️ Edit</a>
        <a href="?action=delete&id=<?= $id ?>&tab=<?= $tab ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($inv['invoice_number']) ?>?')">🗑 Delete</a>
      <?php else: ?>
        <button form="invoiceForm" type="submit" name="action" value="save" class="btn btn-primary">💾 Save Changes</button>
        <a href="?id=<?= $id ?>&tab=<?= $tab ?>" class="btn btn-ghost">Cancel</a>
      <?php endif; ?>
    <?php else: ?>
      <button form="invoiceForm" type="submit" name="action" value="save" class="btn btn-primary">💾 Save Invoice</button>
    <?php endif; ?>
  </div>
</div>

<?php
$isViewOnly = $id && !isset($_GET['edit']);
$formClass  = $isViewOnly ? 'view-only' : '';
$invCur     = $id ? ($inv['inv_currency']??'LKR') : 'LKR';
$invRate    = $id ? (float)($inv['inv_rate']??1) : 1;
?>

<form method="POST" action="?<?= $id?"id={$id}&":'type='.$type.'&' ?>tab=<?= $tab ?>" id="invoiceForm" class="<?= $formClass ?>">
  <?php if ($id): ?><input type="hidden" name="invoice_number" value="<?= h($inv['invoice_number']) ?>"><?php endif; ?>

  <!-- Header Grid -->
  <div class="inv-header-grid">

    <!-- Left: Client + Type -->
    <div class="inv-section">
      <div class="inv-section-title">Invoice Details</div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Type</label>
        <select name="invoice_type" <?= $isViewOnly?'disabled':'' ?>>
          <option value="invoice" <?= (!$id||$inv['invoice_type']==='invoice')?'selected':'' ?>>Invoice</option>
          <option value="quotation" <?= ($id&&$inv['invoice_type']==='quotation')?'selected':'' ?>>Quotation</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:12px" id="clientSelectWrap">
        <label>Client *</label>
        <?php if ($isViewOnly): ?>
          <?php
          // Check if manual client
          $manualData = isset($inv['manual_client_data']) ? json_decode($inv['manual_client_data'], true) : null;
          ?>
          <?php if ($manualData): ?>
            <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:12px 14px">
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--yellow);margin-bottom:6px">🔖 One-Time Client</div>
              <div style="font-weight:700;font-size:15px"><?= h($manualData['company']) ?></div>
              <?php if ($manualData['contact']): ?><div style="font-size:12px;color:var(--text2)"><?= h($manualData['contact']) ?></div><?php endif; ?>
              <?php if ($manualData['email']): ?><div style="font-size:12px;color:var(--text2)"><?= h($manualData['email']) ?></div><?php endif; ?>
              <?php if ($manualData['phone']): ?><div style="font-size:12px;color:var(--text2)"><?= h($manualData['phone']) ?></div><?php endif; ?>
              <?php if ($manualData['address']): ?><div style="font-size:12px;color:var(--text2)"><?= h($manualData['address']) ?></div><?php endif; ?>
              <a href="<?= SITE_URL ?>/clients.php" style="font-size:11px;color:var(--accent);margin-top:6px;display:inline-block">+ Add to Clients →</a>
            </div>
            <input type="hidden" name="client_id" value="<?= $inv['client_id'] ?>">
          <?php else: ?>
            <div style="font-weight:700;font-size:15px"><?= h($inv['company_name']) ?></div>
            <?php if ($inv['contact_name']): ?><div style="font-size:12px;color:var(--text2)"><?= h($inv['contact_name']) ?></div><?php endif; ?>
            <?php if ($inv['c_email']): ?><div style="font-size:12px;color:var(--text2)"><?= h($inv['c_email']) ?></div><?php endif; ?>
            <input type="hidden" name="client_id" value="<?= $inv['client_id'] ?>">
          <?php endif; ?>
        <?php else: ?>
          <?php
          $isManual = isset($inv['manual_client_data']) && $inv['manual_client_data'];
          $savedManual = $isManual ? json_decode($inv['manual_client_data'], true) : [];
          ?>
          <!-- Toggle -->
          <div style="display:flex;gap:6px;margin-bottom:10px">
            <button type="button" id="btnExisting" onclick="setClientMode('existing')"
              class="btn btn-sm <?= !$isManual?'btn-primary':'btn-ghost' ?>">🏢 Existing Client</button>
            <button type="button" id="btnManual" onclick="setClientMode('manual')"
              class="btn btn-sm <?= $isManual?'btn-primary':'btn-ghost' ?>">✏️ Enter Manually</button>
          </div>
          <input type="hidden" name="client_mode" id="clientMode" value="<?= $isManual?'manual':'existing' ?>">

          <!-- Existing client dropdown -->
          <div id="existingClientWrap" style="display:<?= $isManual?'none':'' ?>">
            <select name="client_id" id="invClient" onchange="onClientChange()">
              <option value="">— Select Client —</option>
              <?php foreach ($clients as $cl): ?>
                <option value="<?= $cl['id'] ?>" data-currency="<?= h($cl['default_currency']??'LKR') ?>"
                  <?= ($id&&!$isManual&&$inv['client_id']==$cl['id'])?'selected':'' ?>>
                  <?= h($cl['company_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Manual entry fields -->
          <div id="manualClientWrap" style="display:<?= $isManual?'':'none' ?>;flex-direction:column;gap:8px">
            <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:10px 12px;font-size:12px;color:var(--yellow)">
              ✏️ One-time client — details won't be saved to the client list unless you add them manually.
            </div>
            <input name="manual_company" placeholder="Company / Client Name *" style="font-size:13px;font-weight:600" value="<?= h($savedManual['company']??'') ?>">
            <input name="manual_contact" placeholder="Contact Person" style="font-size:13px" value="<?= h($savedManual['contact']??'') ?>">
            <input name="manual_email" type="email" placeholder="Email Address" style="font-size:13px" value="<?= h($savedManual['email']??'') ?>">
            <input name="manual_phone" placeholder="Phone Number" style="font-size:13px" value="<?= h($savedManual['phone']??'') ?>">
            <textarea name="manual_address" placeholder="Address (optional)" rows="2" style="font-size:12px;resize:vertical"><?= h($savedManual['address']??'') ?></textarea>
          </div>
        <?php endif; ?>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Invoice Currency</label>
        <?php if ($isViewOnly): ?>
          <div style="font-weight:600"><?= h($invCur) ?> <?= $invCur!=='LKR' ? "— Rate: {$sym} ".number_format($invRate,2)." per {$invCur}" : '' ?></div>
          <input type="hidden" name="inv_currency" value="<?= h($invCur) ?>">
        <?php else: ?>
          <select name="inv_currency" id="invCurrency" onchange="updateCurrencyRate()">
            <?php foreach ($currencies as $cur): ?>
              <option value="<?= $cur ?>" <?= $invCur===$cur?'selected':'' ?>><?= $cur ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <?php if (!$isViewOnly): ?>
      <div id="rateInfoWrap" style="display:<?= $invCur!=='LKR'?'':'none' ?>">
        <div class="rate-badge">1 <span id="curLabel"><?= h($invCur) ?></span> = <strong id="rateDisplay"><?= number_format($invRate,2) ?></strong> <?= $sym ?> <span style="font-size:10px;color:var(--text2)">(Settings → Exchange Rates)</span></div>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Billing Month <span style="color:var(--text2);font-weight:400;font-size:10px">(for expense sync)</span></label>
        <input type="month" name="billing_month" id="invBillingMonth" value="<?= h($id?($inv['billing_month']??''):date('Y-m')) ?>" <?= $isViewOnly?'readonly':'' ?>>
      </div>
    </div>

    <!-- Right: Dates + Status -->
    <div class="inv-section">
      <div class="inv-section-title">Dates & Status</div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Issue Date *</label>
        <input type="date" name="issue_date" required value="<?= h($id?$inv['issue_date']:date('Y-m-d')) ?>" <?= $isViewOnly?'readonly':'' ?>>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label><?= $isQuote?'Valid Until':'Due Date' ?></label>
        <input type="date" name="due_date" value="<?= h($id?($inv['due_date']??''):date('Y-m-d',strtotime('+30 days'))) ?>" <?= $isViewOnly?'readonly':'' ?>>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label>Status</label>
        <select name="status" <?= $isViewOnly?'disabled':'' ?>>
          <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= ($id&&$inv['status']===$st)?'selected':'' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isViewOnly): ?><input type="hidden" name="status" value="<?= h($inv['status']) ?>"><?php endif; ?>
      </div>
      <?php if ($id && $inv['paid_date']): ?>
      <div class="form-group">
        <label>Paid On</label>
        <div style="font-weight:600;color:var(--green)"><?= date('d M Y',strtotime($inv['paid_date'])) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Line Items -->
  <div class="inv-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <div class="inv-section-title" style="margin:0">Line Items</div>
      <?php if (!$isViewOnly): ?>
      <div style="display:flex;gap:8px">
        <button type="button" onclick="addItem()" class="btn btn-ghost btn-sm">+ Add Line</button>
        <button type="button" onclick="syncExpenses()" class="btn btn-ghost btn-sm" id="syncBtn">📥 Sync Expenses</button>
      </div>
      <?php endif; ?>
    </div>

    <div id="syncNotice" style="display:none;background:rgba(0,196,140,.1);border:1px solid rgba(0,196,140,.3);color:var(--green);padding:8px 12px;border-radius:6px;font-size:12.5px;margin-bottom:10px"></div>

    <div style="overflow-x:auto">
    <table class="line-items-table" style="min-width:580px">
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Item / Description</th>
          <th class="r" style="width:70px">Qty</th>
          <th class="r" style="width:120px">Price</th>
          <th class="r" style="width:120px">Amount</th>
          <?php if (!$isViewOnly): ?><th style="width:32px"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="lineItems">
        <?php
        // Sort service first, expense last
        usort($invItems, fn($a,$b) => ($a['item_type']==='expense'?1:0)-($b['item_type']==='expense'?1:0));
        $shownSep = false; $itemNum = 1;
        foreach ($invItems as $item):
            $isExp = $item['item_type']==='expense';
            $dParts = explode('|||',$item['description'],2);
            $mDesc = trim($dParts[0]); $sDesc = isset($dParts[1])?trim($dParts[1]):'';
        ?>
        <?php if ($isExp && !$shownSep): $shownSep=true; ?>
        <tr><td colspan="<?= $isViewOnly?5:6 ?>" style="padding:6px 0">
          <div class="sep-line"><div></div><span>📥 AUTO-SYNCED EXPENSES</span><div></div></div>
        </td></tr>
        <?php endif; ?>
        <tr class="line-item-row <?= $isExp?'lock-row':'' ?>">
          <td style="color:var(--text2);font-size:12px;padding-left:4px"><?= $itemNum++ ?></td>
          <td>
            <input type="hidden" name="item_type[]" value="<?= h($item['item_type']) ?>">
            <input type="hidden" name="item_exp_id[]" value="<?= $item['expense_id'] ?>">
            <?php if ($isExp): ?>
              <input type="hidden" name="item_desc[]" value="<?= h($mDesc) ?>">
              <input type="hidden" name="item_subdesc[]" value="<?= h($sDesc) ?>">
              <div style="display:flex;align-items:center;gap:6px">
                <span style="font-size:13px">🔒</span>
                <div>
                  <div style="font-size:13px;font-weight:600;color:var(--text)"><?= h($mDesc) ?></div>
                  <?php if ($sDesc): ?><div style="font-size:11px;color:var(--text2);margin-top:2px"><?= h($sDesc) ?></div><?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <div class="li-desc">
                <input class="li-desc-main" name="item_desc[]" value="<?= h($mDesc) ?>" placeholder="Service / Item name" <?= $isViewOnly?'readonly':'' ?>>
                <input class="li-desc-sub" name="item_subdesc[]" value="<?= h($sDesc) ?>" placeholder="Description (optional)" <?= $isViewOnly?'readonly':'' ?>>
              </div>
            <?php endif; ?>
          </td>
          <td style="width:70px">
            <?php if ($isExp): ?>
              <input type="hidden" name="item_qty[]" value="<?= $item['quantity'] ?>">
              <div style="text-align:right;font-size:13px;color:var(--text2)"><?= rtrim(rtrim(number_format($item['quantity'],2),'0'),'.') ?></div>
            <?php else: ?>
              <input class="li-num" name="item_qty[]" type="number" step="0.01" value="<?= $item['quantity'] ?>" oninput="calcLine(this)" <?= $isViewOnly?'readonly':'' ?>>
            <?php endif; ?>
          </td>
          <td style="width:120px">
            <?php if ($isExp): ?>
              <input type="hidden" name="item_price[]" value="<?= $item['unit_price'] ?>">
              <div style="text-align:right;font-size:13px;color:var(--text2)"><?= $sym ?> <?= number_format($item['unit_price'],2) ?></div>
            <?php else: ?>
              <input class="li-num" name="item_price[]" type="number" step="0.01" value="<?= $item['unit_price'] ?>" oninput="calcLine(this)" <?= $isViewOnly?'readonly':'' ?>>
            <?php endif; ?>
          </td>
          <td class="li-amt <?= $isExp?'expense-amt':'' ?>"><?= $sym ?> <?= number_format($item['amount'],2) ?></td>
          <?php if (!$isViewOnly): ?>
          <td>
            <button type="button" onclick="this.closest('tr').remove();removeSep();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px;line-height:1" title="<?= $isExp?'Remove from invoice':'Delete' ?>">×</button>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($invItems) && !$id): ?>
        <tr class="line-item-row">
          <td style="color:var(--text2);font-size:12px;padding-left:4px">1</td>
          <td>
            <input type="hidden" name="item_type[]" value="service">
            <input type="hidden" name="item_exp_id[]" value="0">
            <div class="li-desc">
              <input class="li-desc-main" name="item_desc[]" placeholder="Service / Item name">
              <input class="li-desc-sub" name="item_subdesc[]" placeholder="Description (optional)">
            </div>
          </td>
          <td><input class="li-num" name="item_qty[]" type="number" step="0.01" value="1" oninput="calcLine(this)"></td>
          <td><input class="li-num" name="item_price[]" type="number" step="0.01" value="0" oninput="calcLine(this)"></td>
          <td class="li-amt"><?= $sym ?> 0.00</td>
          <td><button type="button" onclick="this.closest('tr').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px;line-height:1">×</button></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Totals -->
    <div class="totals-grid" style="margin-top:20px">
      <?php if (!$isViewOnly): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="form-group" style="margin:0"><label>Discount %</label><input class="li-num" type="number" name="discount_pct" id="discPct" step="0.01" value="<?= h($id?$inv['discount_pct']:0) ?>" oninput="calcTotals()"></div>
        <div class="form-group" style="margin:0"><label>Tax / VAT %</label><input class="li-num" type="number" name="tax_pct" id="taxPct" step="0.01" value="<?= h($id?$inv['tax_pct']:0) ?>" oninput="calcTotals()"></div>
      </div>
      <?php else: ?>
        <input type="hidden" name="discount_pct" value="<?= $inv['discount_pct'] ?>">
        <input type="hidden" name="tax_pct" value="<?= $inv['tax_pct'] ?>">
      <?php endif; ?>
      <div class="total-row"><span style="color:var(--text2)">Subtotal</span><strong id="dispSubtotal"><?= $sym ?> <?= $id?number_format($inv['subtotal'],2):'0.00' ?></strong></div>
      <?php if ($id && $inv['discount_pct']>0): ?><div class="total-row" id="discRow"><span style="color:var(--red)">Discount (<?= $inv['discount_pct'] ?>%)</span><span style="color:var(--red)">-<?= $sym ?> <?= number_format($inv['discount_amt'],2) ?></span></div><?php endif; ?>
      <?php if ($id && $inv['tax_pct']>0): ?><div class="total-row" id="taxRow"><span>Tax (<?= $inv['tax_pct'] ?>%)</span><span><?= $sym ?> <?= number_format($inv['tax_amt'],2) ?></span></div><?php endif; ?>
      <?php if (!$id): ?><div class="total-row" id="discRow" style="display:none"><span style="color:var(--red)">Discount</span><span id="dispDiscount" style="color:var(--red)">-<?= $sym ?> 0.00</span></div><div class="total-row" id="taxRow" style="display:none"><span>Tax</span><span id="dispTax"><?= $sym ?> 0.00</span></div><?php endif; ?>
      <div class="total-row big"><span>TOTAL</span><strong id="dispTotal"><?= $sym ?> <?= $id?number_format($inv['total'],2):'0.00' ?></strong></div>
    </div>
  </div>

  <!-- Notes & Terms -->
  <div class="inv-header-grid">
    <div class="inv-section">
      <div class="inv-section-title">Terms & Conditions</div>
      <textarea name="terms" rows="3" style="width:100%;resize:vertical" <?= $isViewOnly?'readonly':'' ?>><?= h($id?($inv['terms']??$defTerms):$defTerms) ?></textarea>
    </div>
    <div class="inv-section">
      <div class="inv-section-title">Notes</div>
      <textarea name="notes" rows="3" style="width:100%;resize:vertical" <?= $isViewOnly?'readonly':'' ?>><?= h($id?($inv['notes']??$defNotes):$defNotes) ?></textarea>
    </div>
  </div>

  <!-- Bottom save bar -->
  <?php if (!$isViewOnly): ?>
  <div style="position:sticky;bottom:0;background:var(--bg2);border-top:1px solid var(--border);padding:14px 0;display:flex;gap:10px;justify-content:flex-end;z-index:40">
    <?php if ($id): ?>
      <a href="?id=<?= $id ?>&tab=<?= $tab ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" name="action" value="save" class="btn btn-primary" style="padding:10px 28px">💾 Save Changes</button>
    <?php else: ?>
      <a href="<?= SITE_URL ?>/invoices.php?tab=<?= $tab ?>" class="btn btn-ghost">Cancel</a>
      <button type="submit" name="action" value="save" class="btn btn-primary" style="padding:10px 28px">💾 Create <?= $isQuote?'Quotation':'Invoice' ?></button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</form>
</div><!-- /inv-page -->

<script>
const sym  = '<?= $sym ?>';
const siteUrl = '<?= SITE_URL ?>';
const rates = {
    LKR:1, USD:<?= (float)(getSetting('rate_usd_lkr','1')?:1) ?>,
    AUD:<?= (float)(getSetting('rate_aud_lkr','1')?:1) ?>,
    EUR:<?= (float)(getSetting('rate_eur_lkr','1')?:1) ?>,
    GBP:<?= (float)(getSetting('rate_gbp_lkr','1')?:1) ?>,
    SGD:<?= (float)(getSetting('rate_sgd_lkr','1')?:1) ?>
};
const clientCurrencies = <?= json_encode($clientCurrencies) ?>;

function getRate() { return rates[document.getElementById('invCurrency')?.value||'LKR']||1; }
function getCur()  { return document.getElementById('invCurrency')?.value||'LKR'; }

function updateCurrencyRate() {
    const cur = getCur();
    const wrap = document.getElementById('rateInfoWrap');
    if (!wrap) return;
    if (cur === 'LKR') { wrap.style.display='none'; return; }
    wrap.style.display='';
    document.getElementById('curLabel').textContent  = cur;
    document.getElementById('rateDisplay').textContent = (rates[cur]||1).toLocaleString('en',{minimumFractionDigits:2});
    calcTotals();
}

function setClientMode(mode) {
    document.getElementById('clientMode').value = mode;
    const existing = document.getElementById('existingClientWrap');
    const manual   = document.getElementById('manualClientWrap');
    const btnE     = document.getElementById('btnExisting');
    const btnM     = document.getElementById('btnManual');
    if (mode === 'manual') {
        if (existing) existing.style.display = 'none';
        if (manual)   manual.style.display   = 'flex';
        if (btnE) { btnE.className = btnE.className.replace('btn-primary','btn-ghost'); }
        if (btnM) { btnM.className = btnM.className.replace('btn-ghost','btn-primary'); }
        // Clear client dropdown selection
        const sel = document.getElementById('invClient');
        if (sel) sel.value = '';
    } else {
        if (existing) existing.style.display = '';
        if (manual)   manual.style.display   = 'none';
        if (btnE) { btnE.className = btnE.className.replace('btn-ghost','btn-primary'); }
        if (btnM) { btnM.className = btnM.className.replace('btn-primary','btn-ghost'); }
    }
}

function onClientChange() {
    const sel = document.getElementById('invClient');
    const cid = sel?.value;
    const curSel = document.getElementById('invCurrency');
    if (cid && curSel) {
        const opt = sel.options[sel.selectedIndex];
        const clientCur = opt?.getAttribute('data-currency') || 'LKR';
        curSel.value = clientCur;
        updateCurrencyRate();
    }
    autoSyncIfReady();
}

function calcLine(input) {
    const row   = input.closest('tr');
    if (!row) return;
    const qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value) || 0;
    const price = parseFloat(row.querySelector('[name="item_price[]"]')?.value) || 0;
    const rate  = getRate();
    const cur   = getCur();
    const amtLKR = qty * price * rate;
    const cell  = row.querySelector('.li-amt');
    if (cell) cell.textContent = sym + ' ' + amtLKR.toLocaleString('en',{minimumFractionDigits:2});
    calcTotals();
}

function removeSep() {
    // Remove separator if no expense rows remain
    const hasExp = document.querySelector('#lineItems .lock-row');
    if (!hasExp) {
        document.querySelectorAll('#lineItems tr').forEach(r => {
            if (r.querySelector('.sep-line')) r.remove();
        });
    }
}

function calcTotals() {
    const rate = getRate();
    let sub = 0;
    document.querySelectorAll('#lineItems .line-item-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]')?.value) || 0;
        const itype = row.querySelector('[name="item_type[]"]')?.value || 'service';
        sub += itype === 'expense' ? qty*price : qty*price*rate;
    });
    const discPct = parseFloat(document.getElementById('discPct')?.value) || 0;
    const taxPct  = parseFloat(document.getElementById('taxPct')?.value) || 0;
    const disc = sub*discPct/100, tax = (sub-disc)*taxPct/100, total = sub-disc+tax;
    const fmt = n => sym+' '+n.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
    const el = id => document.getElementById(id);
    if (el('dispSubtotal')) el('dispSubtotal').textContent = fmt(sub);
    if (el('dispDiscount')) el('dispDiscount').textContent = '-'+fmt(disc);
    if (el('dispTax'))      el('dispTax').textContent      = fmt(tax);
    if (el('dispTotal'))    el('dispTotal').textContent    = fmt(total);
    if (el('discRow'))      el('discRow').style.display    = discPct>0?'':'none';
    if (el('taxRow'))       el('taxRow').style.display     = taxPct>0?'':'none';
}

let itemCount = <?= max(count($invItems),1) ?>;
function addItem() {
    itemCount++;
    const tbody = document.getElementById('lineItems');
    // Remove separator temporarily, re-add after
    const sep = document.getElementById('expSep');
    const tr  = document.createElement('tr');
    tr.className = 'line-item-row';
    tr.innerHTML = `
        <td style="color:var(--text2);font-size:12px;padding-left:4px">${itemCount}</td>
        <td>
            <input type="hidden" name="item_type[]" value="service">
            <input type="hidden" name="item_exp_id[]" value="0">
            <div class="li-desc">
                <input class="li-desc-main" name="item_desc[]" placeholder="Service / Item name">
                <input class="li-desc-sub" name="item_subdesc[]" placeholder="Description (optional)">
            </div>
        </td>
        <td><input class="li-num" name="item_qty[]" type="number" step="0.01" value="1" oninput="calcLine(this)"></td>
        <td><input class="li-num" name="item_price[]" type="number" step="0.01" value="0" oninput="calcLine(this)"></td>
        <td class="li-amt">${sym} 0.00</td>
        <td><button type="button" onclick="this.closest('tr').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px;line-height:1">×</button></td>`;
    // Insert before separator or at end
    const firstExp = tbody.querySelector('.lock-row');
    const sepRow   = [...tbody.querySelectorAll('tr')].find(r => r.querySelector('.sep-line'));
    if (sepRow) tbody.insertBefore(tr, sepRow);
    else tbody.appendChild(tr);
    tr.querySelector('.li-desc-main').focus();
    calcTotals();
}

function autoSyncIfReady() {
    const cid   = document.getElementById('invClient')?.value;
    const month = document.getElementById('invBillingMonth')?.value;
    if (!cid || !month) return;
    const hasItems = [...document.querySelectorAll('#lineItems [name="item_price[]"]')].some(i=>parseFloat(i.value)>0);
    if (hasItems) return;
    doSync(cid, month, false);
}

function syncExpenses() {
    const cid   = document.getElementById('invClient')?.value;
    const month = document.getElementById('invBillingMonth')?.value;
    if (!cid || !month) { alert('Select a client and billing month first.'); return; }
    doSync(cid, month, true);
}

function doSync(cid, month, showAlert) {
    const btn = document.getElementById('syncBtn');
    if (btn) { btn.textContent='⏳ Loading...'; btn.disabled=true; }
    fetch(`${siteUrl}/invoices.php?action=get_expenses&client_id=${cid}&month=${month}`)
        .then(r=>r.json()).then(data=>{
            if (btn) { btn.textContent='📥 Sync Expenses'; btn.disabled=false; }
            if (!data.length) { if(showAlert) alert('No expenses found.'); return; }
            // Remove old expense rows + separator
            document.querySelectorAll('#lineItems .lock-row').forEach(r=>r.remove());
            document.querySelectorAll('#lineItems tr').forEach(r=>{ if(r.querySelector('.sep-line')) r.remove(); });
            // Add separator
            const sep = document.createElement('tr');
            sep.innerHTML = `<td colspan="6" style="padding:6px 0"><div class="sep-line"><div></div><span>📥 AUTO-SYNCED EXPENSES</span><div></div></div></td>`;
            document.getElementById('lineItems').appendChild(sep);
            // Add expense rows
            data.forEach(e=>{
                const tr = document.createElement('tr');
                tr.className='line-item-row lock-row';
                tr.innerHTML=`
                    <td style="color:var(--text2);font-size:12px;padding-left:4px">—</td>
                    <td>
                        <input type="hidden" name="item_type[]" value="expense">
                        <input type="hidden" name="item_exp_id[]" value="${e.id}">
                        <input type="hidden" name="item_desc[]" value="${e.desc.replace(/"/g,'&quot;')}">
                        <input type="hidden" name="item_subdesc[]" value="${(e.subdesc||'').replace(/"/g,'&quot;')}">
                        <div style="display:flex;align-items:center;gap:6px">
                            <span>🔒</span>
                            <div>
                                <div style="font-size:13px;font-weight:600;color:var(--text)">${e.desc}</div>
                                ${e.subdesc?`<div style="font-size:11px;color:var(--text2);margin-top:2px">${e.subdesc}</div>`:''}
                            </div>
                        </div>
                    </td>
                    <td><input type="hidden" name="item_qty[]" value="1"><div style="text-align:right;font-size:12px;color:var(--text2)">1</div></td>
                    <td><input type="hidden" name="item_price[]" value="${e.amount}"><div style="text-align:right;font-size:12px;color:var(--text2)">${sym} ${parseFloat(e.amount).toLocaleString('en',{minimumFractionDigits:2})}</div></td>
                    <td class="li-amt expense-amt">${sym} ${parseFloat(e.amount).toLocaleString('en',{minimumFractionDigits:2})}</td>
                    <td><button type="button" onclick="this.closest('tr').remove();removeSep();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px;line-height:1" title="Remove from invoice">×</button></td>`;
                document.getElementById('lineItems').appendChild(tr);
            });
            const notice = document.getElementById('syncNotice');
            if (notice) { notice.textContent=`✅ ${data.length} expense${data.length>1?'s':''} synced`; notice.style.display='block'; setTimeout(()=>notice.style.display='none',3500); }
            calcTotals();
        });
}

function openPDF(url, invNo) {
    const w = window.open(url,'_blank');
    if (w) w.addEventListener('load', ()=>{ w.document.title=invNo; setTimeout(()=>w.print(),300); });
}

document.addEventListener('DOMContentLoaded', ()=>{
    updateCurrencyRate();
    calcTotals();
    const m = document.getElementById('invBillingMonth');
    if (m) m.addEventListener('change', autoSyncIfReady);

    // Validate before submit
    document.getElementById('invoiceForm')?.addEventListener('submit', function(e) {
        const mode = document.getElementById('clientMode')?.value || 'existing';
        if (mode === 'existing') {
            const sel = document.getElementById('invClient');
            if (sel && !sel.value) {
                e.preventDefault(); alert('Please select a client.'); sel.focus(); return;
            }
        } else {
            const name = document.querySelector('[name="manual_company"]');
            if (name && !name.value.trim()) {
                e.preventDefault(); alert('Please enter the client company name.'); name.focus(); return;
            }
        }
    });
});
</script>

<?php pageFooter(); ?>
