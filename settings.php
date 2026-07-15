<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

function saveSetting($db, $key, $val) {
    $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$key,$val,$val]);
}

// Load all settings
$rows = $db->query("SELECT setting_key,setting_value FROM settings")->fetchAll();
$s = [];
foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];

$action = $_POST['action'] ?? '';

// Each section saves ONLY its own fields — no hidden cross-section fields
if ($action === 'company') {
    foreach (['company_name','company_address','company_phone','company_email','email_from','email_cc','currency','currency_symbol','salary_date','company_reg','company_vat'] as $f)
        saveSetting($db, $f, trim($_POST[$f] ?? ''));
    clearSettingsCache();
    setFlash('success', 'Company info saved.');
    header('Location: '.SITE_URL.'/settings.php#company'); exit;
}

if ($action === 'bank') {
    foreach (['bank_name','bank_account_name','bank_account_number','bank_branch','bank_swift'] as $f)
        saveSetting($db, $f, trim($_POST[$f] ?? ''));
    clearSettingsCache();
    setFlash('success', 'Bank details saved.');
    header('Location: '.SITE_URL.'/settings.php#bank'); exit;
}

if ($action === 'invoice_settings') {
    foreach (['invoice_prefix','quote_prefix','invoice_terms','invoice_notes'] as $f)
        saveSetting($db, $f, trim($_POST[$f] ?? ''));
    clearSettingsCache();
    setFlash('success', 'Invoice settings saved.');
    header('Location: '.SITE_URL.'/settings.php#invoice'); exit;
}

if ($action === 'invoice_reminders') {
    saveSetting($db, 'invoice_reminders_enabled', isset($_POST['invoice_reminders_enabled']) ? '1' : '0');
    saveSetting($db, 'reminder_days_before_1', (string)max(0, (int)($_POST['reminder_days_before_1'] ?? 3)));
    saveSetting($db, 'reminder_days_before_2', (string)max(0, (int)($_POST['reminder_days_before_2'] ?? 1)));
    $ccList = implode(',', array_filter(array_map('trim', explode(',', $_POST['invoice_cc_emails'] ?? '')), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    saveSetting($db, 'invoice_cc_emails', $ccList);
    clearSettingsCache();
    setFlash('success', 'Invoice reminder settings saved.');
    header('Location: '.SITE_URL.'/settings.php#invoice'); exit;
}

if ($action === 'exchange_rates') {
    foreach (['rate_usd_lkr','rate_aud_lkr','rate_eur_lkr','rate_gbp_lkr','rate_sgd_lkr'] as $f)
        saveSetting($db, $f, trim($_POST[$f] ?? ''));
    clearSettingsCache();
    setFlash('success', 'Exchange rates saved.');
    header('Location: '.SITE_URL.'/settings.php#rates'); exit;
}

if ($action === 'ai_settings') {
    saveSetting($db, 'anthropic_api_key', trim($_POST['anthropic_api_key'] ?? ''));
    clearSettingsCache();
    setFlash('success', 'AI settings saved.');
    header('Location: '.SITE_URL.'/settings.php#ai'); exit;
}

if ($action === 'approval_reminders') {
    saveSetting($db, 'approval_reminders_enabled', isset($_POST['approval_reminders_enabled']) ? '1' : '0');
    clearSettingsCache();
    setFlash('success', 'Approval reminder settings saved.');
    header('Location: '.SITE_URL.'/settings.php#ai'); exit;
}

if ($action === 'logo') {
    if (!empty($_FILES['logo']['name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','svg','webp'])) {
            $dir = 'uploads/logos/';
            if (!is_dir(__DIR__.'/'.$dir)) mkdir(__DIR__.'/'.$dir, 0755, true);
            $fname = 'logo_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__.'/'.$dir.$fname)) {
                saveSetting($db, 'logo_path', $dir.$fname);
                clearSettingsCache();
                setFlash('success', 'Logo updated.');
            }
        } else { setFlash('error','Invalid file type.'); }
    }
    header('Location: '.SITE_URL.'/settings.php#logo'); exit;
}

