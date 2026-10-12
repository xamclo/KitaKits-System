<?php
/**
 * Shared read model for every School Management page.
 *
 * One place builds the "high-value rental" row so the dashboard, the
 * modules and the modals can never disagree about a transaction.
 *
 * Nothing here is derived from a calendar date alone: a reservation is
 * only ever shown as released or returned when the corresponding
 * audit/return record actually exists.
 */

/**
 * Base SELECT for a school-relevant transaction.
 * High-value classification comes from equipment.requires_school_approval.
 */
function smTransactionSql(): string
{
    return "
        SELECT
            r.reservation_id, r.user_id, r.equipment_id, r.quantity,
            r.start_date, r.end_date, r.total_price, r.purpose,
            r.status              AS reservation_status,
            r.fulfillment_status,
            r.returned_at, r.return_condition, r.created_at,
            e.equipment_name, e.category, e.equipment_image, e.owner_id,
            e.asset_code, e.declared_value, e.accessories,
            e.requires_school_approval, e.equipment_condition,
            stu.first_name  AS stu_first,
            stu.last_name   AS stu_last,
            stu.student_number, stu.program,
            own.first_name  AS own_first,
            own.last_name   AS own_last,
            a.approval_id, a.status AS school_status,
            a.reviewed_by, a.reviewed_at, a.decision_notes, a.requested_at,
            rv.verification_id, rv.status AS verify_status,
            rv.verified_at, rv.verification_notes, rv.condition_report,
            (SELECT t.payment_status
               FROM transactions t
              WHERE t.reservation_id = r.reservation_id
              ORDER BY t.payment_date DESC
              LIMIT 1) AS payment_status
        FROM reservations r
        INNER JOIN equipment e ON r.equipment_id = e.equipment_id
        INNER JOIN users stu   ON r.user_id      = stu.user_id
        INNER JOIN users own   ON e.owner_id     = own.user_id
        LEFT  JOIN school_approval_requests a
               ON a.reservation_id = r.reservation_id
        LEFT  JOIN school_return_verifications rv
               ON rv.reservation_id = r.reservation_id
    ";
}

/** School witness label for a row. */
function smApprovalLabel(array $row): string
{
    if (empty($row["requires_school_approval"])) return "Not Required";
    if (empty($row["school_status"])) return "Not Requested";
    $s = strtolower($row["school_status"]);
    if ($s === "pending")  return "Pending Witness";
    if ($s === "approved") return "Witnessed";
    if ($s === "rejected") return "Transaction Flagged";
    return ucfirst($s);
}

function smApprovalClass(array $row): string
{
    if (empty($row["requires_school_approval"])) return "notreq";
    if (empty($row["school_status"])) return "notreq";
    $s = strtolower($row["school_status"]);
    if ($s === "pending")  return "pending";
    if ($s === "approved") return "approved";
    if ($s === "rejected") return "rejected";
    return "notreq";
}

/** Return verification label for a row. */
function smVerifyLabel(array $row): string
{
    if (empty($row["requires_school_approval"])) return "Not Required";
    if (empty($row["verify_status"])) return "Not Started";
    $s = strtolower($row["verify_status"]);
    if ($s === "pending")  return "For Verification";
    if ($s === "verified") return "Verified";
    if ($s === "issue")    return "Issue Reported";
    return ucfirst($s);
}

function smVerifyClass(array $row): string
{
    if (empty($row["requires_school_approval"])) return "notreq";
    if (empty($row["verify_status"])) return "notreq";
    $s = strtolower($row["verify_status"]);
    if ($s === "pending")  return "verification";
    if ($s === "verified") return "completed";
    if ($s === "issue")    return "issue";
    return "notreq";
}

/**
 * Fulfillment state. "Active" requires a genuinely released rental;
 * school approval alone never makes a rental active.
 */
function smFulfillmentLabel(array $row): string
{
    $status = strtolower(trim((string)$row["reservation_status"]));
    $verify = strtolower((string)($row["verify_status"] ?? ""));
    $ful    = strtolower(trim((string)($row["fulfillment_status"] ?? "")));

    if (in_array($status, ["declined", "rejected"], true)) return "Declined";
    if ($verify === "verified") return "Completed";
    if (!empty($row["returned_at"])) return "Return Submitted";
    if ($status === "completed") return "Completed";

    /* Handover drives the active state, never a calendar date. A rental
       only reads "Active" once the borrower has confirmed receipt. */
    if ($ful === "active") return "Active";
    if ($ful === "handover_recorded") return "Awaiting Borrower Confirmation";
    if ($status === "approved") return "Awaiting Handover";
    if ($status === "pending") return "Awaiting Release";

    return ucfirst($status !== "" ? $status : $ful);
}

