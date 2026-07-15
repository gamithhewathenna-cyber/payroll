<?php
require_once 'config.php';
require_once 'includes/layout.php';
requireAdmin();
$db = getDB();

// ── Period filter ──────────────────────────────────────────
$period = $_GET['period'] ?? 'current';
$today  = new DateTime();

switch ($period) {
    case 'previous':
        $from = (clone $today)->modify('first day of last month')->format('Y-m-d');
        $to   = (clone $today)->modify('last day of last month')->format('Y-m-d');
        $periodLabel = 'Previous Month (' . (clone $today)->modify('last month')->format('F Y') . ')';
        break;
    case 'custom':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $periodLabel = 'Custom Range: ' . date('d M Y', strtotime($from)) . ' — ' . date('d M Y', strtotime($to));
        break;
    case 'all':
        $from = null; $to = null;
        $periodLabel = 'All Months';
        break;
    case 'current':
    default:
        $period = 'current';
        $from = (clone $today)->modify('first day of this month')->format('Y-m-d');
        $to   = (clone $today)->modify('last day of this month')->format('Y-m-d');
        $periodLabel = 'Current Month (' . $today->format('F Y') . ')';
        break;
}

// ── Query paid freelance payments ──────────────────────────
$where  = ["fp.payment_status = 'paid'"];
$params = [];
if ($from && $to) {
    $where[] = "fp.payment_date BETWEEN ? AND ?";
    $params[] = $from;
    $params[] = $to;
}
$stmt = $db->prepare("SELECT fp.invoice_number, f.freelancer_name, fp.payment_date, fp.bank_reference, fp.payment_amount
                       FROM freelance_payments fp JOIN freelancers f ON f.id = fp.freelancer_id
                       WHERE " . implode(' AND ', $where) . "
                       ORDER BY fp.payment_date ASC, fp.id ASC");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalAmount = array_sum(array_column($rows, 'payment_amount'));

$sym         = getSetting('currency_symbol', 'Rs.');
$companyName = getSetting('company_name', SITE_NAME);

// ── PDF export ──────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'pdf') {

    function pdfEscape($text) {
        $text = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($text === false) $text = '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    function buildReportPdf($rows, $periodLabel, $companyName, $sym, $totalAmount) {
        $marginX = 50; $rightX = 545; $pageTop = 792; $pageBottom = 60;
        $pages = []; $stream = ''; $y = $pageTop;
        $BLACK = [17,17,17]; $GRAY = [102,102,102]; $WHITE = [255,255,255];

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
        $newPage = function() use (&$pages, &$stream, &$y, $pageTop) { $pages[] = $stream; $stream = ''; $y = $pageTop; };

        $colX = ['inv' => $marginX, 'client' => 175, 'date' => 320, 'ref' => 390, 'amt' => 470];
        $tableHeader = function() use (&$y, $put, $fillRect, $marginX, $rightX, $WHITE, $colX) {
            $fillRect($marginX, $y - 14, $rightX - $marginX, 18, [17,17,17]);
            $put($colX['inv'] + 4, $y - 9, 8, true, 'INVOICE #', $WHITE);
            $put($colX['client'],  $y - 9, 8, true, 'CLIENT / FREELANCER', $WHITE);
            $put($colX['date'],    $y - 9, 8, true, 'PAID DATE', $WHITE);
            $put($colX['ref'],     $y - 9, 8, true, 'BANK REFERENCE', $WHITE);
            $put($colX['amt'],     $y - 9, 8, true, 'AMOUNT', $WHITE);
            $y -= 28;
        };

        // Report header
        $put($marginX, $y, 16, true, $companyName); $y -= 20;
        $put($marginX, $y, 13, true, 'Freelance Payment Report'); $y -= 16;
        $put($marginX, $y, 10, false, $periodLabel, $GRAY); $y -= 12;
        $put($marginX, $y, 9, false, 'Generated: ' . date('d M Y H:i'), $GRAY); $y -= 20;

        $tableHeader();
        foreach ($rows as $r) {
            if ($y < $pageBottom) { $newPage(); $tableHeader(); }
            $put($colX['inv'] + 4, $y, 9, false, $r['invoice_number'] ?: '—');
            $put($colX['client'],  $y, 9, false, mb_strimwidth($r['freelancer_name'], 0, 26, '…'));
            $put($colX['date'],    $y, 9, false, $r['payment_date'] ? date('d M Y', strtotime($r['payment_date'])) : '—');
            $put($colX['ref'],     $y, 9, false, $r['bank_reference'] ?: '—');
            $put($colX['amt'],     $y, 9, false, $sym . ' ' . number_format($r['payment_amount'], 2));
            $y -= 6;
            $rule($marginX, $y, $rightX, $y, 1, [230,230,230]);
            $y -= 14;
        }

        if (empty($rows)) {
            $put($marginX, $y, 10, false, 'No paid payments found for this period.', $GRAY);
            $y -= 20;
        }

        if ($y < $pageBottom + 40) { $newPage(); }
        $y -= 8;
        $rule($marginX, $y, $rightX, $y, 2, $BLACK); $y -= 18;
        $put($colX['client'], $y, 11, true, 'TOTAL (' . count($rows) . ' payment' . (count($rows) === 1 ? '' : 's') . ')');
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

    $pdfBytes = buildReportPdf($rows, $periodLabel, $companyName, $sym, $totalAmount);
    $filename = 'Freelance_Payment_Report_' . date('Y-m-d') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    echo $pdfBytes;
    exit;
}

// ── HTML filter/preview page ─────────────────────────────────
pageHeader('Freelance Payment Report');
?>

<div style="max-width:960px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <a href="<?= SITE_URL ?>/freelance.php?tab=payments" class="btn btn-ghost btn-sm">← Back to Freelance</a>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card-title">🔎 Filter</div>
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group" style="margin:0">
        <label>Period</label>
        <select name="period" id="periodSelect" onchange="toggleCustomRange()">
          <option value="all"      <?= $period==='all'?'selected':'' ?>>All Months</option>
          <option value="previous" <?= $period==='previous'?'selected':'' ?>>Previous Month</option>
          <option value="current"  <?= $period==='current'?'selected':'' ?>>Current Month</option>
          <option value="custom"   <?= $period==='custom'?'selected':'' ?>>Custom Range</option>
        </select>
      </div>
      <div class="form-group" id="fromGroup" style="margin:0;display:<?= $period==='custom'?'':'none' ?>">
        <label>From</label>
        <input type="date" name="from" value="<?= h($from ?: date('Y-m-01')) ?>">
      </div>
      <div class="form-group" id="toGroup" style="margin:0;display:<?= $period==='custom'?'':'none' ?>">
        <label>To</label>
        <input type="date" name="to" value="<?= h($to ?: date('Y-m-d')) ?>">
      </div>
      <button type="submit" class="btn btn-ghost">Apply</button>
      <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'pdf'])) ?>" class="btn btn-primary">⬇️ Download PDF</a>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div>
        <strong><?= h($periodLabel) ?></strong>
        <span style="color:var(--text2);font-size:12.5px"> — <?= count($rows) ?> paid payment<?= count($rows)===1?'':'s' ?></span>
      </div>
      <strong style="color:var(--green);font-size:16px"><?= h($sym) ?> <?= number_format($totalAmount,2) ?></strong>
    </div>
    <div class="table-wrap mob-card-table">
      <table>
        <thead><tr><th>Invoice #</th><th>Client / Freelancer</th><th>Paid Date</th><th>Bank Reference</th><th>Amount</th></tr></thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text2);padding:32px">No paid payments found for this period.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td data-label="Invoice #"><?= $r['invoice_number'] ? h($r['invoice_number']) : '—' ?></td>
              <td data-label="Client / Freelancer"><?= h($r['freelancer_name']) ?></td>
              <td data-label="Paid Date"><?= $r['payment_date'] ? date('d M Y', strtotime($r['payment_date'])) : '—' ?></td>
              <td data-label="Bank Reference"><?= $r['bank_reference'] ? h($r['bank_reference']) : '—' ?></td>
              <td data-label="Amount"><strong style="color:var(--green)"><?= h($sym) ?> <?= number_format($r['payment_amount'],2) ?></strong></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function toggleCustomRange() {
    const isCustom = document.getElementById('periodSelect').value === 'custom';
    document.getElementById('fromGroup').style.display = isCustom ? '' : 'none';
    document.getElementById('toGroup').style.display   = isCustom ? '' : 'none';
}
</script>

<?php pageFooter(); ?>