// Reload after save
$rows = $db->query("SELECT setting_key,setting_value FROM settings")->fetchAll();
$s = [];
foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];

pageHeader('Settings');
?>

<style>
.settings-section { scroll-margin-top:80px; }
.settings-nav { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px; }
.settings-nav a { padding:7px 14px; border-radius:20px; font-size:13px; font-weight:600; text-decoration:none; background:var(--bg3); color:var(--text2); border:1px solid var(--border); transition:all .15s; }
.settings-nav a:hover { color:var(--text); }
</style>

<!-- Quick nav -->
<div class="settings-nav">
  <a href="#company">🏢 Company</a>
  <a href="#bank">🏦 Bank Details</a>
  <a href="#invoice">🧾 Invoice</a>
  <a href="#invoice-reminders">📧 Reminders</a>
  <a href="#rates">💱 Exchange Rates</a>
  <a href="#logo">🖼 Logo</a>
  <a href="#ai">🤖 AI Assistant</a>
  <a href="#approval-reminders">🔔 Approvals</a>
</div>

<!-- ── COMPANY INFO ─────────────────────────────────────── -->
<div class="card settings-section" id="company">
  <div class="card-title">🏢 Company Information</div>
  <form method="POST">
    <input type="hidden" name="action" value="company">
    <div class="form-grid">
      <div class="form-group"><label>Company Name</label><input name="company_name" value="<?= h($s['company_name']??'') ?>" placeholder="Creative Elements (Pvt) Ltd"></div>
      <div class="form-group"><label>Company Email</label><input type="email" name="company_email" value="<?= h($s['company_email']??'') ?>" placeholder="info@company.com"></div>
      <div class="form-group"><label>Phone</label><input name="company_phone" value="<?= h($s['company_phone']??'') ?>" placeholder="+94 11 234 5678"></div>
      <div class="form-group"><label>Invoice From Email</label><input type="email" name="email_from" value="<?= h($s['email_from']??'') ?>" placeholder="payroll@company.com"></div>
      <div class="form-group full"><label>Address</label><textarea name="company_address" rows="2"><?= h($s['company_address']??'') ?></textarea></div>
      <div class="form-group"><label>CC Email (Accounts Team)</label><input type="email" name="email_cc" value="<?= h($s['email_cc']??'') ?>" placeholder="accounts@company.com"></div>
      <div class="form-group"><label>Currency</label>
        <select name="currency">
          <?php foreach (['LKR','USD','AUD','EUR','GBP','SGD'] as $c): ?>
            <option value="<?= $c ?>" <?= ($s['currency']??'LKR')===$c?'selected':'' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Currency Symbol</label><input name="currency_symbol" value="<?= h($s['currency_symbol']??'Rs.') ?>" placeholder="Rs."></div>
      <div class="form-group"><label>Salary Date</label>
        <input type="number" name="salary_date" min="1" max="31" value="<?= h($s['salary_date']??'25') ?>">
        <span style="font-size:11px;color:var(--text2)">Shown on payslips as the payment due date</span>
      </div>
      <div class="form-group"><label>Company Reg. Number</label><input name="company_reg" value="<?= h($s['company_reg']??'') ?>" placeholder="PV00123456"></div>
      <div class="form-group"><label>VAT / Tax Number</label><input name="company_vat" value="<?= h($s['company_vat']??'') ?>" placeholder="123456789"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save Company Info</button></div>
  </form>
</div>

