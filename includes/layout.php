<?php
// includes/layout.php — CSS & JS fully embedded, no external file dependencies

function getInlineStyles() {
    return <<<'CSS'
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #0d1117; --bg2: #161b22; --bg3: #21262d; --border: #30363d;
  --text: #e6edf3; --text2: #8b949e; --accent: #3b82f6; --accent2: #1d4ed8;
  --green: #00c48c; --red: #ff4d6d; --yellow: #f5a623;
  --sidebar-w: 240px; --radius: 10px; --topbar-h: 56px;
}
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14px; -webkit-text-size-adjust:100%; }
.app-shell { display:flex; min-height:100vh; }

/* ── Sidebar ── */
.sidebar { width:var(--sidebar-w); background:var(--bg2); border-right:1px solid var(--border); display:flex; flex-direction:column; position:fixed; top:0; left:0; bottom:0; z-index:300; transition:transform .25s ease; }
.sidebar-brand { display:flex; align-items:center; gap:10px; padding:18px 16px 14px; border-bottom:1px solid var(--border); }
.brand-icon { width:34px; height:34px; background:var(--accent); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:800; flex-shrink:0; }
.brand-name { font-weight:800; font-size:16px; letter-spacing:-.3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sidebar-nav { flex:1; padding:10px 8px; display:flex; flex-direction:column; gap:2px; overflow-y:auto; -webkit-overflow-scrolling:touch; }
.nav-item { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:7px; color:var(--text2); text-decoration:none; font-size:13.5px; font-weight:500; transition:all .15s; -webkit-tap-highlight-color:transparent; }
.nav-item:hover, .nav-item.active { background:var(--bg3); color:var(--text); }
.nav-item.active { color:var(--accent); }
.nav-icon { font-size:15px; width:20px; text-align:center; flex-shrink:0; }
.sidebar-footer { padding:12px 12px 16px; border-top:1px solid var(--border); }
.user-badge { display:flex; align-items:center; gap:10px; margin-bottom:10px; min-width:0; }
.user-avatar { width:32px; height:32px; background:var(--accent); border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; flex-shrink:0; }
.user-name { font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.user-role { color:var(--text2); font-size:11px; text-transform:capitalize; }
.logout-btn { display:block; text-align:center; padding:7px; border-radius:6px; background:var(--bg3); color:var(--text2); text-decoration:none; font-size:12.5px; transition:all .15s; margin-bottom:4px; }
.logout-btn:hover { background:var(--red); color:#fff; }

/* Sidebar overlay for mobile */
.sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:299; }
.sidebar-overlay.open { display:block; }

/* ── Main content ── */
.main-content { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }
.topbar { background:var(--bg2); border-bottom:1px solid var(--border); padding:0 16px; height:var(--topbar-h); display:flex; align-items:center; gap:12px; position:sticky; top:0; z-index:50; }
.menu-toggle { display:none; background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; padding:4px 6px; border-radius:6px; flex-shrink:0; -webkit-tap-highlight-color:transparent; }
.menu-toggle:hover { background:var(--bg3); }
.page-title { font-weight:700; font-size:17px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.content-area { padding:20px; flex:1; }

/* ── Flash ── */
.flash-msg { padding:12px 16px; border-radius:var(--radius); margin-bottom:18px; color:#fff; font-weight:500; font-size:13.5px; transition:opacity .3s; }

/* ── Cards ── */
.card { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:18px; margin-bottom:18px; }
.card-title { font-weight:700; font-size:15px; margin-bottom:14px; }

/* ── Stats grid ── */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:14px; margin-bottom:20px; }
.stat-card { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:16px 18px; position:relative; overflow:hidden; }
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--accent); }
.stat-card.green::before { background:var(--green); }
.stat-card.red::before { background:var(--red); }
.stat-card.yellow::before { background:var(--yellow); }
.stat-card.blue::before { background:var(--accent); }
.stat-label { color:var(--text2); font-size:11px; font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:.5px; }
.stat-value { font-weight:800; font-size:24px; line-height:1; margin-bottom:4px; }
.stat-sub { color:var(--text2); font-size:11px; }

/* ── Tables ── */
.table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:var(--radius); }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead th { background:var(--bg3); padding:10px 12px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text2); white-space:nowrap; }
tbody td { padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
tbody tr:hover td { background:rgba(255,255,255,.02); }
tbody tr:last-child td { border-bottom:none; }

/* ── Badges ── */
.badge { display:inline-block; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap; }
.badge-green { background:rgba(0,196,140,.15); color:var(--green); }
.badge-red { background:rgba(255,77,109,.15); color:var(--red); }
.badge-yellow { background:rgba(245,166,35,.15); color:var(--yellow); }
.badge-blue { background:rgba(59,130,246,.15); color:var(--accent); }

/* ── Buttons ── */
.btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 16px; border-radius:7px; border:none; cursor:pointer; font-family:'Poppins',sans-serif; font-size:13.5px; font-weight:500; text-decoration:none; transition:all .15s; line-height:1; white-space:nowrap; -webkit-tap-highlight-color:transparent; }
.btn-primary { background:var(--accent); color:#fff; }
.btn-primary:hover { background:var(--accent2); }
.btn-success { background:var(--green); color:#000; }
.btn-success:hover { filter:brightness(1.1); }
.btn-danger { background:var(--red); color:#fff; }
.btn-danger:hover { filter:brightness(1.1); }
.btn-ghost { background:var(--bg3); color:var(--text2); border:1px solid var(--border); }
.btn-ghost:hover { color:var(--text); }
.btn-sm { padding:5px 10px; font-size:12px; }

/* ── Forms ── */
.form-grid { display:grid; gap:14px; grid-template-columns:1fr 1fr; }
.form-grid.cols-3 { grid-template-columns:1fr 1fr 1fr; }
.form-grid.cols-1 { grid-template-columns:1fr; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group.full { grid-column:1 / -1; }
label { font-size:12px; font-weight:500; color:var(--text2); }
input, select, textarea { background:var(--bg3); border:1px solid var(--border); color:var(--text); padding:9px 12px; border-radius:7px; font-family:'Poppins',sans-serif; font-size:14px; transition:border-color .15s; width:100%; -webkit-appearance:none; }
input:focus, select:focus, textarea:focus { outline:none; border-color:var(--accent); }
select option { background:var(--bg2); }
textarea { resize:vertical; min-height:80px; }
.form-actions { display:flex; gap:10px; padding-top:8px; flex-wrap:wrap; }
.section-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; gap:10px; flex-wrap:wrap; }

/* ── Modal ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.75); z-index:400; align-items:flex-end; justify-content:center; padding:0; }
.modal-overlay.open { display:flex; }
.modal { background:var(--bg2); border:1px solid var(--border); border-radius:14px 14px 0 0; width:100%; max-width:100%; max-height:92vh; overflow-y:auto; -webkit-overflow-scrolling:touch; animation:slideUp .25s ease; }
@keyframes slideUp { from { transform:translateY(40px); opacity:0; } to { transform:translateY(0); opacity:1; } }
.modal-header { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:var(--bg2); z-index:1; }
.modal-title { font-weight:700; font-size:15px; }
.modal-close { background:none; border:none; color:var(--text2); font-size:22px; cursor:pointer; line-height:1; padding:4px 8px; -webkit-tap-highlight-color:transparent; }
.modal-close:hover { color:var(--text); }
.modal-body { padding:20px; padding-bottom:calc(20px + env(safe-area-inset-bottom)); }

/* ── Payslip ── */
@media print { .sidebar,.topbar,.no-print { display:none !important; } .main-content { margin-left:0 !important; } body { background:white !important; color:black !important; } .payslip-doc { background:white !important; color:black !important; border:none !important; } }
.payslip-doc { background:var(--bg2); border:1px solid var(--border); border-radius:var(--radius); padding:24px; max-width:700px; }
.payslip-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; border-bottom:2px solid var(--accent); padding-bottom:18px; gap:12px; }
.payslip-company h2 { font-weight:800; font-size:20px; }
.payslip-company p { color:var(--text2); font-size:12px; }
.payslip-meta { text-align:right; flex-shrink:0; }
.payslip-meta h3 { font-size:14px; color:var(--accent); }
.payslip-meta p { font-size:12px; color:var(--text2); }
.payslip-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:20px; }
.payslip-section h4 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text2); margin-bottom:10px; }
.payslip-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border); font-size:13px; gap:8px; }
.payslip-row:last-child { border-bottom:none; }
.payslip-total { background:var(--bg3); border-radius:8px; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; margin-top:18px; }
.payslip-total-label { font-weight:700; font-size:14px; }
.payslip-total-amount { font-weight:800; font-size:22px; color:var(--green); }

