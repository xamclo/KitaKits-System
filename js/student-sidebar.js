/* =========================================
   STUDENT SIDEBAR TOGGLE
   Loaded on every Student page that uses
   includes/sidebar_student.php.

   One handler for the whole Student area replaces the copy that used to be
   pasted into each page, so collapse/expand behaves identically everywhere
   and the main content always follows the sidebar width.

   The collapsed/expanded choice is kept in localStorage (browser-side
   only). No PHP session, cookie or database value is involved, so this
   purely visual preference can never affect rentals, payments or
   notifications.
   ========================================= */

(function () {
    "use strict";

    var KEY = "kitakits_sidebar_collapsed";
    var LOGOUT_URL = "../logout.php";
    var sidebar = document.getElementById("sidebar");
    var content = document.getElementById("content");
    var btn = document.getElementById("menuBtn");

    if (!sidebar || !content) return;

    function isCollapsed() {
        return sidebar.classList.contains("collapsed");
    }

    // Applies the state to the sidebar AND the content in one place, so
    // the two can never get out of sync.
    function apply(collapsed) {
        sidebar.classList.toggle("collapsed", collapsed);
        content.classList.toggle("expanded", collapsed);
    }

    function remember(collapsed) {
        try {
            if (collapsed) {
                localStorage.setItem(KEY, "1");
            } else {
                localStorage.removeItem(KEY);
            }
        } catch (e) {
            /* private mode: the toggle still works, it just will not persist */
        }
    }

    function readStored() {
        try {
            return localStorage.getItem(KEY);
        } catch (e) {
            return null;
        }
    }

    // Restore the previous state before the first paint so the sidebar
    // does not visibly jump when navigating between pages.
    apply(readStored() === "1");

    if (btn) {
        btn.addEventListener("click", function () {
            var next = !isCollapsed();
            apply(next);
            remember(next);
        });
    }

    // On a narrow screen the collapsed width is already the only sensible
    // layout, so the content offset is dropped entirely.
    window.addEventListener("resize", function () {
        if (window.innerWidth <= 700) {
            content.classList.add("expanded");
        }
    });

    /* ---------------------------------------------------------
       Logout confirmation

       The dialog markup lives in includes/sidebar_student.php, so every
       Student page gets it and no page needs its own copy of this logic.
       Behaviour matches js/admin-sidebar.js exactly.
    --------------------------------------------------------- */
    function initLogoutConfirm() {
        var link = document.getElementById("studentLogout");
        var modal = document.getElementById("logoutModal");

        if (!link || !modal) return;

        var cancelBtn = document.getElementById("logoutCancel");
        var confirmBtn = document.getElementById("logoutConfirm");
        var lastFocused = null;

        function openModal() {
            if (modal.classList.contains("is-open")) return;
            lastFocused = document.activeElement;
            modal.classList.add("is-open");
            if (cancelBtn) cancelBtn.focus();
        }

        function closeModal() {
            if (!modal.classList.contains("is-open")) return;
            modal.classList.remove("is-open");
            if (lastFocused) lastFocused.focus();
        }

        function confirmLogout() {
            window.location.href = LOGOUT_URL;
        }

        link.addEventListener("click", function (event) {
            event.preventDefault();
            openModal();
        });

        if (cancelBtn) cancelBtn.addEventListener("click", closeModal);
        if (confirmBtn) confirmBtn.addEventListener("click", confirmLogout);

        // Clicking the backdrop also cancels.
        modal.addEventListener("click", function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Escape cancels.
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && modal.classList.contains("is-open")) {
                closeModal();
            }
        });

        // Still exposed globally so any Student page that calls
        // openLogout() / closeLogout() keeps working.
        window.openLogout = openModal;
        window.closeLogout = closeModal;
    }

    initLogoutConfirm();
})();
