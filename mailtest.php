<?php
$to      = 'gamithhewathenna@gmail.com';
$subject = 'PayrollPro Mail Test';
$message = 'If you see this, PHP mail is working!';
$headers = 'From: payroll@creativelements.co' . "\r\n";

$result = mail($to, $subject, $message, $headers);
echo $result ? '✅ Mail sent! Check your inbox.' : '❌ mail() failed.';
?>