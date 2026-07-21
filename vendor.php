<?php
require_once 'config.php';
$db = getDB();

$companyName = getSetting('company_name', 'CreativeElements');
$error   = '';
$success = false;

// Extract only digits from invoice number for comparison
function extractDigits($str) {
    preg_match_all('/\d+/', $str, $m);
    return implode('', $m[0]); // e.g. "INV-2026-78798" → "202678798", "Invoice78798" → "78798"
}

// AJAX: check duplicate invoice number using extracted digits
if (isset($_GET['check_invoice'])) {
    $inv     = trim($_GET['check_invoice']);
    $fid     = (int)($_GET['freelancer_id'] ?? 0);
    $digits  = extractDigits($inv);

    if (!$digits) {
        echo json_encode(['duplicate' => false]);
        exit;
    }

    // Fetch all existing invoice numbers for this freelancer
    $existing = [];
    $s1 = $db->prepare("SELECT invoice_number FROM freelance_payments WHERE freelancer_id=?");
    $s1->execute([$fid]);
    foreach ($s1->fetchAll() as $r) $existing[] = extractDigits($r['invoice_number']);

    $s2 = $db->prepare("SELECT invoice_number FROM vendor_submissions WHERE freelancer_id=?");
    $s2->execute([$fid]);
    foreach ($s2->fetchAll() as $r) $existing[] = extractDigits($r['invoice_number']);

    echo json_encode(['duplicate' => in_array($digits, array_filter($existing))]);
    exit;
}

