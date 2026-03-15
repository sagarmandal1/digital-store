<?php

declare(strict_types=1);

?>
<?php if (auth_is_logged_in()): ?>
                </div>
            </main>
<?php else: ?>
    </main>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        function setTheme(theme) {
            document.documentElement.setAttribute('data-bs-theme', theme);
            localStorage.setItem('theme', theme);
        }

        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-bs-theme') || 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        }

        const t1 = document.getElementById('themeToggleTop');
        const t2 = document.getElementById('themeToggleSidebar');
        if (t1) t1.addEventListener('click', toggleTheme);
        if (t2) t2.addEventListener('click', toggleTheme);

        const toastEl = document.getElementById('flashToast');
        if (toastEl && window.bootstrap) {
            const toast = new bootstrap.Toast(toastEl, {delay: 3500});
            toast.show();
        }
    })();
</script>
<?php if (auth_is_logged_in()): ?>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
