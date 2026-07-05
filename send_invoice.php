<?php
require_once 'config.php';
requireAdmin();
$db = getDB();

$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'invoices';
if (!$id) { setFlash('error','Invalid request.'); header('Location: '.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.cc_emails as c_cc_emails, c.phone as c_phone, c.address, c.address_line2, c.city, c.country, c.vat_number FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
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
    $marginX = 50; $rightX = 545; $pageTop = 792; $pageBottom = 70;
    $pages = []; $stream = '';
    $y = $pageTop;

    $BLACK = [17,17,17]; $GRAY = [85,85,85]; $LGRAY = [136,136,136]; $WHITE = [255,255,255];
    $statusColors = [
        'draft'     => [[227,240,255],[0,64,133]],
        'sent'      => [[255,243,205],[133,100,4]],
        'paid'      => [[212,237,218],[21,87,36]],
        'overdue'   => [[248,215,218],[114,29,36]],
        'cancelled' => [[226,227,229],[56,61,65]],
    ];

    $setColor = function($c) use (&$stream) { $stream .= sprintf("%.3F %.3F %.3F rg\n", $c[0]/255, $c[1]/255, $c[2]/255); };
    $put = function($x, $y, $size, $bold, $text, $color = null) use (&$stream, $setColor, $BLACK) {
        $setColor($color ?? $BLACK);
        $font = $bold ? 'F2' : 'F1';
        $stream .= "BT /{$font} {$size} Tf {$x} {$y} Td (" . pdfEscape($text) . ") Tj ET\n";
    };
    $rule = function($x1, $y1, $x2, $y2, $width = 1, $color = null) use (&$stream, $setColor, $BLACK) {
        $c = $color ?? $BLACK;
        $stream .= sprintf("%.3F %.3F %.3F RG\n%d w\n%d %d m %d %d l S\n1 w\n", $c[0]/255,$c[1]/255,$c[2]/255, $width, $x1,$y1,$x2,$y2);
    };
    $fillRect = function($x, $y, $w, $h, $color) use (&$stream, $setColor) {
        $setColor($color);
        $stream .= "{$x} {$y} {$w} {$h} re f\n";
    };
    $newPage = function() use (&$pages, &$stream, &$y, $pageTop) {
        $pages[] = $stream; $stream = ''; $y = $pageTop;
    };
    $tableHeader = function() use (&$y, &$stream, $put, $fillRect, $marginX, $rightX, $WHITE) {
        $fillRect($marginX, $y - 14, $rightX - $marginX, 18, [17,17,17]);
        $put($marginX + 6, $y - 9, 9, true, 'DESCRIPTION', $WHITE);
        $put(340, $y - 9, 9, true, 'QTY', $WHITE);
        $put(390, $y - 9, 9, true, 'UNIT PRICE', $WHITE);
        $put(470, $y - 9, 9, true, 'AMOUNT', $WHITE);
        $y -= 30;
    };

    // ── Header: company + doc info + status badge ──
    $put($marginX, $y, 18, true, $companyName); $y -= 16;
    if (!empty($S['company_address'])) { $put($marginX, $y, 9, false, $S['company_address'], $GRAY); $y -= 12; }
    $emailLine = trim(($S['company_email'] ?? '') . (!empty($S['company_phone']) ? ' · ' . $S['company_phone'] : ''));
    if ($emailLine) { $put($marginX, $y, 9, false, $emailLine, $GRAY); $y -= 12; }
    $y -= 8;
    $put($marginX, $y, 20, true, strtoupper($docLabel)); $y -= 16;
    $put($marginX, $y, 10, false, '#' . $inv['invoice_number'], $GRAY); $y -= 20;

    [$badgeBg, $badgeText] = $statusColors[$inv['status']] ?? $statusColors['draft'];
    $fillRect($marginX, $y - 4, 62, 16, $badgeBg);
    $put($marginX + 8, $y, 8, true, strtoupper($inv['status']), $badgeText);
    $y -= 26;

    $rule($marginX, $y, $rightX, $y, 2, $BLACK); $y -= 22;

    // ── Dates box ──
    $fillRect($marginX, $y - 20, $rightX - $marginX, 28, [248,248,248]);
    $dy = $y - 8;
    $put($marginX + 10, $dy, 8, true, 'ISSUE DATE', $LGRAY);
    $put($marginX + 10, $dy - 11, 10, false, date('d M Y', strtotime($inv['issue_date'])));
    if ($inv['due_date']) {
        $put(220, $dy, 8, true, $isQuote ? 'VALID UNTIL' : 'DUE DATE', $LGRAY);
        $put(220, $dy - 11, 10, false, date('d M Y', strtotime($inv['due_date'])));
    }
    if (!empty($inv['billing_month'])) {
        $put(380, $dy, 8, true, 'BILLING PERIOD', $LGRAY);
        $put(380, $dy - 11, 10, false, date('F Y', strtotime($inv['billing_month'].'-01')));
    }
    $y -= 44;

    // ── Bill To ──
    $put($marginX, $y, 8, true, 'BILL TO', $LGRAY); $y -= 14;
    $put($marginX, $y, 11, true, $inv['company_name']); $y -= 13;
    if (!empty($inv['contact_name'])) { $put($marginX, $y, 9, false, $inv['contact_name'], $GRAY); $y -= 12; }
    if (!empty($inv['address'])) {
        foreach (explode("\n", $inv['address']) as $line) { $put($marginX, $y, 9, false, $line, $GRAY); $y -= 12; }
    }
    if (!empty($inv['address_line2'])) { $put($marginX, $y, 9, false, $inv['address_line2'], $GRAY); $y -= 12; }
    $cityCountry = implode(', ', array_filter([$inv['city'] ?? '', $inv['country'] ?? '']));
    if ($cityCountry) { $put($marginX, $y, 9, false, $cityCountry, $GRAY); $y -= 12; }
    if ($inv['c_email']) { $put($marginX, $y, 9, false, $inv['c_email'], $GRAY); $y -= 12; }
    if (!empty($inv['c_phone'])) { $put($marginX, $y, 9, false, $inv['c_phone'], $GRAY); $y -= 12; }
    if (!empty($inv['vat_number'])) { $put($marginX, $y, 9, false, 'VAT: ' . $inv['vat_number'], $GRAY); $y -= 12; }
    $y -= 14;

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
        $desc       = mb_strimwidth(trim(explode('|||', $item['description'], 2)[0]), 0, 46, '…');
        $put($marginX + 6, $y, 9, false, $desc);
        $put(340, $y, 9, false, rtrim(rtrim(number_format($item['quantity'],2),'0'),'.'));
        $put(390, $y, 9, false, fm2($unitPrice, $dispSym));
        $put(470, $y, 9, true, fm2($amt, $dispSym));
        $y -= 6;
        $rule($marginX, $y, $rightX, $y, 1, [240,240,240]);
        $y -= 14;
    }

    if ($y < $pageBottom + 70) { $newPage(); }
    $y -= 8;
    $tSym = $isForeign ? $fSym : $sym;
    $put(390, $y, 10, false, 'Subtotal', $GRAY); $put(470, $y, 10, false, fm2($isForeign ? $inv['subtotal']/(float)($inv['inv_rate']?:1) : $inv['subtotal'], $tSym)); $y -= 16;
    if ($inv['discount_pct'] > 0) {
        $put(390, $y, 10, false, "Discount ({$inv['discount_pct']}%)", [192,57,43]); $put(470, $y, 10, false, '-' . fm2($isForeign ? $inv['discount_amt']/(float)($inv['inv_rate']?:1) : $inv['discount_amt'], $tSym), [192,57,43]); $y -= 16;
    }
    if ($inv['tax_pct'] > 0) {
        $put(390, $y, 10, false, "Tax ({$inv['tax_pct']}%)", $GRAY); $put(470, $y, 10, false, fm2($isForeign ? $inv['tax_amt']/(float)($inv['inv_rate']?:1) : $inv['tax_amt'], $tSym)); $y -= 16;
    }
    $fillRect(370, $y - 8, $rightX - 370, 22, [240,253,244]);
    $put(380, $y, 12, true, 'TOTAL');
    $put(470, $y, 12, true, fm2($isForeign ? $inv['total']/(float)($inv['inv_rate']?:1) : $inv['total'], $tSym), [26,124,78]);
    $y -= 30;

    if (!empty($S['bank_name']) || !empty($S['bank_account_number'])) {
        if ($y < $pageBottom + 80) { $newPage(); }
        $boxLines = array_filter([
            !empty($S['bank_name'])           ? 'Bank: ' . $S['bank_name'] : null,
            !empty($S['bank_account_name'])   ? 'Account Name: ' . $S['bank_account_name'] : null,
            !empty($S['bank_account_number']) ? 'Account Number: ' . $S['bank_account_number'] : null,
            !empty($S['bank_branch'])         ? 'Branch: ' . $S['bank_branch'] : null,
            !empty($S['bank_swift'])          ? 'SWIFT: ' . $S['bank_swift'] : null,
        ]);
        $boxHeight = 18 + count($boxLines) * 13;
        $fillRect($marginX, $y - $boxHeight + 8, $rightX - $marginX, $boxHeight, [248,248,248]);
        $put($marginX + 10, $y - 6, 9, true, 'PAYMENT DETAILS', $LGRAY); $y -= 20;
        foreach ($boxLines as $line) { $put($marginX + 10, $y, 9, false, $line, $GRAY); $y -= 13; }
        $y -= 8;
    }

    if (!empty($inv['terms'])) {
        if ($y < $pageBottom + 40) { $newPage(); }
        $put($marginX, $y, 8, true, 'TERMS & CONDITIONS', $LGRAY); $y -= 12;
        foreach (explode("\n", wordwrap($inv['terms'], 110)) as $line) { $put($marginX, $y, 9, false, $line, $GRAY); $y -= 12; }
        $y -= 8;
    }
    if (!empty($inv['notes'])) {
        if ($y < $pageBottom + 40) { $newPage(); }
        $put($marginX, $y, 8, true, 'NOTES', $LGRAY); $y -= 12;
        foreach (explode("\n", wordwrap($inv['notes'], 110)) as $line) { $put($marginX, $y, 9, false, $line, $GRAY); $y -= 12; }
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
