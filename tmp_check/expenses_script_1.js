
function togglePrCustomRange() {
    const isCustom = document.getElementById('prPeriodSelect').value === 'custom';
    document.getElementById('prFromGroup').style.display = isCustom ? '' : 'none';
    document.getElementById('prToGroup').style.display   = isCustom ? '' : 'none';
}
