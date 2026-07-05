<?php
// send_invoice_reminders.php — run daily via cPanel Cron Job.
// Recommended:  php /home/USER/public_html/payroll/send_invoice_reminders.php
// If your host only supports URL-based cron, use:
//   https://business.creativelements.co/send_invoice_reminders.php?token=YOUR_CRON_TOKEN
// (the token is generated in the `settings` table by invoice_email_upgrade.sql — check its value there)
require_once 'config.php';
$db = getDB();

if (php_sapi_name() !== 'cli') {
    $token = $_GET['token'] ?? '';
    if ($token === '' || !hash_equals(getSetting('cron_token',''), $token)) { http_response_code(403); exit('Forbidden'); }
}

if (getSetting('invoice_reminders_enabled','1') !== '1') { echo "Reminders disabled.\n"; exit; }

$days1 = (int)getSetting('reminder_days_before_1','3');
$days2 = (int)getSetting('reminder_days_before_2','1');
$sym   = getSetting('currency_symbol','Rs.');
$companyName = getSetting('company_name', SITE_NAME);
$fromEmail   = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');
$ccSetting   = getSetting('invoice_cc_emails','');
$today       = strtotime(date('Y-m-d'));

$stmt = $db->prepare("SELECT i.*, c.company_name AS client_name, c.contact_name, c.email AS c_email, c.cc_emails AS c_cc_emails
                       FROM invoices i JOIN clients c ON c.id=i.client_id
                       WHERE i.invoice_type='invoice' AND i.status IN ('sent','overdue') AND i.due_date IS NOT NULL
                       AND (i.reminder1_sent_at IS NULL OR i.reminder2_sent_at IS NULL)");
$stmt->execute();
$invoices = $stmt->fetchAll();

function reminderBody($inv, $companyName, $sym, $daysLeft) {
    ob_start();
    $overdue = $daysLeft < 0;
    ?>
    <!DOCTYPE html><html><head><meta charset="UTF-8"><style>
      body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
      .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
      .header { background: <?= $overdue ? '#c0392b' : '#1a1f2e' ?>; color: #fff; padding: 24px 32px; }
      .header h1 { margin: 0 0 4px; font-size: 20px; }
      .body { padding: 26px 32px; font-size: 14px; line-height: 1.6; }
      .total-box { background: #fff8e1; border: 2px solid #f5a623; border-radius: 8px; padding: 16px 22px; display: flex; justify-content: space-between; align-items: center; margin: 18px 0; }
      .total-amount { font-size: 22px; font-weight: 800; color: #c0392b; }
      .btn-view { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 11px 26px; border-radius: 7px; font-weight: 700; font-size: 14px; }
      .footer { background: #f8f8f8; padding: 16px 32px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
    </style></head><body>
    <div class="wrap">
      <div class="header"><h1>💳 Payment Reminder</h1><p style="margin:0;opacity:.8;font-size:13px"><?= htmlspecialchars($companyName) ?></p></div>
      <div class="body">
        <p>Dear <strong><?= htmlspecialchars($inv['contact_name'] ?: $inv['client_name']) ?></strong>,</p>
        <p><?php if ($overdue): ?>
          This is a reminder that invoice <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong> is now <strong>overdue</strong>.
        <?php else: ?>
          This is a reminder that invoice <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong> is due in <strong><?= $daysLeft ?> day<?= $daysLeft==1?'':'s' ?></strong> (<?= date('d M Y', strtotime($inv['due_date'])) ?>).
        <?php endif; ?></p>
        <div class="total-box">
          <span>Amount Due</span>
          <span class="total-amount"><?= $sym ?> <?= number_format($inv['total'],2) ?></span>
        </div>
        <div style="text-align:center;margin-top:10px">
          <a class="btn-view" href="<?= SITE_URL ?>/invoice_print.php?id=<?= $inv['id'] ?>">View Invoice</a>
        </div>
      </div>
      <div class="footer"><p><?= htmlspecialchars($companyName) ?></p><p style="margin-top:4px">This is a system-generated reminder. Please do not reply to this email.</p></div>
    </div>
    </body></html>
    <?php
    return ob_get_clean();
}

function sendReminderEmail($db, $inv, $reminderType, $daysLeft, $companyName, $sym, $fromEmail, $ccSetting) {
    if (!$inv['c_email']) return false;
    $body = reminderBody($inv, $companyName, $sym, $daysLeft);
    $ccList = array_unique(array_filter(array_map('trim', explode(',', ($inv['c_cc_emails'] ?? '') . ',' . $ccSetting))));
    $subject = ($daysLeft < 0 ? 'Overdue: ' : 'Payment Reminder: ') . "Invoice {$inv['invoice_number']}";
    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$companyName} <{$fromEmail}>\r\n";
    if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\nX-Mailer: PHP/" . phpversion();
    $sent = mail($inv['c_email'], $subject, $body, $headers, "-f{$fromEmail}");
    if ($sent) {
        $col = $reminderType === 'reminder1' ? 'reminder1_sent_at' : 'reminder2_sent_at';
        $db->prepare("UPDATE invoices SET {$col}=NOW() WHERE id=?")->execute([$inv['id']]);
        $allRecipients = array_merge([$inv['c_email']], $ccList);
        $db->prepare("INSERT INTO invoice_emails (invoice_id, email_type, sent_to) VALUES (?,?,?)")
           ->execute([$inv['id'], $reminderType, implode(', ', $allRecipients)]);
    }
    return $sent;
}

$sentCount = 0;
foreach ($invoices as $inv) {
    $daysLeft = (int)round((strtotime($inv['due_date']) - $today) / 86400);

    if ($inv['reminder1_sent_at'] === null && $daysLeft <= $days1) {
        if (sendReminderEmail($db, $inv, 'reminder1', $daysLeft, $companyName, $sym, $fromEmail, $ccSetting)) $sentCount++;
    }
    if ($inv['reminder2_sent_at'] === null && $daysLeft <= $days2) {
        if (sendReminderEmail($db, $inv, 'reminder2', $daysLeft, $companyName, $sym, $fromEmail, $ccSetting)) $sentCount++;
    }
    if ($daysLeft < 0 && $inv['status'] !== 'overdue') {
        $db->prepare("UPDATE invoices SET status='overdue' WHERE id=?")->execute([$inv['id']]);
    }
}

echo "Checked " . count($invoices) . " invoice(s), sent {$sentCount} reminder email(s).\n";
