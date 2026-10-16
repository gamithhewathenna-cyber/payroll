
let markPaidSelect = null;

function handleStatusChange(sel, id, month) {
    // Client-Paid expenses are settled directly by the client — no bank ref/receipt to collect,
    // so skip the Mark as Paid popup entirely and just update the status like any other change.
    if (sel.value === 'paid' && sel.dataset.billing !== 'client_paid') {
        openMarkPaid(id, month, sel);
    } else {
        updateStatus(id, sel.value, month);
    }
}

function openMarkPaid(id, month, selectEl) {
    markPaidSelect = selectEl || null;
    document.getElementById('markPaidIdInput').value = id;
    document.getElementById('markPaidMonthInput').value = month;
    document.getElementById('markPaidDate').value = new Date().toISOString().slice(0,10);
    document.getElementById('markPaidRef').value = '';
    document.getElementById('markPaidReceipt').value = '';
    document.getElementById('payMethodBank').checked = true;
    toggleBankRefRequired();
    openModal('markPaidModal');
}

function toggleBankRefRequired() {
    const notBank      = document.getElementById('payMethodNotBank').checked;
    const refGroup     = document.getElementById('markPaidRefGroup');
    const receiptGroup = document.getElementById('markPaidReceiptGroup');
    refGroup.style.opacity = notBank ? '.5' : '1';
    document.getElementById('markPaidRefLabel').textContent = notBank ? 'Bank Reference Number (optional)' : 'Bank Reference Number *';
    receiptGroup.style.display = notBank ? 'none' : '';
    if (notBank) document.getElementById('markPaidReceipt').value = '';
}

function validateMarkPaid() {
    const notBank  = document.getElementById('payMethodNotBank').checked;
    const refInput = document.getElementById('markPaidRef');
    if (!notBank && !refInput.value.trim()) {
        alert('Bank Reference Number is required for bank transfer payments.');
        refInput.focus();
        return false;
    }
    return true;
}

function cancelMarkPaid() {
    if (markPaidSelect) markPaidSelect.value = markPaidSelect.dataset.prev;
    closeModal('markPaidModal');
}

document.getElementById('markPaidModal').addEventListener('click', (e) => {
    if (e.target.id === 'markPaidModal') cancelMarkPaid();
});