/* ── Mobile card tables ── */
@media (max-width: 768px) {
  .mob-card-table thead { display: none; }
  .mob-card-table tbody tr {
    display: block;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 10px;
    padding: 12px 14px;
  }
  .mob-card-table tbody td {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
    font-size: 13px;
    gap: 8px;
  }
  .mob-card-table tbody td:last-child { border-bottom: none; padding-top: 10px; }
  .mob-card-table tbody td::before {
    content: attr(data-label);
    font-size: 11px;
    font-weight: 600;
    color: var(--text2);
    text-transform: uppercase;
    letter-spacing: .4px;
    flex-shrink: 0;
  }
  .mob-card-table tbody td[data-label=""] { padding-top: 10px; }
  .mob-card-table .table-wrap { margin: 0; padding: 0; }
  .mob-card-table table { min-width: unset; width: 100%; }
  .mob-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; width: 100%; }
}

/* ══════════════════════════════
   TABLET — max 1024px
══════════════════════════════ */
@media (max-width: 1024px) {
  .stats-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
  .form-grid.cols-3 { grid-template-columns: 1fr 1fr; }
}

/* ══════════════════════════════
   MOBILE — max 768px
══════════════════════════════ */
@media (max-width: 768px) {
  /* Sidebar off-canvas */
  .sidebar { transform: translateX(-100%); box-shadow: none; }
  .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0,0,0,.5); }
  .main-content { margin-left: 0; }
  .menu-toggle { display: flex; }
  .content-area { padding: 14px; }
  .topbar { padding: 0 14px; }

  /* Modal full-screen bottom sheet on mobile */
  .modal-overlay { align-items: flex-end; }
  .modal { border-radius: 16px 16px 0 0; max-height: 88vh; }

  /* Forms */
  .form-grid, .form-grid.cols-3, .form-grid.cols-2 { grid-template-columns: 1fr; }

  /* Stats */
  .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  .stat-value { font-size: 20px; }

  /* Section header stack */
  .section-header { flex-direction: column; align-items: flex-start; gap: 10px; }
  .section-header > * { width: 100%; }
  .section-header form { display: flex; gap: 8px; width: 100%; }
  .section-header form input[type=month],
  .section-header form input[type=text] { flex: 1; }

  /* Tables — horizontal scroll with hint */
  .table-wrap { margin: 0 -14px; padding: 0 14px; }
  table { font-size: 12px; min-width: 500px; }
  thead th, tbody td { padding: 9px 10px; }

  /* Buttons in table cells stack */
  td > a.btn, td > button.btn { margin-bottom: 3px; }

  /* Payslip */
  .payslip-doc { padding: 16px; }
  .payslip-header { flex-direction: column; gap: 12px; }
  .payslip-meta { text-align: left; }
  .payslip-grid { grid-template-columns: 1fr; }
  .payslip-total { flex-direction: column; gap: 6px; align-items: flex-start; }
  .payslip-total-amount { font-size: 28px; }

  /* Cards */
  .card { padding: 14px; }

  /* Dashboard 2-col grid → 1-col */
  div[style*="grid-template-columns:1fr 1fr"] { display: block !important; }
  div[style*="grid-template-columns:1fr 1fr"] > div { margin-bottom: 16px; }
}

