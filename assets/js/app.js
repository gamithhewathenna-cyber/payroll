// assets/js/app.js
// Mark active nav item
document.querySelectorAll('.nav-item').forEach(link => {
    if (link.href === window.location.href || window.location.pathname.endsWith(link.getAttribute('href'))) {
        link.classList.add('active');
    }
});

// Close sidebar on outside click (mobile)
document.addEventListener('click', (e) => {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('.menu-toggle');
    if (sidebar && sidebar.classList.contains('open') && !sidebar.contains(e.target) && e.target !== toggle) {
        sidebar.classList.remove('open');
    }
});

// Modal helpers
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Confirm delete
function confirmDelete(msg) {
    return confirm(msg || 'Are you sure you want to delete this?');
}

// Auto-dismiss flash
const flash = document.querySelector('.flash-msg');
if (flash) setTimeout(() => flash.style.opacity = '0', 3500);