function smFulfillmentClass(array $row): string
{
    $label = smFulfillmentLabel($row);
    if ($label === "Active") return "borrow";
    if ($label === "Return Submitted") return "return";
    if ($label === "Completed") return "completed";
    if ($label === "Awaiting Release") return "pending";
    if ($label === "Declined") return "rejected";
    return "notreq";
}

/**
 * The recorded audit trail for one reservation, oldest first.
 * Stages without a database event are absent - never invented.
 */
function smTimeline($conn, int $reservationId): array
{
    $stmt = $conn->prepare(
        "SELECT a.action_type, a.previous_status, a.new_status, a.created_at, a.notes,
                a.actor_user_id, u.first_name, u.last_name, u.role
         FROM transaction_audit_logs a
         LEFT JOIN users u ON a.actor_user_id = u.user_id
         WHERE a.reservation_id = ?
         ORDER BY a.log_id ASC"
    );
    $stmt->bind_param("i", $reservationId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }
    $stmt->close();
    return $out;
}

/** Evidence photos recorded against a return, if any. */
function smEvidence($conn, int $reservationId): array
{
    $stmt = $conn->prepare(
        "SELECT evidence_id, file_name, caption, created_at
         FROM school_return_evidence
         WHERE reservation_id = ?
         ORDER BY evidence_id ASC"
    );
    $stmt->bind_param("i", $reservationId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = $r;
    }
    $stmt->close();
    return $out;
}

/**
 * Accessories recorded by the equipment owner.
 * Empty when nothing was recorded - never invents a list.
 */
function smAccessories(?string $raw): array
{
    if ($raw === null || trim($raw) === "") return [];
    $out = [];
    foreach (preg_split('/\r\n|\r|\n|,/', $raw) as $p) {
        $p = trim($p);
        if ($p !== "") $out[] = $p;
    }
    return $out;
}

/**
 * Rental duration in whole days between the recorded pickup and return
 * dates. Returns 0 when either date is missing - never guesses.
 */
function smDurationDays($start, $end): int
{
    if (!$start || !$end) return 0;
    $d = (strtotime($end) - strtotime($start)) / 86400;
    return (int)max(0, ceil($d));
}

/**
 * Shapes one joined transaction row into the structure every module and
 * modal consumes, so the dashboard, tables and modals can never disagree.
 */
function smShapeRow(array $r): array
{
    return [
        "reservation_id"  => (int)$r["reservation_id"],
        "equipment" => [
            "id"        => (int)$r["equipment_id"],
            "name"      => $r["equipment_name"],
            "category"  => $r["category"],
            "image"     => $r["equipment_image"],
            "asset_code" => $r["asset_code"],
            "declared_value" => $r["declared_value"],
            "high_value" => (int)$r["requires_school_approval"] === 1,
            "accessories" => smAccessories($r["accessories"]),
            "condition" => $r["equipment_condition"],
        ],
        "student" => [
            "id"     => (int)$r["user_id"],
            "name"   => trim($r["stu_first"] . " " . $r["stu_last"]),
            "first"  => $r["stu_first"],
            "last"   => $r["stu_last"],
            "number" => $r["student_number"],
            "program" => $r["program"],
        ],
        "owner" => [
            "id"    => (int)$r["owner_id"],
            "name"  => trim($r["own_first"] . " " . $r["own_last"]),
            "first" => $r["own_first"],
            "last"  => $r["own_last"],
        ],
        "dates" => [
            "requested" => $r["created_at"],
            "start"     => $r["start_date"],
            "end"       => $r["end_date"],
            "returned"  => $r["returned_at"],
            "duration_days" => smDurationDays($r["start_date"], $r["end_date"]),
        ],
        "purpose"     => $r["purpose"],
        "quantity"    => (int)$r["quantity"],
        "total_price" => $r["total_price"],
        "payment"     => $r["payment_status"] ?: "unpaid",
        "school" => [
            "state"       => smApprovalLabel($r),
            "css"         => smApprovalClass($r),
            "notes"       => $r["decision_notes"],
            "reviewed_at" => $r["reviewed_at"],
            "reviewed_by" => $r["reviewed_by"],
            "requested_at" => $r["requested_at"],
            "required"    => (int)$r["requires_school_approval"] === 1,
        ],
        "verification" => [
            "state" => smVerifyLabel($r),
            "css"   => smVerifyClass($r),
            "at"    => $r["verified_at"],
            "notes" => $r["verification_notes"],
            "condition_report" => $r["condition_report"],
        ],
        "fulfillment" => [
            "state" => smFulfillmentLabel($r),
            "css"   => smFulfillmentClass($r),
            "reservation_status" => $r["reservation_status"],
            "fulfillment_status" => $r["fulfillment_status"] ?? "",
            "return_condition"   => $r["return_condition"],
        ],
    ];
}
