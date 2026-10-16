<!-- Filters + Add -->
<?php
$searchInv   = trim($_GET['search'] ?? '');
$activeRange = $dateRange;
?>
<div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:16px">
  <form method="GET" id="filterForm">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">

    <!-- Invoice search -->
    <div style="margin-bottom:12px">
      <div style="position:relative;max-width:320px">
        <input type="text" name="search" id="invSearch" value="<?= h($searchInv) ?>"
          placeholder="🔍 Search invoice number..."
          style="width:100%;padding-right:36px"
          oninput="liveSearch(this.value)">
        <?php if ($searchInv): ?>
          <a href="?tab=<?= h($tab) ?>&range=<?= h($activeRange) ?>" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text2);text-decoration:none;font-size:16px">×</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick range buttons — each is a plain link, no form conflict -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <?php foreach (['month'=>'This Month','week'=>'This Week','lastweek'=>'Last Week','lastmonth'=>'Last Month','all'=>'All Time'] as $r=>$label): ?>
        <a href="?tab=<?= h($tab) ?>&range=<?= $r ?>&status=<?= h($filter) ?>&client=<?= $filterClient ?>&search=<?= h($searchInv) ?>"
           class="btn btn-sm <?= $activeRange===$r?'btn-primary':'btn-ghost' ?>"
           style="font-size:12px"><?= $label ?></a>
      <?php endforeach; ?>
      <button type="button" onclick="openModal('customMonthModal')"
        class="btn btn-sm <?= $activeRange==='custom'?'btn-primary':'btn-ghost' ?>"
        style="font-size:12px">📅 Custom</button>
    </div>

    <!-- Custom month picker (popup, centered — unlike the bottom-sheet modals elsewhere) -->
    <div class="modal-overlay" id="customMonthModal" style="align-items:center;padding:16px">
      <div class="modal" style="max-width:320px;border-radius:14px;animation:none">
        <div class="modal-header">
          <div class="modal-title">Select Month</div>
          <button type="button" class="modal-close" onclick="closeModal('customMonthModal')">×</button>
        </div>
        <div class="modal-body">
          <div class="form-group" style="margin:0">
            <label style="font-size:11px">Month</label>
            <input type="month" name="month" value="<?= h($monthSel ?: date('Y-m')) ?>" style="width:100%">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px"
            onclick="document.getElementById('rangeInput').value='custom'">Apply</button>
        </div>
      </div>
    </div>

    <!-- Status + Client dropdowns -->
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="range" id="rangeInput" value="<?= h($activeRange) ?>">
      <select name="status" style="width:160px" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="pending" <?= $filter==='pending'?'selected':'' ?>>Pending Invoices</option>
        <?php foreach (['draft','sent','paid','overdue','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $filter===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="client" style="width:180px" onchange="this.form.submit()">
        <option value="">All Clients</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClient===$c['id']?'selected':'' ?>><?= h($c['company_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($filter || $filterClient || $searchInv || $activeRange !== 'month'): ?>
        <a href="?tab=<?= h($tab) ?>" class="btn btn-ghost btn-sm">✕ Clear All</a>
      <?php endif; ?>
      <span style="font-size:12px;color:var(--text2)">
        <?php if ($df && $dt && $activeRange !== 'all'): ?>
          <?= date('d M Y',strtotime($df)) ?> – <?= date('d M Y',strtotime($dt)) ?> ·
        <?php endif; ?>
        <strong style="color:var(--text)" id="recordCount"><?= count($invoices) ?> records</strong>
      </span>
    </div>
  </form>
