<?php
/**
 * School Management API.
 *
 * One guarded endpoint for every module, dispatching on the action name.
 * Every action:
 *   - requires a School Management session (server-side role, not client state)
 *   - uses prepared statements
 *   - re-reads current state before writing (no blind updates)
 *   - is safe to run twice (no duplicate requests, transactions or restocks)
 *   - appends an audit event for anything it changes
 *
 * No Student or Admin file is required or modified here.
 */

require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/school_auth.php";
require_once __DIR__ . "/../../includes/school_queries.php";

$schoolUserId = schoolApiGuard($conn);

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/** Fetch one fully-shaped transaction. */
function smFetchRow($conn, int $id): ?array
{
    $stmt = $conn->prepare(smTransactionSql() . " WHERE r.reservation_id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? smShapeRow($row) : null;
}

/** Run a shaping SELECT and return an array of rows. */
function smFetchRows($conn, string $where, string $order, int $limit = 200): array
{
    $sql = smTransactionSql() . " WHERE " . $where . " ORDER BY " . $order . " LIMIT " . (int)$limit;
    $res = $conn->query($sql);
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = smShapeRow($r);
    }
    $res->free();
    return $out;
}

/* ------------------------------------------------------------------
   DASHBOARD - four counters + recent transactions
------------------------------------------------------------------ */
if ($action === "dashboard") {

    // Matches the Pending Witness Requests list exactly (see approvals_list), so the
    // counter and the table can never disagree.
    $pendingApprovals = (int)$conn->query(
        "SELECT COUNT(*) FROM school_approval_requests a
         INNER JOIN reservations r ON a.reservation_id = r.reservation_id
         WHERE a.status = 'pending' AND r.status IN ('pending','approved')"
    )->fetch_row()[0];

    $activeRentals = (int)$conn->query(
        "SELECT COUNT(*) FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         WHERE e.requires_school_approval = 1
           AND r.fulfillment_status = 'active'
           AND r.returned_at IS NULL"
    )->fetch_row()[0];

    $returnVerifications = (int)$conn->query(
        "SELECT COUNT(*) FROM school_return_verifications v
         INNER JOIN reservations r ON v.reservation_id = r.reservation_id
         WHERE v.status = 'pending' AND r.returned_at IS NOT NULL"
    )->fetch_row()[0];

    $reportedIssues = (int)$conn->query(
        "SELECT COUNT(*) FROM reports WHERE status IN ('open','pending','under_review')"
    )->fetch_row()[0];

    // Latest recorded activity across the rental lifecycle.
    $recent = smFetchRows(
        $conn,
        "e.requires_school_approval = 1",
        "GREATEST(r.created_at, IFNULL(r.returned_at,'1970-01-01'), IFNULL(a.reviewed_at,'1970-01-01'), IFNULL(rv.verified_at,'1970-01-01')) DESC",
        12
    );

    schoolReply(true, "Dashboard loaded.", [
        "counts" => [
            "pendingApprovals"    => $pendingApprovals,
            "activeRentals"       => $activeRentals,
            "returnVerifications" => $returnVerifications,
            "reportedIssues"      => $reportedIssues,
        ],
        "recent" => $recent,
    ]);
}

/* ------------------------------------------------------------------
   MODULE 1 - PENDING APPROVALS
------------------------------------------------------------------ */
if ($action === "approvals_list") {
    // The owner may approve BEFORE or AFTER asking School Management to
    // witness, so a pending school request is queued whether the reservation
    // is still 'pending' or already 'approved' by the owner. Filtering on
    // 'pending' only made approved-but-unwitnessed requests disappear.
    $rows = smFetchRows(
        $conn,
        "e.requires_school_approval = 1 AND a.status = 'pending' AND r.status IN ('pending','approved')",
        "a.requested_at DESC"
    );
    schoolReply(true, "Approvals loaded.", ["rows" => $rows]);
}

/** Full detail for the Transaction Approval modal. */
if ($action === "approval_fetch") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) schoolReply(false, "Invalid reservation id.");

    $row = smFetchRow($conn, $id);
    if (!$row) schoolReply(false, "Transaction not found.");

    schoolReply(true, "Transaction loaded.", [
        "tx"       => $row,
        "timeline" => smTimeline($conn, $id),
    ]);
}

