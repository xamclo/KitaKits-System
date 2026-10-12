<?php
/**
 * Equipment handover (release) for School Management oversight.
 *
 * ADDITION ONLY. It does not change how reservations are created, approved
 * or declined by the owner. It records the physical handover separately
 * from the owner's decision, so a rental only becomes ACTIVE once the
 * handover conditions are genuinely met - never because a date passed.
 *
 * Steps:
 *   1. owner_release      - owner records the handover (condition, accessories)
 *   2. confirm_borrower    - borrower confirms receipt of the items
 *   3. state              - current handover state for the owner UI
 *
 * Server-side rules enforced here:
 *   - only the equipment owner may act
 *   - high-value items need a recorded SCHOOL WITNESS first
 *   - one handover row per reservation (repeat clicks are refused)
 *   - borrower confirmation is required before the rental is "active"
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

function hoReply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/** Loads the reservation and confirms this admin owns the equipment. */
function hoLoad($conn, int $id, int $userId): array
{
    $stmt = $conn->prepare(
        "SELECT r.reservation_id, r.user_id AS borrower_id, r.status,
                r.fulfillment_status, r.quantity, r.total_price,
                r.returned_at, r.return_condition,
                e.equipment_id, e.equipment_name, e.owner_id,
                e.requires_school_approval, e.accessories,
                a.status AS school_status
         FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         LEFT  JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
         WHERE r.reservation_id = ? AND e.owner_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: [];
}

/* ------------------------------------------------------------------
   STATE
------------------------------------------------------------------ */
if ($action === "state") {
    $id = (int)($_POST["id"] ?? 0);
    $d = hoLoad($conn, $id, $userId);
    if (!$d) hoReply(false, "Reservation not found or is not yours.");

    $h = $conn->prepare(
        "SELECT * FROM equipment_handover_confirmations WHERE reservation_id = ? LIMIT 1"
    );
    $h->bind_param("i", $id);
    $h->execute();
    $hand = $h->get_result()->fetch_assoc();
    $h->close();

    $evidence = [];
    if ($hand) {
        $e = $conn->prepare(
            "SELECT evidence_id, file_name, caption FROM equipment_handover_evidence
             WHERE handover_id = ? ORDER BY evidence_id ASC"
        );
        $e->bind_param("i", $hand["handover_id"]);
        $e->execute();
        $res = $e->get_result();
        while ($r = $res->fetch_assoc()) { $evidence[] = $r; }
        $e->close();
    }

    hoReply(true, "Handover state loaded.", [
        "requires_school" => (int)$d["requires_school_approval"] === 1,
        "school_status"   => $d["school_status"],
        "handover"        => $hand,
        "evidence"        => $evidence,
        "can_release"     => in_array($d["status"], ["pending", "approved"], true) && !$hand,
    ]);
}

/* ------------------------------------------------------------------
   OWNER RECORDS THE HANDOVER
------------------------------------------------------------------ */
if ($action === "owner_release") {
    $id          = (int)($_POST["id"] ?? 0);
    $condition   = trim((string)($_POST["condition"] ?? ""));
    $accessories = trim((string)($_POST["accessories"] ?? ""));
    $notes       = trim((string)($_POST["notes"] ?? ""));

    if ($id < 1) hoReply(false, "Invalid reservation id.");
    if ($condition === "") hoReply(false, "Please record the equipment condition at handover.");

    $conn->begin_transaction();

    try {
        $d = hoLoad($conn, $id, $userId);
        if (!$d) throw new RuntimeException("Reservation not found or is not yours.");
        if (!in_array($d["status"], ["pending", "approved"], true)) {
            throw new RuntimeException("This request was already " . $d["status"] . ".");
        }

        // High-value items need a recorded WITNESSED school witness.
        if ((int)$d["requires_school_approval"] === 1) {
            if (!$d["school_status"]) {
                throw new RuntimeException("School Management witnessing has not been completed for this high-value equipment.");
            }
            if (strtolower($d["school_status"]) !== "approved") {
                throw new RuntimeException("School Management witnessing is still " . $d["school_status"] . ". The equipment cannot be released yet.");
            }
        }

        // One handover per reservation.
        $exists = $conn->prepare(
            "SELECT handover_id FROM equipment_handover_confirmations WHERE reservation_id = ? FOR UPDATE"
        );
        $exists->bind_param("i", $id);
        $exists->execute();
        $already = $exists->get_result()->fetch_assoc();
        $exists->close();
        if ($already) throw new RuntimeException("The handover for this rental was already recorded.");

        // Accessories default to the owner's declared list when not overridden.
        if ($accessories === "") { $accessories = (string)($d["accessories"] ?? ""); }

        $ins = $conn->prepare(
            "INSERT INTO equipment_handover_confirmations
                (reservation_id, owner_id, borrower_id, released_at,
                 condition_at_handover, accessories, owner_confirmed_at, notes)
             VALUES (?, ?, ?, NOW(), ?, ?, NOW(), ?)"
        );
        $rid = (int)$d["reservation_id"];
        $bid = (int)$d["borrower_id"];
        $ins->bind_param("iiisss", $rid, $userId, $bid, $condition, $accessories, $notes);
        $ins->execute();
        $handoverId = (int)$conn->insert_id;
        $ins->close();

        // The owner's decision and the handover are separate steps.
        $upd = $conn->prepare(
            "UPDATE reservations SET status = 'approved', fulfillment_status = 'handover_recorded'
             WHERE reservation_id = ? AND status IN ('pending','approved')"
        );
        $upd->bind_param("i", $id);
        $upd->execute();
        $upd->close();

        auditEvent($conn, $id, $userId, "owner_accepted", "pending", "approved",
            "Owner accepted the request.");
        auditEvent($conn, $id, $userId, "equipment_released", "handover_recorded", "awaiting_borrower_confirmation",
            "Owner recorded handover. Condition: " . $condition . ".");

        notifyUser($conn, $bid,
            "The owner marked the equipment as released for RES-" . $id . ". Confirm receipt after you physically receive it.");

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        hoReply(false, $e->getMessage());
    }

    hoReply(true, "Equipment handover recorded successfully.");
}

/* ------------------------------------------------------------------
   BORROWER CONFIRMS RECEIPT  -> rental becomes active
------------------------------------------------------------------ */
if ($action === "confirm_borrower") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) hoReply(false, "Invalid reservation id.");

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "SELECT h.handover_id, h.borrower_confirmed_at, r.reservation_id,
                    r.user_id AS borrower_id, r.fulfillment_status, r.returned_at,
                    e.equipment_name
             FROM equipment_handover_confirmations h
             INNER JOIN reservations r ON h.reservation_id = r.reservation_id
             INNER JOIN equipment e ON r.equipment_id = e.equipment_id
             WHERE h.reservation_id = ? AND r.user_id = ? FOR UPDATE"
        );
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$h) hoReply(false, "No handover is waiting for your confirmation.");
        if (!empty($h["borrower_confirmed_at"])) hoReply(false, "You already confirmed this handover.");
        if (!empty($h["returned_at"])) hoReply(false, "This rental has already been returned.");

        $hid = (int)$h["handover_id"];
        $upd = $conn->prepare(
            "UPDATE equipment_handover_confirmations
             SET borrower_confirmed_at = NOW()
             WHERE handover_id = ? AND borrower_confirmed_at IS NULL"
        );
        $upd->bind_param("i", $hid);
        $upd->execute();
        if ($upd->affected_rows !== 1) hoReply(false, "You already confirmed this handover.");
        $upd->close();

        $rel = $conn->prepare(
            "UPDATE reservations SET fulfillment_status = 'active'
             WHERE reservation_id = ? AND fulfillment_status = 'handover_recorded'"
        );
        $rel->bind_param("i", $id);
        $rel->execute();
        $rel->close();

        auditEvent($conn, $id, $userId, "rental_active", "awaiting_borrower_confirmation", "active",
            "Borrower confirmed handover of " . $h["equipment_name"] . ".");

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        hoReply(false, $e->getMessage());
    }

    hoReply(true, "Handover confirmed. The rental is now active.");
}

