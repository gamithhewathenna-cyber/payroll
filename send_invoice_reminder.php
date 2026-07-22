<?php
// Manual "Send Reminder" button on an individual invoice — separate from the automatic
// daily schedule in send_invoice_reminders.php. Does not touch reminder1_sent_at /
// reminder2_sent_at, so sending one manually never blocks or skips a scheduled reminder.
require_once 'config.php';
require_once __DIR__ . '/includes/reminder_render.php';
require_once __DIR__ . '/includes/invoice_access.php';
requireAdmin();
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'invoices';
if (!$id) { setFlash('error','Invalid request.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name AS client_name, c.contact_name, c.email AS c_email, c.cc_emails AS c_cc_emails FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { setFlash('error','Invoice not found.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }
if (!$inv['c_email']) { setFlash('error','This client has no email address on file.'); header('Location: '.SITE_URL.'/invoice_form.php?id='.$id.'&tab='.$tab); exit; }
if (!$inv['due_date']) { setFlash('error','This invoice has no due date set.'); header('Location: '.SITE_URL.'/invoice_form.php?id='.$id.'&tab='.$tab); exit; }

$companyName = getSetting('company_name', SITE_NAME);
$sym         = getSetting('currency_symbol', 'Rs.');
$fromEmail   = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');
$ccSetting   = getSetting('invoice_cc_emails', '');
$daysLeft    = (int)round((strtotime($inv['due_date']) - strtotime(date('Y-m-d'))) / 86400);

$inv['access_token'] = getInvoiceAccessToken($db, $id);
$body    = reminderEmailBody($inv, $companyName, $sym, $daysLeft);
$ccList  = array_unique(array_filter(array_map('trim', explode(',', ($inv['c_cc_emails'] ?? '') . ',' . $ccSetting))));
$subject = ($daysLeft < 0 ? 'Overdue: ' : 'Payment Reminder: ') . "Invoice {$inv['invoice_number']}";

$headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: {$companyName} <{$fromEmail}>\r\n";
if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
$headers .= "Reply-To: {$fromEmail}\r\nX-Mailer: PHP/" . phpversion();

$sent = mail($inv['c_email'], $subject, $body, $headers, "-f{$fromEmail}");

if ($sent) {
    $allRecipients = array_merge([$inv['c_email']], $ccList);
    $db->prepare("INSERT INTO invoice_emails (invoice_id, email_type, sent_to) VALUES (?,?,?)")
       ->execute([$id, 'reminder_manual', implode(', ', $allRecipients)]);
    $ccNote = $ccList ? ' (CC: ' . implode(', ', $ccList) . ')' : '';
    setFlash('success', "✅ Reminder emailed to {$inv['c_email']}{$ccNote}!");
} else {
    setFlash('error', "❌ Failed to send reminder to {$inv['c_email']}. Check your cPanel email settings.");
}

header('Location: ' . SITE_URL . '/invoice_form.php?id=' . $id . '&tab=' . $tab);
exit;