/**
 * Confirm as witness or flag the transaction.
 *
 * Re-reads the request row inside the transaction, so a double-click or a
 * stale page cannot record a second decision. The reservation is never
 * deleted and no payment or stock change happens here - witnessing is
 * deliberately kept separate from release and from payment.
 */
if ($action === "approval_decide") {
    $id       = (int)($_POST["id"] ?? 0);
    $decision = strtolower(trim((string)($_POST["decision"] ?? "")));
    $notes    = trim((string)($_POST["notes"] ?? ""));

    if ($id < 1) schoolReply(false, "Invalid reservation id.");
    if (!in_array($decision, ["approved", "rejected"], true)) {
        schoolReply(false, "Invalid decision.");
    }
    if ($decision === "rejected" && $notes === "") {
        schoolReply(false, "A flag reason is required.");
    }

    $conn->begin_transaction();

    try {
        $check = $conn->prepare(
            "SELECT a.approval_id, a.status, a.requested_by, r.user_id, r.status AS res_status,
                    e.equipment_name, e.owner_id, e.requires_school_approval
             FROM school_approval_requests a
             INNER JOIN reservations r ON a.reservation_id = r.reservation_id
             INNER JOIN equipment e   ON r.equipment_id = e.equipment_id
             WHERE a.reservation_id = ? FOR UPDATE"
        );
        $check->bind_param("i", $id);
        $check->execute();
        $d = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$d) {
            throw new RuntimeException("No witness request exists for this transaction.");
        }
        if (!$d["requires_school_approval"]) {
            throw new RuntimeException("This equipment is not classified as high-value.");
        }
        if (strtolower($d["status"]) !== "pending") {
            throw new RuntimeException("This request was already " . $d["status"] . ".");
        }

        $upd = $conn->prepare(
            "UPDATE school_approval_requests
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), decision_notes = ?
             WHERE approval_id = ? AND status = 'pending'"
        );
        $upd->bind_param("sisi", $decision, $schoolUserId, $notes, $d["approval_id"]);
        $upd->execute();

        if ($upd->affected_rows !== 1) {
            throw new RuntimeException("The decision was already recorded.");
        }
        $upd->close();

        // If school flags a transaction that was owner-approved, restore stock
        // since the rental will not proceed.
        if ($decision === "rejected" && strtolower($d["res_status"]) === "approved") {
            $restock = $conn->prepare(
                "UPDATE equipment SET available_quantity = available_quantity + ? WHERE equipment_id = ?"
            );
            $restock->bind_param("ii", $d["quantity"], $d["equipment_id"]);
            $restock->execute();
            $restock->close();
        }

        auditEvent(
            $conn,
            $id,
            $schoolUserId,
            $decision === "approved" ? "school_witnessed" : "transaction_flagged",
            "pending",
            $decision,
            $notes !== "" ? $notes : null
        );

        $msg = $decision === "approved"
            ? "The transaction for " . $d["equipment_name"] . " (RES-" . $id . ") has been recorded as witnessed by School Management."
            : "School Management flagged the transaction for " . $d["equipment_name"] . " (RES-" . $id . ").";

        // Borrower and owner both hear the real decision.
        notifyUser($conn, (int)$d["user_id"], $msg);
        if ((int)$d["owner_id"] !== (int)$d["user_id"]) {
            notifyUser($conn, (int)$d["owner_id"], $msg);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        schoolReply(false, $e->getMessage());
    }

    schoolReply(
        true,
        $decision === "approved"
            ? "Transaction witnessed by School Management successfully."
            : "Transaction flagged by School Management successfully."
    );
}

/* ------------------------------------------------------------------
   MODULE 2 - ACTIVE RENTALS
   Only rentals that genuinely entered the active stage: approved and
   nothing returned. School approval alone never makes a rental active.
------------------------------------------------------------------ */
if ($action === "active_list") {
    $rows = smFetchRows(
        $conn,
        "e.requires_school_approval = 1 AND r.fulfillment_status = 'active' AND r.returned_at IS NULL",
        "r.start_date ASC"
    );
    schoolReply(true, "Active rentals loaded.", ["rows" => $rows]);
}

