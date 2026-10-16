
let rejectId = null;
function openReject(id) { rejectId = id; openModal('rejectModal'); }
function confirmReject() {
    const reason = encodeURIComponent(document.getElementById('rejectReason').value || 'Rejected by admin.');
    window.location = `?action=reject_submission&id=${rejectId}&reason=${reason}`;
}
