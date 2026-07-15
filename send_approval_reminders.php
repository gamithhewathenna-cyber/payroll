<?php
// send_approval_reminders.php — run on weekday mornings via cPanel Cron Job.
// Recommended schedule: 0 9 * * 1-5  (9:00 AM, Monday–Friday)
// Recommended command:  php /home/USER/public_html/payroll/send_approval_reminders.php
// If your host only supports URL-based cron, use:
//   https://business.creativelements.co/send_approval_reminders.php?token=YOUR_CRON_TOKEN
// (the cron_token is generated in the `settings` table by invoice_email_upgrade.sql — check its value there)
require_once 'config.php';
require_once __DIR__ . '/includes/vendor_approval.php';
$db = getDB();

if (php_sapi_name() !== 'cli') {
    $token = $_GET['token'] ?? '';
    if ($token === '' || !hash_equals(getSetting('cron_token',''), $token)) { http_response_code(403); exit('Forbidden'); }
}

if (getSetting('approval_reminders_enabled','1') !== '1') { echo "Approval reminders disabled.\n"; exit; }

$pending = getPendingApprovals($db);
if (empty($pending)) { echo "Nothing pending — no reminder sent.\n"; exit; }

$companyName = getSetting('company_name', SITE_NAME);
$fromEmail   = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');

$admins = $db->query("SELECT email, full_name FROM users WHERE is_super_admin=1 AND email IS NOT NULL AND email != ''")->fetchAll();
if (!$admins) { echo "No admin recipients configured (no super-admin has an email set).\n"; exit; }

$rowsHtml = '';
foreach ($pending as $item) {
    $rowsHtml .= '<div style="padding:10px 0;border-bottom:1px solid #eee">';
    $rowsHtml .= '<strong>' . $item['title'] . '</strong><br>';
    foreach ($item['rows'] as [$label, $val]) {
        $rowsHtml .= '<span style="color:#888;font-size:12px">' . htmlspecialchars($label) . ': </span><span style="font-size:12px">' . $val . '</span><br>';
    }
    $rowsHtml .= '</div>';
}

$count   = count($pending);
$subject = "⏳ {$count} item" . ($count === 1 ? '' : 's') . " awaiting your approval | {$companyName}";
$sent    = 0;

foreach ($admins as $admin) {
    $emailBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
      body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
      .wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1)}
      .header{background:#1a1f2e;padding:24px 32px}
      .header h1{color:#fff;margin:0 0 4px;font-size:19px}
      .header p{color:rgba(255,255,255,.65);margin:0;font-size:13px}
      .body{padding:24px 32px;font-size:13.5px;color:#333}
      .btn{display:inline-block;background:#3b82f6;color:#fff;text-decoration:none;padding:11px 26px;border-radius:7px;font-weight:700;font-size:14px;margin-top:16px}
      .footer{background:#f8f8f8;padding:14px 32px;text-align:center;font-size:11px;color:#999}
    </style></head><body><div class="wrap">
    <div class="header"><h1>⏳ Reminder: Approvals Waiting</h1><p>' . htmlspecialchars($companyName) . '</p></div>
    <div class="body">
      <p>Hi ' . htmlspecialchars($admin['full_name']) . ',</p>
      <p>You have <strong>' . $count . '</strong> item' . ($count === 1 ? '' : 's') . ' still waiting for your approval in the AI Assistant.</p>
      ' . $rowsHtml . '
      <a class="btn" href="' . SITE_URL . '/chat.php">Review Now →</a>
    </div>
    <div class="footer">' . htmlspecialchars($companyName) . ' · Automated weekday-morning reminder</div>
    </div></body></html>';

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$companyName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\nX-Mailer: PHP/" . phpversion();
    if (mail($admin['email'], $subject, $emailBody, $headers, "-f{$fromEmail}")) $sent++;
}

echo "Sent {$sent} reminder email(s) to " . count($admins) . " admin(s) for {$count} pending item(s).\n";
