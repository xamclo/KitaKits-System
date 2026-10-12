/* =========================================================
   SCHOOL MANAGEMENT - sidebar collapse, logout, modal helpers.
   Loaded on every /school page. Does not touch the Student or
   Admin sidebars or their scripts.
   ========================================================= */
(function () {
    "use strict";

    var KEY = "kitakits_school_sidebar_collapsed";
    var LOGOUT_URL = "../logout.php";

    /* ---------- sidebar collapse ---------- */
    var sidebar = document.getElementById("sidebar");
    var btn = document.getElementById("menuBtn");

    if (sidebar) {
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (e) {}

        // Restore before first paint so navigation does not animate open.
        if (stored === "1") {
            sidebar.classList.add("collapsed");
            document.body.classList.add("sm-sidebar-collapsed");
        }

        if (btn) {
            btn.addEventListener("click", function () {
                var next = !sidebar.classList.contains("collapsed");
                sidebar.classList.toggle("collapsed", next);
                document.body.classList.toggle("sm-sidebar-collapsed", next);
                try {
                    if (next) { localStorage.setItem(KEY, "1"); }
                    else { localStorage.removeItem(KEY); }
                } catch (e) {}
            });
        }
    }

    /* ---------- logout confirmation ---------- */
    (function () {
        var link = document.getElementById("schoolLogout");
        var modal = document.getElementById("logoutModal");
        if (!link || !modal) return;

        var cancelBtn = document.getElementById("logoutCancel");
        var confirmBtn = document.getElementById("logoutConfirm");
        var lastFocused = null;

        function open(e) {
            if (e) e.preventDefault();
            lastFocused = document.activeElement;
            modal.classList.add("is-open");
            if (cancelBtn) cancelBtn.focus();
        }
        function close() {
            modal.classList.remove("is-open");
            if (lastFocused) lastFocused.focus();
        }

        link.addEventListener("click", function (e) { e.preventDefault(); open(e); });
        if (cancelBtn) cancelBtn.addEventListener("click", close);
        if (confirmBtn) confirmBtn.addEventListener("click", function () { window.location.href = LOGOUT_URL; });
        modal.addEventListener("click", function (e) { if (e.target === modal) close(); });
        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && modal.classList.contains("is-open")) close();
        });
    })();

    /* ---------- modal helpers (used by every module) ---------- */
    window.smModal = {
        open: function (id) {
            var m = document.getElementById(id);
            if (m) m.classList.add("open");
        },
        close: function (id) {
            var m = document.getElementById(id);
            if (m) m.classList.remove("open");
        },
        closeAll: function () {
            document.querySelectorAll(".sm-modal.open").forEach(function (m) { m.classList.remove("open"); });
        }
    };

    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") window.smModal.closeAll();
    });

    // Clicking the backdrop closes any open modal.
    document.querySelectorAll(".sm-modal").forEach(function (m) {
        m.addEventListener("click", function (e) { if (e.target === m) m.classList.remove("open"); });
    });

    window.smToast = function (msg, kind) {
        var t = document.getElementById("smToast");
        if (!t) {
            t = document.createElement("div");
            t.id = "smToast";
            t.className = "sm-toast";
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.className = "sm-toast " + (kind === "err" ? "err" : "ok");
        requestAnimationFrame(function () { t.classList.add("show"); });
        clearTimeout(t._tm);
        t._tm = setTimeout(function () { t.classList.remove("show"); }, 3600);
    };

    /* ---------- api helper ---------- */
    window.smPost = function (url, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString(),
            credentials: "same-origin"
        }).then(function (r) { return r.json(); });
    };

    window.smReload = function () { window.location.reload(); };

    /* ---------- helpers used by several tables ---------- */
    window.smEsc = function (s) {
        return String(s === null || s === undefined ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    };

    window.smMoney = function (n) {
        var v = parseFloat(n);
        return isNaN(v) ? "0.00" : v.toFixed(2);
    };

    window.smDate = function (s) {
        if (!s) return "-";
        var d = new Date(String(s).replace(" ", "T"));
        if (isNaN(d.getTime())) return String(s);
        return d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    };

    window.smDateTime = function (s) {
        if (!s) return "-";
        var d = new Date(String(s).replace(" ", "T"));
        if (isNaN(d.getTime())) return String(s);
        return d.toLocaleString("en-US", {
            year: "numeric", month: "short", day: "numeric",
            hour: "numeric", minute: "2-digit"
        });
    };
})();