<!-- ── BANK DETAILS ─────────────────────────────────────── -->
<div class="card settings-section" id="bank">
  <div class="card-title">🏦 Bank Details <span style="font-size:12px;font-weight:400;color:var(--text2)">(Shown on invoices & quotations)</span></div>
  <form method="POST">
    <input type="hidden" name="action" value="bank">
    <div class="form-grid">
      <div class="form-group"><label>Bank Name</label><input name="bank_name" value="<?= h($s['bank_name']??'') ?>" placeholder="e.g. Sampath Bank"></div>
      <div class="form-group"><label>Account Name</label><input name="bank_account_name" value="<?= h($s['bank_account_name']??'') ?>" placeholder="e.g. Creative Elements (Pvt) Ltd"></div>
      <div class="form-group"><label>Account Number</label><input name="bank_account_number" value="<?= h($s['bank_account_number']??'') ?>" placeholder="0142 1000 8706"></div>
      <div class="form-group"><label>Branch</label><input name="bank_branch" value="<?= h($s['bank_branch']??'') ?>" placeholder="e.g. Boralesgamuwa"></div>
      <div class="form-group"><label>SWIFT Code</label><input name="bank_swift" value="<?= h($s['bank_swift']??'') ?>" placeholder="e.g. BSAMLKLX"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save Bank Details</button></div>
  </form>
</div>

<!-- ── INVOICE SETTINGS ─────────────────────────────────── -->
<div class="card settings-section" id="invoice">
  <div class="card-title">🧾 Invoice Settings</div>
  <form method="POST">
    <input type="hidden" name="action" value="invoice_settings">
    <div class="form-grid">
      <div class="form-group"><label>Invoice Number Prefix</label><input name="invoice_prefix" value="<?= h($s['invoice_prefix']??'INV') ?>" placeholder="INV" maxlength="10" style="font-family:monospace;font-weight:700"></div>
      <div class="form-group"><label>Quotation Number Prefix</label><input name="quote_prefix" value="<?= h($s['quote_prefix']??'QUO') ?>" placeholder="QUO" maxlength="10" style="font-family:monospace;font-weight:700"></div>
      <div class="form-group full"><label>Default Payment Terms</label><textarea name="invoice_terms" rows="3"><?= h($s['invoice_terms']??'Payment due within 30 days of invoice date.') ?></textarea></div>
      <div class="form-group full"><label>Default Footer Notes</label><textarea name="invoice_notes" rows="2"><?= h($s['invoice_notes']??'Thank you for your business.') ?></textarea></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save Invoice Settings</button></div>
  </form>
</div>

<!-- ── INVOICE EMAILS & REMINDERS ───────────────────────── -->
<div class="card settings-section" id="invoice-reminders">
  <div class="card-title">📧 Invoice Emails &amp; Payment Reminders</div>
  <form method="POST">
    <input type="hidden" name="action" value="invoice_reminders">
    <div class="form-grid">
      <div class="form-group full">
        <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="invoice_reminders_enabled" <?= ($s['invoice_reminders_enabled']??'1')==='1'?'checked':'' ?> style="width:16px;height:16px">
          Automatically send payment reminder emails before the due date
        </label>
      </div>
      <div class="form-group"><label>1st Reminder — Days Before Due Date</label><input type="number" name="reminder_days_before_1" min="0" value="<?= h($s['reminder_days_before_1']??'3') ?>"></div>
      <div class="form-group"><label>2nd Reminder — Days Before Due Date</label><input type="number" name="reminder_days_before_2" min="0" value="<?= h($s['reminder_days_before_2']??'1') ?>"></div>
      <div class="form-group full">
        <label>Always CC on Invoice Emails &amp; Reminders <span style="color:var(--text2);font-weight:400">(comma-separated)</span></label>
        <input name="invoice_cc_emails" value="<?= h($s['invoice_cc_emails']??'accounts@creativelements.co,reach@creativelements.co') ?>" placeholder="accounts@creativelements.co,reach@creativelements.co">
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save Reminder Settings</button></div>
  </form>
</div>