/* ══════════════════════════════
   SMALL MOBILE — max 480px
══════════════════════════════ */
@media (max-width: 480px) {
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .stat-card { padding: 12px 14px; }
  .stat-value { font-size: 18px; }
  .btn { font-size: 12.5px; padding: 7px 12px; }
  .btn-sm { padding: 4px 8px; font-size: 11px; }
  .page-title { font-size: 15px; }
  .topbar { height: 52px; }
  .modal { max-height: 94vh; }
  .content-area { padding: 12px; }
  table { min-width: 400px; }
}

/* ══════════════════════════════
   SAFE AREA (iPhone notch etc)
══════════════════════════════ */
@supports (padding: env(safe-area-inset-bottom)) {
  .sidebar-footer { padding-bottom: calc(18px + env(safe-area-inset-bottom)); }
  .topbar { padding-top: env(safe-area-inset-top); height: calc(var(--topbar-h) + env(safe-area-inset-top)); }
}
CSS;
}

function getInlineJS() {
    return <<<'JS'
// Active nav highlight
document.querySelectorAll('.nav-item').forEach(link => {
    const href = link.getAttribute('href');
    if (href && window.location.pathname.endsWith(href.split('/').pop())) link.classList.add('active');
});

// Sidebar + overlay
function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
}
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar && sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
}

