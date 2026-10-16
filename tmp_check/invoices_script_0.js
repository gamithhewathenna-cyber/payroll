
const sym = '0';
const siteUrl = '0';
const rates = {
    'LKR': 1,
    'USD': 0,
    'AUD': 0,
    'EUR': 0,
    'GBP': 0,
    'SGD': 0,
};
const currencySymbols = { 'LKR':'Rs.', 'USD':'$', 'AUD':'A$', 'EUR':'€', 'GBP':'£', 'SGD':'S$' };

function getRate() {
    const cur = document.getElementById('invCurrency')?.value || 'LKR';
    return rates[cur] || 1;
}
function getInvCurrency() {
    return document.getElementById('invCurrency')?.value || 'LKR';
}

function updateCurrencyRate() {
    const cur = getInvCurrency();
    const wrap = document.getElementById('rateInfoWrap');
    const display = document.getElementById('rateDisplay');
    const rateInfo = document.getElementById('rateInfo');
    if (cur === 'LKR') {
        if (wrap) wrap.style.display = 'none';
    } else {
        if (wrap) wrap.style.display = '';
        if (display) display.textContent = rates[cur]?.toLocaleString('en', {minimumFractionDigits:2}) || '—';
        if (rateInfo) rateInfo.querySelector('strong') && (rateInfo.innerHTML = `1 ${cur} = <strong id="rateDisplay">${(rates[cur]||1).toLocaleString('en',{minimumFractionDigits:2})}</strong> LKR &nbsp;<span style="color:var(--text2);font-size:11px">(from Settings → Exchange Rates)</span>`);
    }
    // Update all line item amount displays
    document.querySelectorAll('#lineItems .line-item').forEach(row => {
        if (!row.querySelector('[name="item_type[]"]')?.value === 'expense') calcLine(row.querySelector('[name="item_qty[]"]'));
    });
    calcTotals();
}

function toggleClientMode() {
    const type = document.getElementById('invType').value;
    const isQuote = type === 'quotation';
    const selWrap  = document.getElementById('clientSelectWrap');
    const freeWrap = document.getElementById('clientFreeWrap');
    const bmWrap   = document.getElementById('billingMonthWrap');
    const sel      = document.getElementById('invClient');
    const free     = document.getElementById('customClientName');
    if (isQuote) {
        selWrap.style.display  = 'none';
        freeWrap.style.display = '';
        bmWrap.style.display   = 'none';
        sel.removeAttribute('required');
        if (free) free.setAttribute('required','required');
    } else {
        selWrap.style.display  = '';
        freeWrap.style.display = 'none';
        bmWrap.style.display   = '';
        if (free) free.removeAttribute('required');
    }
    loadExpenses();
}

function openInvoiceModal(type) {
    document.getElementById('invType').value = type;
    document.getElementById('modalTitle').textContent = 'New ' + (type === 'invoice' ? 'Invoice' : 'Quotation');
    toggleClientMode();
    openModal('invoiceModal');
}

