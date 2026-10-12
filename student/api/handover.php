<?php
/**
 * Student-side handover confirmation and return submission helper.
 *
 * ADDITION ONLY. The student can confirm that they physically received the
 * equipment, and can see why their rental is still waiting. It never lets a
 * student request a school witness, alter payment, or touch another rental.
 */

session_start();
require_once __DIR__ . "/../../config/database.php";
require_once __DIR__ . "/../../includes/school_auth.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "student") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Unauthorized."]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

function stuReply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/* ------------------------------------------------------------------
   STATE - only this student's own rentals
------------------------------------------------------------------ */
if ($action === "state") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) stuReply(false, "Invalid reservation id.");

    $stmt = $conn->prepare(
        "SELECT r.reservation_id, r.status, r.fulfillment_status,
                r.returned_at, e.equipment_name, e.owner_id,
                e.requires_school_approval,
                h.handover_id, h.condition_at_handover, h.accessories,
                h.borrower_confirmed_at, h.released_at,
                a.status AS school_status
         FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         LEFT JOIN equipment_handover_confirmations h ON h.reservation_id = r.reservation_id
         LEFT JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
         WHERE r.reservation_id = ? AND r.user_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) stuReply(false, "Reservation not found.");

    $canConfirm = !empty($row["handover_id"]) && empty($row["borrower_confirmed_at"])
                  && empty($row["returned_at"]);

    stuReply(true, "Handover state loaded.", ["row" => $row, "can_confirm" => $canConfirm]);
}

/* ------------------------------------------------------------------
   BORROWER CONFIRMS RECEIPT
------------------------------------------------------------------ */
if ($action === "confirm") {
    $id = (int)($_POST["id"] ?? 0);
    if ($id < 1) stuReply(false, "Invalid reservation id.");

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "SELECT h.handover_id, h.borrower_confirmed_at,
                    r.reservation_id, r.user_id AS borrower_id,
                    r.fulfillment_status, r.returned_at, e.equipment_name
             FROM equipment_handover_confirmations h
             INNER JOIN reservations r ON h.reservation_id = r.reservation_id
             INNER JOIN equipment e ON r.equipment_id = e.equipment_id
             WHERE h.reservation_id = ? AND r.user_id = ? FOR UPDATE"
        );
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
        $h = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$h) throw new RuntimeException("No handover is waiting for your confirmation.");
        if (!empty($h["borrower_confirmed_at"])) {
            throw new RuntimeException("You already confirmed this handover.");
        }
        if (!empty($h["returned_at"])) {
            throw new RuntimeException("This rental has already been returned.");
        }

        $hid = (int)$h["handover_id"];
        $upd = $conn->prepare(
            "UPDATE equipment_handover_confirmations
             SET borrower_confirmed_at = NOW()
             WHERE handover_id = ? AND borrower_confirmed_at IS NULL"
        );
        $upd->bind_param("i", $hid);
        $upd->execute();
        if ($upd->affected_rows !== 1) {
            throw new RuntimeException("You already confirmed this handover.");
        }
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

        $ownerId = (int)$h["owner_id"];
        if ($ownerId > 0 && $ownerId !== (int)$h["borrower_id"]) {
            notifyUser($conn, $ownerId,
                "The student confirmed receipt of the equipment for RES-" . $id . ".");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        stuReply(false, $e->getMessage());
    }

    stuReply(true, "Handover confirmed. Your rental is now active.");
}

stuReply(false, "Unknown action.");