// Overlay click closes sidebar
const overlay = document.getElementById('sidebarOverlay');
if (overlay) overlay.addEventListener('click', closeSidebar);

// Close on nav item tap (mobile)
document.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', () => { if (window.innerWidth <= 768) closeSidebar(); });
});

// Modal helpers
function openModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

// Close modal on overlay click
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
        document.body.style.overflow = '';
    }
});

function confirmDelete(msg) { return confirm(msg || 'Are you sure you want to delete this?'); }

// Auto-dismiss flash
const flash = document.querySelector('.flash-msg');
if (flash) setTimeout(() => { flash.style.opacity = '0'; setTimeout(() => flash.remove(), 300); }, 3500);
JS;
}

function pageHeader($title = 'Dashboard') {
    $siteName = getSetting('company_name', SITE_NAME);
    $logoPath = getSetting('logo_path', '');
    $siteUrl = SITE_URL;
    $role = $_SESSION['role'] ?? '';
    $userName = $_SESSION['full_name'] ?? '';
    $flash = getFlash();
    $flashHtml = '';
    if ($flash) {
        $fc = $flash['type'] === 'success' ? '#00c48c' : ($flash['type'] === 'error' ? '#ff4d6d' : '#f5a623');
        $flashHtml = '<div class="flash-msg" style="background:'.$fc.'">' . h($flash['msg']) . '</div>';
    }
    $css = getInlineStyles();

    // Logo: image or text icon
    $brandLogo = $logoPath
        ? '<img src="' . h($siteUrl . '/' . $logoPath) . '" style="height:34px;width:34px;border-radius:8px;object-fit:cover" alt="Logo">'
        : '<span class="brand-icon">' . strtoupper(substr($siteName, 0, 1)) . '</span>';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} — {$siteName}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>{$css}</style>
</head>
<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      {$brandLogo}
      <span class="brand-name">{$siteName}</span>
    </div>
    <nav class="sidebar-nav">
      <a href="{$siteUrl}/dashboard.php" class="nav-item"><span class="nav-icon">▦</span> Dashboard</a>
