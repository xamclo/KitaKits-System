/**
 * Admin - Rental History table and the "Mark Returned" modal.
 *
 * Reads admin/api/rental-history.php, which derives Returned / Overdue /
 * Ongoing in SQL so the badges can never disagree with the dates.
 */
(function () {
    "use strict";

    var API = "api/rental-history.php";

    var tableBody = document.getElementById("rhTableBody");
    var modal = document.getElementById("rhModal");
    var alertEl = document.getElementById("rhAlert");
    var toast = document.getElementById("rhToast");

    var renterEl = document.getElementById("rhRenter");
    var equipEl = document.getElementById("rhEquipment");
    var datesEl = document.getElementById("rhDates");
    var condSelect = document.getElementById("rhCondition");
    var dateInput = document.getElementById("rhReturnedAt");
    var saveBtn = document.getElementById("rhSave");

    var currentId = null;
    var toastTimer = null;

    /* ---------------------------------------------------------
       Helpers
    --------------------------------------------------------- */
    function esc(value) {
        var div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    function fmtDate(value) {
        if (!value) { return "-"; }
        // Dates arrive as YYYY-MM-DD. Parse the parts directly so the day
        // never shifts across time zones, and render as "Oct 10, 2026".
        var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        var months = ["Jan","Feb","Mar","Apr","May","Jun",
                      "Jul","Aug","Sep","Oct","Nov","Dec"];
        if (m) {
            var mo = months[parseInt(m[2], 10) - 1] || m[2];
            return mo + " " + parseInt(m[3], 10) + ", " + m[1];
        }
        var d = new Date(value);
        if (isNaN(d.getTime())) { return String(value); }
        return d.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    }

    // history_state comes from the API, so these three are exhaustive.
    function stateInfo(state) {
        var s = String(state || "").toLowerCase();
        if (s === "returned") { return { cls: "returned",  label: "Returned" }; }
        if (s === "overdue")  { return { cls: "overdue",   label: "Overdue" }; }
        return { cls: "completed", label: "Ongoing" };
    }

    // Missing condition shows a neutral dash; known conditions keep their
    // compact coloured badge.
    function condInfo(condition) {
        var map = {
            "Excellent": "excellent",
            "Good": "good",
            "Fair": "fair",
            "Damaged": "damaged"
        };
        var key = map[condition];
        return key
            ? { cls: "rh-cond--" + key, label: condition }
            : { cls: "rh-cond--none", label: "-" };
    }

    function showToast(message, isError) {
        if (!toast) { return; }

        toast.classList.toggle("rh-toast--error", !!isError);
        toast.innerHTML =
            '<i class="fa-solid ' + (isError ? "fa-circle-exclamation" : "fa-circle-check") + '"></i>' +
            "<span>" + esc(message) + "</span>";

        void toast.offsetWidth;
        toast.classList.add("is-visible");

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.classList.remove("is-visible");
        }, 3200);
    }

    function setAlert(message) {
        if (!alertEl) { return; }
        if (!message) {
            alertEl.classList.remove("is-visible");
            alertEl.textContent = "";
            return;
        }
        alertEl.textContent = message;
        alertEl.classList.add("is-visible");
    }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.set("action", action);
        Object.keys(fields || {}).forEach(function (k) { body.set(k, fields[k]); });

        return fetch(API, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString(),
            credentials: "same-origin"
        }).then(function (res) { return res.json(); });
    }

    /* ---------------------------------------------------------
       Render
    --------------------------------------------------------- */
    function renderRows(rows) {
        if (!rows.length) {
            tableBody.innerHTML =
                '<tr><td colspan="5">' +
                '<div class="rh-empty">' +
                '<i class="fa-solid fa-clock-rotate-left"></i>' +
                "<h3>No rental history</h3>" +
                "<p>Approved rentals will appear here once they are used.</p>" +
                "</div></td></tr>";
            return;
        }

        var html = "";

        rows.forEach(function (row) {
            var st = stateInfo(row.history_state);
            var cond = condInfo(row.return_condition);
            var renter = ((row.first_name || "") + " " + (row.last_name || "")).trim();
            var isReturned = st.label === "Returned";

            // Rental Period: two intentional lines, each date kept intact.
            var period =
                '<div class="rr-period">' +
                '<span class="rr-period__line"><span class="rr-period__label">Pickup:</span>' + esc(fmtDate(row.start_date)) + "</span>" +
                '<span class="rr-period__line"><span class="rr-period__label">Return:</span>' + esc(fmtDate(row.end_date)) + "</span>" +
                "</div>";

            // Status: the Returned / Overdue / Ongoing badge is shown once,
            // here. The genuine "Mark Returned" action is preserved inside
            // the same cell (below the badge) instead of a separate column.
            var statusCell =
                '<div class="rh-status">' +
                '<span class="status-badge ' + st.cls + '">' + st.label + "</span>" +
                (isReturned
                    ? ""
                    : '<button type="button" class="rh-btn" data-id="' + row.reservation_id + '">Mark Returned</button>') +
                "</div>";

            html +=
                "<tr>" +
                '<td><span class="rr-name">' + esc(renter) + "</span></td>" +
                '<td><span class="rr-name">' + esc(row.equipment_name) + "</span></td>" +
                "<td>" + period + "</td>" +
                '<td><span class="rh-cond ' + cond.cls + '">' + esc(cond.label) + "</span></td>" +
                "<td>" + statusCell + "</td>" +
                "</tr>";
        });

        tableBody.innerHTML = html;
    }

    function loadTable() {
        fetch(API + "?action=list", { credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message || "Could not load rental history.", true);
                    return;
                }
                renderRows(data.history || []);
            })
            .catch(function () {
                showToast("Network error while loading rental history.", true);
            });
    }

    /* ---------------------------------------------------------
       Mark Returned modal
    --------------------------------------------------------- */
    function openModal() {
        if (modal) { modal.classList.add("is-open"); }
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (modal) { modal.classList.remove("is-open"); }
        document.body.style.overflow = "";
        setAlert("");
        currentId = null;
    }

    function todayISO() {
        var d = new Date();
        return d.toISOString().slice(0, 10);
    }

    // Delegated so it works on re-rendered rows.
    document.addEventListener("click", function (event) {
        var btn = event.target.closest(".rh-btn[data-id]");

        if (!btn) { return; }

        var id = btn.getAttribute("data-id");

        post("fetch", { id: id })
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message, true);
                    return;
                }

                var r = data.request;
                currentId = r.reservation_id;

                renterEl.textContent = ((r.first_name || "") + " " + (r.last_name || "")).trim();
                equipEl.textContent = r.equipment_name;
                datesEl.textContent = fmtDate(r.start_date) + "  \u2192  " + fmtDate(r.end_date);

                condSelect.value = r.return_condition || "Good";
                dateInput.value = todayISO();

                setAlert("");
                openModal();
            })
            .catch(function () {
                showToast("Network error while loading the rental.", true);
            });
    });

    // X, Cancel, backdrop and Escape all close the modal.
    document.addEventListener("click", function (event) {
        if (event.target.closest("[data-rh-close]")) {
            closeModal();
            return;
        }
        if (event.target.classList && event.target.classList.contains("rh-modal")) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal && modal.classList.contains("is-open")) {
            closeModal();
        }
    });

    /* ---------------------------------------------------------
       Save the return
    --------------------------------------------------------- */
    if (saveBtn) {
        saveBtn.addEventListener("click", function () {
            if (!currentId) { return; }

            var label = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = "Saving…";

            post("mark_returned", {
                id: currentId,
                return_condition: condSelect.value,
                returned_at: dateInput.value
            })
                .then(function (data) {
                    saveBtn.textContent = label;
                    saveBtn.disabled = false;

                    if (!data.ok) {
                        setAlert(data.message || "Something went wrong.");
                        return;
                    }

                    closeModal();
                    showToast(data.message, false);
                    loadTable();
                })
                .catch(function () {
                    saveBtn.textContent = label;
                    saveBtn.disabled = false;
                    setAlert("Network error. Please try again.");
                });
        });
    }

    loadTable();
})();

