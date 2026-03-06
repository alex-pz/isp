</div><!-- /page-content -->
</div><!-- /main-content -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ---- Sidebar Toggle ----
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const toggleBtn = document.getElementById('sidebar-toggle');

if (toggleBtn) toggleBtn.addEventListener('click', () => {
    if (window.innerWidth < 992) {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    } else {
        const mc = document.getElementById('main-content');
        if (sidebar.style.transform === 'translateX(-100%)') {
            sidebar.style.transform = '';
            mc.style.marginLeft = 'var(--sidebar-width)';
        } else {
            sidebar.style.transform = 'translateX(-100%)';
            mc.style.marginLeft = '0';
        }
    }
});

if (overlay) overlay.addEventListener('click', () => {
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
});

// ---- Global Search (quick redirect) ----
const gs = document.getElementById('globalSearch');
if (gs) {
    gs.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && this.value.trim()) {
            window.location.href = '<?= SITE_URL ?>/modules/defaulter/list.php?q=' + encodeURIComponent(this.value.trim());
        }
    });
}

// ---- Auto-dismiss alerts after 5s ----
document.querySelectorAll('.alert.flash-message').forEach(alert => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
        bsAlert.close();
    }, 5000);
});

// ---- Confirm delete ----
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function(e) {
        if (!confirm(this.dataset.confirm || 'আপনি কি নিশ্চিত?')) {
            e.preventDefault();
        }
    });
});
</script>

<?= $extraScripts ?? '' ?>
</body>
</html>