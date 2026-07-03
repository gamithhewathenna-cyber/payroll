<?php
require_once 'config.php';
requireLogin();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location:'.SITE_URL.'/invoices.php'); exit; }

$inv = $db->prepare("SELECT i.*, c.company_name, c.contact_name, c.email as c_email, c.phone as c_phone, c.address, c.address_line2, c.city, c.country, c.vat_number FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?");
$inv->execute([$id]);
$inv = $inv->fetch();
if (!$inv) { header('Location:'.SITE_URL.'/invoices.php'); exit; }

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order");
$items->execute([$id]);
$items = $items->fetchAll();

$S = [];
$sRows = $db->query("SELECT setting_key,setting_value FROM settings")->fetchAll();
foreach ($sRows as $r) $S[$r['setting_key']] = $r['setting_value'];

$sym         = $S['currency_symbol'] ?? 'Rs.';
$companyName = $S['company_name']    ?? 'Creative Elements (Pvt) Ltd';
$logoPath    = $S['logo_path']       ?? '';
$isQuote     = $inv['invoice_type'] === 'quotation';
$docLabel    = $isQuote ? 'QUOTATION' : 'INVOICE';
$invNo       = $inv['invoice_number'];
$period      = $inv['billing_month'] ? date('F Y', strtotime($inv['billing_month'].'-01')) : '';

// Try mPDF if available, else fall back to HTML download
$mpdfPath = __DIR__ . '/vendor/autoload.php';

