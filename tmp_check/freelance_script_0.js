
let markPaidId = null;
function filterInvoiceList(prefix) {
    const month  = document.getElementById(prefix + 'Month').value;
    const searchEl = document.getElementById(prefix + 'Search');
    const search = searchEl ? searchEl.value.trim().toLowerCase() : '';
    const list = document.getElementById(prefix + 'List');
    let visible = 0;
    list.querySelectorAll('.invoiceRow').forEach(row => {
        const monthOk  = !month || row.dataset.month === month;
        const searchOk = !search || row.dataset.search.includes(search);
        const show = monthOk && searchOk;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const noMatch = list.querySelector('.invoiceNoMatch');
    if (noMatch) noMatch.style.display = (visible === 0) ? '' : 'none';
}
function openMarkPaid(id) {
    markPaidId = id;
    document.getElementById('markPaidDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('markPaidRef').value = '';
    document.querySelectorAll('.markPaidInvoiceCheckbox').forEach(cb => cb.checked = false);
    document.getElementById('markPaidInvoiceMonth').value = '0';
    document.getElementById('markPaidInvoiceSearch').value = '';
    filterInvoiceList('markPaidInvoice');
    openModal('markPaidModal');
}
function confirmMarkPaid() {
    const date = document.getElementById('markPaidDate').value;
    if (!date) { alert('Please select a payment date.'); return; }
    const ref = encodeURIComponent(document.getElementById('markPaidRef').value || '');
    const invoiceIds = Array.from(document.querySelectorAll('.markPaidInvoiceCheckbox:checked')).map(cb => cb.value).join(',');
    window.location = `?action=mark_paid&id=${markPaidId}&month=0&payment_date=${date}&bank_ref=${ref}&client_invoice_ids=${encodeURIComponent(invoiceIds)}`;
}
