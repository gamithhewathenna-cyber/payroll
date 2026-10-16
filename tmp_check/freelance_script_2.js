
var currSym = '0';
function calcBal() {
    const inv  = parseFloat(document.getElementById('fInvAmt')?.value) || 0;
    const advEl = document.getElementById('fAdvAmt');
    const adv  = (advEl && advEl.value.trim() !== '') ? parseFloat(advEl.value) : inv;
    const bal  = Math.max(0, inv - adv);
    const disp = document.getElementById('fBalDisplay');
    if (disp) disp.textContent = currSym + ' ' + bal.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
}
document.addEventListener('DOMContentLoaded', () => {
    calcBal();
    if (document.getElementById('editPaymentInvoiceMonth')) {
        filterInvoiceList('editPaymentInvoice');
    }
});