if ($action === "active_fetch") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) schoolReply(false, "Invalid reservation id.");
    $row = smFetchRow($conn, $id);
    if (!$row) schoolReply(false, "Transaction not found.");
    schoolReply(true, "Rental loaded.", ["tx" => $row, "timeline" => smTimeline($conn, $id)]);
}

/* ------------------------------------------------------------------
   MODULE 3 - RETURN VERIFICATIONS
   Rows are only offered once the borrower return step exists AND an
   institutional verification record has been opened.
------------------------------------------------------------------ */
if ($action === "returns_list") {
    $rows = smFetchRows(
        $conn,
        "e.requires_school_approval = 1 AND rv.status = 'pending' AND r.returned_at IS NOT NULL",
        "r.returned_at ASC"
    );
    schoolReply(true, "Return verifications loaded.", ["rows" => $rows]);
}

if ($action === "returns_fetch") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) schoolReply(false, "Invalid reservation id.");
    $row = smFetchRow($conn, $id);
    if (!$row) schoolReply(false, "Transaction not found.");

    schoolReply(true, "Return loaded.", [
        "tx"       => $row,
        "evidence" => smEvidence($conn, $id),
        "timeline" => smTimeline($conn, $id),
    ]);
}

/**
 * Confirm a return.
 *
 * Requires an open verification record. Re-reads state inside the
 * transaction so a repeat click cannot verify twice. Never restocks
 * inventory here - stock belongs to the owner's own return process.
 */
if ($action === "returns_verify") {
    $id    = (int)($_POST["id"] ?? 0);
    $notes = trim((string)($_POST["notes"] ?? ""));

    if ($id < 1) schoolReply(false, "Invalid reservation id.");

    $conn->begin_transaction();

    try {
        $check = $conn->prepare(
            "SELECT v.verification_id, v.status, r.returned_at, r.status AS res_status,
                    r.user_id, e.owner_id, e.equipment_name, e.requires_school_approval,
                    v.inspected_by
             FROM school_return_verifications v
             INNER JOIN reservations r ON v.reservation_id = r.reservation_id
             INNER JOIN equipment e   ON r.equipment_id = e.equipment_id
             WHERE v.reservation_id = ? FOR UPDATE"
        );
        $check->bind_param("i", $id);
        $check->execute();
        $v = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$v) throw new RuntimeException("No return verification is open for this rental.");
        if (!$v["requires_school_approval"]) throw new RuntimeException("This equipment is not classified as high-value.");
        if (empty($v["returned_at"])) throw new RuntimeException("The borrower has not submitted a return yet.");
        if (empty($v["inspected_by"])) {
            throw new RuntimeException("The equipment owner has not yet inspected and confirmed the return. School Management cannot verify until the owner has recorded their inspection.");
        }
        if (strtolower($v["status"]) !== "pending") {
            throw new RuntimeException("This return was already " . $v["status"] . ".");
        }

        $upd = $conn->prepare(
            "UPDATE school_return_verifications
             SET status = 'verified', verified_by = ?, verified_at = NOW(), verification_notes = ?
             WHERE verification_id = ? AND status = 'pending'"
        );
        $upd->bind_param("sis", $schoolUserId, $notes, $v["verification_id"]);
        $upd->execute();

        if ($upd->affected_rows !== 1) throw new RuntimeException("The verification was already recorded.");
        $upd->close();

        // Close the rental only when every required stage is satisfied:
        // the return was submitted, the owner inspected it, and the school
        // verified it. Fulfillment is closed at the same time so the three
        // dashboards agree.
        $completed = false;

        if (strtolower($v["res_status"]) === "approved") {
            $fin = $conn->prepare(
                "UPDATE reservations
                 SET status = 'completed', fulfillment_status = 'completed'
                 WHERE reservation_id = ? AND status = 'approved'"
            );
            $fin->bind_param("i", $id);
            $fin->execute();
            $completed = $fin->affected_rows === 1;
            $fin->close();
        } else {
            $fin2 = $conn->prepare(
                "UPDATE reservations SET fulfillment_status = 'completed' WHERE reservation_id = ?"
            );
            $fin2->bind_param("i", $id);
            $fin2->execute();
            $fin2->close();
        }

        auditEvent($conn, $id, $schoolUserId, "return_verified", "pending", "verified",
            $notes !== "" ? $notes : null);

        if ($completed) {
            auditEvent($conn, $id, $schoolUserId, "transaction_completed",
                "return_verified", "completed",
                "Rental closed after School Management verified the return.");
        }


        $msg = "School Management verified the return of " . $v["equipment_name"] . " (RES-" . $id . ").";
        notifyUser($conn, (int)$v["user_id"], $msg);
        if ((int)$v["owner_id"] !== (int)$v["user_id"]) {
            notifyUser($conn, (int)$v["owner_id"], $msg);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        schoolReply(false, $e->getMessage());
    }

    schoolReply(true, "Equipment return verified successfully.");
}

