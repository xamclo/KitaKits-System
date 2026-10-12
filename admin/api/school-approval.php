<?php
/**
 * Equipment owner -> School Management bridge.
 *
 * ADDITION ONLY. It does not change how reservations are created, approved
 * or declined. It lets an owner:
 *   1. submit a School Management witnessing request for high-value equipment
 *   2. see the current school witness state for their own rentals
 *   3. release equipment ONLY once every required witness step is satisfied
 *
 * Owner approval (reservations.status) and school witness
 * (school_approval_requests.status) stay completely separate.
 */

session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/school_auth.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "admin") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Unauthorized."]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

function ownerReply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/* ------------------------------------------------------------------
   STATE - which of this owner's rentals need school approval
------------------------------------------------------------------ */
if ($action === "state") {
    $sql = "SELECT r.reservation_id, r.status AS reservation_status, r.start_date, r.end_date,
                   e.equipment_name, e.requires_school_approval,
                   a.approval_id, a.status AS school_status, a.decision_notes
            FROM reservations r
            INNER JOIN equipment e ON r.equipment_id = e.equipment_id
            LEFT  JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
            WHERE e.owner_id = ?
            ORDER BY r.reservation_id DESC
            LIMIT 200";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    ownerReply(true, "State loaded.", ["rows" => $rows]);
}

/* ------------------------------------------------------------------
   REQUEST SCHOOL WITNESS
------------------------------------------------------------------ */
if ($action === "request_approval") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) ownerReply(false, "Invalid reservation id.");

    $conn->begin_transaction();

    try {
        $check = $conn->prepare(
            "SELECT r.reservation_id, r.status, r.user_id,
                    e.equipment_name, e.owner_id, e.requires_school_approval,
                    a.status AS school_status
             FROM reservations r
             INNER JOIN equipment e ON r.equipment_id = e.equipment_id
             LEFT  JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
             WHERE r.reservation_id = ? AND e.owner_id = ? FOR UPDATE"
        );
        $check->bind_param("ii", $id, $userId);
        $check->execute();
        $d = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$d) throw new RuntimeException("Reservation not found or is not yours.");
        if (!$d["requires_school_approval"]) {
            throw new RuntimeException("This equipment is not classified as high-value, so School Management witnessing is not required.");
        }
        // School Management witnessing can only be requested after the
        // equipment owner has approved the rental. The owner's approval
        // and the school witness are separate stages.
        $ownerStatus = strtolower(trim((string)$d["status"]));
        if ($ownerStatus !== "approved") {
            throw new RuntimeException("Only an owner-approved request can be submitted for School Management witnessing.");
        }

        $existing = strtolower(trim((string)($d["school_status"] ?? "")));

        // Already requested and still active: do nothing at all. No second
        // request row, no second audit event, no duplicate notification.
        if ($existing === "pending") {
            $conn->commit();
            ownerReply(true, "School Management witnessing has already been requested.");
        }
        if ($existing === "approved") {
            $conn->commit();
            ownerReply(true, "School Management witnessing was already completed.");
        }

        if ($existing === "rejected") {
            // A fresh review round after a previous flag.
            $re = $conn->prepare(
                "UPDATE school_approval_requests
                 SET status = 'pending', requested_by = ?, requested_at = NOW(),
                     reviewed_by = NULL, reviewed_at = NULL, decision_notes = NULL
                 WHERE reservation_id = ? AND status = 'rejected'"
            );
            $re->bind_param("ii", $userId, $id);
            $re->execute();
            $re->close();
        } else {
            $ins = $conn->prepare(
                "INSERT INTO school_approval_requests (reservation_id, requested_by, request_type, status)
                 VALUES (?, ?, 'witness', 'pending')"
            );
            $ins->bind_param("ii", $id, $userId);
            $ins->execute();
            $ins->close();
        }

        auditEvent($conn, $id, $userId, "school_approval_requested",
            $existing !== "" ? $existing : "none", "pending",
            "School Management witnessing requested by the equipment owner.");

        notifyAllSchoolUsers(
            $conn,
            "A high-value transaction has been submitted for School Management witnessing."
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        ownerReply(false, $e->getMessage());
    }

    ownerReply(true, "School Management witnessing requested successfully.");
}

/* ------------------------------------------------------------------
   RELEASE EQUIPMENT

   Release is recorded as a HANDOVER, never as a bare status change.

   This endpoint used to flip reservations.status directly, which let
   high-value equipment leave with no recorded condition, no accessory
   list and no borrower confirmation - and counted the rental as active
   before anyone had physically received it.

   There is now exactly one release path: api/handover.php, which captures
   the handover evidence and keeps fulfillment_status separate from the
   owner decision.
------------------------------------------------------------------ */
if ($action === "release") {
    ownerReply(false,
        "Release is recorded through the handover step. Use api/handover.php (action=owner_release) so the condition, accessories and borrower confirmation are captured."
    );
}

ownerReply(false, "Unknown action.");