// Load freelancers for dropdown
$freelancers = $db->query("SELECT id, freelancer_name FROM freelancers WHERE status='active' ORDER BY freelancer_name")->fetchAll();

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d   = $_POST;
    $fid = (int)($d['freelancer_id'] ?? 0);
    // Build full invoice number from suffix
    $suffix = trim($d['invoice_number_suffix'] ?? '');
    $inv    = 'Invoice-' . $suffix;

    // Validate required
    if (!$fid || !trim($d['project_name'] ?? '') || !$suffix || !trim($d['invoice_date'] ?? '') || !trim($d['payment_amount'] ?? '') || !trim($d['month'] ?? '')) {
        $error = 'Please fill in all required fields.';
    }
    // Validate file
    elseif (!isset($_FILES['invoice_file']) || $_FILES['invoice_file']['error'] !== 0) {
        $error = 'Please upload your invoice PDF.';
    }
    elseif ($_FILES['invoice_file']['size'] > 500 * 1024) {
        $error = 'Invoice file must be under 500KB. Your file is ' . round($_FILES['invoice_file']['size']/1024) . 'KB.';
    }
    elseif (mime_content_type($_FILES['invoice_file']['tmp_name']) !== 'application/pdf') {
        $error = 'Only PDF files are accepted.';
    }
    elseif (strtoupper(pathinfo($_FILES['invoice_file']['name'], PATHINFO_FILENAME)) !== strtoupper($suffix)) {
        $error = 'File name does not match invoice number.<br>Expected: <strong>' . h(strtoupper($suffix)) . '.pdf</strong><br>You uploaded: <strong>' . h($_FILES['invoice_file']['name']) . '</strong><br>Please rename your PDF and try again.';
    }
    else {
        // Check duplicate using extracted digits
        $digits = extractDigits($inv);
        $isDup  = false;
        if ($digits) {
            $s1 = $db->prepare("SELECT invoice_number FROM freelance_payments WHERE freelancer_id=?");
            $s1->execute([$fid]);
            foreach ($s1->fetchAll() as $r) {
                if (extractDigits($r['invoice_number']) === $digits) { $isDup = true; break; }
            }
            if (!$isDup) {
                $s2 = $db->prepare("SELECT invoice_number FROM vendor_submissions WHERE freelancer_id=?");
                $s2->execute([$fid]);
                foreach ($s2->fetchAll() as $r) {
                    if (extractDigits($r['invoice_number']) === $digits) { $isDup = true; break; }
                }
            }
        }
        if ($isDup) {
            $error = "Invoice number <strong>{$inv}</strong> appears to already exist (duplicate numbers detected). Please check your invoice number.";
        } else {
            // Save file
            $invDate = $d['invoice_date'];
            $year    = date('Y', strtotime($invDate));
            $month   = date('F', strtotime($invDate));
            $dir     = __DIR__ . "/invoices/{$year}/{$month}/";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $inv);
            $filename = $safeName . '_' . time() . '.pdf';
            if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dir . $filename)) {
                $filePath = "invoices/{$year}/{$month}/{$filename}";
                $db->prepare("INSERT INTO vendor_submissions (freelancer_id,project_name,invoice_number,invoice_date,payment_amount,month,invoice_file,invoice_file_name) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$fid,trim($d['project_name']),$inv,$invDate,$d['payment_amount'],trim($d['month']),$filePath,$_FILES['invoice_file']['name']]);
                $success = true;
            } else {
                $error = 'Failed to save file. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vendor Invoice Portal — <?= h($companyName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --bg:#0d1117; --bg2:#161b22; --bg3:#21262d; --border:#30363d; --text:#e6edf3; --text2:#8b949e; --accent:#3b82f6; --green:#00c48c; --red:#ff4d6d; --yellow:#f5a623; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14px;
  background-image:radial-gradient(ellipse at 20% 50%,rgba(59,130,246,.06) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(245,166,35,.04) 0%,transparent 50%); }
.page { max-width:600px; margin:0 auto; padding:40px 20px; }
.header { text-align:center; margin-bottom:36px; }
.logo { width:56px;height:56px;background:var(--yellow);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;margin:0 auto 14px;color:#000 }
.header h1 { font-size:26px; font-weight:800; margin-bottom:6px; }
.header p { color:var(--text2); font-size:13px; }
.badge { display:inline-block;background:rgba(245,166,35,.15);color:var(--yellow);padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600;margin-top:8px }
.card { background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:28px; }
.form-group { display:flex;flex-direction:column;gap:6px;margin-bottom:16px; }
.form-group label { font-size:12.5px;font-weight:600;color:var(--text2); }
.form-group label span.req { color:var(--red); }
input,select { background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:10px 14px;border-radius:8px;font-family:'Poppins',sans-serif;font-size:13.5px;width:100%;transition:border-color .15s; }
input:focus,select:focus { outline:none;border-color:var(--accent); }
input.error-field { border-color:var(--red); }
input.success-field { border-color:var(--green); }
select option { background:var(--bg2); }
.file-upload { background:var(--bg3);border:2px dashed var(--border);border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all .15s; }
.file-upload:hover { border-color:var(--accent);background:rgba(59,130,246,.05); }
.file-upload input[type=file] { display:none; }
.file-upload .icon { font-size:28px;margin-bottom:8px; }
.file-upload .label { font-size:13px;font-weight:600;color:var(--text); }
.file-upload .hint { font-size:11px;color:var(--text2);margin-top:4px; }
.file-upload.has-file { border-color:var(--green);background:rgba(0,196,140,.05); }
.file-upload.has-file .label { color:var(--green); }
#invoiceWrapper:focus-within { border-color:var(--accent); }
#invoiceWrapper input:focus { outline:none; }
.btn { display:flex;align-items:center;justify-content:center;width:100%;padding:13px;border-radius:8px;border:none;cursor:pointer;font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;background:var(--yellow);color:#000;transition:all .15s;margin-top:8px; }
.btn:hover { filter:brightness(1.1); }
.btn:disabled { opacity:.5;cursor:not-allowed; }
.error-box { background:rgba(255,77,109,.1);border:1px solid rgba(255,77,109,.3);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:13px;color:var(--red); }
.inv-error { font-size:11.5px;color:var(--red);margin-top:4px;display:none; }
.inv-ok { font-size:11.5px;color:var(--green);margin-top:4px;display:none; }
.success-page { text-align:center;padding:40px 20px; }
.success-icon { font-size:64px;margin-bottom:16px; }
.success-page h2 { font-size:24px;font-weight:800;margin-bottom:10px;color:var(--green); }
.success-page p { color:var(--text2);font-size:14px;line-height:1.7; }
.required-note { font-size:11px;color:var(--text2);margin-bottom:20px; }
.size-bar { height:4px;background:var(--bg3);border-radius:2px;margin-top:8px;overflow:hidden;display:none; }
.size-bar-fill { height:100%;border-radius:2px;transition:width .3s; }
.upload-progress-wrap { display:none;margin-top:12px; }
.upload-progress-bar { height:10px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;overflow:hidden; }
.upload-progress-fill { height:100%;width:0%;background:var(--accent);border-radius:6px;transition:width .15s ease; }
.upload-progress-text { font-size:12px;color:var(--text2);margin-top:6px;text-align:center; }
</style>
</head>
<body>
<div class="page">
  <div class="header">
    <div class="logo">🧑‍💻</div>
    <h1><?= h($companyName) ?></h1>
    <p>Vendor & Freelancer Invoice Portal</p>
    <span class="badge">CreativeElements-Vendors</span>
  </div>

  <?php if ($success): ?>
  <!-- SUCCESS STATE -->
  <div class="card">
    <div class="success-page">
      <div class="success-icon">✅</div>
      <h2>Invoice Submitted!</h2>
      <p>Your invoice has been submitted successfully.<br>
      Our accounts team will review and approve it shortly.<br><br>
      <strong style="color:var(--text)">You will be notified once it's processed.</strong></p>
      <button onclick="window.location.reload()" style="margin-top:24px;background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:10px 24px;border-radius:8px;cursor:pointer;font-family:Poppins,sans-serif;font-size:13px">Submit Another Invoice</button>
    </div>
  </div>

  <?php else: ?>
  <!-- FORM STATE -->
  <div class="card">
    <?php if ($error): ?>
      <div class="error-box">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <p class="required-note">Fields marked with <span style="color:var(--red)">*</span> are required.</p>

    <form method="POST" enctype="multipart/form-data" id="vendorForm">

      <div class="form-group">
        <label>Freelancer / Vendor Name <span class="req">*</span></label>
        <select name="freelancer_id" id="freelancerId" required onchange="resetInvoiceCheck()">
          <option value="">— Search and select your name —</option>
          <?php foreach ($freelancers as $f): ?>
            <option value="<?= $f['id'] ?>" <?= (isset($_POST['freelancer_id']) && $_POST['freelancer_id'] == $f['id']) ? 'selected' : '' ?>>
              <?= h($f['freelancer_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Project Name <span class="req">*</span></label>
        <input type="text" name="project_name" required placeholder="e.g. Website Redesign Q2" value="<?= h($_POST['project_name'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Invoice Number <span class="req">*</span></label>
        <div style="display:flex;align-items:center;background:var(--bg3);border:1px solid var(--border);border-radius:8px;overflow:hidden;transition:border-color .15s" id="invoiceWrapper">
          <span style="padding:10px 14px;background:var(--bg2);border-right:1px solid var(--border);color:var(--text2);font-weight:600;font-size:13.5px;white-space:nowrap;user-select:none">Invoice-</span>
          <input type="text" name="invoice_number_suffix" id="invoiceNumberSuffix" required placeholder="e.g. 26MAYAI005" value="<?= h($_POST['invoice_number_suffix'] ?? '') ?>" onblur="checkInvoice()" oninput="syncInvoice()" style="background:transparent;border:none;border-radius:0;flex:1;padding:10px 14px;text-transform:uppercase">
        </div>
        <!-- Hidden field that stores the full invoice number -->
        <input type="hidden" name="invoice_number" id="invoiceNumber" value="Invoice-<?= h($_POST['invoice_number_suffix'] ?? '') ?>">
        <div class="inv-error" id="invError">❌ Duplicate detected — this invoice number already exists.</div>
        <div class="inv-ok" id="invOk">✅ Invoice number is available.</div>
      </div>

      <div class="form-group">
        <label>Invoice Date <span class="req">*</span></label>
        <input type="date" name="invoice_date" required value="<?= h($_POST['invoice_date'] ?? date('Y-m-d')) ?>">
      </div>

      <div class="form-group">
        <label>Payment Amount <span class="req">*</span></label>
        <input type="number" name="payment_amount" step="0.01" required placeholder="0.00" value="<?= h($_POST['payment_amount'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label>Project Month <span class="req">*</span></label>
        <input type="month" name="month" id="monthHidden" required value="<?= h($_POST['month'] ?? '') ?>">
        <span style="font-size:11.5px;color:var(--text2)">📌 This should be the project start date.</span>
      </div>

      <div class="form-group">
        <label>Upload Invoice <span class="req">*</span> <span style="color:var(--text2);font-weight:400">(PDF only, max 500KB)</span></label>
        <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:10px 14px;margin-bottom:10px;font-size:12px;color:var(--text2)">
          📌 <strong style="color:var(--text)">File name must match your invoice number exactly.</strong><br>
          Example: Invoice number <strong id="invExample" style="color:var(--accent)">26MAYAI005</strong> → file must be named <strong id="fileExample" style="color:var(--accent)">26MAYAI005.pdf</strong>
        </div>
        <div class="file-upload" id="fileDropZone" onclick="document.getElementById('invoiceFile').click()">
          <input type="file" name="invoice_file" id="invoiceFile" accept=".pdf" onchange="handleFileSelect(this)">
          <div class="icon">📄</div>
          <div class="label" id="fileLabel">Click to upload PDF invoice</div>
          <div class="hint">PDF format only • Maximum 500KB • File name must match invoice number</div>
          <div class="size-bar" id="sizeBar">
            <div class="size-bar-fill" id="sizeBarFill"></div>
          </div>
        </div>
        <div class="inv-error" id="fileError"></div>
      </div>

      <button type="submit" class="btn" id="submitBtn">📤 Submit Invoice</button>
      <div class="upload-progress-wrap" id="uploadProgressWrap">
        <div class="upload-progress-bar"><div class="upload-progress-fill" id="uploadProgressFill"></div></div>
        <div class="upload-progress-text" id="uploadProgressText">Uploading… 0%</div>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <p style="text-align:center;font-size:11px;color:var(--text2);margin-top:20px">
    <?= h($companyName) ?> · Vendor Portal · For support contact accounts@creativelements.co
  </p>
</div>

<script>
let invoiceValid  = true;
let fileValid     = false;
let checkTimer    = null;

    const suffix = document.getElementById('invoiceNumberSuffix').value.trim().toUpperCase();
    document.getElementById('invoiceNumber').value = 'Invoice-' + suffix;
    // Update the live example hint
    const ex = suffix || '26MAYAI005';
    document.getElementById('invExample').textContent = suffix || '26MAYAI005';
    document.getElementById('fileExample').textContent = (suffix || '26MAYAI005') + '.pdf';
    // Re-validate file if already selected
    const fileInput = document.getElementById('invoiceFile');
    if (fileInput.files[0]) handleFileSelect(fileInput);
}

function checkInvoice() {
    const suffix  = document.getElementById('invoiceNumberSuffix').value.trim().toUpperCase();
    const fid     = document.getElementById('freelancerId').value;
    const full    = 'Invoice-' + suffix;
    document.getElementById('invoiceNumber').value = full;
    if (!suffix || !fid) return;
    clearTimeout(checkTimer);
    checkTimer = setTimeout(() => {
        fetch(`?check_invoice=${encodeURIComponent(full)}&freelancer_id=${fid}`)
            .then(r => r.json())
            .then(data => {
                const errEl   = document.getElementById('invError');
                const okEl    = document.getElementById('invOk');
                const wrapper = document.getElementById('invoiceWrapper');
                if (data.duplicate) {
                    errEl.style.display       = 'block';
                    okEl.style.display        = 'none';
                    wrapper.style.borderColor = 'var(--red)';
                    invoiceValid = false;
                } else {
                    errEl.style.display       = 'none';
                    okEl.style.display        = 'block';
                    wrapper.style.borderColor = 'var(--green)';
                    invoiceValid = true;
                }
            });
    }, 400);
}

function resetInvoiceCheck() {
    document.getElementById('invError').style.display = 'none';
    document.getElementById('invOk').style.display    = 'none';
    document.getElementById('invoiceWrapper').style.borderColor = '';
    document.getElementById('invoiceNumberSuffix').value = '';
    document.getElementById('invoiceNumber').value = 'Invoice-';
    document.getElementById('invExample').textContent  = '26MAYAI005';
    document.getElementById('fileExample').textContent = '26MAYAI005.pdf';
    invoiceValid = true;
}

function handleFileSelect(input) {
    const file    = input.files[0];
    const zone    = document.getElementById('fileDropZone');
    const label   = document.getElementById('fileLabel');
    const sizeBar = document.getElementById('sizeBar');
    const fill    = document.getElementById('sizeBarFill');
    const fileErr = document.getElementById('fileError');
    const maxSize = 500 * 1024;

    fileValid = false;
    if (!file) return;

    // Get expected filename from invoice number suffix
    const suffix   = document.getElementById('invoiceNumberSuffix').value.trim().toUpperCase();
    const expected = suffix + '.pdf';
    const actual   = file.name.trim();

    // Size bar
    const pct = Math.min((file.size / maxSize) * 100, 100);
    sizeBar.style.display    = 'block';
    fill.style.width         = pct + '%';
    fill.style.background    = file.size > maxSize ? '#ff4d6d' : '#00c48c';

    // Validations in order
    if (file.type !== 'application/pdf') {
        fileErr.style.display = 'block';
        fileErr.innerHTML     = '❌ Only PDF files are accepted.';
        zone.classList.remove('has-file');
        zone.style.borderColor = 'var(--red)';
        return;
    }
    if (file.size > maxSize) {
        fileErr.style.display = 'block';
        fileErr.innerHTML     = `❌ File is ${Math.round(file.size/1024)}KB — must be under 500KB.`;
        zone.classList.remove('has-file');
        zone.style.borderColor = 'var(--red)';
        return;
    }
    if (!suffix) {
        fileErr.style.display = 'block';
        fileErr.innerHTML     = '❌ Please enter your invoice number first, then upload the file.';
        zone.classList.remove('has-file');
        zone.style.borderColor = 'var(--red)';
        return;
    }
    if (actual.toUpperCase() !== expected.toUpperCase()) {
        fileErr.style.display = 'block';
        fileErr.innerHTML     = `❌ File name mismatch!<br>
            Expected: <strong style="color:var(--green)">${expected}</strong><br>
            You uploaded: <strong style="color:var(--red)">${actual}</strong><br>
            Please rename your PDF to <strong>${expected}</strong> and try again.`;
        zone.classList.remove('has-file');
        zone.style.borderColor = 'var(--red)';
        return;
    }

    // All good
    fileErr.style.display    = 'none';
    zone.classList.add('has-file');
    zone.style.borderColor   = 'var(--green)';
    label.textContent        = `✅ ${file.name} (${Math.round(file.size/1024)}KB)`;
    fileValid = true;
}

document.getElementById('vendorForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const suffix = document.getElementById('invoiceNumberSuffix').value.trim();
    if (!suffix) {
        alert('Please enter your invoice number.');
        return;
    }
    document.getElementById('invoiceNumber').value = 'Invoice-' + suffix.toUpperCase();
    if (!invoiceValid) {
        alert('Please fix the duplicate invoice number before submitting.');
        return;
    }
    const file = document.getElementById('invoiceFile').files[0];
    if (!file) {
        alert('Please upload your invoice PDF.');
        return;
    }
    if (!fileValid) {
        alert('Please fix the invoice file issues before submitting.');
        return;
    }
    submitWithProgress(this);
});

function submitWithProgress(form) {
    const submitBtn = document.getElementById('submitBtn');
    const progWrap  = document.getElementById('uploadProgressWrap');
    const progFill  = document.getElementById('uploadProgressFill');
    const progText  = document.getElementById('uploadProgressText');

    submitBtn.disabled      = true;
    submitBtn.style.display = 'none';
    progWrap.style.display  = 'block';
    progFill.style.width    = '0%';
    progFill.style.background = 'var(--accent)';
    progText.textContent    = 'Uploading… 0%';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.href, true);

    xhr.upload.onprogress = function(evt) {
        if (!evt.lengthComputable) return;
        const pct = Math.round((evt.loaded / evt.total) * 100);
        progFill.style.width = pct + '%';
        progText.textContent = pct < 100 ? `Uploading… ${pct}%` : 'Processing…';
    };

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
            document.open();
            document.write(xhr.responseText);
            document.close();
        } else {
            progText.textContent      = '❌ Upload failed — please try again.';
            progFill.style.background = 'var(--red)';
            submitBtn.disabled        = false;
            submitBtn.style.display   = 'flex';
        }
    };

    xhr.onerror = function() {
        progText.textContent      = '❌ Upload failed — check your connection and try again.';
        progFill.style.background = 'var(--red)';
        submitBtn.disabled        = false;
        submitBtn.style.display   = 'flex';
    };

    xhr.send(new FormData(form));
}
</script>
</body>
</html>
