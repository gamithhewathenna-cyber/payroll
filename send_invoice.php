<?php
require_once 'config.php';
requireAdmin();
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'invoices';
if (!$id) { setFlash('error','Invalid request.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.cc_emails as c_cc_emails FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { setFlash('error','Invoice not found.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }
if (!$inv['c_email']) { setFlash('error','This client has no email address on file.'); header('Location: '.SITE_URL.'/invoice_form.php?id='.$id.'&tab='.$tab); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$S = [];
foreach ($db->query("SELECT setting_key,setting_value FROM settings")->fetchAll() as $r) $S[$r['setting_key']] = $r['setting_value'];

$sym         = $S['currency_symbol'] ?? 'Rs.';
$companyName = $S['company_name']    ?? SITE_NAME;
$isQuote     = $inv['invoice_type'] === 'quotation';
$docLabel    = $isQuote ? 'Quotation' : 'Invoice';
$greetName   = $inv['contact_name'] ?: $inv['company_name'];
$monthLabel  = $inv['billing_month'] ? date('F Y', strtotime($inv['billing_month'].'-01')) : date('F Y', strtotime($inv['issue_date']));

function fm2($amount, $sym) { return $sym . ' ' . number_format((float)$amount, 2); }

// ── Minimal, dependency-free PDF builder (Helvetica text only — no external library) ──
function pdfEscape($text) {
    $text = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    if ($text === false) $text = '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function buildInvoicePdf($inv, $items, $S, $sym, $companyName, $isQuote, $docLabel) {
    $marginX = 50; $pageTop = 792; $pageBottom = 70;
    $pages = []; $stream = '';
    $y = $pageTop;

    $put = function($x, $y, $size, $bold, $text) use (&$stream) {
        $font = $bold ? 'F2' : 'F1';
        $stream .= "BT /{$font} {$size} Tf {$x} {$y} Td (" . pdfEscape($text) . ") Tj ET\n";
    };
    $rule = function($x1, $y1, $x2, $y2) use (&$stream) {
        $stream .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
    };
    $newPage = function() use (&$pages, &$stream, &$y, $pageTop) {
        $pages[] = $stream; $stream = ''; $y = $pageTop;
    };
    $tableHeader = function() use (&$y, $put, $rule, $marginX) {
        $put($marginX, $y, 9, true, 'DESCRIPTION');
        $put(340, $y, 9, true, 'QTY');
        $put(390, $y, 9, true, 'UNIT PRICE');
        $put(470, $y, 9, true, 'AMOUNT');
        $y -= 6;
        $rule($marginX, $y, 545, $y);
        $y -= 16;
    };

    // Header
    $put($marginX, $y, 16, true, $companyName); $y -= 16;
    if (!empty($S['company_address'])) { $put($marginX, $y, 9, false, $S['company_address']); $y -= 12; }
    if (!empty($S['company_email']))   { $put($marginX, $y, 9, false, $S['company_email']);   $y -= 12; }
    $y -= 12;
    $put($marginX, $y, 14, true, strtoupper($docLabel) . '  #' . $inv['invoice_number']); $y -= 18;
    $put($marginX, $y, 10, false, 'Issue Date: ' . date('d M Y', strtotime($inv['issue_date']))); $y -= 14;
    if ($inv['due_date']) {
        $put($marginX, $y, 10, false, ($isQuote ? 'Valid Until: ' : 'Due Date: ') . date('d M Y', strtotime($inv['due_date'])));
        $y -= 14;
    }
    $y -= 10;
    $put($marginX, $y, 10, true, 'Bill To:'); $y -= 14;
    $put($marginX, $y, 10, false, $inv['company_name']); $y -= 14;
    if ($inv['c_email']) { $put($marginX, $y, 9, false, $inv['c_email']); $y -= 14; }
    $y -= 10;

    $tableHeader();
    $invCur   = $inv['inv_currency'] ?? 'LKR';
    $isForeign = $invCur !== 'LKR';
    $curSymbols = ['LKR'=>'Rs.','USD'=>'$','AUD'=>'A$','EUR'=>'€','GBP'=>'£','SGD'=>'S$'];
    $fSym = $curSymbols[$invCur] ?? $invCur;
    foreach ($items as $item) {
        if ($y < $pageBottom) { $newPage(); $tableHeader(); }
        $isExpItem  = $item['item_type'] === 'expense';
        $dispSym    = ($isForeign && !$isExpItem) ? $fSym : $sym;
        $unitPrice  = ($isForeign && !$isExpItem) ? $item['unit_price'] : ($isExpItem ? $item['unit_price'] : $item['unit_price'] * (float)($inv['inv_rate'] ?? 1));
        $amt        = ($isForeign && !$isExpItem) ? $item['quantity'] * $item['unit_price'] : $item['amount'];
        $desc       = mb_strimwidth(trim(explode('|||', $item['description'], 2)[0]), 0, 48, '…');
        $put($marginX, $y, 9, false, $desc);
        $put(340, $y, 9, false, rtrim(rtrim(number_format($item['quantity'],2),'0'),'.'));
        $put(390, $y, 9, false, fm2($unitPrice, $dispSym));
        $put(470, $y, 9, false, fm2($amt, $dispSym));
        $y -= 16;
    }

    if ($y < $pageBottom + 60) { $newPage(); }
    $y -= 10;
    $rule($marginX, $y, 545, $y); $y -= 18;
    $tSym = $isForeign ? $fSym : $sym;
    $put(390, $y, 10, false, 'Subtotal'); $put(470, $y, 10, false, fm2($isForeign ? $inv['subtotal']/(float)($inv['inv_rate']?:1) : $inv['subtotal'], $tSym)); $y -= 14;
    if ($inv['discount_pct'] > 0) {
        $put(390, $y, 10, false, "Discount ({$inv['discount_pct']}%)"); $put(470, $y, 10, false, '-' . fm2($isForeign ? $inv['discount_amt']/(float)($inv['inv_rate']?:1) : $inv['discount_amt'], $tSym)); $y -= 14;
    }
    if ($inv['tax_pct'] > 0) {
        $put(390, $y, 10, false, "Tax ({$inv['tax_pct']}%)"); $put(470, $y, 10, false, fm2($isForeign ? $inv['tax_amt']/(float)($inv['inv_rate']?:1) : $inv['tax_amt'], $tSym)); $y -= 14;
    }
    $put(390, $y, 12, true, 'TOTAL'); $put(470, $y, 12, true, fm2($isForeign ? $inv['total']/(float)($inv['inv_rate']?:1) : $inv['total'], $tSym)); $y -= 24;

    if (!empty($S['bank_name']) || !empty($S['bank_account_number'])) {
        if ($y < $pageBottom + 60) { $newPage(); }
        $put($marginX, $y, 10, true, 'Payment Details'); $y -= 14;
        if (!empty($S['bank_name']))           { $put($marginX, $y, 9, false, 'Bank: ' . $S['bank_name']); $y -= 12; }
        if (!empty($S['bank_account_name']))   { $put($marginX, $y, 9, false, 'Account Name: ' . $S['bank_account_name']); $y -= 12; }
        if (!empty($S['bank_account_number'])) { $put($marginX, $y, 9, false, 'Account Number: ' . $S['bank_account_number']); $y -= 12; }
        if (!empty($S['bank_branch']))          { $put($marginX, $y, 9, false, 'Branch: ' . $S['bank_branch']); $y -= 12; }
        if (!empty($S['bank_swift']))            { $put($marginX, $y, 9, false, 'SWIFT: ' . $S['bank_swift']); $y -= 12; }
    }

    $pages[] = $stream;

    // ── Assemble PDF binary ──
    $numPages = count($pages);
    $objs = [];
    $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $kids = [];
    for ($i = 0; $i < $numPages; $i++) {
        $pageObjNum    = 3 + $i * 2;
        $contentObjNum = 4 + $i * 2;
        $kids[] = "{$pageObjNum} 0 R";
    }
    $fontObj1 = 3 + $numPages * 2;
    $fontObj2 = $fontObj1 + 1;
    $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$numPages} >>";
    for ($i = 0; $i < $numPages; $i++) {
        $pageObjNum    = 3 + $i * 2;
        $contentObjNum = 4 + $i * 2;
        $objs[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontObj1} 0 R /F2 {$fontObj2} 0 R >> >> /Contents {$contentObjNum} 0 R >>";
        $content = $pages[$i];
        $objs[$contentObjNum] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
    }
    $objs[$fontObj1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objs[$fontObj2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    ksort($objs);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxNum = max(array_keys($objs));
    $pdf .= "xref\n0 " . ($maxNum + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= $maxNum; $n++) {
        $pdf .= isset($offsets[$n]) ? (str_pad($offsets[$n], 10, '0', STR_PAD_LEFT) . " 00000 n \n") : "0000000000 00000 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

$pdfBytes = buildInvoicePdf($inv, $items, $S, $sym, $companyName, $isQuote, $docLabel);
$pdfName  = preg_replace('/[^A-Za-z0-9._-]/', '_', $inv['invoice_number']) . '.pdf';

// ── Simple, left-aligned, mobile-friendly email body ──
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin:0; padding:0; background:#fff; font-family:Arial,Helvetica,sans-serif; }
  .wrap { max-width:100%; padding:24px; text-align:left; color:#222; font-size:14px; line-height:1.6; }
  p { margin:0 0 14px; }
</style>
</head>
<body>
<div class="wrap">
  <p>Hi <?= htmlspecialchars($greetName) ?>,</p>
  <p>Please find the attached <?= strtolower($docLabel) ?> for <?= htmlspecialchars($monthLabel) ?>.</p>
  <p>If you have any questions, please let us know.</p>
  <p>Thank you!</p>
  <p><?= htmlspecialchars($companyName) ?></p>
</div>
</body>
</html>
<?php
$emailBody = ob_get_clean();

// Recipients
$toEmail = $inv['c_email'];
$ccList  = array_unique(array_filter(array_map('trim', explode(',', ($inv['c_cc_emails'] ?? '') . ',' . getSetting('invoice_cc_emails','')))));

$fromEmail = getSetting('email_from') ?: (getSetting('company_email') ?: 'accounts@creativelements.co');
$fromName  = $companyName;
$subject   = "{$docLabel} {$inv['invoice_number']} from {$companyName}";
$msgId     = '<' . time() . '.' . md5($toEmail) . '@creativelements.co>';
$boundary  = 'PR_' . md5($msgId);

$headers  = "MIME-Version: 1.0\r\n";
$headers .= "From: {$fromName} <{$fromEmail}>\r\n";
if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";
$headers .= "Reply-To: {$fromEmail}\r\n";
$headers .= "Return-Path: {$fromEmail}\r\n";
$headers .= "Message-ID: {$msgId}\r\n";
$headers .= "Date: " . date('r') . "\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $emailBody . "\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: application/pdf; name=\"{$pdfName}\"\r\n";
$body .= "Content-Transfer-Encoding: base64\r\n";
$body .= "Content-Disposition: attachment; filename=\"{$pdfName}\"\r\n\r\n";
$body .= chunk_split(base64_encode($pdfBytes)) . "\r\n";
$body .= "--{$boundary}--";

$params = "-f{$fromEmail}";
$sent = mail($toEmail, $subject, $body, $headers, $params);

if ($sent) {
    $allRecipients = array_merge([$toEmail], $ccList);
    $db->prepare("INSERT INTO invoice_emails (invoice_id, email_type, sent_to) VALUES (?,?,?)")
       ->execute([$id, 'invoice', implode(', ', $allRecipients)]);
    if ($inv['status'] === 'draft') {
        $db->prepare("UPDATE invoices SET status='sent' WHERE id=?")->execute([$id]);
    }
    $ccNote = $ccList ? ' (CC: ' . implode(', ', $ccList) . ')' : '';
    setFlash('success', "✅ {$docLabel} emailed to {$toEmail}{$ccNote}!");
} else {
    setFlash('error', "❌ Failed to send email to {$toEmail}. Check your cPanel email settings.");
}

header('Location: ' . SITE_URL . '/invoice_form.php?id=' . $id . '&tab=' . $tab);
exit;