function addItem(desc='', qty=1, price=0, type='service', expId=0, subdesc='') {
    const isExpense = type === 'expense';
    const amt = (qty * price).toFixed(2);
    const div = document.createElement('div');
    div.className = 'line-item' + (isExpense ? ' locked-expense' : '');
    div.style.cssText = 'display:grid;grid-template-columns:1fr auto auto auto;gap:6px;align-items:center' + (isExpense ? ';background:rgba(59,130,246,.04);border-radius:7px;padding:6px 8px;border:1px solid rgba(59,130,246,.15)' : '');
    const lockIcon = isExpense ? '<span title="Auto-synced — locked" style="font-size:14px;margin-right:4px">🔒</span>' : '';
    div.innerHTML = `
        <input type="hidden" name="item_type[]" value="${type}">
        <input type="hidden" name="item_exp_id[]" value="${expId}">
        <div style="display:flex;flex-direction:column;gap:4px;min-width:0">
            <div style="display:flex;align-items:center;gap:4px">
                ${lockIcon}
                <input name="item_desc[]" placeholder="Service / Item name" value="${desc}" style="font-size:13px;font-weight:600;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''}>
            </div>
            <input name="item_subdesc[]" placeholder="Description of work (optional)" value="${subdesc}" style="font-size:12px;${isExpense?'pointer-events:none;opacity:.7;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''}>
        </div>
        <input name="item_qty[]" type="number" step="0.01" value="${qty}" style="width:65px;font-size:13px;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''} oninput="${isExpense?'':' calcLine(this)'}">
        <input name="item_price[]" type="number" step="0.01" value="${price}" style="width:110px;font-size:13px;${isExpense?'pointer-events:none;opacity:.8;background:transparent;border-color:transparent;':''}" ${isExpense?'readonly':''} oninput="${isExpense?'':' calcLine(this)'}">
        <div style="display:flex;align-items:center;gap:4px">
            <span class="item-amt" style="width:100px;font-size:13px;font-weight:600;color:var(--${isExpense?'accent':'green'});text-align:right">${sym} ${parseFloat(amt).toLocaleString('en',{minimumFractionDigits:2})}</span>
            ${isExpense
                ? '<span title="Auto-synced from Expenses" style="width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text2)">🔒</span>'
                : '<button type="button" onclick="this.closest(\'.line-item\').remove();calcTotals()" style="background:var(--red);border:none;color:#fff;border-radius:5px;width:24px;height:24px;cursor:pointer;font-size:14px">×</button>'
            }
        </div>`;

    // Expenses always go to bottom, with a separator if needed
    if (isExpense) {
        let sep = document.getElementById('expenseSeparator');
        if (!sep) {
            sep = document.createElement('div');
            sep.id = 'expenseSeparator';
            sep.style.cssText = 'grid-column:1/-1;display:flex;align-items:center;gap:8px;margin:6px 0';
            sep.innerHTML = '<div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div><span style="font-size:11px;color:var(--accent);font-weight:600;white-space:nowrap">📥 AUTO-SYNCED EXPENSES</span><div style="flex:1;height:1px;background:rgba(59,130,246,.3)"></div>';
            // Insert separator as a wrapper
            const sepWrap = document.createElement('div');
            sepWrap.id = 'expenseSeparator';
            sepWrap.style.cssText = 'display:flex;align-items:center;gap:8px;margin:6px 0;padding:0 4px';
            sepWrap.innerHTML = sep.innerHTML;
            document.getElementById('lineItems').appendChild(sepWrap);
        }
        document.getElementById('lineItems').appendChild(div);
    } else {
        // Service items go before the separator
        const sep = document.getElementById('expenseSeparator');
        if (sep) {
            document.getElementById('lineItems').insertBefore(div, sep);
        } else {
            document.getElementById('lineItems').appendChild(div);
        }
    }
    calcTotals();
}

function calcLine(input) {
    const row   = input.closest('.line-item');
    if (!row) return;
    const qty   = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
    const price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
    const rate  = getRate();
    const cur   = getInvCurrency();
    const amtLKR = qty * price * rate;
    const display = cur === 'LKR'
        ? `${sym} ${amtLKR.toLocaleString('en',{minimumFractionDigits:2})}`
        : `${sym} ${amtLKR.toLocaleString('en',{minimumFractionDigits:2})} <span style="font-size:10px;color:var(--text2)">(${currencySymbols[cur]||cur} ${(qty*price).toLocaleString('en',{minimumFractionDigits:2})})</span>`;
    row.querySelector('.item-amt').innerHTML = display;
    calcTotals();
}

