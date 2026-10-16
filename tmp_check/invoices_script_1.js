
function liveSearch(val) {
    // Client-side instant filter on invoice number column
    const rows = document.querySelectorAll('#invoiceTable tbody tr');
    const q = val.toLowerCase();
    rows.forEach(row => {
        const num = row.querySelector('.inv-num-cell')?.textContent?.toLowerCase() || '';
        const client = row.querySelector('.inv-client-cell')?.textContent?.toLowerCase() || '';
        row.style.display = (!q || num.includes(q) || client.includes(q)) ? '' : 'none';
    });
    // Show count
    const visible = [...rows].filter(r => r.style.display !== 'none').length;
    const counter = document.getElementById('recordCount');
    if (counter) counter.textContent = visible + ' records';
}

function openPDF(url, invNo) {
    const w = window.open(url,'_blank');
    if (w) w.addEventListener('load', ()=>{ w.document.title=invNo; setTimeout(()=>w.print(),300); });
}
