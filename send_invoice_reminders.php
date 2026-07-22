<?php
// send_invoice_reminders.php — run daily via cPanel Cron Job.
// Recommended:  php /home/USER/public_html/payroll/send_invoice_reminders.php
// If your host only supports URL-based cron, use:
//   https://business.creativelements.co/send_invoice_reminders.php?token=YOUR_CRON_TOKEN
// (the token is generated in the `settings` table by invoice_email_upgrade.sql — check its value there)
require_once 'config.php';
require_once __DIR__ . '/includes/reminder_render.php';
require_once __DIR__ . '/includes/invoice_access.php';
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

function sendReminderEmail($db, $inv, $reminderType, $daysLeft, $companyName, $sym, $fromEmail, $ccSetting) {
    if (!$inv['c_email']) return false;
    $inv['access_token'] = getInvoiceAccessToken($db, $inv['id']);
    $body = reminderEmailBody($inv, $companyName, $sym, $daysLeft);
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
