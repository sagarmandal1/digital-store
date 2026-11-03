(function () {
    // ── Modal-backdrop cleanup ────────────────────────────────────────────────
    // Bootstrap appends .modal-backdrop to <body> when a modal opens.
    // If a form inside a modal submits (POST → redirect), the browser navigates
    // BEFORE Bootstrap finishes hiding the modal, leaving the backdrop stuck.
    //
    // Extra case: bfcache — browser restores the full DOM snapshot including the
    // backdrop; DOMContentLoaded does NOT fire, so we must use `pageshow` with
    // event.persisted to catch that case.
    function clearModalArtifacts() {
        document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        // Dismiss any open Bootstrap modal so Bootstrap's state machine is clean
        if (window.bootstrap && window.bootstrap.Modal) {
            document.querySelectorAll('.modal.show').forEach(function (el) {
                try {
                    var m = window.bootstrap.Modal.getInstance(el);
                    if (m) { m.dispose(); }
                } catch (e) { /* ignore */ }
                el.classList.remove('show');
                el.style.removeProperty('display');
                el.setAttribute('aria-hidden', 'true');
                el.removeAttribute('aria-modal');
                el.removeAttribute('role');
            });
        }
    }

    function removeBackdropsOnly() {
        document.querySelectorAll('.modal-backdrop').forEach(function (el) { el.remove(); });
    }

    function enforceBackdroplessModals() {
        if (window.bootstrap && window.bootstrap.Modal && window.bootstrap.Modal.Default) {
            window.bootstrap.Modal.Default.backdrop = false;
        }
    }

    function hasOpenModal() {
        return !!document.querySelector('.modal.show');
    }

    function clearOrphanBackdrop() {
        if (!hasOpenModal()) {
            clearModalArtifacts();
        }
    }

    // Runs immediately for normal fresh loads
    enforceBackdroplessModals();
    clearModalArtifacts();

    // `pageshow` fires on EVERY page display: normal load AND bfcache restore.
    // This is the only reliable hook for bfcache (event.persisted === true).
    window.addEventListener('pageshow', function () {
        enforceBackdroplessModals();
        clearModalArtifacts();
    });

    // Before navigating away: clean up so the bfcache snapshot is backdrop-free
    window.addEventListener('pagehide', clearModalArtifacts);

    // Hard guard: even if Bootstrap inserts a backdrop, remove it immediately.
    window.addEventListener('show.bs.modal', function () {
        setTimeout(removeBackdropsOnly, 0);
    }, true);

    window.addEventListener('shown.bs.modal', removeBackdropsOnly, true);

    // Safety net: if backdrop exists without any open modal, remove it.
    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            clearOrphanBackdrop();
        }
    });

    if (window.MutationObserver && document.body) {
        var backdropObserver = new MutationObserver(function () {
            if (document.querySelector('.modal-backdrop') && !hasOpenModal()) {
                clearModalArtifacts();
            }
        });
        backdropObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }

    // Global intercept (capture phase): ANY form inside a .modal that does a
    // standard submit gets backdrop stripped BEFORE the redirect happens.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.closest) { return; }
        var insideModal = form.closest('.modal');
        if (!insideModal) { return; }
        if (window.bootstrap && window.bootstrap.Modal) {
            try {
                var m = window.bootstrap.Modal.getInstance(insideModal);
                if (!m) { m = new window.bootstrap.Modal(insideModal); }
                insideModal.classList.remove('fade'); // disable animation for instant hide
                m.hide();
            } catch (e) { /* ignore */ }
        }
        clearModalArtifacts();
    }, true /* capture phase */);

    function setTheme(theme) {
        document.documentElement.setAttribute("data-bs-theme", theme);
        localStorage.setItem("theme", theme);
        updateThemeIcons(theme);
    }

    function updateThemeIcons(theme) {
        var iconClass = theme === "dark" ? "bi bi-sun" : "bi bi-moon-stars";
        ["themeToggleTop", "themeToggleSidebar"].forEach(function (id) {
            var btn = document.getElementById(id);
            if (!btn) {
                return;
            }
            var icon = btn.querySelector("i");
            if (icon) {
                icon.className = iconClass;
            }
        });
    }

    function toggleTheme() {
        var current = document.documentElement.getAttribute("data-bs-theme") || "light";
        setTheme(current === "dark" ? "light" : "dark");
    }

    function bindThemeButtons() {
        ["themeToggleTop", "themeToggleSidebar"].forEach(function (id) {
            var btn = document.getElementById(id);
            if (btn) {
                btn.addEventListener("click", toggleTheme);
            }
        });
        updateThemeIcons(document.documentElement.getAttribute("data-bs-theme") || "light");
    }

    function showFlashToast() {
        var toastEl = document.getElementById("flashToast");
        if (!toastEl || !window.bootstrap) {
            return;
        }
        var toast = new bootstrap.Toast(toastEl, { delay: 3400 });
        toast.show();
    }

    function animateReveal() {
        var nodes = document.querySelectorAll(".app-main-content .card, .app-main-content .alert, .app-main-content .table-responsive, .app-auth-shell .card");
        nodes.forEach(function (node, index) {
            node.classList.add("reveal-item");
            node.style.animationDelay = (Math.min(index, 8) * 60) + "ms";
        });
    }

    function bindSlashSearchShortcut() {
        document.addEventListener("keydown", function (event) {
            if (event.key !== "/" || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }
            var tag = (event.target && event.target.tagName) ? event.target.tagName.toLowerCase() : "";
            if (tag === "input" || tag === "textarea") {
                return;
            }
            var input = document.querySelector('input[type="search"], input[name="q"], input[name="search"]');
            if (input) {
                event.preventDefault();
                input.focus();
            }
        });
    }

    bindThemeButtons();
    showFlashToast();
    animateReveal();
    bindSlashSearchShortcut();
})();
