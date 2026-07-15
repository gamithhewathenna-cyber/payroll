<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// ── DELETE actions ──────────────────────────────────────────
if ($action === 'delete_payment' && $id) {
    $db->prepare("DELETE FROM freelance_payments WHERE id=?")->execute([$id]);
    setFlash('success', 'Payment deleted.');
    header('Location: ' . SITE_URL . '/freelance.php?month=' . ($_GET['month'] ?? date('Y-m'))); exit;
}
if ($action === 'delete_freelancer' && $id) {
    $db->prepare("DELETE FROM freelancers WHERE id=?")->execute([$id]);
    setFlash('success', 'Freelancer deleted.');
    header('Location: ' . SITE_URL . '/freelance.php'); exit;
}
if ($action === 'mark_paid' && $id) {
    // Load full payment details for email
    $stmt = $db->prepare("SELECT fp.*, f.freelancer_name, f.email FROM freelance_payments fp JOIN freelancers f ON f.id=fp.freelancer_id WHERE fp.id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $paidDate = !empty($_GET['payment_date']) ? $_GET['payment_date'] : (!empty($row['payment_date']) ? $row['payment_date'] : date('Y-m-d'));
    $bankRef  = trim($_GET['bank_ref'] ?? '') ?: null;
    $db->prepare("UPDATE freelance_payments SET payment_status='paid', payment_date=?, bank_reference=? WHERE id=?")->execute([$paidDate, $bankRef, $id]);

    // Send payment confirmation email
    if ($row && $row['email']) {
        $companyName = getSetting('company_name', SITE_NAME);
        $fromEmail   = getSetting('email_from', 'payroll@creativelements.co');
        $ccEmail     = getSetting('email_cc', '');
        $sym2        = getSetting('currency_symbol', 'Rs.');
        $invAmt      = (float)($row['invoice_amount'] ?? $row['payment_amount'] ?? 0);
        if ($invAmt <= 0) $invAmt = (float)($row['payment_amount'] ?? 0);
        $period      = date('F Y', strtotime(($row['month'] ?: date('Y-m')).'-01'));
        $msgId       = '<'.time().'.'.md5($row['email']).'@creativelements.co>';
        $fname       = htmlspecialchars($row['freelancer_name']);
        $coName      = htmlspecialchars($companyName);
        $projName    = htmlspecialchars($row['project_name']);
        $invNum      = htmlspecialchars($row['invoice_number'] ?? '-');
        $paidDateFmt = date('d F Y', strtotime($paidDate));

        $subject    = "Payment Received - " . $row['project_name'] . " | " . $companyName;
        $emailBody  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
        $emailBody .= 'body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}';
        $emailBody .= '.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}';
        $emailBody .= '.hdr{background:#1a1f2e;padding:28px 32px;text-align:center}';
        $emailBody .= '.hdr h1{color:#fff;margin:0;font-size:20px}.hdr p{color:rgba(255,255,255,.6);margin:4px 0 0;font-size:13px}';
        $emailBody .= '.badge{background:#00c48c;color:#fff;display:inline-block;padding:10px 28px;border-radius:30px;font-size:16px;font-weight:700;margin:24px auto}';
        $emailBody .= '.body{padding:28px 32px}.msg{font-size:15px;line-height:1.7;color:#444;margin-bottom:20px}';
        $emailBody .= '.box{background:#f8f8f8;border-radius:8px;padding:16px 20px;margin-bottom:16px}';
        $emailBody .= '.row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #eee;font-size:14px}';
        $emailBody .= '.row:last-child{border-bottom:none}.lbl{color:#888}.val{font-weight:700}';
        $emailBody .= '.total{background:#00c48c;border-radius:8px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}';
        $emailBody .= '.total .lbl{color:rgba(255,255,255,.8);font-size:14px}.total .val{color:#fff;font-size:22px;font-weight:900}';
        $emailBody .= '.footer{background:#f8f8f8;padding:14px 32px;text-align:center;font-size:11px;color:#aaa}';
        $emailBody .= '</style></head><body><div class="wrap">';
        $emailBody .= '<div class="hdr"><h1>'.$coName.'</h1><p>Payment Confirmation</p></div>';
        $emailBody .= '<div class="body">';
        $emailBody .= '<div style="text-align:center"><span class="badge">✅ Payment Completed</span></div>';
        $emailBody .= '<p class="msg">Dear <strong>'.$fname.'</strong>,<br><br>Your payment has been successfully processed. Please find the details below.</p>';
        $emailBody .= '<div class="box">';
        $emailBody .= '<div class="row"><span class="lbl">Project</span><span class="val">'.$projName.'</span></div>';
        $emailBody .= '<div class="row"><span class="lbl">Invoice #</span><span class="val">'.$invNum.'</span></div>';
        $emailBody .= '<div class="row"><span class="lbl">Period</span><span class="val">'.$period.'</span></div>';
        $emailBody .= '<div class="row"><span class="lbl">Payment Date</span><span class="val">'.$paidDateFmt.'</span></div>';
        $emailBody .= '<div class="row"><span class="lbl">Method</span><span class="val">'.ucwords(str_replace('_',' ',$row['payment_method'])).'</span></div>';
        if ($bankRef) $emailBody .= '<div class="row"><span class="lbl">Bank Ref.</span><span class="val">'.htmlspecialchars($bankRef).'</span></div>';
        $emailBody .= '</div>';
        $emailBody .= '<div class="total"><span class="lbl">Amount Paid</span><span class="val">'.$sym2.' '.number_format($invAmt,2).'</span></div>';
        $emailBody .= '<p style="font-size:13px;color:#666;text-align:center">Please check your bank account. Contact us if you have any questions.</p>';
        $emailBody .= '</div><div class="footer">'.$coName.' - Automated payment confirmation</div></div></body></html>';

        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$companyName} <{$fromEmail}>\r\n";
        if ($ccEmail) $headers .= "Cc: {$ccEmail}\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\nMessage-ID: {$msgId}\r\nDate: ".date('r')."\r\nX-Mailer: PHP/".phpversion();
        mail($row['email'], $subject, $emailBody, $headers, "-f{$fromEmail}");
    }

    setFlash('success', 'Marked as paid — ' . date('d M Y', strtotime($paidDate)) . ($row && $row['email'] ? '. Confirmation email sent.' : ''));
    header('Location: ' . SITE_URL . '/freelance.php?tab=payments&month=' . ($_GET['month'] ?? date('Y-m'))); exit;
}
if ($action === 'send_advance_notice' && $id) {
    $stmt = $db->prepare("SELECT fp.*, f.freelancer_name, f.email FROM freelance_payments fp JOIN freelancers f ON f.id=fp.freelancer_id WHERE fp.id=?");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if ($p && $p['email']) {
        $companyName = getSetting('company_name', SITE_NAME);
        $fromEmail   = getSetting('email_from', 'payroll@creativelements.co');
        $ccEmail     = getSetting('email_cc', '');
        $sym2        = getSetting('currency_symbol', 'Rs.');
        $invAmt      = (float)($p['invoice_amount']  ?? $p['payment_amount']);
        $advAmt      = (float)($p['advance_amount']  ?? 0);
        $balDue      = (float)($p['balance_due']     ?? 0);
        $period      = date('F Y', strtotime($p['month'].'-01'));
        $msgId       = '<'.time().'.'.md5($p['email']).'@creativelements.co>';
        $invDate     = $p['invoice_date'] ? date('d F Y', strtotime($p['invoice_date'])) : '-';
        $subject     = "Advance Payment Transferred - " . $p['project_name'] . " | " . $companyName;
        $emailBody   = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
        $emailBody  .= 'body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}';
        $emailBody  .= '.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden}';
        $emailBody  .= '.hdr{background:#1a1f2e;padding:28px 32px;text-align:center}';
        $emailBody  .= '.hdr h1{color:#fff;margin:0;font-size:20px}.hdr p{color:rgba(255,255,255,.6);margin:4px 0 0;font-size:13px}';
        $emailBody  .= '.body{padding:28px 32px}.msg{font-size:15px;line-height:1.7;color:#444;margin-bottom:20px}';
        $emailBody  .= '.box{background:#f8f8f8;border-radius:8px;padding:16px 20px;margin-bottom:16px}';
        $emailBody  .= '.row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #eee;font-size:13px}';
        $emailBody  .= '.row:last-child{border-bottom:none}.lbl{color:#888}.val{font-weight:700}';
        $emailBody  .= '.bal{background:#fff8e1;border:2px solid #f5a623;border-radius:8px;padding:14px;margin-bottom:16px;text-align:center}';
        $emailBody  .= '.bal-lbl{font-size:12px;color:#888}.bal-amt{font-size:22px;font-weight:900;color:#e67e22}';
        $emailBody  .= '.footer{background:#f8f8f8;padding:14px 32px;text-align:center;font-size:11px;color:#aaa}';
        $emailBody  .= '</style></head><body><div class="wrap">';
        $emailBody  .= '<div class="hdr"><h1>' . htmlspecialchars($companyName) . '</h1><p>Payment Notification</p></div>';
        $emailBody  .= '<div class="body">';
        $emailBody  .= '<p class="msg">Dear <strong>' . htmlspecialchars($p['freelancer_name']) . '</strong>,<br><br>An advance payment has been transferred for the following project.</p>';
        $emailBody  .= '<div class="box">';
        $emailBody  .= '<div class="row"><span class="lbl">Project</span><span class="val">' . htmlspecialchars($p['project_name']) . '</span></div>';
        $emailBody  .= '<div class="row"><span class="lbl">Invoice #</span><span class="val">' . htmlspecialchars($p['invoice_number'] ?? '-') . '</span></div>';
        $emailBody  .= '<div class="row"><span class="lbl">Invoice Date</span><span class="val">' . $invDate . '</span></div>';
        $emailBody  .= '<div class="row"><span class="lbl">Period</span><span class="val">' . $period . '</span></div>';
        $emailBody  .= '<div class="row"><span class="lbl">Total Invoice</span><span class="val">' . $sym2 . ' ' . number_format($invAmt,2) . '</span></div>';
        $emailBody  .= '<div class="row"><span class="lbl" style="color:#e67e22">Advance Paid</span><span class="val" style="color:#e67e22">' . $sym2 . ' ' . number_format($advAmt,2) . '</span></div>';
        $emailBody  .= '</div>';
        $emailBody  .= '<div class="bal"><div class="bal-lbl">Balance Due</div><div class="bal-amt">' . $sym2 . ' ' . number_format($balDue,2) . '</div></div>';
        $emailBody  .= '<p style="font-size:13px;color:#666">Please check your bank account. Contact us if you have not received it within 2 business days.</p>';
        $emailBody  .= '</div><div class="footer">' . htmlspecialchars($companyName) . ' - Automated notification</div></div></body></html>';
        $headers    = "MIME-Version: 1.0
Content-Type: text/html; charset=UTF-8
";
        $headers   .= "From: {$companyName} <{$fromEmail}>
";
        if ($ccEmail) $headers .= "Cc: {$ccEmail}
";
        $headers   .= "Reply-To: {$fromEmail}
Message-ID: {$msgId}
Date: " . date('r') . "
X-Mailer: PHP/" . phpversion();
        mail($p['email'], $subject, $emailBody, $headers, "-f{$fromEmail}");
        setFlash('success', "Advance notice sent to " . $p['freelancer_name'] . " (" . $p['email'] . ").");
    } else {
        setFlash('error', 'No email address found for this freelancer.');
    }
    header('Location: ' . SITE_URL . '/freelance.php?tab=payments&month=' . ($_GET['month'] ?? date('Y-m'))); exit;
}
if ($action === 'approve_submission' && $id) {
    $stmt = $db->prepare("SELECT vs.*, f.freelancer_name, f.email FROM vendor_submissions vs JOIN freelancers f ON f.id=vs.freelancer_id WHERE vs.id=?");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if ($sub) {
        $db->prepare("INSERT INTO freelance_payments (freelancer_id,project_name,invoice_number,invoice_date,payment_amount,payment_method,payment_status,month,invoice_file,invoice_file_name) VALUES (?,?,?,?,?,'bank_transfer','pending',?,?,?)")
           ->execute([$sub['freelancer_id'],$sub['project_name'],$sub['invoice_number'],$sub['invoice_date'],$sub['payment_amount'],$sub['month'],$sub['invoice_file'],$sub['invoice_file_name']]);
        $db->prepare("UPDATE vendor_submissions SET submission_status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?")
           ->execute([$_SESSION['full_name'], $id]);
        // Send approval email
        if ($sub['email']) {
            $companyName = getSetting('company_name', SITE_NAME);
            $fromEmail   = getSetting('email_from') ?: 'payroll@creativelements.co';
            $period      = date('F Y', strtotime($sub['month'].'-01'));
            $invNum      = $sub['invoice_number'];
            $amount      = formatMoney($sub['payment_amount']);
            $msgId       = '<'.time().'.'.md5($sub['email']).'@creativelements.co>';
            $emailBody   = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.header{background:#1a1f2e;padding:28px 32px;text-align:center}
.header h1{color:#fff;margin:0 0 4px;font-size:20px}
.header p{color:rgba(255,255,255,.6);margin:0;font-size:13px}
.badge{background:#00c48c;color:#fff;display:inline-block;padding:8px 24px;border-radius:30px;font-size:15px;font-weight:700;margin:24px auto}
.body{padding:28px 32px}
.msg{font-size:15px;line-height:1.7;color:#444;margin-bottom:24px}
.box{background:#f8f8f8;border-radius:8px;padding:18px 22px;margin-bottom:20px}
.row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #eee;font-size:14px}
.row:last-child{border-bottom:none}
.lbl{color:#888}.val{font-weight:600;color:#222}
.notice{background:#fff8e1;border-left:4px solid #f5a623;padding:12px 16px;border-radius:4px;font-size:13px;color:#666}
.footer{background:#f8f8f8;padding:16px 32px;text-align:center;font-size:12px;color:#aaa;border-top:1px solid #eee}
</style></head><body><div class="wrap">
<div class="header"><h1>'.htmlspecialchars($companyName).'</h1><p>Vendor Invoice Notification</p></div>
<div class="body">
<div style="text-align:center"><span class="badge">✅ Invoice Approved</span></div>
<p class="msg">Dear <strong>'.htmlspecialchars($sub['freelancer_name']).'</strong>,<br><br>
Great news! Your invoice has been <strong style="color:#00c48c">approved</strong> by our accounts team.<br>
Your payment will be processed and ready soon. We will notify you once the payment has been made.</p>
<div class="box">
<div class="row"><span class="lbl">Invoice Number</span><span class="val">'.htmlspecialchars($invNum).'</span></div>
<div class="row"><span class="lbl">Project</span><span class="val">'.htmlspecialchars($sub['project_name']).'</span></div>
<div class="row"><span class="lbl">Amount</span><span class="val" style="color:#00c48c">'.$amount.'</span></div>
<div class="row"><span class="lbl">Period</span><span class="val">'.$period.'</span></div>
<div class="row"><span class="lbl">Status</span><span class="val" style="color:#00c48c">✅ Approved — Payment Pending</span></div>
</div>
<div class="notice">💡 If you have any questions about your payment, please contact our accounts team.</div>
</div>
<div class="footer">'.htmlspecialchars($companyName).' · This is an automated notification.</div>
</div></body></html>';
            $subject  = "Invoice Approved: {$invNum} | {$companyName}";
            $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$companyName} <{$fromEmail}>\r\n";
            $ccEmail = getCCEmail();
            if ($ccEmail) $headers .= "Cc: {$ccEmail}\r\n";
            $headers .= "Reply-To: {$fromEmail}\r\nMessage-ID: {$msgId}\r\nDate: ".date('r')."\r\nX-Mailer: PHP/".phpversion();
            mail($sub['email'], $subject, $emailBody, $headers, "-f{$fromEmail}");
        }
        setFlash('success', 'Invoice approved and added to payroll.' . ($sub['email'] ? ' Approval email sent to vendor.' : ''));
    }
    header('Location: ' . SITE_URL . '/freelance.php?tab=approvals'); exit;
}
if ($action === 'reject_submission' && $id) {
    $reason = trim($_GET['reason'] ?? 'Rejected by admin.');
    $stmt   = $db->prepare("SELECT vs.*, f.freelancer_name, f.email FROM vendor_submissions vs JOIN freelancers f ON f.id=vs.freelancer_id WHERE vs.id=?");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    $db->prepare("UPDATE vendor_submissions SET submission_status='rejected', rejection_reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")
       ->execute([$reason, $_SESSION['full_name'], $id]);
    // Send rejection email
    if ($sub && $sub['email']) {
        $companyName = getSetting('company_name', SITE_NAME);
        $fromEmail   = getSetting('email_from') ?: 'payroll@creativelements.co';
        $invNum      = $sub['invoice_number'];
        $msgId       = '<'.time().'.'.md5($sub['email']).'@creativelements.co>';
        $emailBody   = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.header{background:#1a1f2e;padding:28px 32px;text-align:center}
.header h1{color:#fff;margin:0 0 4px;font-size:20px}
.header p{color:rgba(255,255,255,.6);margin:0;font-size:13px}
.badge{background:#ff4d6d;color:#fff;display:inline-block;padding:8px 24px;border-radius:30px;font-size:15px;font-weight:700;margin:24px auto}
.body{padding:28px 32px}
.msg{font-size:15px;line-height:1.7;color:#444;margin-bottom:20px}
.reason{background:#fff0f3;border-left:4px solid #ff4d6d;padding:14px 18px;border-radius:4px;font-size:14px;color:#c0392b;margin-bottom:20px}
.notice{background:#fff8e1;border-left:4px solid #f5a623;padding:12px 16px;border-radius:4px;font-size:13px;color:#666}
.footer{background:#f8f8f8;padding:16px 32px;text-align:center;font-size:12px;color:#aaa;border-top:1px solid #eee}
</style></head><body><div class="wrap">
<div class="header"><h1>'.htmlspecialchars($companyName).'</h1><p>Vendor Invoice Notification</p></div>
<div class="body">
<div style="text-align:center"><span class="badge">❌ Invoice Not Approved</span></div>
<p class="msg">Dear <strong>'.htmlspecialchars($sub['freelancer_name']).'</strong>,<br><br>
Unfortunately, your invoice <strong>'.htmlspecialchars($invNum).'</strong> could not be approved at this time.</p>
<div class="reason"><strong>Reason:</strong> '.htmlspecialchars($reason).'</div>
<div class="notice">💡 Please review the reason above, make the necessary corrections, and resubmit through the vendor portal. If you need help, contact our accounts team.</div>
</div>
<div class="footer">'.htmlspecialchars($companyName).' · This is an automated notification.</div>
</div></body></html>';
        $subject  = "Invoice Update: {$invNum} | {$companyName}";
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$companyName} <{$fromEmail}>\r\n";
        $ccEmail = getCCEmail();
            if ($ccEmail) $headers .= "Cc: {$ccEmail}\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\nMessage-ID: {$msgId}\r\nDate: ".date('r')."\r\nX-Mailer: PHP/".phpversion();
        mail($sub['email'], $subject, $emailBody, $headers, "-f{$fromEmail}");
    }
    setFlash('success', 'Invoice rejected.' . ($sub && $sub['email'] ? ' Rejection email sent to vendor.' : ''));
    header('Location: ' . SITE_URL . '/freelance.php?tab=approvals'); exit;
}
if ($action === 'delete_submission' && $id) {
    $db->prepare("DELETE FROM vendor_submissions WHERE id=?")->execute([$id]);
    setFlash('success', 'Submission deleted.');
    header('Location: ' . SITE_URL . '/freelance.php?tab=approvals'); exit;
}

// ── POST actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = $_POST;

    // Add freelancer
    if ($action === 'add_freelancer') {
        $db->prepare("INSERT INTO freelancers (freelancer_name,email,phone,bank_name,bank_account,bank_branch) VALUES (?,?,?,?,?,?)")
           ->execute([trim($d['freelancer_name']),trim($d['email']),trim($d['phone']),trim($d['bank_name']),trim($d['bank_account']),trim($d['bank_branch'])]);
        setFlash('success', 'Freelancer added.');
        header('Location: ' . SITE_URL . '/freelance.php'); exit;
    }

    // Edit freelancer
    if ($action === 'edit_freelancer') {
        $db->prepare("UPDATE freelancers SET freelancer_name=?,email=?,phone=?,bank_name=?,bank_account=?,bank_branch=?,status=? WHERE id=?")
           ->execute([trim($d['freelancer_name']),trim($d['email']),trim($d['phone']),trim($d['bank_name']),trim($d['bank_account']),trim($d['bank_branch']),$d['status'],$id]);
        setFlash('success', 'Freelancer updated.');
        header('Location: ' . SITE_URL . '/freelance.php'); exit;
    }

    // Add payment
    if ($action === 'add_payment') {
        // Handle invoice file upload
        $invoiceFile = null;
        $invoiceOrigName = null;
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === 0) {
            $allowed = ['application/pdf','image/jpeg','image/png','image/jpg'];
            $ftype   = mime_content_type($_FILES['invoice_file']['tmp_name']);
            if (in_array($ftype, $allowed)) {
                $year    = date('Y', strtotime($d['invoice_date'] ?: 'now'));
                $month   = date('F', strtotime($d['invoice_date'] ?: 'now'));
                $dir     = __DIR__ . "/invoices/{$year}/{$month}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext      = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($d['invoice_number'] ?: 'INV'));
                $filename = $safeName . '_' . time() . '.' . strtolower($ext);
                if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dir . $filename)) {
                    $invoiceFile     = "invoices/{$year}/{$month}/{$filename}";
                    $invoiceOrigName = $_FILES['invoice_file']['name'];
                }
            }
        }
        $db->prepare("INSERT INTO freelance_payments (freelancer_id,project_name,invoice_number,invoice_date,payment_amount,payment_method,payment_status,month,notes,invoice_file,invoice_file_name,bank_reference) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$d['freelancer_id'],trim($d['project_name']),trim($d['invoice_number']),trim($d['invoice_date']),$d['payment_amount'],$d['payment_method'],$d['payment_status'],$d['month'],trim($d['notes']),$invoiceFile,$invoiceOrigName,trim($d['bank_reference']??'') ?: null]);
        if ($d['payment_status'] === 'paid') {
            $lid = $db->lastInsertId();
            $db->prepare("UPDATE freelance_payments SET payment_date=CURDATE() WHERE id=?")->execute([$lid]);
        }
        setFlash('success', 'Payment added.' . ($invoiceFile ? ' Invoice uploaded.' : ''));
        header('Location: ' . SITE_URL . '/freelance.php?month=' . $d['month']); exit;
    }

    // Edit payment
    if ($action === 'edit_payment') {
        $payDate  = !empty($d['payment_date']) ? $d['payment_date'] : null;
        $invAmt   = (float)($d['invoice_amount']  ?? $d['payment_amount'] ?? 0);
        $advAmt   = trim($d['advance_amount'] ?? '') !== '' ? (float)$d['advance_amount'] : $invAmt;
        $balDue   = round(max(0, $invAmt - $advAmt), 2);

        // Handle invoice file upload
        $invoiceFile     = null;
        $invoiceOrigName = null;
        if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === 0) {
            $allowed = ['application/pdf','image/jpeg','image/png','image/jpg'];
            $ftype   = mime_content_type($_FILES['invoice_file']['tmp_name']);
            if (in_array($ftype, $allowed)) {
                $year    = date('Y', strtotime($d['invoice_date'] ?: 'now'));
                $month   = date('F', strtotime($d['invoice_date'] ?: 'now'));
                $dir     = __DIR__ . "/invoices/{$year}/{$month}/";
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext      = pathinfo($_FILES['invoice_file']['name'], PATHINFO_EXTENSION);
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($d['invoice_number'] ?: 'INV'));
                $filename = $safeName . '_' . time() . '.' . strtolower($ext);
                if (move_uploaded_file($_FILES['invoice_file']['tmp_name'], $dir . $filename)) {
                    $invoiceFile     = "invoices/{$year}/{$month}/{$filename}";
                    $invoiceOrigName = $_FILES['invoice_file']['name'];
                }
            }
        }

        $bankRef = trim($d['bank_reference']??'') ?: null;
        if ($invoiceFile) {
            $db->prepare("UPDATE freelance_payments SET freelancer_id=?,project_name=?,invoice_number=?,invoice_date=?,invoice_amount=?,advance_amount=?,balance_due=?,payment_amount=?,payment_method=?,payment_status=?,payment_date=?,month=?,notes=?,invoice_file=?,invoice_file_name=?,bank_reference=? WHERE id=?")
               ->execute([$d['freelancer_id'],trim($d['project_name']),trim($d['invoice_number']),trim($d['invoice_date']),$invAmt,$advAmt,$balDue,$invAmt,$d['payment_method'],$d['payment_status'],$payDate,$d['month'],trim($d['notes']),$invoiceFile,$invoiceOrigName,$bankRef,$id]);
        } else {
            $db->prepare("UPDATE freelance_payments SET freelancer_id=?,project_name=?,invoice_number=?,invoice_date=?,invoice_amount=?,advance_amount=?,balance_due=?,payment_amount=?,payment_method=?,payment_status=?,payment_date=?,month=?,notes=?,bank_reference=? WHERE id=?")
               ->execute([$d['freelancer_id'],trim($d['project_name']),trim($d['invoice_number']),trim($d['invoice_date']),$invAmt,$advAmt,$balDue,$invAmt,$d['payment_method'],$d['payment_status'],$payDate,$d['month'],trim($d['notes']),$bankRef,$id]);
        }
        if ($d['payment_status'] === 'paid') {
            $db->prepare("UPDATE freelance_payments SET payment_date=CURDATE() WHERE id=? AND payment_date IS NULL")->execute([$id]);
        }
        $sym2 = getSetting('currency_symbol','Rs.');
        setFlash('success', 'Payment updated.' . ($balDue > 0 ? ' Balance due: '.$sym2.' '.number_format($balDue,2) : '') . ($invoiceFile ? ' Invoice uploaded.' : ''));
        header('Location: ' . SITE_URL . '/freelance.php?month=' . $d['month']); exit;
    }
}

$filterMonth   = $_GET['month'] ?? date('Y-m');
$tab           = $_GET['tab'] ?? 'payments';

// Load data
$freelancers = $db->query("SELECT * FROM freelancers ORDER BY freelancer_name")->fetchAll();

// Pending approvals count for badge
$pendingApprovals = $db->query("SELECT COUNT(*) FROM vendor_submissions WHERE submission_status='pending'")->fetchColumn();

$payments = $db->prepare("
    SELECT fp.*, f.freelancer_name, f.email, f.bank_name, f.bank_account, f.bank_branch
    FROM freelance_payments fp
    JOIN freelancers f ON f.id = fp.freelancer_id
    WHERE fp.month = ?
    ORDER BY fp.created_at DESC
");
$payments->execute([$filterMonth]);
$payments = $payments->fetchAll();

$totalPaid    = array_sum(array_column(array_filter($payments, fn($p) => $p['payment_status']==='paid'), 'payment_amount'));
$totalPending = array_sum(array_column(array_filter($payments, fn($p) => $p['payment_status']==='pending'), 'payment_amount'));

// Edit rows
$editPayment    = null;
$editFreelancer = null;
if ($action === 'edit_payment' && $id) {
    $s = $db->prepare("SELECT * FROM freelance_payments WHERE id=?"); $s->execute([$id]); $editPayment = $s->fetch();
}
if ($action === 'edit_freelancer' && $id) {
    $s = $db->prepare("SELECT * FROM freelancers WHERE id=?"); $s->execute([$id]); $editFreelancer = $s->fetch();
}

pageHeader('Freelance Payroll');
?>

<!-- Header Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:20px">
  <div class="stat-card yellow">
    <div class="stat-label">Freelancers</div>
    <div class="stat-value"><?= count($freelancers) ?></div>
    <div class="stat-sub">Registered</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Paid <?= date('M Y', strtotime($filterMonth.'-01')) ?></div>
    <div class="stat-value" style="font-size:20px"><?= formatMoney($totalPaid) ?></div>
    <div class="stat-sub">Completed</div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">Pending <?= date('M Y', strtotime($filterMonth.'-01')) ?></div>
    <div class="stat-value" style="font-size:20px"><?= formatMoney($totalPending) ?></div>
    <div class="stat-sub">Outstanding</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total <?= date('M Y', strtotime($filterMonth.'-01')) ?></div>
    <div class="stat-value" style="font-size:20px"><?= formatMoney($totalPaid + $totalPending) ?></div>
    <div class="stat-sub">All payments</div>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:6px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:0">
  <a href="?tab=payments&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='payments' ? 'background:var(--accent);color:#fff' : 'background:var(--bg3);color:var(--text2)' ?>">
    💰 Payments
  </a>
  <a href="?tab=approvals" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;position:relative;<?= $tab==='approvals' ? 'background:var(--yellow);color:#000' : 'background:var(--bg3);color:var(--text2)' ?>">
    📥 Approvals
    <?php if ($pendingApprovals > 0): ?>
      <span style="position:absolute;top:-6px;right:-6px;background:var(--red);color:#fff;font-size:10px;font-weight:700;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center"><?= $pendingApprovals ?></span>
    <?php endif; ?>
  </a>
  <a href="?tab=freelancers&month=<?= $filterMonth ?>" style="padding:10px 18px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:13.5px;<?= $tab==='freelancers' ? 'background:var(--accent);color:#fff' : 'background:var(--bg3);color:var(--text2)' ?>">
    👤 Freelancers
  </a>
</div>

<?php if ($tab === 'payments'): ?>
<!-- ═══════════════════════════════ PAYMENTS TAB ═══════════════════════════════ -->

<div class="section-header">
  <form method="GET" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="tab" value="payments">
    <input type="month" name="month" value="<?= h($filterMonth) ?>" style="width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
  </form>
  <div style="display:flex;gap:8px">
    <?php if (!empty($freelancers)): ?>
      <button class="btn btn-primary" onclick="openModal('addPaymentModal')">+ Add Payment</button>
    <?php else: ?>
      <span style="color:var(--text2);font-size:13px;padding:8px">Add a freelancer first →</span>
      <a href="?tab=freelancers" class="btn btn-primary btn-sm">+ Add Freelancer</a>
    <?php endif; ?>
  </div>
</div>

<!-- Highlighted Freelance Box -->
<div style="border:2px solid var(--yellow);border-radius:12px;overflow:hidden;margin-bottom:24px">
  <div style="background:rgba(245,166,35,.1);padding:12px 20px;display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(245,166,35,.3)">
    <span style="font-size:18px">🧑‍💻</span>
    <span style="font-weight:700;font-size:14px;color:var(--yellow)">FREELANCE PAYROLL</span>
    <span style="font-size:12px;color:var(--text2);margin-left:4px">— Managed separately from company employees</span>
  </div>
  <div style="padding:0">
    <div class="table-wrap mob-card-table">
      <table>
        <thead>
          <tr>
            <th>Freelancer</th>
            <th>Project</th>
            <th>Invoice #</th>
            <th>Invoice Date</th>
            <th>Amount</th>
            <th>Method</th>
            <th>Invoice File</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text2);padding:32px">
              No freelance payments for <?= date('F Y', strtotime($filterMonth.'-01')) ?>.
            </td></tr>
          <?php else: foreach ($payments as $p): ?>
            <tr>
              <td data-label="Freelancer">
                <strong><?= h($p['freelancer_name']) ?></strong>
                <?php if ($p['email']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h($p['email']) ?></span><?php endif; ?>
              </td>
              <td data-label="Project"><?= h($p['project_name']) ?></td>
              <td data-label="Invoice #">
                <?php if ($p['invoice_number']): ?>
                  <span class="badge badge-blue"><?= h($p['invoice_number']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text2)">—</span>
                <?php endif; ?>
              </td>
              <td data-label="Date" style="color:var(--text2);font-size:12px">
                <?= $p['invoice_date'] ? date('d M Y', strtotime($p['invoice_date'])) : '—' ?>
              </td>
              <td data-label="Amount">
                <strong style="color:var(--green)"><?= formatMoney($p['payment_amount']) ?></strong>
                <?php if (($p['advance_amount']??0) > 0 && ($p['advance_amount']??0) < $p['payment_amount']): ?>
                  <div style="font-size:11px;color:var(--text2)">Advance: <?= formatMoney($p['advance_amount']) ?></div>
                <?php endif; ?>
              </td>
              <td data-label="Balance">
                <?php
                $bal = (float)($p['balance_due'] ?? 0);
                $isPaid = $p['payment_status'] === 'paid';
                ?>
                <?php if ($bal > 0 && !$isPaid): ?>
                  <span style="color:var(--yellow);font-weight:700"><?= formatMoney($bal) ?></span>
                <?php else: ?>
                  <span style="color:var(--text2)">—</span>
                <?php endif; ?>
              </td>
              <td data-label="Method" style="color:var(--text2);font-size:12px"><?= ucwords(str_replace('_',' ',$p['payment_method'])) ?></td>
              <td data-label="File">
                <?php if ($p['invoice_file']): ?>
                  <a href="<?= SITE_URL.'/'.h($p['invoice_file']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="<?= h($p['invoice_file_name']) ?>">
                    📄 View
                  </a>
                <?php else: ?>
                  <span style="color:var(--text2);font-size:12px">—</span>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <?php if ($p['payment_status'] === 'paid'): ?>
                  <span class="badge badge-green">✓ Paid</span>
                  <?php if ($p['payment_date']): ?>
                    <div style="font-size:11px;color:var(--green);margin-top:3px">📅 <?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($p['bank_reference'])): ?>
                    <div style="font-size:11px;color:var(--text2);margin-top:1px">🏦 <?= h($p['bank_reference']) ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge-yellow">Pending</span>
                <?php endif; ?>
              </td>
              <td data-label=""><div class="mob-actions">
                <a href="<?= SITE_URL ?>/freelance_payslip.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Payslip</a>
                <a href="<?= SITE_URL ?>/send_freelance_payslip.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm" onclick="return confirm('Send payslip to <?= h($p['freelancer_name']) ?>?')" <?= !$p['email'] ? 'style="opacity:.4;pointer-events:none"title="No email set"' : '' ?>>📧 Send Slip</a>
                <?php if (($p['advance_amount']??0) > 0 && ($p['advance_amount']??0) < $p['payment_amount']): ?>
                  <a href="?action=send_advance_notice&id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm" style="color:var(--yellow)" onclick="return confirm('Send advance payment notification to <?= h($p['freelancer_name']) ?>?')" <?= !$p['email'] ? 'style="opacity:.4;pointer-events:none"title="No email set"' : '' ?>>💸 Advance</a>
                <?php endif; ?>
                <a href="?action=edit_payment&id=<?= $p['id'] ?>&tab=payments&month=<?= $filterMonth ?>" class="btn btn-ghost btn-sm">Edit</a>
                <?php if ($p['payment_status'] !== 'paid'): ?>
                  <button type="button" onclick="openMarkPaid(<?= $p['id'] ?>)" class="btn btn-success btn-sm">✓ Paid</button>
                <?php endif; ?>
                <a href="?action=delete_payment&id=<?= $p['id'] ?>&month=<?= $filterMonth ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete this payment?')">Del</a>
              </div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Mark as Paid Modal -->
<div class="modal-overlay" id="markPaidModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <div class="modal-title">✓ Mark as Paid</div>
      <button class="modal-close" onclick="closeModal('markPaidModal')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:14px">
        <label>Payment Date *</label>
        <input type="date" id="markPaidDate">
      </div>
      <div class="form-group" style="margin-bottom:16px">
        <label>Bank Reference Code</label>
        <input type="text" id="markPaidRef" placeholder="e.g. TXN123456789">
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-success" style="width:auto;padding:8px 20px" onclick="confirmMarkPaid()">✓ Confirm Paid</button>
        <button class="btn btn-ghost" style="width:auto;padding:8px 20px;background:var(--bg3);color:var(--text2)" onclick="closeModal('markPaidModal')">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script>
let markPaidId = null;
function openMarkPaid(id) {
    markPaidId = id;
    document.getElementById('markPaidDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('markPaidRef').value = '';
    openModal('markPaidModal');
}
function confirmMarkPaid() {
    const date = document.getElementById('markPaidDate').value;
    if (!date) { alert('Please select a payment date.'); return; }
    const ref = encodeURIComponent(document.getElementById('markPaidRef').value || '');
    window.location = `?action=mark_paid&id=${markPaidId}&month=<?= h($filterMonth) ?>&payment_date=${date}&bank_ref=${ref}`;
}
</script>

<?php elseif ($tab === 'approvals'): ?>
<!-- ═══════════════════════════════ APPROVALS TAB ═══════════════════════════════ -->

<?php
$allSubs = $db->query("SELECT vs.*, f.freelancer_name, f.email FROM vendor_submissions vs JOIN freelancers f ON f.id=vs.freelancer_id ORDER BY vs.submitted_at DESC")->fetchAll();
$pendingSubs  = array_filter($allSubs, fn($s) => $s['submission_status'] === 'pending');
$reviewedSubs = array_filter($allSubs, fn($s) => $s['submission_status'] !== 'pending');
?>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <div class="modal-title">Reject Invoice</div>
      <button class="modal-close" onclick="closeModal('rejectModal')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group" style="margin-bottom:16px">
        <label>Reason for rejection</label>
        <textarea id="rejectReason" placeholder="e.g. Invoice amount doesn't match agreement..." style="background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:10px;border-radius:8px;width:100%;font-family:Poppins,sans-serif;font-size:13px;resize:vertical;min-height:80px"></textarea>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-danger" style="width:auto;padding:8px 20px" onclick="confirmReject()">Reject</button>
        <button class="btn btn-ghost" style="width:auto;padding:8px 20px;background:var(--bg3);color:var(--text2)" onclick="closeModal('rejectModal')">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- Pending Submissions -->
<div style="border:2px solid var(--yellow);border-radius:12px;overflow:hidden;margin-bottom:24px">
  <div style="background:rgba(245,166,35,.1);padding:12px 20px;display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(245,166,35,.3)">
    <span style="font-size:16px">📥</span>
    <span style="font-weight:700;font-size:14px;color:var(--yellow)">PENDING APPROVAL</span>
    <span style="background:var(--red);color:#fff;font-size:11px;font-weight:700;border-radius:20px;padding:2px 8px"><?= count($pendingSubs) ?> pending</span>
    <span style="font-size:12px;color:var(--text2);margin-left:4px">— Submitted via Vendor Portal</span>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Freelancer</th><th>Project</th><th>Invoice #</th><th>Date</th><th>Amount</th><th>Invoice</th><th>Submitted</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($pendingSubs)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text2);padding:30px">✅ No pending submissions.</td></tr>
        <?php else: foreach ($pendingSubs as $s): ?>
          <tr style="background:rgba(245,166,35,.03)">
            <td>
              <strong><?= h($s['freelancer_name']) ?></strong><br>
              <?php if ($s['email']): ?>
                <span style="font-size:11px;color:var(--text2)"><?= h($s['email']) ?></span>
              <?php else: ?>
                <span style="font-size:11px;color:var(--red)">⚠️ No email — <a href="?action=edit_freelancer&id=<?= $s['freelancer_id'] ?>&tab=freelancers" style="color:var(--red)">Add email</a></span>
              <?php endif; ?>
            </td>
            <td><?= h($s['project_name']) ?></td>
            <td><span class="badge badge-blue"><?= h($s['invoice_number']) ?></span></td>
            <td style="font-size:12px"><?= date('d M Y', strtotime($s['invoice_date'])) ?></td>
            <td><strong style="color:var(--green)"><?= formatMoney($s['payment_amount']) ?></strong></td>
            <td>
              <?php if ($s['invoice_file']): ?>
                <a href="<?= SITE_URL.'/'.h($s['invoice_file']) ?>" target="_blank" class="btn btn-ghost btn-sm">📄 View</a>
              <?php else: ?><span style="color:var(--text2)">—</span><?php endif; ?>
            </td>
            <td style="font-size:11px;color:var(--text2)"><?= date('d M Y H:i', strtotime($s['submitted_at'])) ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=approve_submission&id=<?= $s['id'] ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve and add to payroll?')">✅ Approve</a>
              <button onclick="openReject(<?= $s['id'] ?>)" class="btn btn-danger btn-sm">❌ Reject</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Reviewed Submissions -->
<div class="card">
  <div class="card-title">Previously Reviewed</div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Freelancer</th><th>Project</th><th>Invoice #</th><th>Amount</th><th>Status</th><th>Reviewed By</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($reviewedSubs)): ?>
          <tr><td colspan="7" style="text-align:center;color:var(--text2);padding:20px">No reviewed submissions yet.</td></tr>
        <?php else: foreach ($reviewedSubs as $s): ?>
          <tr>
            <td><strong><?= h($s['freelancer_name']) ?></strong></td>
            <td><?= h($s['project_name']) ?></td>
            <td><?= h($s['invoice_number']) ?></td>
            <td><?= formatMoney($s['payment_amount']) ?></td>
            <td>
              <?php if ($s['submission_status'] === 'approved'): ?>
                <span class="badge badge-green">✅ Approved</span>
              <?php else: ?>
                <span class="badge badge-red">❌ Rejected</span>
                <?php if ($s['rejection_reason']): ?><div style="font-size:11px;color:var(--text2);margin-top:3px"><?= h($s['rejection_reason']) ?></div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text2)"><?= h($s['reviewed_by']) ?><br><?= $s['reviewed_at'] ? date('d M Y', strtotime($s['reviewed_at'])) : '' ?></td>
            <td><a href="?action=delete_submission&id=<?= $s['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete()">Del</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
let rejectId = null;
function openReject(id) { rejectId = id; openModal('rejectModal'); }
function confirmReject() {
    const reason = encodeURIComponent(document.getElementById('rejectReason').value || 'Rejected by admin.');
    window.location = `?action=reject_submission&id=${rejectId}&reason=${reason}`;
}
</script>

<?php else: ?>
<!-- ═══════════════════════════════ FREELANCERS TAB ═══════════════════════════════ -->

<div class="section-header">
  <div></div>
  <button class="btn btn-primary" onclick="openModal('addFreelancerModal')">+ Add Freelancer</button>
</div>

<div class="card">
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Bank</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (empty($freelancers)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:30px">No freelancers yet. Add one to get started.</td></tr>
        <?php else: foreach ($freelancers as $f): ?>
          <tr>
            <td data-label="Name"><strong><?= h($f['freelancer_name']) ?></strong></td>
            <td data-label="Email" style="color:var(--text2)"><?= h($f['email']) ?: '—' ?></td>
            <td data-label="Phone" style="color:var(--text2)"><?= h($f['phone']) ?: '—' ?></td>
            <td>
              <?php if ($f['bank_name']): ?>
                <span><?= h($f['bank_name']) ?></span>
                <?php if ($f['bank_branch']): ?><br><span style="font-size:11px;color:var(--text2)"><?= h($f['bank_branch']) ?></span><?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text2)">—</span>
              <?php endif; ?>
            </td>
            <td><?= $f['status']==='active' ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-red">Inactive</span>' ?></td>
            <td data-label=""><div class="mob-actions">
              <a href="?action=edit_freelancer&id=<?= $f['id'] ?>&tab=freelancers" class="btn btn-ghost btn-sm">Edit</a>
              <a href="?action=delete_freelancer&id=<?= $f['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirmDelete('Delete <?= h($f['freelancer_name']) ?>?')">Del</a>
            </div></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>


<!-- ══════ ADD PAYMENT MODAL ══════ -->
<div class="modal-overlay" id="addPaymentModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">🧑‍💻 Add Freelance Payment</div>
      <button class="modal-close" onclick="closeModal('addPaymentModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add_payment" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group full">
            <label>Freelancer *</label>
            <select name="freelancer_id" required>
              <option value="">— Select Freelancer —</option>
              <?php foreach ($freelancers as $f): ?>
                <option value="<?= $f['id'] ?>"><?= h($f['freelancer_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full"><label>Project Name *</label><input name="project_name" required placeholder="e.g. Website Redesign"></div>
          <div class="form-group"><label>Invoice Number</label><input name="invoice_number" placeholder="e.g. INV-2026-001"></div>
          <div class="form-group"><label>Invoice Date *</label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="form-group"><label>Payment Amount *</label><input type="number" name="payment_amount" step="0.01" required placeholder="0.00"></div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= $filterMonth ?>" required></div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cash">Cash</option>
              <option value="online">Online Payment</option>
            </select>
          </div>
          <div class="form-group"><label>Payment Status</label>
            <select name="payment_status">
              <option value="pending">Pending</option>
              <option value="paid">Paid</option>
            </select>
          </div>
          <div class="form-group"><label>Bank Reference Code</label><input type="text" name="bank_reference" placeholder="e.g. TXN123456789"></div>
          <div class="form-group full">
            <label>📎 Upload Invoice</label>
            <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" style="padding:6px">
            <span style="font-size:11px;color:var(--text2)">PDF, JPG or PNG — saved to invoices/2026/May/ automatically</span>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" placeholder="Any additional notes..."></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Payment</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addPaymentModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════ EDIT PAYMENT MODAL ══════ -->
<?php if ($editPayment): ?>
<div class="modal-overlay open" id="editPaymentModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Freelance Payment</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/freelance.php?tab=payments&month=<?= $filterMonth ?>'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit_payment&id=<?= $editPayment['id'] ?>" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group full">
            <label>Freelancer *</label>
            <select name="freelancer_id" required>
              <?php foreach ($freelancers as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $f['id']==$editPayment['freelancer_id']?'selected':'' ?>><?= h($f['freelancer_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full"><label>Project Name *</label><input name="project_name" required value="<?= h($editPayment['project_name']) ?>"></div>
          <div class="form-group"><label>Invoice Number</label><input name="invoice_number" value="<?= h($editPayment['invoice_number']) ?>"></div>
          <div class="form-group"><label>Invoice Date *</label><input type="date" name="invoice_date" value="<?= h($editPayment['invoice_date'] ?? date('Y-m-d')) ?>" required></div>
          <div class="form-group"><label>Total Invoice Amount *</label>
            <input type="number" name="invoice_amount" id="fInvAmt" step="0.01" required
              value="<?= h($editPayment['invoice_amount'] ?? $editPayment['payment_amount']) ?>"
              placeholder="0.00" oninput="calcBal()">
            <span style="font-size:11px;color:var(--text2)">Full amount on the vendor invoice</span>
          </div>
          <div class="form-group"><label>Advance / Paying Now</label>
            <input type="number" name="advance_amount" id="fAdvAmt" step="0.01"
              value="<?= h($editPayment['advance_amount'] ?? $editPayment['payment_amount']) ?>"
              placeholder="Leave blank = pay in full" oninput="calcBal()">
            <span style="font-size:11px;color:var(--text2)">Amount being paid now</span>
          </div>
          <div class="form-group">
            <label>Balance Due</label>
            <div id="fBalDisplay" style="font-size:22px;font-weight:800;color:var(--yellow);padding:8px 0">
              <?php
                $fInv = (float)($editPayment['invoice_amount'] ?? $editPayment['payment_amount'] ?? 0);
                $fAdv = (float)($editPayment['advance_amount'] ?? $editPayment['payment_amount'] ?? $fInv);
                $fSym = getSetting('currency_symbol','Rs.');
                echo $fSym . ' ' . number_format(max(0, $fInv - $fAdv), 2);
              ?>
            </div>
            <span style="font-size:11px;color:var(--text2)">Remaining to pay later</span>
          </div>
          <div class="form-group"><label>Month *</label><input type="month" name="month" value="<?= h($editPayment['month']) ?>" required></div>
          <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
              <option value="bank_transfer" <?= $editPayment['payment_method']==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
              <option value="cash" <?= $editPayment['payment_method']==='cash'?'selected':'' ?>>Cash</option>
              <option value="online" <?= $editPayment['payment_method']==='online'?'selected':'' ?>>Online Payment</option>
            </select>
          </div>
          <div class="form-group"><label>Payment Status</label>
            <select name="payment_status">
              <option value="pending" <?= $editPayment['payment_status']==='pending'?'selected':'' ?>>Pending</option>
              <option value="paid" <?= $editPayment['payment_status']==='paid'?'selected':'' ?>>Paid</option>
            </select>
          </div>
          <div class="form-group">
            <label>Paid Date</label>
            <input type="date" name="payment_date" value="<?= h($editPayment['payment_date'] ?? '') ?>">
            <span style="font-size:11px;color:var(--text2)">Set when payment was actually made</span>
          </div>
          <div class="form-group"><label>Bank Reference Code</label><input type="text" name="bank_reference" value="<?= h($editPayment['bank_reference'] ?? '') ?>" placeholder="e.g. TXN123456789"></div>
          <div class="form-group full">
            <label>📎 Replace Invoice</label>
            <?php if ($editPayment['invoice_file']): ?>
              <div style="margin-bottom:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;display:flex;align-items:center;gap:8px">
                <span>📄</span>
                <span style="color:var(--green)"><?= h($editPayment['invoice_file_name'] ?? 'Invoice uploaded') ?></span>
                <a href="<?= SITE_URL.'/'.h($editPayment['invoice_file']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="margin-left:auto">View</a>
              </div>
            <?php endif; ?>
            <input type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png" style="padding:6px">
            <span style="font-size:11px;color:var(--text2)">Upload new file to replace existing invoice</span>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes"><?= h($editPayment['notes']) ?></textarea></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/freelance.php?tab=payments&month=<?= $filterMonth ?>" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════ ADD FREELANCER MODAL ══════ -->
<div class="modal-overlay" id="addFreelancerModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Add Freelancer</div>
      <button class="modal-close" onclick="closeModal('addFreelancerModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=add_freelancer&tab=freelancers">
        <div class="form-grid">
          <div class="form-group full"><label>Full Name *</label><input name="freelancer_name" required placeholder="e.g. John Silva"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="john@example.com"></div>
          <div class="form-group"><label>Phone</label><input name="phone" placeholder="+94 77 123 4567"></div>
          <div class="form-group"><label>Bank Name</label><input name="bank_name" placeholder="e.g. Commercial Bank"></div>
          <div class="form-group"><label>Bank Account Number</label><input name="bank_account" placeholder="e.g. 1234567890"></div>
          <div class="form-group"><label>Bank Branch</label><input name="bank_branch" placeholder="e.g. Colombo 03"></div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Add Freelancer</button>
          <button type="button" class="btn btn-ghost" onclick="closeModal('addFreelancerModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════ EDIT FREELANCER MODAL ══════ -->
<?php if ($editFreelancer): ?>
<div class="modal-overlay open" id="editFreelancerModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Edit Freelancer</div>
      <button class="modal-close" onclick="window.location='<?= SITE_URL ?>/freelance.php?tab=freelancers'">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=edit_freelancer&id=<?= $editFreelancer['id'] ?>&tab=freelancers">
        <div class="form-grid">
          <div class="form-group full"><label>Full Name *</label><input name="freelancer_name" required value="<?= h($editFreelancer['freelancer_name']) ?>"></div>
          <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= h($editFreelancer['email']) ?>"></div>
          <div class="form-group"><label>Phone</label><input name="phone" value="<?= h($editFreelancer['phone']) ?>"></div>
          <div class="form-group"><label>Bank Name</label><input name="bank_name" value="<?= h($editFreelancer['bank_name']) ?>"></div>
          <div class="form-group"><label>Bank Account Number</label><input name="bank_account" value="<?= h($editFreelancer['bank_account']) ?>"></div>
          <div class="form-group"><label>Bank Branch</label><input name="bank_branch" value="<?= h($editFreelancer['bank_branch']) ?>"></div>
          <div class="form-group"><label>Status</label>
            <select name="status">
              <option value="active" <?= $editFreelancer['status']==='active'?'selected':'' ?>>Active</option>
              <option value="inactive" <?= $editFreelancer['status']==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= SITE_URL ?>/freelance.php?tab=freelancers" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
var currSym = '<?= addslashes(getSetting('currency_symbol','Rs.')) ?>';
function calcBal() {
    const inv  = parseFloat(document.getElementById('fInvAmt')?.value) || 0;
    const advEl = document.getElementById('fAdvAmt');
    const adv  = (advEl && advEl.value.trim() !== '') ? parseFloat(advEl.value) : inv;
    const bal  = Math.max(0, inv - adv);
    const disp = document.getElementById('fBalDisplay');
    if (disp) disp.textContent = currSym + ' ' + bal.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
}
document.addEventListener('DOMContentLoaded', calcBal);
</script>

<?php pageFooter(); ?>
