
const EXCHANGE_RATES = 0;
const RATES_UPDATED  = "0";

function syncClientName(prefix) {
    const sel = document.getElementById(prefix + 'ClientId');
    const hidden = document.getElementById(prefix + 'ClientName');
    const opt = sel.options[sel.selectedIndex];
    if (hidden) hidden.value = opt ? opt.getAttribute('data-name') || '' : '';
}

function toggleClient(prefix) {
    const type  = document.getElementById(prefix + 'BillingType').value;
    const field = document.getElementById(prefix + 'ClientField');
    if (field) field.style.display = type === 'internal' ? 'none' : '';
    const notice = document.getElementById(prefix + 'ClientPaidNotice');
    if (notice) notice.style.display = type === 'client_paid' ? 'block' : 'none';
}

function calcTotal(prefix) {
    const cost      = parseFloat(document.getElementById(prefix + 'Cost').value) || 0;
    const markup    = parseFloat(document.getElementById(prefix + 'Markup').value) || 0;
    const fee       = parseFloat(document.getElementById(prefix + 'Fee').value) || 0;
    const curSel    = document.getElementById(prefix + 'Currency');
    const currency  = curSel ? curSel.value : 'LKR';
    const rate      = EXCHANGE_RATES[currency] || 1;
    const lkrBox    = document.getElementById(prefix + 'LkrBox');
    const lkrVal    = document.getElementById(prefix + 'LkrVal');
    const rateLabel = document.getElementById(prefix + 'RateLabel');

    if (lkrBox) {
        if (currency !== 'LKR' && cost > 0) {
            lkrBox.style.display = '';
            lkrVal.textContent = 'Rs. ' + (cost * rate).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
            if (rateLabel) rateLabel.textContent = '1 ' + currency + ' = Rs. ' + rate.toFixed(2) + ' · Updated: ' + RATES_UPDATED;
        } else {
            lkrBox.style.display = 'none';
        }
    }

    const costLKR = cost * rate;
    const total   = costLKR + (costLKR * markup / 100) + fee;
    document.getElementById(prefix + 'Total').textContent = total.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById(prefix + 'TotalHidden').value = total.toFixed(2);
}

function updateStatus(id, status, month) {
    window.location = `?action=status&id=${id}&s=${status}&month=${month}&tab=ads`;
}

document.addEventListener('DOMContentLoaded', () => {
    toggleClient('add');
    calcTotal('add');
    ;
    toggleClient('edit');
    calcTotal('edit');
    ;
});