HTML;

    if ($role === 'admin') {
        echo <<<HTML
      <a href="{$siteUrl}/employees.php" class="nav-item"><span class="nav-icon">👤</span> Employees</a>
      <a href="{$siteUrl}/invoices.php" class="nav-item"><span class="nav-icon">📋</span> Invoices</a>
      <a href="{$siteUrl}/freelance.php" class="nav-item"><span class="nav-icon">🧑‍💻</span> Freelance</a>
      <a href="{$siteUrl}/expenses.php" class="nav-item"><span class="nav-icon">🧾</span> Expenses</a>
      <a href="{$siteUrl}/payroll.php" class="nav-item"><span class="nav-icon">💰</span> Payroll</a>
      <a href="{$siteUrl}/commissions.php" class="nav-item"><span class="nav-icon">📈</span> Commissions</a>
      <a href="{$siteUrl}/allowances.php" class="nav-item"><span class="nav-icon">🎁</span> Allowances</a>
      <a href="{$siteUrl}/payslips.php" class="nav-item"><span class="nav-icon">📄</span> Payslips</a>
      <a href="{$siteUrl}/clients.php" class="nav-item"><span class="nav-icon">🏢</span> Clients</a>
      <a href="{$siteUrl}/reports.php" class="nav-item"><span class="nav-icon">📊</span> Reports</a>
      <div style="margin:8px 10px 4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text2)">Admin</div>
      <a href="{$siteUrl}/chat.php" class="nav-item"><span class="nav-icon">🤖</span> AI Assistant</a>
      <a href="{$siteUrl}/users.php" class="nav-item"><span class="nav-icon">👥</span> Users</a>
      <a href="{$siteUrl}/settings.php" class="nav-item"><span class="nav-icon">⚙️</span> Settings</a>
HTML;
    } elseif ($role === 'staff') {
        $pages = [
            'employees'   => ['👤', 'Employees',   'employees.php'],
            'payroll'     => ['💰', 'Payroll',     'payroll.php'],
            'freelance'   => ['🧑‍💻', 'Freelance', 'freelance.php'],
            'commissions' => ['📈', 'Commissions', 'commissions.php'],
            'allowances'  => ['🎁', 'Allowances',  'allowances.php'],
            'expenses'    => ['🧾', 'Expenses',    'expenses.php'],
            'clients'     => ['🏢', 'Clients',     'clients.php'],
            'payslips'    => ['📄', 'Payslips',    'payslips.php'],
            'reports'     => ['📊', 'Reports',     'reports.php'],
        ];
        foreach ($pages as $key => [$icon, $label, $file]) {
            if (canAccess($key)) {
                echo "      <a href=\"{$siteUrl}/{$file}\" class=\"nav-item\"><span class=\"nav-icon\">{$icon}</span> {$label}</a>\n";
            }
        }
    }

    // Employee self-service
    if ($role === 'employee') {
        echo <<<HTML
      <a href="{$siteUrl}/my_payslips.php" class="nav-item"><span class="nav-icon">🧾</span> My Payslips</a>
      <a href="{$siteUrl}/my_history.php" class="nav-item"><span class="nav-icon">📋</span> Salary History</a>
HTML;
    }

    // Job title in footer
    $jobTitle = $_SESSION['job_title'] ?? '';
    $roleDisplay = $jobTitle ?: ($role === 'admin' ? 'Super Admin' : ucfirst($role));

    echo <<<HTML
    </nav>
    <div class="sidebar-footer">
      <div class="user-badge">
        <div class="user-avatar">{$userName[0]}</div>
        <div>
          <div class="user-name">{$userName}</div>
          <div class="user-role">{$roleDisplay}</div>
        </div>
      </div>
      <a href="{$siteUrl}/change_password.php" class="logout-btn">🔑 Change Password</a>
      <a href="{$siteUrl}/vendor.php" target="_blank" class="logout-btn" style="color:var(--yellow)">🧑‍💻 Vendor Portal</a>
      <a href="{$siteUrl}/logout.php" class="logout-btn">Sign Out</a>
    </div>
  </aside>
  <div class="main-content">
    <header class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Menu">☰</button>
      <h1 class="page-title">{$title}</h1>
    </header>
    <div class="content-area">
      {$flashHtml}
HTML;
}

function pageFooter() {
    $js = getInlineJS();
    echo <<<HTML
    </div>
  </div>
</div>
<script>{$js}</script>
</body>
</html>
HTML;
}