if (file_exists($mpdfPath)) {
    // ── mPDF path ──────────────────────────────────────────
    require_once $mpdfPath;
    ob_start();
    include __DIR__ . '/invoice_print.php';
    // won't use this path - handled below
    ob_end_clean();
} else {
    // ── Pure HTML → PDF via Chrome headless not available ──
    // Use best alternative: inline HTML with Content-Disposition
    // This makes the browser open Save dialog with correct filename

    // Build clean HTML for the invoice
    $logoBase64 = '';
    if ($logoPath && file_exists(__DIR__ . '/' . $logoPath)) {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','svg'=>'image/svg+xml','webp'=>'image/webp'][$ext] ?? 'image/png';
        $logoBase64 = 'data:'.$mime.';base64,' . base64_encode(file_get_contents(__DIR__ . '/' . $logoPath));
    }

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>';
    $html .= '
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial,Helvetica,sans-serif; font-size:12px; color:#111; background:#fff; }
    .page { max-width:800px; margin:0 auto; padding:36px 40px; }
    .header { display:table; width:100%; margin-bottom:28px; padding-bottom:18px; border-bottom:3px solid #111; }
    .hdr-left { display:table-cell; width:60%; vertical-align:top; }
    .hdr-right { display:table-cell; width:40%; vertical-align:top; text-align:right; }
    .logo { height:44px; margin-bottom:6px; }
    .company-name { font-size:16px; font-weight:bold; }
    .company-sub { font-size:10px; color:#555; line-height:1.6; margin-top:3px; }
    .doc-title { font-size:30px; font-weight:900; letter-spacing:-1px; }
    .inv-num { font-size:13px; color:#555; margin-top:3px; }
    .status-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:10px; font-weight:bold; margin-top:6px; }
    .dates-row { display:table; width:100%; background:#f7f7f7; border-radius:5px; padding:12px 14px; margin-bottom:24px; }
    .date-cell { display:table-cell; width:25%; }
    .date-label { font-size:9px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px; color:#888; margin-bottom:2px; }
    .date-val { font-size:12px; font-weight:bold; }
    .bill-row { display:table; width:100%; margin-bottom:26px; }
    .bill-cell { display:table-cell; width:50%; vertical-align:top; padding-right:20px; }
    .bill-cell h3 { font-size:9px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px; color:#888; border-bottom:1px solid #eee; padding-bottom:4px; margin-bottom:8px; }
    .bill-cell p { font-size:12px; line-height:1.7; }
    table { width:100%; border-collapse:collapse; margin-bottom:20px; }
    thead th { background:#111; color:#fff; padding:8px 10px; text-align:left; font-size:10px; font-weight:bold; text-transform:uppercase; }
    thead th.r { text-align:right; }
    tbody td { padding:8px 10px; border-bottom:1px solid #eee; font-size:12px; vertical-align:top; }
    tbody td.r { text-align:right; }
    .item-sub { font-size:10px; color:#666; margin-top:2px; }
    tfoot td { padding:8px 10px; background:#111; color:#fff; font-weight:bold; font-size:13px; }
    tfoot td.r { text-align:right; color:#7fffcc; }
    .totals { width:260px; margin-left:auto; margin-bottom:24px; }
    .totals tr td { padding:4px 0; font-size:12px; }
    .totals tr td:last-child { text-align:right; font-weight:bold; }
    .totals .big td { font-size:16px; font-weight:900; padding-top:8px; border-top:2px solid #111; }
    .totals .big td:last-child { color:#1a7c4e; }
    .bank-box { background:#f7f7f7; border:1px solid #e0e0e0; border-radius:5px; padding:14px 16px; margin-bottom:22px; }
    .bank-box h3 { font-size:10px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px; color:#555; margin-bottom:10px; }
    .bank-grid { display:table; width:100%; }
    .bank-cell { display:table-cell; width:33%; vertical-align:top; }
    .bank-label { font-size:9px; color:#999; margin-bottom:2px; }
    .bank-val { font-size:11px; font-weight:bold; }
    .notes-box h3 { font-size:10px; font-weight:bold; text-transform:uppercase; letter-spacing:.4px; color:#888; margin-bottom:5px; }
    .notes-box p { font-size:11px; color:#555; line-height:1.6; margin-bottom:14px; }
    .footer { border-top:1px solid #ddd; padding-top:10px; font-size:9px; color:#aaa; display:table; width:100%; }
    .footer span { display:table-cell; }
    .footer span:last-child { text-align:right; }
    ';
    $html .= '</style></head><body><div class="page">';

    // Header
    $html .= '<div class="header">';
    $html .= '<div class="hdr-left">';
    if ($logoBase64) $html .= '<img src="'.$logoBase64.'" class="logo">';
    $html .= '<div class="company-name">'.h($companyName).'</div>';
    $html .= '<div class="company-sub">'.nl2br(h($S['company_address'] ?? '27/1, 1st Lane, Boralesgamuwa')).'<br>'.h($S['company_email'] ?? 'accounts@creativelements.co');
    if (!empty($S['company_phone'])) $html .= ' &bull; '.h($S['company_phone']);
    if (!empty($S['company_reg'])) $html .= '<br>Reg: '.h($S['company_reg']);
    $html .= '</div></div>';
    $html .= '<div class="hdr-right">';
    $html .= '<div class="doc-title">'.$docLabel.'</div>';
    $html .= '<div class="inv-num">'.h($invNo).'</div>';
    $statusColors = ['draft'=>'#cce5ff','sent'=>'#fff3cd','paid'=>'#d4edda','overdue'=>'#f8d7da','cancelled'=>'#e2e3e5'];
    $statusTextColors = ['draft'=>'#004085','sent'=>'#856404','paid'=>'#155724','overdue'=>'#721c24','cancelled'=>'#383d41'];
    $bg = $statusColors[$inv['status']] ?? '#eee';
    $tc = $statusTextColors[$inv['status']] ?? '#333';
    $html .= '<div><span class="status-badge" style="background:'.$bg.';color:'.$tc.'">'.strtoupper($inv['status']).'</span></div>';
    $html .= '</div></div>';

    // Dates
    $html .= '<div class="dates-row">';
    $html .= '<div class="date-cell"><div class="date-label">Issue Date</div><div class="date-val">'.date('d F Y',strtotime($inv['issue_date'])).'</div></div>';
    if ($inv['due_date']) $html .= '<div class="date-cell"><div class="date-label">Due Date</div><div class="date-val">'.date('d F Y',strtotime($inv['due_date'])).'</div></div>';
    if ($period) $html .= '<div class="date-cell"><div class="date-label">Billing Period</div><div class="date-val">'.$period.'</div></div>';
    if ($inv['paid_date']) $html .= '<div class="date-cell"><div class="date-label">Paid On</div><div class="date-val" style="color:#1a7c4e">'.date('d F Y',strtotime($inv['paid_date'])).'</div></div>';
    $html .= '</div>';

    // Bill To / From
    $html .= '<div class="bill-row">';
    $html .= '<div class="bill-cell"><h3>Bill To</h3><p>';
    $html .= '<strong>'.h($inv['company_name']).'</strong><br>';
    if ($inv['contact_name']) $html .= h($inv['contact_name']).'<br>';
    if ($inv['address']) $html .= nl2br(h($inv['address'])).'<br>';
    if ($inv['address_line2']) $html .= h($inv['address_line2']).'<br>';
    if ($inv['city'] || $inv['country']) $html .= h(implode(', ',array_filter([$inv['city'],$inv['country']]))).'<br>';
    if ($inv['c_email']) $html .= h($inv['c_email']);
    if ($inv['vat_number']) $html .= '<br>VAT: '.h($inv['vat_number']);
    $html .= '</p></div>';
    $html .= '<div class="bill-cell"><h3>From</h3><p><strong>'.h($companyName).'</strong><br>'.nl2br(h($S['company_address']??'27/1, 1st Lane, Boralesgamuwa')).'<br>'.h($S['company_email']??'accounts@creativelements.co').'</p></div>';
    $html .= '</div>';

    // Line items
    $html .= '<table><thead><tr><th>#</th><th>Description</th><th class="r">Qty</th><th class="r">Unit Price</th><th class="r">Amount</th></tr></thead><tbody>';
    $i = 1;
    foreach ($items as $item) {
        list($mainD, $subD) = explode('|||', $item['description'].'|||', 2);
        $subD = trim($subD);
        $html .= '<tr>';
        $html .= '<td style="color:#888;width:24px">'.$i.'</td>';
        $html .= '<td><strong>'.h(trim($mainD)).'</strong>'.($subD?'<div class="item-sub">'.h($subD).'</div>':'').'</td>';
        $html .= '<td class="r" style="color:#555">'.rtrim(rtrim(number_format($item['quantity'],2),'0'),'.').'</td>';
        $html .= '<td class="r">'.$sym.' '.number_format($item['unit_price'],2).'</td>';
        $html .= '<td class="r"><strong>'.$sym.' '.number_format($item['amount'],2).'</strong></td>';
        $html .= '</tr>';
        $i++;
    }
    $html .= '</tbody>';
    $html .= '<tfoot><tr><td colspan="4">TOTAL</td><td class="r">'.$sym.' '.number_format($inv['total'],2).'</td></tr></tfoot>';
    $html .= '</table>';

    // Totals breakdown
    $html .= '<table class="totals"><tr><td style="color:#555">Subtotal</td><td>'.$sym.' '.number_format($inv['subtotal'],2).'</td></tr>';
    if ($inv['discount_pct'] > 0) $html .= '<tr><td style="color:#c0392b">Discount ('.$inv['discount_pct'].'%)</td><td style="color:#c0392b">-'.$sym.' '.number_format($inv['discount_amt'],2).'</td></tr>';
    if ($inv['tax_pct'] > 0) $html .= '<tr><td>Tax ('.$inv['tax_pct'].'%)</td><td>'.$sym.' '.number_format($inv['tax_amt'],2).'</td></tr>';
    $html .= '<tr class="big"><td>TOTAL DUE</td><td>'.$sym.' '.number_format($inv['total'],2).'</td></tr></table>';

    // Bank details
    if (!empty($S['bank_name']) || !empty($S['bank_account_number'])) {
        $html .= '<div class="bank-box"><h3>Payment Details</h3><div class="bank-grid">';
        if ($S['bank_name']) $html .= '<div class="bank-cell"><div class="bank-label">Bank</div><div class="bank-val">'.h($S['bank_name']).'</div></div>';
        if ($S['bank_account_name']) $html .= '<div class="bank-cell"><div class="bank-label">Account Name</div><div class="bank-val">'.h($S['bank_account_name']).'</div></div>';
        if ($S['bank_account_number']) $html .= '<div class="bank-cell"><div class="bank-label">Account Number</div><div class="bank-val">'.h($S['bank_account_number']).'</div></div>';
        if ($S['bank_branch']) $html .= '<div class="bank-cell"><div class="bank-label">Branch</div><div class="bank-val">'.h($S['bank_branch']).'</div></div>';
        if ($S['bank_swift']) $html .= '<div class="bank-cell"><div class="bank-label">SWIFT</div><div class="bank-val">'.h($S['bank_swift']).'</div></div>';
        $html .= '</div></div>';
    }

    // Notes & Terms
    if ($inv['terms']) $html .= '<div class="notes-box"><h3>Terms &amp; Conditions</h3><p>'.nl2br(h($inv['terms'])).'</p></div>';
    if ($inv['notes']) $html .= '<div class="notes-box"><h3>Notes</h3><p>'.nl2br(h($inv['notes'])).'</p></div>';

    // Footer
    $html .= '<div class="footer"><span>'.h($companyName).' &bull; '.h($S['company_address']??'').' &bull; '.h($S['company_email']??'').'</span>';
    $html .= '<span>'.$docLabel.' '.h($invNo).' &bull; Generated '.date('d M Y').'</span></div>';

    $html .= '</div></body></html>';

    // Force download as .html that opens as PDF in browser
    $filename = preg_replace('/[^A-Za-z0-9\-_]/', '-', $invNo) . '.html';

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $html;
    exit;
}
