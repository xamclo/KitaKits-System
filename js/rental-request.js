/**
 * Admin - Rental Request table and Review modal.
 *
 * Talks to admin/api/rental-request.php, which reuses the same approve /
 * decline rules as the existing ?approve= and ?decline= links. Those links
 * still work; this file only adds the modal so no page reload is needed.
 */
(function () {
    "use strict";

    var API = "api/rental-request.php";

    var tableBody = document.getElementById("rrTableBody");
    var modal = document.getElementById("rrModal");
    var alertEl = document.getElementById("rrAlert");
    var toast = document.getElementById("rrToast");

    var nameEl = document.getElementById("rrRenter");
    var equipEl = document.getElementById("rrEquipment");
    var pickupEl = document.getElementById("rrPickup");
    var returnEl = document.getElementById("rrReturn");
    var paymentEl = document.getElementById("rrPayment");

    var rejectBtn = document.getElementById("rrReject");
    var approveBtn = document.getElementById("rrApprove");

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
        // never shifts across time zones, and render as "Oct 10, 2026" on
        // a single line.
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

    // 'declined' and 'rejected' both render as the same red "Rejected".
    function statusInfo(status) {
        var s = String(status || "").toLowerCase();
        if (s === "approved") { return { cls: "approved", label: "Approved" }; }
        if (s === "pending")  { return { cls: "pending",  label: "Pending" }; }
        if (s === "declined" || s === "rejected") { return { cls: "declined", label: "Rejected" }; }
        return { cls: "completed", label: status || "-" };
    }

    function paymentInfo(status) {
        return String(status || "").toLowerCase() === "paid"
            ? { cls: "rr-pay--paid", label: "Paid" }
            : { cls: "rr-pay--unpaid", label: "Unpaid" };
    }

    function showToast(message, isError) {
        if (!toast) { return; }

        toast.classList.toggle("rr-toast--error", !!isError);
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

    function getList() {
        return fetch(API + "?action=list", { credentials: "same-origin" })
            .then(function (res) { return res.json(); });
    }

    /* ---------------------------------------------------------
       Render
    --------------------------------------------------------- */
    function renderRows(rows) {
        if (!rows.length) {
            tableBody.innerHTML =
                '<tr><td colspan="6">' +
                '<div class="rr-empty">' +
                '<i class="fa-solid fa-clipboard-list"></i>' +
                "<h3>No rental requests</h3>" +
                "<p>New student requests will appear here.</p>" +
                "</div></td></tr>";
            return;
        }

        var html = "";

    /* School Witness indicator - a small colored dot + readable text, in
       its own column. Only genuinely high-value equipment shows a state;
       ordinary equipment shows a neutral dash. The state comes from
       school_status:
         approved -> Witnessed (green dot)
         pending  -> Pending Witness (amber dot)
         none     -> Not Requested (gray dot)                */
    function smSchoolStatus(row) {
        if (parseInt(row.requires_school_approval || 0, 10) !== 1) {
            return '<span class="rr-witness rr-witness--none"><span class="rr-witness__dot"></span>&mdash;</span>';
        }
        var s = String(row.school_status || "").toLowerCase();
        if (s === "approved") return '<span class="rr-witness rr-witness--witnessed"><span class="rr-witness__dot"></span>Witnessed</span>';
        if (s === "pending")  return '<span class="rr-witness rr-witness--pending"><span class="rr-witness__dot"></span>Pending Witness</span>';
        return '<span class="rr-witness rr-witness--none"><span class="rr-witness__dot"></span>Not Requested</span>';
    }

    /* True when the Submit for School Witness action is available: high-value
       equipment with no witness request yet. Witnessed / pending states show
       status only, so no action. */
    function smSchoolActionable(row) {
        if (parseInt(row.requires_school_approval || 0, 10) !== 1) return false;
        var s = String(row.school_status || "").toLowerCase();
        return s !== "approved" && s !== "pending";
    }

        rows.forEach(function (row) {
            var st = statusInfo(row.status);
            var pay = paymentInfo(row.payment_status);
            var renter = ((row.first_name || "") + " " + (row.last_name || "")).trim();

            // Rental Period: two intentional lines, each date kept intact.
            var period =
                '<div class="rr-period">' +
                '<span class="rr-period__line"><span class="rr-period__label">Pickup:</span>' + esc(fmtDate(row.start_date)) + "</span>" +
                '<span class="rr-period__line"><span class="rr-period__label">Return:</span>' + esc(fmtDate(row.end_date)) + "</span>" +
                "</div>";

            // Status: one compact badge + a quiet payment line underneath.
            var statusCell =
                '<div class="rr-status">' +
                '<span class="status-badge ' + st.cls + '">' + st.label + "</span>" +
                '<span class="rr-status__pay">Payment: ' + esc(pay.label) + "</span>" +
                "</div>";

            // Actions: Review is the primary button. When a secondary action
            // (Submit for School Witness) is available it moves into a compact
            // More menu so the row never overflows.
            var moreBtn = smSchoolActionable(row)
                ? '<button type="button" class="rr-more" data-more="' + row.reservation_id +
                  '" aria-haspopup="true" aria-label="More actions">&#8943;</button>'
                : "";
            var actionsHtml =
                '<div class="rr-actions">' +
                '<button type="button" class="rr-review" data-id="' + row.reservation_id + '">Review</button>' +
                moreBtn +
                "</div>";

            html +=
                "<tr>" +
                '<td><span class="rr-name">' + esc(renter) + "</span></td>" +
                '<td><span class="rr-name">' + esc(row.equipment_name) + "</span></td>" +
                "<td>" + period + "</td>" +
                "<td>" + statusCell + "</td>" +
                "<td>" + smSchoolStatus(row) + "</td>" +
                "<td>" + actionsHtml + "</td>" +
                "</tr>";
        });

        tableBody.innerHTML = html;
    }

    function loadTable() {
        getList()
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message || "Could not load requests.", true);
                    return;
                }
                renderRows(data.requests || []);
            })
            .catch(function () {
                showToast("Network error while loading requests.", true);
            });
    }

    /* ---------------------------------------------------------
       Review modal
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

    /* ---------------------------------------------------------
       More (⋯) menu for secondary actions (Submit for School Witness).
       The menu is position:fixed so the table container's overflow can
       never clip it. The menu item keeps its data-school attribute, so
       the existing [data-school] handler below performs the request.
    --------------------------------------------------------- */
    var moreMenu = null;

    function closeMoreMenu() {
        if (moreMenu) {
            moreMenu.remove();
            moreMenu = null;
        }
    }

    function toggleMoreMenu(anchorBtn) {
        var id = anchorBtn.getAttribute("data-more");

        // Clicking the same anchor again just closes the open menu.
        if (moreMenu && moreMenu.getAttribute("data-for") === id) {
            closeMoreMenu();
            return;
        }
        closeMoreMenu();

        moreMenu = document.createElement("div");
        moreMenu.className = "rr-menu is-open";
        moreMenu.setAttribute("data-for", id);
        moreMenu.setAttribute("role", "menu");

        var item = document.createElement("button");
        item.type = "button";
        item.className = "rr-menu__item";
        item.setAttribute("data-school", id);
        item.textContent = "Submit for School Witness";
        moreMenu.appendChild(item);

        document.body.appendChild(moreMenu);

        // Right-align to the button and keep it inside the viewport.
        var rect = anchorBtn.getBoundingClientRect();
        var menuW = moreMenu.offsetWidth;
        var left = rect.right - menuW;
        if (left < 8) { left = 8; }
        moreMenu.style.left = left + "px";
        moreMenu.style.top = (rect.bottom + 6) + "px";
    }

    // Open + fill the modal from the database record.
    document.addEventListener("click", function (event) {
        // Clicking outside the menu (and outside the ⋯ button) closes it.
        if (!event.target.closest(".rr-menu") && !event.target.closest(".rr-more")) {
            closeMoreMenu();
        }

        // More (⋯) button: open/toggle the secondary-action menu.
        var moreBtn = event.target.closest(".rr-more");
        if (moreBtn) {
            toggleMoreMenu(moreBtn);
            return;
        }

        var schoolBtn = event.target.closest("[data-school]");

        if (schoolBtn) {
            closeMoreMenu();
            var sid = schoolBtn.getAttribute("data-school");
            schoolBtn.disabled = true;
            fetch("api/school-approval.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                credentials: "same-origin",
                body: "action=request_approval&id=" + encodeURIComponent(sid)
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) { setAlert(res.message); }
                    else { setAlert((res && res.message) || "Could not request school approval."); }
                    loadTable();
                })
                .catch(function () { setAlert("Could not reach the server."); })
                .finally(function () { schoolBtn.disabled = false; });
            return;
        }

        var btn = event.target.closest(".rr-review");

        if (!btn) { return; }

        var id = btn.getAttribute("data-id");

        post("fetch", id)
            .then(function (data) {
                if (!data.ok) {
                    showToast(data.message, true);
                    return;
                }

                var r = data.request;
                currentId = r.reservation_id;

                nameEl.textContent = ((r.first_name || "") + " " + (r.last_name || "")).trim();
                equipEl.textContent = r.equipment_name;
                pickupEl.textContent = fmtDate(r.start_date);
                returnEl.textContent = fmtDate(r.end_date);
                paymentEl.textContent = paymentInfo(r.payment_status).label;

                setAlert("");
                openModal();
            })
            .catch(function () {
                showToast("Network error while loading the request.", true);
            });
    });

    // X, Cancel, backdrop and Escape all close the modal.
    document.addEventListener("click", function (event) {
        if (event.target.closest("[data-rr-close]")) {
            closeModal();
            return;
        }
        if (event.target.classList && event.target.classList.contains("rr-modal")) {
            closeModal();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeMoreMenu();
            if (modal && modal.classList.contains("is-open")) {
                closeModal();
            }
        }
    });

    // Reposition/close the menu if the window scrolls or resizes while open.
    window.addEventListener("scroll", function () {
        if (moreMenu) { closeMoreMenu(); }
    }, true);
    window.addEventListener("resize", function () {
        if (moreMenu) { closeMoreMenu(); }
    });

    /* ---------------------------------------------------------
       Approve / Reject
    --------------------------------------------------------- */
    function decide(action, button) {
        if (!currentId) { return; }

        var other = action === "approve" ? rejectBtn : approveBtn;
        var label = button.textContent;

        button.disabled = true;
        other.disabled = true;
        button.textContent = "Saving…";

        post(action, currentId)
            .then(function (data) {
                button.textContent = label;

                if (!data.ok) {
                    button.disabled = false;
                    other.disabled = false;
                    setAlert(data.message || "Something went wrong.");
                    return;
                }

                closeModal();
                showToast(data.message, false);
                loadTable();
            })
            .catch(function () {
                button.textContent = label;
                button.disabled = false;
                other.disabled = false;
                setAlert("Network error. Please try again.");
            });
    }

    if (approveBtn) {
        approveBtn.addEventListener("click", function () { decide("approve", approveBtn); });
    }

    if (rejectBtn) {
        rejectBtn.addEventListener("click", function () { decide("reject", rejectBtn); });
    }

    loadTable();
})();