/** Flag an issue instead of confirming. Records the observation only. */
if ($action === "returns_issue") {
    $id    = (int)($_POST["id"] ?? 0);
    $notes = trim((string)($_POST["notes"] ?? ""));

    if ($id < 1) schoolReply(false, "Invalid reservation id.");
    if ($notes === "") schoolReply(false, "Please describe the issue.");

    $check = $conn->prepare(
        "SELECT v.verification_id, v.status, r.user_id, e.owner_id, e.equipment_name
         FROM school_return_verifications v
         INNER JOIN reservations r ON v.reservation_id = r.reservation_id
         INNER JOIN equipment e   ON r.equipment_id = e.equipment_id
         WHERE v.reservation_id = ?"
    );
    $check->bind_param("i", $id);
    $check->execute();
    $v = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$v) schoolReply(false, "No return verification is open for this rental.");
    if (strtolower($v["status"]) !== "pending") {
        schoolReply(false, "This return was already " . $v["status"] . ".");
    }

    $conn->begin_transaction();
    try {
        $upd = $conn->prepare(
            "UPDATE school_return_verifications
             SET status = 'issue', verified_by = ?, verified_at = NOW(), verification_notes = ?
             WHERE verification_id = ? AND status = 'pending'"
        );
        $upd->bind_param("sis", $schoolUserId, $notes, $v["verification_id"]);
        $upd->execute();
        if ($upd->affected_rows !== 1) throw new RuntimeException("The verification was already recorded.");
        $upd->close();

        // Reuses the existing reports table - no duplicate storage.
        $rep = $conn->prepare(
            "INSERT INTO reports (user_id, reservation_id, subject, issue_type, description, status, admin_notes)
             VALUES (?, ?, ?, 'Return Verification Issue', ?, 'open', ?)"
        );
        $subject = "School verification on RES-" . $id;
        $reportUserId = (int)$v["user_id"];
        $reportStatus = "Flagged by School Management";
        $rep->bind_param("iisss", $reportUserId, $id, $subject, $notes, $reportStatus);
        $rep->execute();
        $rep->close();

        auditEvent($conn, $id, $schoolUserId, "return_issue_flagged", "pending", "issue", $notes);

        $msg = "School Management reported an issue with the return of " . $v["equipment_name"] . " (RES-" . $id . ").";
        notifyUser($conn, (int)$v["user_id"], $msg);
        if ((int)$v["owner_id"] !== (int)$v["user_id"]) {
            notifyUser($conn, (int)$v["owner_id"], $msg);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        schoolReply(false, $e->getMessage());
    }

    schoolReply(true, "Issue recorded successfully.");
}

/* ------------------------------------------------------------------
   MODULE 4 - REPORTS / ISSUES
   Reuses the existing reports table.
------------------------------------------------------------------ */
if ($action === "reports_list") {
    $res = $conn->query(
        "SELECT rp.*, u.first_name, u.last_name,
                r.equipment_id, e.equipment_name, e.category, e.owner_id,
                stu.student_number
         FROM reports rp
         LEFT JOIN users u        ON rp.user_id = u.user_id
         LEFT JOIN reservations r ON rp.reservation_id = r.reservation_id
         LEFT JOIN equipment e    ON r.equipment_id = e.equipment_id
         LEFT JOIN users stu      ON r.user_id = stu.user_id
         ORDER BY rp.report_id DESC
         LIMIT 300"
    );
    $out = [];
    while ($x = $res->fetch_assoc()) {
        $x["reporter_name"] = trim(($x["first_name"] ?? "") . " " . ($x["last_name"] ?? ""));
        $out[] = $x;
    }
    $res->free();
    schoolReply(true, "Reports loaded.", ["rows" => $out]);
}

