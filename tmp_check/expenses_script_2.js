
const EXCHANGE_RATES = 0;
const RATES_UPDATED  = "0";

// Sync client name from select
function syncClientName(prefix) {
    const sel = document.getElementById(prefix + 'ClientId');
    const hidden = document.getElementById(prefix + 'ClientName');
    const opt = sel.options[sel.selectedIndex];
    if (hidden) hidden.value = opt ? opt.getAttribute('data-name') || '' : '';
}

// Toggle client name field
function toggleClient(prefix) {
    const type  = document.getElementById(prefix + 'BillingType').value;
    const field = document.getElementById(prefix + 'ClientField');
    if (field) field.style.display = type === 'internal' ? 'none' : '';
    // Show client-paid notice
    const notice = document.getElementById(prefix + 'ClientPaidNotice');
    if (notice) notice.style.display = type === 'client_paid' ? 'block' : 'none';
}

function openAddModal() {
    toggleRecordType('add');
    openModal('addModal');
}

// Add Expense: two record types (this page is Bank Transfer records only) —
//  "bank_transfer_expense"  normal expense (billing fields stay, counts in Revenue/Profit)
//                           but paid via bank transfer at creation — bank ref/receipt shown,
//                           status forced to Paid, appears in Payment Report
//  "bank_transfer"          pure tracking record — billing fields hidden, excluded from
//                           Revenue/Profit/totals, only appears in Payment Report
function toggleRecordType(prefix) {
    const pureBankTransferRadio = document.getElementById(prefix + 'RecordBankTransfer');
    const bankTransferExpenseRadio = document.getElementById(prefix + 'RecordBankTransferExpense');
    if (!pureBankTransferRadio) return;
    const isPureBankTransfer = pureBankTransferRadio.checked;
    const isBankTransferExpense = !!(bankTransferExpenseRadio && bankTransferExpenseRadio.checked);
    const isAnyBankTransfer = isPureBankTransfer || isBankTransferExpense;

    // Billing fields only hide for the pure (non-billable) tracking record
    ['BillingTypeGroup', 'MarkupGroup', 'FeeGroup'].forEach(suffix => {
        const el = document.getElementById(prefix + suffix);
        if (el) el.style.display = isPureBankTransfer ? 'none' : '';
    });
    const clientField = document.getElementById(prefix + 'ClientField');
    if (clientField) clientField.style.display = isPureBankTransfer ? 'none' : '';
    if (isPureBankTransfer) {
        const notice = document.getElementById(prefix + 'ClientPaidNotice');
        if (notice) notice.style.display = 'none';
    } else {
        toggleClient(prefix);
    }

    // Status is forced to Paid for either bank-transfer flavor
    const statusGroup = document.getElementById(prefix + 'StatusGroup');
    if (statusGroup) statusGroup.style.display = isAnyBankTransfer ? 'none' : '';

    // Original expense receipt only makes sense for a real (billable) expense
    const origReceiptGroup = document.getElementById(prefix + 'OrigReceiptGroup');
    if (origReceiptGroup) origReceiptGroup.style.display = isPureBankTransfer ? 'none' : '';

    // Bank reference + payment receipt apply to either bank-transfer flavor
    const bankRefGroup = document.getElementById(prefix + 'BankRefGroup');
    const receiptGroup = document.getElementById(prefix + 'PaymentReceiptGroup');
    if (bankRefGroup) bankRefGroup.style.display = isAnyBankTransfer ? '' : 'none';
    if (receiptGroup) receiptGroup.style.display = isAnyBankTransfer ? '' : 'none';
}

function validateAddExpense() {
    const pureBankTransferRadio = document.getElementById('addRecordBankTransfer');
    const bankTransferExpenseRadio = document.getElementById('addRecordBankTransferExpense');
    const isAnyBankTransfer = (pureBankTransferRadio && pureBankTransferRadio.checked) || (bankTransferExpenseRadio && bankTransferExpenseRadio.checked);
    if (isAnyBankTransfer) {
        const ref = document.getElementById('addBankRef');
        if (!ref.value.trim()) {
            alert('Bank Reference Number is required for a Bank Transfer record.');
            ref.focus();
            return false;
        }
    }
    return true;
}

// Auto-calculate total billable with currency conversion
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

// Update status via AJAX-style redirect
function updateStatus(id, status, month) {
    window.location = `?action=status&id=${id}&s=${status}&month=${month}`;
}

// Init edit modal calculations
document.addEventListener('DOMContentLoaded', () => {
    const ec = document.getElementById('editCost');
    if (ec) calcTotal('edit');
});
