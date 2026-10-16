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