/** Record an investigation note / status change on a report. */
if ($action === "reports_note") {
    $reportId = (int)($_POST["report_id"] ?? 0);
    $notes    = trim((string)($_POST["notes"] ?? ""));
    $status   = strtolower(trim((string)($_POST["status"] ?? "under_review")));

    if ($reportId < 1) schoolReply(false, "Invalid report id.");
    if ($notes === "") schoolReply(false, "Please enter an investigation note.");
    if (!in_array($status, ["open", "under_review", "resolved"], true)) {
        schoolReply(false, "Invalid report status.");
    }

    $get = $conn->prepare("SELECT status FROM reports WHERE report_id = ?");
    $get->bind_param("i", $reportId);
    $get->execute();
    $r = $get->get_result()->fetch_assoc();
    $get->close();
    if (!$r) schoolReply(false, "Report not found.");

    // Notes are appended to the existing note, never overwritten silently.
    $upd = $conn->prepare(
        "UPDATE reports
         SET status = ?,
             admin_notes = CONCAT(COALESCE(admin_notes,''), ?)
         WHERE report_id = ?"
    );
    $stamp = "\n[" . date("Y-m-d H:i") . " School Management] " . $notes;
    $upd->bind_param("ssi", $status, $stamp, $reportId);
    $upd->execute();
    $upd->close();

    auditEvent($conn, null, $schoolUserId, "report_note", $r["status"], $status, $notes);

    schoolReply(true, "Investigation note recorded.");
}

/* ------------------------------------------------------------------
   MODULE 5 - TRANSACTION HISTORY
------------------------------------------------------------------ */
if ($action === "history_list") {
    $rows = smFetchRows(
        $conn,
        "e.requires_school_approval = 1",
        "r.created_at DESC",
        300
    );
    schoolReply(true, "History loaded.", ["rows" => $rows]);
}

if ($action === "history_fetch") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) schoolReply(false, "Invalid reservation id.");
    $row = smFetchRow($conn, $id);
    if (!$row) schoolReply(false, "Transaction not found.");
    schoolReply(true, "History loaded.", ["tx" => $row, "timeline" => smTimeline($conn, $id)]);
}

/* ------------------------------------------------------------------
   MODULE 6 - INVENTORY OVERVIEW
   Read-only monitoring. School Management has no delete/price/ownership
   authority over student equipment.
------------------------------------------------------------------ */
if ($action === "inventory_list") {
    $res = $conn->query(
        "SELECT e.equipment_id, e.equipment_name, e.category, e.equipment_image,
                e.rental_price, e.available_quantity, e.status, e.equipment_condition,
                e.asset_code, e.declared_value, e.requires_school_approval, e.owner_id,
                u.first_name, u.last_name,
                (SELECT COUNT(*) FROM reservations r
                  WHERE r.equipment_id = e.equipment_id
                    AND r.status = 'approved' AND r.returned_at IS NULL) AS active_rentals
         FROM equipment e
         INNER JOIN users u ON e.owner_id = u.user_id
         WHERE e.requires_school_approval = 1
         ORDER BY e.equipment_name ASC"
    );
    $out = [];
    while ($x = $res->fetch_assoc()) {
        $x["owner_name"] = trim($x["first_name"] . " " . $x["last_name"]);
        $out[] = $x;
    }
    $res->free();
    schoolReply(true, "Inventory loaded.", ["rows" => $out]);
}

/* ------------------------------------------------------------------
   NOTIFICATIONS for the header bell
------------------------------------------------------------------ */
if ($action === "notifications") {
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->bind_param("i", $schoolUserId);
    $c->execute();
    $unread = (int)(($c->get_result()->fetch_assoc()["c"] ?? 0));
    $c->close();

    $l = $conn->prepare(
        "SELECT notification_id, message, is_read, created_at
         FROM notifications WHERE user_id = ?
         ORDER BY notification_id DESC LIMIT 10"
    );
    $l->bind_param("i", $schoolUserId);
    $l->execute();
    $res = $l->get_result();
    $items = [];
    while ($x = $res->fetch_assoc()) {
        $items[] = $x;
    }
    $l->close();

    schoolReply(true, "Notifications loaded.", ["unread" => $unread, "items" => $items]);
}

schoolReply(false, "Unknown action.");
