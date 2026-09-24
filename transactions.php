<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

// ── Period filter ──────────────────────────────────────────
$period = $_GET['period'] ?? 'monthly';
$tdate  = $_GET['tdate']  ?? date('Y-m-d');
$tmonth = $_GET['tmonth'] ?? date('Y-m');
$tyear  = $_GET['tyear']  ?? date('Y');

$dateFrom = $dateTo = null;
switch ($period) {
    case 'daily':
        $dateFrom = $dateTo = $tdate;
        $periodLabel = date('d M Y', strtotime($tdate));
        break;
    case 'yearly':
        $dateFrom = $tyear . '-01-01';
        $dateTo   = $tyear . '-12-31';
        $periodLabel = 'Year ' . $tyear;
        break;
    case 'all':
        $dateFrom = $dateTo = null;
        $periodLabel = 'All Transactions';
        break;
    case 'monthly':
    default:
        $period   = 'monthly';
        $dateFrom = date('Y-m-01', strtotime($tmonth . '-01'));
        $dateTo   = date('Y-m-t',  strtotime($tmonth . '-01'));
        $periodLabel = date('F Y', strtotime($tmonth . '-01'));
        break;
}

$sym = getSetting('currency_symbol', 'Rs.');

// ── Freelance payments (paid) ───────────────────────────────
// Same rows as the Freelance Payment Report page, for consistency.
$fWhere  = ["fp.payment_status = 'paid'"];
$fParams = [];
if ($dateFrom && $dateTo) { $fWhere[] = "fp.payment_date BETWEEN ? AND ?"; $fParams[] = $dateFrom; $fParams[] = $dateTo; }
$freelanceRows = $db->prepare("SELECT fp.payment_date, fp.invoice_number, fp.project_name, f.freelancer_name, fp.bank_reference, fp.payment_amount, fp.invoice_file, fp.invoice_file_name
    FROM freelance_payments fp JOIN freelancers f ON f.id = fp.freelancer_id
    WHERE " . implode(' AND ', $fWhere) . " ORDER BY fp.payment_date DESC");
$freelanceRows->execute($fParams);
$freelanceRows = $freelanceRows->fetchAll();

$transactions = [];
foreach ($freelanceRows as $r) {
    $details = $r['freelancer_name'];
    if ($r['project_name'])    $details .= ' — ' . $r['project_name'];
    if ($r['invoice_number'])  $details .= ' (' . $r['invoice_number'] . ')';
    $transactions[] = [
        'date'           => $r['payment_date'],
        'type'           => 'Freelance',
        'details'        => $details,
        'amount'         => (float)$r['payment_amount'],
        'bank_reference' => $r['bank_reference'],
        'file_path'      => $r['invoice_file'] ?: null,
        'file_label'     => 'Invoice',
    ];
}

// ── Expense payments (paid) ─────────────────────────────────
// Same rows as the Expenses → Payment Report tab, so the two stay consistent: paid, not
// Client-Paid (collected directly by the client), and only actual bank transfers — a "Not
// Bank Transfer" payment has no bank transaction to reconcile, so it's excluded too.
$eWhere  = ["status = 'paid'", "billing_type != 'client_paid'", "payment_method = 'bank_transfer'"];
$eParams = [];
if ($dateFrom && $dateTo) { $eWhere[] = "payment_date BETWEEN ? AND ?"; $eParams[] = $dateFrom; $eParams[] = $dateTo; }
$expenseRows = $db->prepare("SELECT payment_date, expense_category, project_name, client_name, description, bank_reference, total_billable, receipt_path, payment_receipt_path
    FROM expenses WHERE " . implode(' AND ', $eWhere) . " ORDER BY payment_date DESC");
$expenseRows->execute($eParams);
$expenseRows = $expenseRows->fetchAll();

foreach ($expenseRows as $r) {
    $details = $r['expense_category'];
    if ($r['client_name'])          $details .= ' — ' . $r['client_name'];
    elseif ($r['project_name'])     $details .= ' — ' . $r['project_name'];
    if ($r['description'])          $details .= ' (' . mb_strimwidth($r['description'], 0, 40, '…') . ')';
    $transactions[] = [
        'date'           => $r['payment_date'],
        'type'           => 'Expense',
        'details'        => $details,
        'amount'         => (float)$r['total_billable'],
        'bank_reference' => $r['bank_reference'],
        'file_path'      => $r['payment_receipt_path'] ?: ($r['receipt_path'] ?: null),
        'file_label'     => 'Receipt',
    ];
}

// Merge, newest first (rows with no payment date recorded sort last)
usort($transactions, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

$totalAmount     = array_sum(array_column($transactions, 'amount'));
$freelanceTotal  = array_sum(array_column($freelanceRows, 'payment_amount'));
$expenseTotal    = array_sum(array_column($expenseRows, 'total_billable'));

// Year options for the yearly picker — from the earliest transaction year to this year
$earliestYear = (int)date('Y');
foreach ($transactions as $t) { if (!$t['date']) continue; $y = (int)date('Y', strtotime($t['date'])); if ($y < $earliestYear) $earliestYear = $y; }
$yearOptions = range((int)date('Y'), $earliestYear);

// ── PDF export ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {

    function txPdfEscape($text) {
        $text = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($text === false) $text = '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    function buildTransactionsPdf($rows, $periodLabel, $companyName, $sym, $totalAmount) {
        $marginX = 50; $rightX = 545; $pageTop = 792; $pageBottom = 60;
        $pages = []; $stream = ''; $y = $pageTop;
        $BLACK = [17,17,17]; $GRAY = [102,102,102]; $WHITE = [255,255,255];

        $setColor = function($c) use (&$stream) { $stream .= sprintf("%.3F %.3F %.3F rg\n", $c[0]/255, $c[1]/255, $c[2]/255); };
        $put = function($x, $y, $size, $bold, $text, $color = null) use (&$stream, $setColor, $BLACK) {
            $setColor($color ?? $BLACK);
            $font = $bold ? 'F2' : 'F1';
            $stream .= "BT /{$font} {$size} Tf {$x} {$y} Td (" . txPdfEscape($text) . ") Tj ET\n";
        };
        $rule = function($x1, $y1, $x2, $y2, $width = 1, $color = null) use (&$stream, $setColor, $BLACK) {
            $c = $color ?? $BLACK;
            $stream .= sprintf("%.3F %.3F %.3F RG\n%d w\n%d %d m %d %d l S\n1 w\n", $c[0]/255,$c[1]/255,$c[2]/255, $width, $x1,$y1,$x2,$y2);
        };
        $fillRect = function($x, $y, $w, $h, $color) use (&$stream, $setColor) {
            $setColor($color);
            $stream .= "{$x} {$y} {$w} {$h} re f\n";
        };
        $newPage = function() use (&$pages, &$stream, &$y, $pageTop) { $pages[] = $stream; $stream = ''; $y = $pageTop; };

        $colX = ['date' => $marginX, 'type' => 110, 'details' => 175, 'ref' => 415, 'amt' => 480];
        $tableHeader = function() use (&$y, $put, $fillRect, $marginX, $rightX, $WHITE, $colX) {
            $fillRect($marginX, $y - 14, $rightX - $marginX, 18, [17,17,17]);
            $put($colX['date'] + 4, $y - 9, 8, true, 'DATE', $WHITE);
            $put($colX['type'],     $y - 9, 8, true, 'TYPE', $WHITE);
            $put($colX['details'],  $y - 9, 8, true, 'DESCRIPTION / DETAILS', $WHITE);
            $put($colX['ref'],      $y - 9, 8, true, 'BANK REF', $WHITE);
            $put($colX['amt'],      $y - 9, 8, true, 'AMOUNT', $WHITE);
            $y -= 28;
        };

        // Report header
        $put($marginX, $y, 16, true, $companyName); $y -= 20;
        $put($marginX, $y, 13, true, 'Transactions Report'); $y -= 16;
        $put($marginX, $y, 10, false, $periodLabel, $GRAY); $y -= 12;
        $put($marginX, $y, 9, false, 'Generated: ' . date('d M Y H:i'), $GRAY); $y -= 20;

        $tableHeader();
        foreach ($rows as $r) {
            if ($y < $pageBottom) { $newPage(); $tableHeader(); }
            $put($colX['date'] + 4, $y, 9, false, $r['date'] ? date('d M Y', strtotime($r['date'])) : '—');
            $put($colX['type'],     $y, 9, false, $r['type']);
            $put($colX['details'],  $y, 9, false, mb_strimwidth($r['details'], 0, 42, '…'));
            $put($colX['ref'],      $y, 9, false, $r['bank_reference'] ?: '—');
            $put($colX['amt'],      $y, 9, false, $sym . ' ' . number_format($r['amount'], 2));
            $y -= 6;
            $rule($marginX, $y, $rightX, $y, 1, [230,230,230]);
            $y -= 14;
        }

        if (empty($rows)) {
            $put($marginX, $y, 10, false, 'No transactions found for this period.', $GRAY);
            $y -= 20;
        }

        if ($y < $pageBottom + 40) { $newPage(); }
        $y -= 8;
        $rule($marginX, $y, $rightX, $y, 2, $BLACK); $y -= 18;
        $put($colX['details'], $y, 11, true, 'TOTAL (' . count($rows) . ' transaction' . (count($rows) === 1 ? '' : 's') . ')');
        $put($colX['amt'], $y, 11, true, $sym . ' ' . number_format($totalAmount, 2));

        $pages[] = $stream;

        // ── Assemble PDF binary ──
        $numPages = count($pages);
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = [];
        for ($i = 0; $i < $numPages; $i++) { $kids[] = (3 + $i * 2) . " 0 R"; }
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

    $companyName = getSetting('company_name', SITE_NAME);
    $pdfBytes    = buildTransactionsPdf($transactions, $periodLabel, $companyName, $sym, $totalAmount);
    $filename    = 'Transactions_Report_' . date('Y-m-d') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    echo $pdfBytes;
    exit;
}

pageHeader('Transactions');
?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Total Transactions</div><div class="stat-value"><?= count($transactions) ?></div><div class="stat-sub"><?= h($periodLabel) ?></div></div>
  <div class="stat-card blue"><div class="stat-label">🧑‍💻 Freelance Paid</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($freelanceTotal,2) ?></div><div class="stat-sub"><?= count($freelanceRows) ?> transactions</div></div>
  <div class="stat-card yellow"><div class="stat-label">🧾 Expenses Paid</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($expenseTotal,2) ?></div><div class="stat-sub"><?= count($expenseRows) ?> transactions</div></div>
  <div class="stat-card green"><div class="stat-label">Combined Total</div><div class="stat-value" style="font-size:18px"><?= h($sym) ?> <?= number_format($totalAmount,2) ?></div><div class="stat-sub">Freelance + Expenses</div></div>
</div>

<!-- Filter -->
<div class="card" style="margin-bottom:20px">
  <div class="card-title">🔎 Filter</div>
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0">
      <label>View</label>
      <select name="period" id="periodSelect" onchange="toggleTxPeriod()">
        <option value="all"     <?= $period==='all'?'selected':'' ?>>All Transactions</option>
        <option value="daily"   <?= $period==='daily'?'selected':'' ?>>Daily</option>
        <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Monthly</option>
        <option value="yearly"  <?= $period==='yearly'?'selected':'' ?>>Yearly</option>
      </select>
    </div>
    <div class="form-group" id="tdateGroup" style="margin:0;display:<?= $period==='daily'?'':'none' ?>">
      <label>Date</label>
      <input type="date" name="tdate" value="<?= h($tdate) ?>">
    </div>
    <div class="form-group" id="tmonthGroup" style="margin:0;display:<?= $period==='monthly'?'':'none' ?>">
      <label>Month</label>
      <input type="month" name="tmonth" value="<?= h($tmonth) ?>">
    </div>
    <div class="form-group" id="tyearGroup" style="margin:0;display:<?= $period==='yearly'?'':'none' ?>">
      <label>Year</label>
      <select name="tyear">
        <?php foreach ($yearOptions as $y): ?>
          <option value="<?= $y ?>" <?= (string)$tyear===(string)$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'pdf'])) ?>" class="btn btn-ghost">⬇️ Download PDF</a>
  </form>
