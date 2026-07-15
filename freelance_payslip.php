<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireLogin();
$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.SITE_URL.'/freelance.php'); exit; }

$stmt = $db->prepare("SELECT fp.*, f.freelancer_name, f.email, f.phone, f.bank_name, f.bank_account, f.bank_branch, f.id as freelancer_id FROM freelance_payments fp JOIN freelancers f ON f.id=fp.freelancer_id WHERE fp.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { header('Location: '.SITE_URL.'/freelance.php'); exit; }

$sym         = getSetting('currency_symbol', 'Rs.');
$companyName = getSetting('company_name', 'Creative Elements (Pvt) Ltd');
$companyAddr = getSetting('company_address', '27/1, 1St Lane, Boralesgamuwa');
$companyEmail= getSetting('company_email', '');
$logoPath    = getSetting('logo_path', '');
$bankName    = getSetting('bank_name', '');
$period      = date('F Y', strtotime(($p['month'] ?: date('Y-m')).'-01'));

// Smart amount logic — works for both old and new records
$invAmt = (float)($p['invoice_amount'] ?? 0);
$advAmt = (float)($p['advance_amount'] ?? 0);
$balDue = (float)($p['balance_due']    ?? 0);
$payAmt = (float)($p['payment_amount'] ?? 0);
if ($invAmt <= 0) $invAmt = $payAmt;
if ($advAmt <= 0) $advAmt = $invAmt;
if ($balDue <= 0) $balDue = max(0, $invAmt - $advAmt);
$hasAdvance = $advAmt > 0 && $advAmt < $invAmt;

pageHeader('Freelance Payment Slip');
?>

<div style="max-width:760px;margin:0 auto">

  <!-- Top Actions -->
  <div style="display:flex;gap:10px;margin-bottom:20px;align-items:center;flex-wrap:wrap">
    <a href="<?= SITE_URL ?>/freelance.php?tab=payments" class="btn btn-ghost btn-sm">← Back</a>
    <button onclick="window.print()" class="btn btn-primary btn-sm">🖨 Print / Save PDF</button>
    <a href="<?= SITE_URL ?>/send_freelance_payslip.php?id=<?= $id ?>" class="btn btn-ghost btn-sm" onclick="return confirm('Send payslip to <?= h($p['freelancer_name']) ?>?')">📧 Send Slip</a>
    <?php if ($hasAdvance): ?>
      <a href="<?= SITE_URL ?>/freelance.php?action=send_advance_notice&id=<?= $id ?>&month=<?= h($p['month']) ?>" class="btn btn-ghost btn-sm" style="color:var(--yellow)" onclick="return confirm('Send advance payment notice?')">💸 Send Advance Notice</a>
    <?php endif; ?>
  </div>

  <!-- Payslip Card -->
  <div class="payslip-doc">

    <!-- Header -->
    <div class="payslip-header">
      <div class="payslip-company">
        <?php if ($logoPath && file_exists(__DIR__.'/'.$logoPath)): ?>
          <img src="<?= SITE_URL.'/'.h($logoPath) ?>" style="height:40px;margin-bottom:8px;display:block" alt="Logo">
        <?php endif; ?>
        <h2><?= h($companyName) ?></h2>
        <p><?= h($companyAddr) ?><br><?= h($companyEmail) ?></p>
      </div>
      <div class="payslip-meta">
        <div style="font-size:13px;font-weight:800;color:var(--accent);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">PAYMENT SLIP</div>
        <div style="font-size:12px;color:var(--text2)">Period: <strong style="color:var(--text)"><?= $period ?></strong></div>
        <div style="font-size:12px;color:var(--text2);margin-top:2px">Status:
          <strong style="color:<?= $p['payment_status']==='paid'?'var(--green)':'var(--yellow)' ?>">
            <?= $p['payment_status']==='paid' ? 'PAID' : 'PENDING' ?>
          </strong>
        </div>
        <?php if ($p['payment_date']): ?>
          <div style="font-size:12px;color:var(--text2);margin-top:2px">Paid: <strong style="color:var(--text)"><?= date('Y-m-d',strtotime($p['payment_date'])) ?></strong></div>
        <?php endif; ?>
        <?php if (!empty($p['bank_reference'])): ?>
          <div style="font-size:12px;color:var(--text2);margin-top:2px">Bank Ref: <strong style="color:var(--text)"><?= h($p['bank_reference']) ?></strong></div>
        <?php endif; ?>
        <?php if ($p['invoice_number']): ?>
          <div style="font-size:12px;color:var(--text2);margin-top:2px">Ref: <strong style="color:var(--text)"><?= h($p['invoice_number']) ?></strong></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Freelancer + Payment Details -->
    <div class="payslip-grid">
      <div class="payslip-section">
        <h4>Freelancer Details</h4>
        <div class="payslip-row"><span>Name</span><span><?= h($p['freelancer_name']) ?></span></div>
        <?php if ($p['email']): ?><div class="payslip-row"><span>Email</span><span><?= h($p['email']) ?></span></div><?php endif; ?>
        <?php if ($p['phone']): ?><div class="payslip-row"><span>Phone</span><span><?= h($p['phone']) ?></span></div><?php endif; ?>
        <div class="payslip-row"><span>Project</span><span><strong><?= h($p['project_name']) ?></strong></span></div>
        <?php if ($p['invoice_date']): ?>
          <div class="payslip-row"><span>Invoice Date</span><span><?= date('d M Y',strtotime($p['invoice_date'])) ?></span></div>
        <?php endif; ?>
      </div>
      <div class="payslip-section">
        <h4>Payment Details</h4>
        <div class="payslip-row"><span>Method</span><span><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></span></div>
        <?php if ($p['bank_name']): ?><div class="payslip-row"><span>Bank</span><span><?= h($p['bank_name']) ?></span></div><?php endif; ?>
        <?php if ($p['bank_account']): ?><div class="payslip-row"><span>Account No.</span><span><?= h($p['bank_account']) ?></span></div><?php endif; ?>
        <?php if ($p['bank_branch']): ?><div class="payslip-row"><span>Branch</span><span><?= h($p['bank_branch']) ?></span></div><?php endif; ?>
        <?php if (!empty($p['bank_reference'])): ?><div class="payslip-row"><span>Bank Ref.</span><span><?= h($p['bank_reference']) ?></span></div><?php endif; ?>
        <?php if ($p['notes']): ?><div class="payslip-row"><span>Notes</span><span style="color:var(--text2)"><?= h($p['notes']) ?></span></div><?php endif; ?>
      </div>
    </div>

    <!-- Earnings -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
      <div class="payslip-section">
        <h4>Earnings</h4>
        <div class="payslip-row">
          <span>Project Payment</span>
          <span><?= $sym ?> <?= number_format($invAmt,2) ?></span>
        </div>
      </div>
      <?php if ($hasAdvance): ?>
      <div class="payslip-section">
        <h4>💸 Advance Breakdown</h4>
        <div class="payslip-row">
          <span>Total Invoice</span>
          <span><?= $sym ?> <?= number_format($invAmt,2) ?></span>
        </div>
        <div class="payslip-row">
          <span>Advance Paid</span>
          <span style="color:var(--green)">- <?= $sym ?> <?= number_format($advAmt,2) ?></span>
        </div>
        <div class="payslip-row">
          <span style="font-weight:700">Balance Due</span>
          <span style="color:var(--yellow);font-weight:700"><?= $sym ?> <?= number_format($balDue,2) ?></span>
        </div>
      </div>
      <?php else: ?>
      <div class="payslip-section">
        <h4>Deductions</h4>
        <div class="payslip-row"><span style="color:var(--text2)">No deductions</span><span><?= $sym ?> 0.00</span></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Net Total -->
    <div class="payslip-total">
      <div class="payslip-total-label"><?= $hasAdvance ? 'Advance Paid This Month' : 'Total Payment' ?></div>
      <div class="payslip-total-amount"><?= $sym ?> <?= number_format($hasAdvance ? $advAmt : $invAmt, 2) ?></div>
    </div>

    <?php if ($hasAdvance): ?>
    <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:12px 16px;margin-top:12px;font-size:12px;color:var(--yellow);text-align:center">
      Remaining balance of <strong><?= $sym ?> <?= number_format($balDue,2) ?></strong> will be paid upon project completion.
    </div>
    <?php endif; ?>

  </div>
</div>

<?php pageFooter(); ?>
