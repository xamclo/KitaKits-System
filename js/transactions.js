/**
 * Admin - Transactions table, receipt modal and Mark as Paid.
 *
 * Reads admin/api/transactions.php. Payment status is resolved in SQL
 * (is_paid), so the badge and the action button always agree with the
 * stored value.
 */
(function () {
    "use strict";

    var API = "api/transactions.php";

    var tableBody = document.getElementById("txTableBody");
    var modal = document.getElementById("txModal");
    var alertEl = document.getElementById("txAlert");
    var toast = document.getElementById("txToast");

    var refEl = document.getElementById("txRef");
    var equipEl = document.getElementById("txEquipment");
    var renterEl = document.getElementById("txRenter");
    var payEl = document.getElementById("txPayment");
    var amountEl = document.getElementById("txAmount");

    var toastTimer = null;

    /* ---------------------------------------------------------
       Helpers
    --------------------------------------------------------- */
    function esc(value) {
        var div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    // TX-0001 style reference built from the real transaction_id.
    function txRef(id) {
        var n = String(id == null ? "" : id);
        while (n.length < 4) { n = "0" + n; }
        return "TX-" + n;
    }

    function peso(value) {
        var n = Number(value);
        return "\u20B1" + (isNaN(n) ? "0.00" : n.toFixed(2));
    }

    function fmtDate(value) {
        if (!value) { return "-"; }
        var d = new Date(value);
        if (isNaN(d.getTime())) { return String(value); }
        return d.toLocaleDateString("en-CA");
    }

    function showToast(message, isError) {
        if (!toast) { return; }

        toast.classList.toggle("tx-toast--error", !!isError);
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

    function post(action, id) {
        return fetch(API, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "action=" + encodeURIComponent(action) + "&id=" + encodeURIComponent(id),
            credentials: "same-origin"
        }).then(function (res) { return res.json(); });
    }

    /* ---------------------------------------------------------
       Render
    --------------------------------------------------------- */
    function renderRows(rows) {
        if (!rows.length) {
            tableBody.innerHTML =
                '<tr><td colspan="6">' +
                '<div class="tx-empty">' +
                '<i class="fa-solid fa-receipt"></i>' +
                "<h3>No transactions yet</h3>" +
                "<p>Transactions appear here when a rental is approved.</p>" +
                "</div></td></tr>";
            return;
        }

        var html = "";

        rows.forEach(function (row) {
            var isPaid = Number(row.is_paid) === 1;
            var renter = ((row.first_name || "") + " " + (row.last_name || "")).trim();

            var payBadge = isPaid
                ? '<span class="tx-pay tx-pay--paid">Paid</span>'
                : '<span class="tx-pay tx-pay--unpaid">Unpaid</span>';

            var action = isPaid
                ? '<button type="button" class="tx-btn tx-btn--receipt" data-act="receipt" data-id="' + row.transaction_id + '">View Receipt</button>'
                : '<button type="button" class="tx-btn tx-btn--pay" data-act="pay" data-id="' + row.transaction_id + '">Mark as Paid</button>';

            html +=
                "<tr>" +
                '<td><span class="tx-ref">' + esc(txRef(row.transaction_id)) + "</span></td>" +
                "<td>" + esc(renter) + "</td>" +
                "<td>" + esc(row.equipment_name) + "</td>" +
                "<td>" + payBadge + "</td>" +
                "<td>" +
                '<span class="tx-amount">' + peso(row.amount) +
                (row.payment_method ? "<small>" + esc(row.payment_method) + "</small>" : "") +
                "</span></td>" +
                "<td>" + action + "</td>" +
                "</tr>";
        });

        tableBody.innerHTML = html;
    }

    function loadTable() {
        fetch(API + "?action=list", { credentials: "same-origin" })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message || "Could not load transactions.", true);
                    return;
                }
                renderRows(data.transactions || []);
            })
            .catch(function () {
                showToast("Network error while loading transactions.", true);
            });
    }

    /* ---------------------------------------------------------
       Receipt modal
    --------------------------------------------------------- */
    function openModal() {
        if (modal) { modal.classList.add("is-open"); }
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (modal) { modal.classList.remove("is-open"); }
        document.body.style.overflow = "";
        setAlert("");
    }

    // Receipt and Mark as Paid, delegated so re-rendered rows still work.
    document.addEventListener("click", function (event) {
        var btn = event.target.closest(".tx-btn[data-act]");
        if (btn) {
            var id = btn.getAttribute("data-id");
            var act = btn.getAttribute("data-act");

            if (act === "receipt") {
                post("fetch", id)
                    .then(function (data) {
                        if (!data.ok) {
                            showToast(data.message, true);
                            return;
                        }

                        var t = data.transaction;
                        refEl.textContent = txRef(t.transaction_id);
                        equipEl.textContent = t.equipment_name;
                        renterEl.textContent = ((t.first_name || "") + " " + (t.last_name || "")).trim();
                        payEl.textContent = Number(t.is_paid) === 1 ? "Paid" : "Unpaid";
                        amountEl.textContent = peso(t.amount);

                        setAlert("");
                        openModal();
                    })
                    .catch(function () {
                        showToast("Network error while loading the receipt.", true);
                    });
                return;
            }

            if (act === "pay") {
                var label = btn.textContent;
                btn.disabled = true;
                btn.textContent = "Saving\u2026";

                post("mark_paid", id)
                    .then(function (data) {
                        btn.textContent = label;
                        btn.disabled = false;

                        if (!data.ok) {
                            showToast(data.message, true);
                            return;
                        }

                        showToast(data.message, false);
                        loadTable();
                    })
                    .catch(function () {
                        btn.textContent = label;
                        btn.disabled = false;
                        showToast("Network error. Please try again.", true);
                    });
            }
            return;
        }

        if (event.target.closest("[data-tx-close]")) {
            closeModal();
            return;
        }

        if (event.target.classList && event.target.classList.contains("tx-modal")) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal && modal.classList.contains("is-open")) {
            closeModal();
        }
    });

    loadTable();
})();

