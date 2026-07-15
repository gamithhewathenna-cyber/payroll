<?php
// Shared vendor-invoice-submission approval logic — used by both the manual
// Freelance → Approvals tab (freelance.php) and the AI Assistant chat popup (chat.php),
// so approving/rejecting a vendor invoice behaves identically regardless of which UI did it.

// Pending items awaiting approval via the chat popup/widget: vendor invoice submissions
// and staff-submitted expense requests. Used by chat.php (full page) and includes/layout.php
// (floating widget badge + cards) so both stay in sync automatically.
function getPendingApprovals($db) {
    $out = [];
    $vendorRows = $db->query("SELECT vs.id, vs.project_name, vs.invoice_number, vs.payment_amount, vs.month, f.freelancer_name
                               FROM vendor_submissions vs JOIN freelancers f ON f.id = vs.freelancer_id
                               WHERE vs.submission_status = 'pending' ORDER BY vs.submitted_at ASC")->fetchAll();
    foreach ($vendorRows as $r) {
        $out[] = [
            'type'    => 'vendor_submission',
            'id'      => (int)$r['id'],
            'title'   => '🧑‍💻 Vendor Invoice — ' . h($r['freelancer_name']),
            'rows'    => [
                ['Project', h($r['project_name'])],
                ['Invoice #', h($r['invoice_number'] ?: '—')],
                ['Amount', formatMoney($r['payment_amount'])],
                ['Month', date('F Y', strtotime($r['month'].'-01'))],
            ],
        ];
    }
    $expenseRows = $db->query("SELECT id, requested_by, change_type, payload, created_at FROM expense_change_requests WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll();
    foreach ($expenseRows as $r) {
        $p = json_decode($r['payload'], true) ?: [];
        $label = ['add'=>'Add','edit'=>'Edit','delete'=>'Delete'][$r['change_type']] ?? $r['change_type'];
        $out[] = [
            'type'    => 'expense_request',
            'id'      => (int)$r['id'],
            'title'   => "💰 Expense {$label} Request — " . h($r['requested_by']),
            'rows'    => $r['change_type'] === 'delete'
                ? [['Action', 'Delete expense #' . (int)($p['expense_id'] ?? 0)]]
                : [
                    ['Category', h($p['expense_category'] ?? '—')],
                    ['Client', h($p['client_name'] ?? 'Internal')],
                    ['Amount', isset($p['total_billable']) ? formatMoney($p['total_billable']) : '—'],
                  ],
        ];
    }
    return $out;
}

// Approves a vendor_submissions row: records the freelance payment (unchanged existing
// behavior), creates a matching Expense entry (category "Freelancer Costs") so it flows
// into expense tracking/rebilling, and emails the vendor.
function approveVendorSubmission($db, $id, $reviewerName) {
    $stmt = $db->prepare("SELECT vs.*, f.freelancer_name, f.email FROM vendor_submissions vs JOIN freelancers f ON f.id=vs.freelancer_id WHERE vs.id=? AND vs.submission_status='pending'");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) return ['success' => false, 'message' => 'Submission not found or already reviewed.'];

    $db->prepare("INSERT INTO freelance_payments (freelancer_id,project_name,invoice_number,invoice_date,payment_amount,payment_method,payment_status,month,invoice_file,invoice_file_name) VALUES (?,?,?,?,?,'bank_transfer','pending',?,?,?)")
       ->execute([$sub['freelancer_id'],$sub['project_name'],$sub['invoice_number'],$sub['invoice_date'],$sub['payment_amount'],$sub['month'],$sub['invoice_file'],$sub['invoice_file_name']]);

    $db->prepare("UPDATE vendor_submissions SET submission_status='approved', reviewed_at=NOW(), reviewed_by=? WHERE id=?")
       ->execute([$reviewerName, $id]);

    // Also record it as an expense so it flows into expense tracking / client rebilling
    $db->prepare("INSERT INTO expenses (expense_date,billing_month,billing_type,expense_category,project_name,description,cost_amount,currency,exchange_rate,markup_percentage,additional_fee,total_billable,status,notes,created_by,approval_status,approved_by,approved_at) VALUES (?,?,'internal',?,?,?,?,'LKR',1,0,0,?,'pending',?,?,'approved',?,NOW())")
       ->execute([
           $sub['invoice_date'] ?: date('Y-m-d'),
           $sub['month'],
           'Freelancer Costs',
           $sub['project_name'],
           'Freelancer invoice ' . $sub['invoice_number'] . ' — ' . $sub['freelancer_name'],
           $sub['payment_amount'],
           $sub['payment_amount'],
           'Auto-created from vendor invoice submission (' . $sub['freelancer_name'] . ')',
           $reviewerName,
           $reviewerName,
       ]);

    // Notify the vendor
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

    return ['success' => true, 'message' => 'Invoice approved, added to payroll, and recorded as an expense.' . ($sub['email'] ? ' Approval email sent to vendor.' : ''), 'freelancer' => $sub['freelancer_name'], 'amount' => (float)$sub['payment_amount']];
}

function rejectVendorSubmission($db, $id, $reason, $reviewerName) {
    $reason = trim($reason) ?: 'Rejected by admin.';
    $stmt   = $db->prepare("SELECT vs.*, f.freelancer_name, f.email FROM vendor_submissions vs JOIN freelancers f ON f.id=vs.freelancer_id WHERE vs.id=? AND vs.submission_status='pending'");
    $stmt->execute([$id]);
    $sub = $stmt->fetch();
    if (!$sub) return ['success' => false, 'message' => 'Submission not found or already reviewed.'];

    $db->prepare("UPDATE vendor_submissions SET submission_status='rejected', rejection_reason=?, reviewed_at=NOW(), reviewed_by=? WHERE id=?")
       ->execute([$reason, $reviewerName, $id]);

    if ($sub['email']) {
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

    return ['success' => true, 'message' => 'Invoice rejected.' . ($sub['email'] ? ' Rejection email sent to vendor.' : '')];
}