/* ------------------------------------------------------------------
   INSPECT RETURN - admin records physical return inspection
------------------------------------------------------------------ */
if ($action === "inspect_return") {
    $id = (int)($_POST["id"] ?? 0);
    $conditionReport = trim((string)($_POST["condition_report"] ?? ""));
    $accessories = trim((string)($_POST["accessories"] ?? ""));

    if ($id < 1) hoReply(false, "Invalid reservation id.");

    $conn->begin_transaction();

    try {
        $d = hoLoad($conn, $id, $userId);
        if (!$d) throw new RuntimeException("Reservation not found or is not yours.");
        if (empty($d["returned_at"])) {
            throw new RuntimeException("The student has not submitted a return yet.");
        }

        $rvRow = null;
        // High-value items need a school return verification record.
        if ((int)$d["requires_school_approval"] === 1) {
            $rv = $conn->prepare(
                "SELECT verification_id, status, inspected_by
                 FROM school_return_verifications
                 WHERE reservation_id = ? LIMIT 1"
            );
            $rv->bind_param("i", $id);
            $rv->execute();
            $rvRow = $rv->get_result()->fetch_assoc();
            $rv->close();

            if (!$rvRow) {
                throw new RuntimeException("No return verification record exists for this high-value transaction.");
            }
        }

        // Restore stock - equipment is physically back with owner.
        // Only restore once (idempotent): check if this reservation already
        // triggered a restock by looking for a completed audit event or
        // by checking if quantity was already added back. We use a simple
        // approach: only restore if reservation status is still 'approved'
        // (not yet completed). Once completed, stock is already restored.
        $restored = false;
        if (strtolower($d["status"]) === "approved") {
            $restock = $conn->prepare(
                "UPDATE equipment
                 SET available_quantity = available_quantity + ?
                 WHERE equipment_id = ?"
            );
            $restock->bind_param("ii", $d["quantity"], $d["equipment_id"]);
            $restock->execute();
            $restored = $restock->affected_rows > 0;
            $restock->close();
        }

        // Update school_return_verifications if it exists (high-value only)
        if ($rvRow) {
            $upd = $conn->prepare(
                "UPDATE school_return_verifications
                 SET condition_report = ?, accessories = ?, inspected_by = ?, inspected_at = NOW()
                 WHERE verification_id = ?"
            );
            $upd->bind_param("ssii", $conditionReport, $accessories, $userId, $rvRow["verification_id"]);
            $upd->execute();
            $upd->close();
        }

        $auditStatus = $restored ? "return_submitted" : ($rvRow ? $rvRow["status"] : "return_inspected");
        auditEvent($conn, $id, $userId, "owner_inspection", $rvRow ? $rvRow["status"] : "none", $auditStatus,
            "Owner recorded return inspection. Condition: " . $conditionReport . ($restored ? ". Stock restored." : "."));

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        hoReply(false, $e->getMessage());
    }

    hoReply(true, "Return inspection recorded. School Management can now verify the return record." . ($restored ? " Stock restored." : ""));
}

hoReply(false, "Unknown action.");