</div>

<!-- Results -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <strong><?= h($periodLabel) ?></strong>
      <span style="color:var(--text2);font-size:12.5px"> — <?= count($transactions) ?> transaction<?= count($transactions)===1?'':'s' ?></span>
    </div>
    <strong style="color:var(--green);font-size:16px"><?= h($sym) ?> <?= number_format($totalAmount,2) ?></strong>
  </div>
  <div class="table-wrap mob-card-table">
    <table>
      <thead><tr><th>Date</th><th>Type</th><th>Description / Details</th><th>Amount</th><th>Bank Reference</th><th>Document</th></tr></thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="6" style="text-align:center;color:var(--text2);padding:32px">No transactions found for this period.</td></tr>
        <?php else: foreach ($transactions as $t): ?>
          <tr>
            <td data-label="Date" style="white-space:nowrap"><?= $t['date'] ? date('d M Y', strtotime($t['date'])) : '—' ?></td>
            <td data-label="Type">
              <?php if ($t['type'] === 'Freelance'): ?>
                <span class="badge badge-blue">🧑‍💻 Freelance</span>
              <?php else: ?>
                <span class="badge badge-yellow">🧾 Expense</span>
              <?php endif; ?>
            </td>
            <td data-label="Description / Details"><?= h($t['details']) ?></td>
            <td data-label="Amount"><strong style="color:var(--green)"><?= h($sym) ?> <?= number_format($t['amount'],2) ?></strong></td>
            <td data-label="Bank Reference"><?= $t['bank_reference'] ? h($t['bank_reference']) : '—' ?></td>
            <td data-label="Document">
              <?php if (!empty($t['file_path'])): ?>
                <a href="<?= SITE_URL ?>/<?= h($t['file_path']) ?>" target="_blank" class="btn btn-ghost btn-sm">📄 <?= h($t['file_label']) ?></a>
              <?php else: ?>
                <span style="color:var(--text2)">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function toggleTxPeriod() {
    const period = document.getElementById('periodSelect').value;
    document.getElementById('tdateGroup').style.display  = period === 'daily'   ? '' : 'none';
    document.getElementById('tmonthGroup').style.display = period === 'monthly' ? '' : 'none';
    document.getElementById('tyearGroup').style.display   = period === 'yearly'  ? '' : 'none';
}
</script>

<?php pageFooter(); ?>