<!-- ── EXCHANGE RATES ────────────────────────────────────── -->
<div class="card settings-section" id="rates">
  <div class="card-title">💱 Exchange Rates <span style="font-size:12px;font-weight:400;color:var(--text2)">(to LKR — used for invoice currency conversion)</span></div>
  <form method="POST">
    <input type="hidden" name="action" value="exchange_rates">
    <div class="form-grid">
      <div class="form-group">
        <label>1 USD = LKR</label>
        <input type="number" name="rate_usd_lkr" step="0.01" value="<?= h($s['rate_usd_lkr']??'325.00') ?>" placeholder="325.00">
      </div>
      <div class="form-group">
        <label>1 AUD = LKR</label>
        <input type="number" name="rate_aud_lkr" step="0.01" value="<?= h($s['rate_aud_lkr']??'215.00') ?>" placeholder="215.00">
      </div>
      <div class="form-group">
        <label>1 EUR = LKR</label>
        <input type="number" name="rate_eur_lkr" step="0.01" value="<?= h($s['rate_eur_lkr']??'360.00') ?>" placeholder="360.00">
      </div>
      <div class="form-group">
        <label>1 GBP = LKR</label>
        <input type="number" name="rate_gbp_lkr" step="0.01" value="<?= h($s['rate_gbp_lkr']??'420.00') ?>" placeholder="420.00">
      </div>
      <div class="form-group">
        <label>1 SGD = LKR</label>
        <input type="number" name="rate_sgd_lkr" step="0.01" value="<?= h($s['rate_sgd_lkr']??'245.00') ?>" placeholder="245.00">
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Update Exchange Rates</button></div>
  </form>
</div>

<!-- ── LOGO ─────────────────────────────────────────────── -->
<div class="card settings-section" id="logo">
  <div class="card-title">🖼 Company Logo</div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="logo">
    <div class="form-group" style="max-width:400px">
      <?php if (!empty($s['logo_path'])): ?>
        <div style="margin-bottom:14px">
          <img src="<?= h(SITE_URL.'/'.$s['logo_path']) ?>" style="height:60px;border-radius:8px;background:var(--bg3);padding:8px" alt="Logo">
          <div style="font-size:11px;color:var(--text2);margin-top:4px">Current logo</div>
        </div>
      <?php endif; ?>
      <label>Upload New Logo <span style="color:var(--text2);font-weight:400">(PNG, JPG, SVG — max 2MB)</span></label>
      <input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp">
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Upload Logo</button></div>
  </form>
</div>

<!-- ── AI ASSISTANT ──────────────────────────────────────── -->
<div class="card settings-section" id="ai">
  <div class="card-title">🤖 AI Assistant</div>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">Connect to Anthropic's Claude AI to enable the AI chat assistant. Get your API key from <a href="https://console.anthropic.com" target="_blank" style="color:var(--accent)">console.anthropic.com</a>.</p>
  <form method="POST">
    <input type="hidden" name="action" value="ai_settings">
    <div class="form-grid">
      <div class="form-group full">
        <label>Anthropic API Key</label>
        <input type="password" name="anthropic_api_key" value="<?= h($s['anthropic_api_key']??'') ?>" placeholder="sk-ant-api03-..." autocomplete="off">
        <span style="font-size:11px;color:var(--text2);margin-top:4px;display:block">
          <?php if (!empty($s['anthropic_api_key'])): ?>
            ✅ API key is set — <a href="<?= SITE_URL ?>/chat.php" style="color:var(--accent)">Open AI Assistant →</a>
          <?php else: ?>
            No key set. Add one to enable the AI assistant.
          <?php endif; ?>
        </span>
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save AI Settings</button></div>
  </form>
</div>

<!-- ── APPROVAL REMINDERS ────────────────────────────────── -->
<div class="card settings-section" id="approval-reminders">
  <div class="card-title">🔔 Approval Reminders</div>
  <p style="font-size:13px;color:var(--text2);margin-bottom:16px">If vendor invoice submissions or staff expense requests are still waiting for your approval, a reminder email is sent to all super admins on weekday mornings (requires a cron job — see <code>send_approval_reminders.php</code>).</p>
  <form method="POST">
    <input type="hidden" name="action" value="approval_reminders">
    <div class="form-grid">
      <div class="form-group full">
        <label class="checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="approval_reminders_enabled" <?= ($s['approval_reminders_enabled']??'1')==='1'?'checked':'' ?> style="width:16px;height:16px">
          Email me a reminder on weekday mornings if anything is still awaiting approval
        </label>
      </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Save Reminder Settings</button></div>
  </form>
</div>

<?php pageFooter(); ?>