function calcTotals() {
    const rate = getRate();
    const cur  = getInvCurrency();
    let sub = 0;
    document.querySelectorAll('#lineItems .line-item').forEach(row => {
        const qty   = parseFloat(row.querySelector('[name="item_qty[]"]')?.value) || 0;
        const price = parseFloat(row.querySelector('[name="item_price[]"]')?.value) || 0;
        const type  = row.querySelector('[name="item_type[]"]')?.value || 'service';
        // Expense items are already in LKR, service items need conversion
        const amtLKR = type === 'expense' ? qty * price : qty * price * rate;
        sub += amtLKR;
    });
    const discPct = parseFloat(document.getElementById('discPct').value) || 0;
    const taxPct  = parseFloat(document.getElementById('taxPct').value) || 0;
    const disc = sub * discPct / 100;
    const tax  = (sub - disc) * taxPct / 100;
    const total = sub - disc + tax;
    const fmt = n => `${sym} ${n.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    const fmtFx = n => cur !== 'LKR' ? ` <span style="font-size:11px;color:var(--text2)">(${currencySymbols[cur]||cur} ${(n/rate).toLocaleString('en',{minimumFractionDigits:2})})</span>` : '';
    document.getElementById('dispSubtotal').innerHTML = fmt(sub) + fmtFx(sub);
    document.getElementById('dispDiscount').innerHTML = fmt(disc);
    document.getElementById('dispTax').innerHTML      = fmt(tax);
    document.getElementById('dispTotal').innerHTML    = fmt(total) + fmtFx(total);
    document.getElementById('discRow').style.display = discPct > 0 ? '' : 'none';
    document.getElementById('taxRow').style.display  = taxPct  > 0 ? '' : 'none';
}

function loadExpenses() {
    const clientSel = document.getElementById('invClient');
    const clientId  = clientSel?.value;
    document.getElementById('importBtn').style.display = clientId ? '' : 'none';
    // Auto-set currency from client's default
    if (clientId && clientSel) {
        const opt = clientSel.options[clientSel.selectedIndex];
        const clientCur = opt?.getAttribute('data-currency') || 'LKR';
        const curSel = document.getElementById('invCurrency');
        if (curSel && curSel.value === 'LKR') { // only auto-set if not already changed
            curSel.value = clientCur;
            updateCurrencyRate();
        }
    }
    autoSyncIfReady();
}

function autoSyncIfReady() {
    const clientId = document.getElementById('invClient').value;
    const month    = document.getElementById('invBillingMonth').value;
    if (!clientId || !month) return;
    // Only auto-sync if no items have a price yet (fresh invoice)
    const hasItems = [...document.querySelectorAll('#lineItems [name="item_price[]"]')]
        .some(i => parseFloat(i.value) > 0);
    if (hasItems) return; // don't overwrite existing items
    syncExpenses(clientId, month, false);
}

function importExpenses() {
    const clientId = document.getElementById('invClient').value;
    const month    = document.getElementById('invBillingMonth').value;
    if (!clientId || !month) { alert('Please select a client and billing month first.'); return; }
    if (document.querySelectorAll('#lineItems .line-item').length > 1 ||
        parseFloat(document.querySelector('#lineItems [name="item_price[]"]')?.value) > 0) {
        if (!confirm('This will add expense items to your existing line items. Continue?')) return;
    }
    syncExpenses(clientId, month, true);
}

function syncExpenses(clientId, month, showAlert) {
    const btn = document.getElementById('importBtn');
    if (btn) { btn.textContent = '⏳ Loading...'; btn.disabled = true; }
    fetch(`${siteUrl}/invoices.php?action=get_expenses&client_id=${clientId}&month=${month}`)
        .then(r => r.json())
        .then(data => {
            if (btn) { btn.textContent = '📥 Sync Expenses'; btn.disabled = false; }
            if (!data.length) {
                if (showAlert) alert('No expenses found for this client and month.');
                return;
            }
            // Remove existing expense-type items to avoid duplicates
            document.querySelectorAll('#lineItems .line-item').forEach(row => {
                const typeInput = row.querySelector('[name="item_type[]"]');
                if (typeInput && typeInput.value === 'expense') row.remove();
            });
            // Add synced expenses
            data.forEach(e => addItem(e.desc, 1, e.amount, 'expense', e.id, e.subdesc || ''));
            // Show sync notice
            const notice = document.getElementById('syncNotice');
            if (notice) {
                notice.textContent = `✅ ${data.length} expense${data.length>1?'s':''} synced from Expenses & Client Rebilling`;
                notice.style.display = 'block';
                setTimeout(() => notice.style.display = 'none', 4000);
            }
        })
        .catch(() => {
            if (btn) { btn.textContent = '📥 Sync Expenses'; btn.disabled = false; }
        });
}

// AJAX expense loader — auto-sync when month changes too
document.addEventListener('DOMContentLoaded', () => {
    toggleClientMode();
    updateCurrencyRate();
    loadExpenses();
    calcTotals();
    const monthInput = document.getElementById('invBillingMonth');
    if (monthInput) monthInput.addEventListener('change', autoSyncIfReady);
});
