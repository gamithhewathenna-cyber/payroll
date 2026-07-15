<?php
// includes/layout.php — CSS & JS fully embedded, no external file dependencies
require_once __DIR__ . '/vendor_approval.php';

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

/* ══════════════════════════════
   FLOATING AI CHAT WIDGET
══════════════════════════════ */
.aiw-bubble { position:fixed; bottom:20px; right:20px; width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,#7c3aed,#3b82f6); border:none; display:flex; align-items:center; justify-content:center; font-size:24px; cursor:pointer; box-shadow:0 6px 20px rgba(0,0,0,.35); z-index:500; -webkit-tap-highlight-color:transparent; }
.aiw-bubble:hover { transform:scale(1.06); }
.aiw-bubble.has-pending { animation:aiwPulse 2s ease-in-out infinite; }
@keyframes aiwPulse { 0%,100% { box-shadow:0 6px 20px rgba(0,0,0,.35), 0 0 0 0 rgba(255,77,109,.5); } 50% { box-shadow:0 6px 20px rgba(0,0,0,.35), 0 0 0 8px rgba(255,77,109,0); } }
.aiw-badge { position:absolute; top:-4px; right:-4px; background:var(--red); color:#fff; min-width:20px; height:20px; border-radius:10px; font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; padding:0 5px; border:2px solid var(--bg); }
.aiw-panel { position:fixed; bottom:20px; right:20px; width:360px; max-width:calc(100vw - 24px); height:520px; max-height:calc(100vh - 40px); background:var(--bg2); border:1px solid var(--border); border-radius:16px; box-shadow:0 12px 40px rgba(0,0,0,.5); display:none; flex-direction:column; overflow:hidden; z-index:500; }
.aiw-panel.open { display:flex; }
.aiw-header { background:linear-gradient(135deg,#7c3aed,#3b82f6); padding:12px 14px; display:flex; align-items:center; gap:8px; color:#fff; flex-shrink:0; }
.aiw-header strong { flex:1; font-size:13.5px; }
.aiw-header button { background:rgba(255,255,255,.15); border:none; color:#fff; width:26px; height:26px; border-radius:6px; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; -webkit-tap-highlight-color:transparent; }
.aiw-header button:hover { background:rgba(255,255,255,.28); }
.aiw-messages { flex:1; overflow-y:auto; padding:14px; display:flex; flex-direction:column; gap:10px; scroll-behavior:smooth; }
.aiw-input-wrap { border-top:1px solid var(--border); padding:10px; display:flex; gap:6px; flex-shrink:0; }
.aiw-input { flex:1; resize:none; background:var(--bg3); border:1px solid var(--border); color:var(--text); border-radius:9px; padding:8px 11px; font-family:'Poppins',sans-serif; font-size:12.5px; line-height:1.4; max-height:70px; }
.aiw-input:focus { outline:none; border-color:var(--accent); }
.aiw-send { background:var(--accent); color:#fff; border:none; border-radius:9px; padding:0 14px; cursor:pointer; font-size:13px; flex-shrink:0; }
.aiw-send:hover { background:var(--accent2); }
.aiw-msg { display:flex; gap:8px; max-width:92%; }
.aiw-msg.user { align-self:flex-end; flex-direction:row-reverse; }
.aiw-msg.assistant { align-self:flex-start; }
.aiw-avatar { width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; flex-shrink:0; margin-top:2px; }
.aiw-msg.user .aiw-avatar { background:var(--accent); }
.aiw-msg.assistant .aiw-avatar { background:linear-gradient(135deg,#7c3aed,#3b82f6); }
.aiw-bubble-body { padding:8px 11px; border-radius:11px; font-size:12.5px; line-height:1.55; }
.aiw-msg.user .aiw-bubble-body { background:var(--accent); color:#fff; border-bottom-right-radius:3px; }
.aiw-msg.assistant .aiw-bubble-body { background:var(--bg3); color:var(--text); border-bottom-left-radius:3px; }
.aiw-bubble-body strong { font-weight:700; }
.aiw-card { background:rgba(59,130,246,.08); border:1px solid rgba(59,130,246,.2); border-radius:9px; padding:10px 12px; margin-top:6px; }
.aiw-card.approval { border-color:rgba(245,166,35,.35); background:rgba(245,166,35,.08); }
.aiw-card-title { font-weight:700; color:var(--accent); font-size:12px; margin-bottom:6px; }
.aiw-card.approval .aiw-card-title { color:var(--yellow); }
.aiw-card-row { display:flex; justify-content:space-between; padding:2px 0; font-size:11px; color:var(--text2); gap:8px; }
.aiw-card-row strong { color:var(--text); text-align:right; }
.aiw-btn-confirm { background:var(--green); color:#000; border:none; border-radius:6px; padding:6px 12px; font-size:11px; font-weight:700; cursor:pointer; margin-top:8px; }
.aiw-btn-cancel { background:transparent; color:var(--text2); border:1px solid var(--border); border-radius:6px; padding:6px 10px; font-size:11px; cursor:pointer; margin-top:8px; margin-left:5px; }
.aiw-btn-reject { color:var(--red); border-color:rgba(255,77,109,.3); }
.aiw-result-ok { background:rgba(0,196,140,.1); border:1px solid rgba(0,196,140,.25); border-radius:7px; padding:7px 10px; margin-top:6px; font-size:11.5px; color:var(--green); }
.aiw-result-err { background:rgba(255,77,109,.1); border:1px solid rgba(255,77,109,.25); border-radius:7px; padding:7px 10px; margin-top:6px; font-size:11.5px; color:var(--red); }
.aiw-typing { display:flex; gap:3px; align-items:center; padding:4px 0; }
.aiw-typing span { width:6px; height:6px; background:var(--text2); border-radius:50%; animation:aiwDot .9s infinite; }
.aiw-typing span:nth-child(2) { animation-delay:.15s; }
.aiw-typing span:nth-child(3) { animation-delay:.3s; }
@keyframes aiwDot { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-5px)} }
@media (max-width:480px) { .aiw-panel { width:calc(100vw - 24px); right:12px; bottom:12px; height:calc(100vh - 90px); } .aiw-bubble { bottom:16px; right:16px; } }
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

// ══ Floating AI Chat Widget ══
(function() {
    const bubble = document.getElementById('aiwBubble');
    const panel  = document.getElementById('aiwPanel');
    if (!bubble || !panel) return; // not rendered on this page (non-admin, or on chat.php itself)

    const CHAT_URL   = window.AIW_SITE_URL + '/chat.php';
    const HIST_KEY    = 'aiw_history';
    const OPEN_KEY    = 'aiw_open';

    // Notification chime — generated with Web Audio API so no audio file/asset is needed.
    // Browsers block audio before any user interaction, so we lazily create the AudioContext
    // on the first click/keypress anywhere on the page and just skip the chime silently if
    // that hasn't happened yet (e.g. an approval already appears on initial page load).
    let audioCtx = null;
    function unlockAudio() { if (!audioCtx) { try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {} } }
    document.addEventListener('click', unlockAudio, { once: true });
    document.addEventListener('keydown', unlockAudio, { once: true });
    function playChime() {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') audioCtx.resume().catch(()=>{});
        const now = audioCtx.currentTime;
        [880, 1174.66].forEach((freq, i) => {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0, now + i*0.12);
            gain.gain.linearRampToValueAtTime(0.15, now + i*0.12 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + i*0.12 + 0.28);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(now + i*0.12);
            osc.stop(now + i*0.12 + 0.3);
        });
    }

    let history = [];
    try { history = JSON.parse(sessionStorage.getItem(HIST_KEY) || '[]'); } catch(e) { history = []; }
    // Deliberately NOT persisted across page loads: approval cards themselves aren't saved into
    // `history`, so on a fresh page load the DOM has none of them yet. This set only needs to
    // dedupe within the current page view (e.g. an item already shown on load, then echoed back
    // in a later sendMessage() response) — if it persisted, an item shown once would be silently
    // marked "seen" forever and never redrawn on the next page, even though it's still pending.
    let shownApprovalIds = new Set();
    // This one DOES persist — it gates the notification chime, not the card display, so a
    // still-pending item only ever dings once per browser session instead of on every page
    // navigation. New items (not seen before in this session) still ding every time.
    const NOTIFIED_KEY = 'aiw_notified_approvals';
    let notifiedApprovalIds;
    try { notifiedApprovalIds = new Set(JSON.parse(sessionStorage.getItem(NOTIFIED_KEY) || '[]')); } catch(e) { notifiedApprovalIds = new Set(); }
    function saveNotified() { sessionStorage.setItem(NOTIFIED_KEY, JSON.stringify([...notifiedApprovalIds])); }
    let pending = null;

    function saveHistory() { sessionStorage.setItem(HIST_KEY, JSON.stringify(history)); }

    function fmt(t) {
        return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
                .replace(/\n/g,'<br>');
    }

    function addMsg(role, html, extra) {
        extra = extra || '';
        const wrap = document.getElementById('aiwMessages');
        const d = document.createElement('div');
        d.className = 'aiw-msg ' + role;
        d.innerHTML = `<div class="aiw-avatar">${role==='user'?'👤':'🤖'}</div><div class="aiw-bubble-body">${html}${extra}</div>`;
        wrap.appendChild(d);
        wrap.scrollTop = wrap.scrollHeight;
        return d;
    }

    function showTyping() {
        const wrap = document.getElementById('aiwMessages');
        const d = document.createElement('div');
        d.id = 'aiwTyping'; d.className = 'aiw-msg assistant';
        d.innerHTML = '<div class="aiw-avatar">🤖</div><div class="aiw-bubble-body"><div class="aiw-typing"><span></span><span></span><span></span></div></div>';
        wrap.appendChild(d); wrap.scrollTop = wrap.scrollHeight;
    }
    function hideTyping() { document.getElementById('aiwTyping')?.remove(); }

    function buildCard(action) {
        if (!action) return '';
        const d = action.data || {};
        const titles = {create_invoice:'📄 Create Invoice',create_expense:'💰 Add Expense',create_client:'🏢 Add Client',create_payroll:'👥 Process Payroll',mark_invoice_paid:'✅ Mark Invoice Paid',get_report:'📊 Get Report'};
        let rows = '';
        if (action.action==='mark_invoice_paid') {
            rows = `<div class="aiw-card-row"><span>Client</span><strong>${d.client_name||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Invoice #</span><strong>${d.invoice_number||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Month</span><strong>${d.month||'—'}</strong></div>`;
        } else if (action.action==='create_invoice') {
            rows = `<div class="aiw-card-row"><span>Client</span><strong>${d.client_name||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Currency</span><strong>${d.currency||'LKR'}</strong></div>
                    <div class="aiw-card-row"><span>Status</span><strong>${d.status||'draft'}</strong></div>`;
        } else if (action.action==='create_expense') {
            rows = `<div class="aiw-card-row"><span>Category</span><strong>${d.expense_category||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Amount</span><strong>${d.currency||'LKR'} ${parseFloat(d.cost_amount||0).toLocaleString('en',{minimumFractionDigits:2})}</strong></div>`;
        } else if (action.action==='create_client') {
            rows = `<div class="aiw-card-row"><span>Company</span><strong>${d.company_name||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Email</span><strong>${d.email||'—'}</strong></div>`;
        } else if (action.action==='create_payroll') {
            rows = `<div class="aiw-card-row"><span>Employee</span><strong>${d.employee_name||'—'}</strong></div>
                    <div class="aiw-card-row"><span>Month</span><strong>${d.month||'—'}</strong></div>`;
        }
        return `<div class="aiw-card"><div class="aiw-card-title">${titles[action.action]||action.action}</div>${rows}<div><button class="aiw-btn-confirm" onclick="AIW.exec(this)">✅ Confirm</button><button class="aiw-btn-cancel" onclick="AIW.cancel(this)">Cancel</button></div></div>`;
    }

    function buildApprovalCard(item) {
        const rows = item.rows.map(([label, val]) => `<div class="aiw-card-row"><span>${label}</span><strong>${val}</strong></div>`).join('');
        return `<div class="aiw-card approval"><div class="aiw-card-title">${item.title}</div>${rows}<div>
            <button class="aiw-btn-confirm" onclick="AIW.approve('${item.type}',${item.id},this)">✅ Approve</button>
            <button class="aiw-btn-cancel aiw-btn-reject" onclick="AIW.reject('${item.type}',${item.id},this)">❌ Reject</button>
          </div></div>`;
    }

    function updateBadge(count) {
        const badge = document.getElementById('aiwBadge');
        if (!badge || count === null || count === undefined) return;
        if (count > 0) { badge.textContent = count; badge.style.display = 'flex'; bubble.classList.add('has-pending'); }
        else { badge.style.display = 'none'; bubble.classList.remove('has-pending'); }
    }

    function renderPendingApprovals(list) {
        list = list || [];
        let shouldChime = false;
        list.forEach(item => {
            const key = item.type + ':' + item.id;
            if (!shownApprovalIds.has(key)) {
                shownApprovalIds.add(key);
                addMsg('assistant', '🔔 <strong>New approval needed</strong>', buildApprovalCard(item));
            }
            if (!notifiedApprovalIds.has(key)) {
                notifiedApprovalIds.add(key);
                shouldChime = true;
            }
        });
        if (list.length) saveNotified();
        updateBadge(list.length);
        if (shouldChime) playChime();
    }

    async function sendMessage() {
        const inp = document.getElementById('aiwInput');
        const txt = inp.value.trim(); if (!txt) return;
        inp.value = '';
        addMsg('user', fmt(txt));
        history.push({role:'user', content:txt});
        saveHistory();
        showTyping();
        try {
            const fd = new FormData();
            fd.append('action','chat');
            fd.append('messages', JSON.stringify(history));
            const res  = await fetch(CHAT_URL, {method:'POST', body:fd});
            const data = await res.json();
            hideTyping();
            if (data.error) {
                addMsg('assistant', '❌ ' + fmt(data.error));
                history.push({role:'assistant', content:data.error});
                saveHistory();
                return;
            }
            const reply = data.reply || '';
            pending = data.action || null;
            const linkHtml = data.link ? `<div style="margin-top:6px"><a href="${data.link}" target="_blank" style="color:var(--accent)">View →</a></div>` : '';
            addMsg('assistant', fmt(reply), linkHtml + buildCard(pending));
            history.push({role:'assistant', content:reply});
            saveHistory();
            renderPendingApprovals(data.pendingApprovals);
        } catch(e) {
            hideTyping();
            addMsg('assistant', '❌ Network error. Please try again.');
        }
    }

    async function execAction(btn) {
        if (!pending) return;
        const card = btn.closest('.aiw-card');
        card?.querySelectorAll('button').forEach(b=>b.remove());
        const snap = pending; pending = null;
        showTyping();
        try {
            const fd = new FormData();
            fd.append('action','execute');
            fd.append('payload', JSON.stringify(snap));
            const res  = await fetch(CHAT_URL, {method:'POST', body:fd});
            const data = await res.json();
            hideTyping();
            const cls  = data.success ? 'aiw-result-ok' : 'aiw-result-err';
            const link = data.link ? ` <a href="${data.link}" target="_blank" style="color:var(--accent)">View →</a>` : '';
            addMsg('assistant', `<div class="${cls}">${fmt(data.message||'')}${link}</div>`);
            history.push({role:'assistant', content: data.message||''});
            saveHistory();
        } catch(e) {
            hideTyping();
            addMsg('assistant','❌ Execution failed.');
        }
    }

    function cancelAction(btn) {
        pending = null;
        const card = btn.closest('.aiw-card');
        card?.querySelectorAll('button').forEach(b=>b.remove());
        addMsg('assistant','Cancelled.');
    }

    async function runApproval(actionType, id, card, reason) {
        try {
            const fd = new FormData();
            fd.append('action','execute');
            fd.append('payload', JSON.stringify({action: actionType, data: {id, reason: reason||''}}));
            const res  = await fetch(CHAT_URL, {method:'POST', body:fd});
            const data = await res.json();
            const cls  = data.success ? 'aiw-result-ok' : 'aiw-result-err';
            const link = data.link ? ` <a href="${data.link}" target="_blank" style="color:var(--accent)">View →</a>` : '';
            card.insertAdjacentHTML('afterend', `<div class="${cls}">${fmt(data.message||'')}${link}</div>`);
            card.remove();
        } catch(e) {
            card.insertAdjacentHTML('afterend', `<div class="aiw-result-err">❌ Action failed.</div>`);
        }
    }
    async function approveItem(type, id, btn) {
        const card = btn.closest('.aiw-card');
        card.querySelectorAll('button').forEach(b => b.disabled = true);
        await runApproval(type === 'vendor_submission' ? 'approve_vendor_submission' : 'approve_expense_request', id, card);
    }
    async function rejectItem(type, id, btn) {
        const reason = type === 'vendor_submission' ? (prompt('Reason for rejecting this invoice (optional):') || '') : '';
        const card = btn.closest('.aiw-card');
        card.querySelectorAll('button').forEach(b => b.disabled = true);
        await runApproval(type === 'vendor_submission' ? 'reject_vendor_submission' : 'reject_expense_request', id, card, reason);
    }

    function openPanel() {
        panel.classList.add('open');
        bubble.style.display = 'none';
        sessionStorage.setItem(OPEN_KEY, '1');
        const wrap = document.getElementById('aiwMessages');
        wrap.scrollTop = wrap.scrollHeight;
    }
    function minimizePanel() {
        panel.classList.remove('open');
        bubble.style.display = 'flex';
        sessionStorage.setItem(OPEN_KEY, '0');
    }
    function toggle() { panel.classList.contains('open') ? minimizePanel() : openPanel(); }
    function handleKey(e) { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } }

    // Restore prior conversation into the DOM (persisted across page navigations via sessionStorage)
    history.forEach(m => addMsg(m.role, fmt(m.content)));
    if (sessionStorage.getItem(OPEN_KEY) === '1') openPanel();

    window.AIW = { toggle, minimize: minimizePanel, send: sendMessage, key: handleKey, exec: execAction, cancel: cancelAction, approve: approveItem, reject: rejectItem };

    if (window.AIW_INITIAL_PENDING) renderPendingApprovals(window.AIW_INITIAL_PENDING);
})();
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
    $siteUrl = SITE_URL;
    $widgetHtml = '';

    if (isAdmin() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'chat.php') {
        $db          = getDB();
        $pending     = getPendingApprovals($db);
        $count       = count($pending);
        $pendingJson = json_encode($pending);
        $siteUrlJson = json_encode($siteUrl);
        $badgeStyle  = $count > 0 ? 'display:flex' : 'display:none';

        $widgetHtml = <<<HTML
<button class="aiw-bubble" id="aiwBubble" onclick="AIW.toggle()" aria-label="AI Assistant">
  🤖
  <span class="aiw-badge" id="aiwBadge" style="{$badgeStyle}">{$count}</span>
</button>
<div class="aiw-panel" id="aiwPanel">
  <div class="aiw-header">
    <span style="font-size:16px">🤖</span>
    <strong>AI Assistant</strong>
    <a href="{$siteUrl}/chat.php" style="background:rgba(255,255,255,.15);color:#fff;text-decoration:none;width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px" title="Open full page">⤢</a>
    <button onclick="AIW.minimize()" title="Minimize">–</button>
  </div>
  <div class="aiw-messages" id="aiwMessages"></div>
  <div class="aiw-input-wrap">
    <textarea class="aiw-input" id="aiwInput" rows="1" placeholder="Ask me anything…" onkeydown="AIW.key(event)"></textarea>
    <button class="aiw-send" onclick="AIW.send()">↑</button>
  </div>
</div>
<script>
window.AIW_SITE_URL = {$siteUrlJson};
window.AIW_INITIAL_PENDING = {$pendingJson};
</script>
HTML;
    }

    echo <<<HTML
    </div>
  </div>
</div>
{$widgetHtml}
<script>{$js}</script>
</body>
</html>
HTML;
}
