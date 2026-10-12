/**
 * Admin sidebar — shared collapse + logout confirmation.
 * Loaded once on every admin page so the behaviour is identical everywhere.
 */
(function () {
    "use strict";

    var SIDEBAR_KEY = "kitakits_admin_sidebar_collapsed";
    var LOGOUT_URL = "../logout.php";

    /* ---------------------------------------------------------
       Sidebar collapse
    --------------------------------------------------------- */
    function initSidebar() {
        var menuBtn = document.getElementById("menuBtn");
        var sidebar = document.getElementById("sidebar");
        var content = document.getElementById("content");

        if (!menuBtn || !sidebar) {
            return;
        }

        // Suppress the width transition for the frame in which we restore
        // the saved state, otherwise the sidebar visibly slides open on
        // every page load.
        document.body.classList.add("sidebar-preload");

        // Keep the same state while moving between admin pages.
        try {
            if (window.localStorage.getItem(SIDEBAR_KEY) === "1") {
                sidebar.classList.add("collapsed");
                if (content) {
                    content.classList.add("expanded");
                }
            }
        } catch (e) {
            /* localStorage unavailable — ignore */
        }

        function syncAria() {
            var isCollapsed = sidebar.classList.contains("collapsed");
            menuBtn.setAttribute("aria-expanded", isCollapsed ? "false" : "true");
            menuBtn.setAttribute(
                "aria-label",
                isCollapsed ? "Expand sidebar" : "Collapse sidebar"
            );
        }

        syncAria();

        // Two frames guarantee the restored layout has painted before the
        // transition is allowed back in.
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                document.body.classList.remove("sidebar-preload");
            });
        });

        menuBtn.addEventListener("click", function () {
            sidebar.classList.toggle("collapsed");

            if (content) {
                content.classList.toggle("expanded");
            }

            syncAria();

            try {
                window.localStorage.setItem(
                    SIDEBAR_KEY,
                    sidebar.classList.contains("collapsed") ? "1" : "0"
                );
            } catch (e) {
                /* localStorage unavailable — ignore */
            }
        });
    }

    /* ---------------------------------------------------------
       Logout confirmation
       The sidebar link gets an id so the handler can find it
       without touching any page-specific markup.
    --------------------------------------------------------- */
    function initLogoutConfirm() {
        var link = document.getElementById("adminLogout");
        var modal = document.getElementById("logoutModal");

        if (!link || !modal) {
            return;
        }

        var cancelBtn = document.getElementById("logoutCancel");
        var confirmBtn = document.getElementById("logoutConfirm");
        var lastFocused = null;

        function openModal(event) {
            event.preventDefault();
            lastFocused = document.activeElement;
            modal.classList.add("is-open");
            if (cancelBtn) {
                cancelBtn.focus();
            }
        }

        function closeModal() {
            modal.classList.remove("is-open");
            if (lastFocused) {
                lastFocused.focus();
            }
        }

        function confirmLogout() {
            window.location.href = LOGOUT_URL;
        }

        link.addEventListener("click", openModal);

        if (cancelBtn) {
            cancelBtn.addEventListener("click", closeModal);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener("click", confirmLogout);
        }

        // Clicking the backdrop also cancels.
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Escape cancels, Enter on the focused button still works.
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && modal.classList.contains("is-open")) {
                closeModal();
            }
        });
    }

    function init() {
        initSidebar();
        initLogoutConfirm();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
