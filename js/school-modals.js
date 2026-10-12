/* =========================================================
   SCHOOL MANAGEMENT - modal renderers.
   Fills the three shared dialogs from the API. Every value comes
   from the database response; nothing is invented here.
   ========================================================= */
(function () {
    "use strict";

    var API = "api/index.php";
    var currentApprovalId = 0;
    var currentReturnId = 0;

    /* ---------- small builders ---------- */
    function kv(pairs) {
        return pairs.map(function (p) {
            return "<dt>" + smEsc(p[0]) + "</dt><dd>" + (p[2] ? p[1] : smEsc(p[1])) + "</dd>";
        }).join("");
    }

    function hero(t) {
        var img = (t.equipment && t.equipment.image)
            ? "../uploads/" + encodeURIComponent(String(t.equipment.image).split("/").pop())
            : "";
        var box = img
            ? '<img src="' + smEsc(img) + '" alt="' + smEsc(t.equipment.name) + '">'
            : '<span class="sm-thumb-lg"><i class="fa-solid fa-box"></i></span>';
        return box +
            '<div><div class="sm-eq-name" style="font-size:15px;">' + smEsc(t.equipment.name) + "</div>" +
            '<div class="sm-sub">' + smEsc(t.equipment.category || "-") + "</div></div>";
    }

    function checklist(items, emptyText) {
        if (!items || items.length === 0) {
            return '<div class="sm-sub">' + smEsc(emptyText) + "</div>";
        }
        return '<ul class="sm-checklist">' + items.map(function (i) {
            return "<li><i class=\"fa-solid fa-circle-check\"></i>" + smEsc(i) + "</li>";
        }).join("") + "</ul>";
    }

    /* ---------- MODULE 1: Transaction Witnessing ---------- */
    function renderApproval(tx) {
        currentApprovalId = tx.reservation_id;

        document.getElementById("smApprTxn").innerHTML = kv([
            ["Type", "Equipment Borrow"],
            ["Date Requested", smDateTime(tx.dates.requested)],
            ["Status", '<span class="sm-badge-pill pending">' + smEsc(tx.school.state) + "</span>", true]
        ]);

        document.getElementById("smApprEquip").innerHTML = hero(tx);
        document.getElementById("smApprEquipKv").innerHTML = kv([
            ["Asset ID", tx.equipment.asset_code || "Not recorded"],
            ["High-Value Equipment", tx.equipment.high_value ? "Yes - School witnessing required" : "No", true],
            ["Declared Value", tx.equipment.declared_value !== null && tx.equipment.declared_value !== ""
                ? "PHP " + smMoney(tx.equipment.declared_value) : "Not recorded"]
        ]);

        document.getElementById("smApprAccessories").innerHTML =
            checklist(tx.equipment.accessories, "No accessories recorded for this item.");

        var st = tx.student;
        document.getElementById("smApprStudent").innerHTML =
            '<span class="sm-ava stu">' + smEsc((st.first || "?").charAt(0).toUpperCase()) + "</span>" +
            '<div><div class="sm-eq-name">' + smEsc(st.name) + "</div>" +
            '<div class="sm-sub">' + smEsc(st.number || "-") +
            (st.program ? " &middot; " + smEsc(st.program) : "") + "</div></div>";

        document.getElementById("smApprDetail").innerHTML = kv([
            ["Rental duration", (tx.dates.duration_days || 0) + " day(s)"],
            ["Pickup date", smDate(tx.dates.start)],
            ["Return date", smDate(tx.dates.end)],
            ["Rental purpose", tx.purpose || "Not provided"],
            ["Equipment owner", tx.owner.name],
            ["Owner approval", tx.school.state === "Pending Witness" ? "Owner has approved this rental" : tx.school.state]
        ]);

        document.getElementById("smApprNotes").value = "";
    }

    function decide(decision) {
        var notes = document.getElementById("smApprNotes").value.trim();
        if (decision === "rejected" && notes === "") {
            smToast("Please provide a flag reason.", "err");
            return;
        }
        smPost(API, { action: "approval_decide", id: currentApprovalId, decision: decision, notes: notes })
            .then(function (res) {
                smToast(res.message, res.ok ? "ok" : "err");
                if (res.ok) {
                    smModal.close("smApprovalModal");
                    setTimeout(smReload, 700);
                }
            });
    }

    document.addEventListener("DOMContentLoaded", function () {
        var ab = document.getElementById("smApprApproveBtn");
        var rb = document.getElementById("smApprRejectBtn");
        if (ab) ab.addEventListener("click", function () { decide("approved"); });
        if (rb) rb.addEventListener("click", function () { decide("rejected"); });
    });

    /* ---------- MODULE 3: Return Verification ---------- */
    function renderReturn(tx, evidence) {
        currentReturnId = tx.reservation_id;

        document.getElementById("smRetTxn").innerHTML = kv([
            ["Type", "Equipment Return"],
            ["Date Returned", smDateTime(tx.dates.returned || tx.dates.end)],
            ["Status", '<span class="sm-badge-pill ' + smEsc(tx.verification.css) + '">' + smEsc(tx.verification.state) + "</span>", true]
        ]);

        document.getElementById("smRetEquip").innerHTML = hero(tx);
        document.getElementById("smRetEquipKv").innerHTML = kv([
            ["Asset ID", tx.equipment.asset_code || "Not recorded"],
            ["Reported Condition", tx.fulfillment.return_condition || "Not recorded"]
        ]);

        // Only real recorded evidence is listed.
        var conds = [];
        if (tx.fulfillment.return_condition) conds.push("Reported condition: " + tx.fulfillment.return_condition);
        if (tx.equipment.condition) conds.push("Listed condition: " + tx.equipment.condition);
        document.getElementById("smRetCondition").innerHTML = checklist(conds, "No return condition has been recorded yet.");

        var ev = document.getElementById("smRetEvidence");
        if (evidence && evidence.length) {
            ev.innerHTML = '<div class="sm-evidence">' + evidence.map(function (e) {
                return "<div><img src=\"../uploads/" + smEsc(encodeURIComponent(e.file_name)) + "\" alt=\"" + smEsc(e.caption || e.file_name) + "\">" +
                       '<div class="cap">' + smEsc(e.caption || "") + "</div></div>";
            }).join("") + "</div>";
        } else {
            ev.innerHTML = '<div class="sm-sub">No photos were uploaded for this return.</div>';
        }

        document.getElementById("smRetOwnerNotes").textContent =
            tx.verification.condition_report || "No inspection notes recorded by the equipment owner.";

        document.getElementById("smRetNotes").value = "";
    }

    function returnAction(what) {
        var notes = document.getElementById("smRetNotes").value.trim();
        if (what === "issue" && notes === "") {
            smToast("Please describe the issue before reporting it.", "err");
            return;
        }
        smPost(API, { action: what, id: currentReturnId, notes: notes }).then(function (res) {
            smToast(res.message, res.ok ? "ok" : "err");
            if (res.ok) {
                smModal.close("smReturnModal");
                setTimeout(smReload, 700);
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        var cb = document.getElementById("smRetConfirmBtn");
        var ib = document.getElementById("smRetIssueBtn");
        if (cb) cb.addEventListener("click", function () { returnAction("returns_verify"); });
        if (ib) ib.addEventListener("click", function () { returnAction("returns_issue"); });
    });

    /* ---------- MODULE 5: Transaction History ---------- */
    function renderHistory(tx, timeline) {
        document.getElementById("smHistEquip").innerHTML = hero(tx);

        document.getElementById("smHistDetail").innerHTML = kv([
            ["Asset ID", tx.equipment.asset_code || "Not recorded"],
            ["Status", '<span class="sm-badge-pill ' + smEsc(tx.fulfillment.css) + '">' + smEsc(tx.fulfillment.state) + "</span>", true],
            ["School Witness", '<span class="sm-badge-pill ' + smEsc(tx.school.css) + '">' + smEsc(tx.school.state) + "</span>", true],
            ["Return Verification", '<span class="sm-badge-pill ' + smEsc(tx.verification.css) + '">' + smEsc(tx.verification.state) + "</span>", true],
            ["Student borrower", tx.student.name],
            ["Equipment owner", tx.owner.name],
            ["Rental duration", (tx.dates.duration_days || 0) + " day(s)"]
        ]);

        // Only stages that actually exist as database events are shown.
        var map = {
            reservation_created:   "Request Created",
            owner_approved:        "Approved by Equipment Owner",
            school_approval_requested: "Submitted for School Witness",
            school_approved:       "Witnessed by School Management",
            school_rejected:       "Transaction Flagged by School Management",
            equipment_released:    "Equipment Released",
            return_submitted:      "Equipment Returned",
            owner_inspection:      "Owner Inspection Recorded",
            return_verified:       "Return Verified by School Management",
            return_issue_flagged:  "Issue Flagged at Verification",
            report_note:           "Investigation Note"
        };

        var host = document.getElementById("smHistTimeline");
        if (!timeline || timeline.length === 0) {
            host.innerHTML = '<li class=""><div class="sm-tl-title">No recorded events yet</div>' +
                             '<div class="sm-tl-meta">Stages appear only when a real event exists.</div></li>';
            return;
        }

        host.innerHTML = timeline.map(function (e) {
            var cls = "done";
            if (String(e.action_type).indexOf("school_") === 0) cls = "review";
            if (String(e.action_type).indexOf("rejected") !== -1 ||
                String(e.action_type).indexOf("issue") !== -1) cls = "rejected";
            var who = (e.first_name ? (e.first_name + " " + e.last_name) : "System");
            if (e.role === "school") who += " (School Management)";
            return "<li class=\"" + cls + "\">" +
                   '<div class="sm-tl-title">' + smEsc(map[e.action_type] || e.action_type) + "</div>" +
                   '<div class="sm-tl-meta">' + smEsc(smDateTime(e.created_at)) + " &middot; " + smEsc(who) +
                   (e.notes ? " &middot; " + smEsc(e.notes) : "") + "</div></li>";
        }).join("");
    }

    /* ---------- shared fetchers used by every page ---------- */
    window.smFetchHistory = function (id, cb) {
        smPost(API, { action: "history_fetch", id: id }).then(cb);
    };
    window.smRenderHistory = renderHistory;

    window.smOpenApproval = function (id) {
        smPost(API, { action: "approval_fetch", id: id }).then(function (res) {
            if (!res.ok) { smToast(res.message, "err"); return; }
            renderApproval(res.tx);
            smModal.open("smApprovalModal");
        });
    };

    window.smOpenReturn = function (id) {
        smPost(API, { action: "returns_fetch", id: id }).then(function (res) {
            if (!res.ok) { smToast(res.message, "err"); return; }
            renderReturn(res.tx, res.evidence);
            smModal.open("smReturnModal");
        });
    };

    window.smOpenActive = function (id) {
        smPost(API, { action: "active_fetch", id: id }).then(function (res) {
            if (!res.ok) { smToast(res.message, "err"); return; }
            renderHistory(res.tx, res.timeline);
            smModal.open("smHistoryModal");
        });
    };
})();
