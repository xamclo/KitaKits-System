<?php
/**
 * Rental Request endpoint for the admin dashboard.
 *
 * This is an ADDITION. It re-implements the same approve/decline rules
 * that already exist in rental_request.php (?approve= / ?decline=):
 *
 *   approve -> check stock, decrement available_quantity,
 *              set status='approved', insert a transaction row,
 *              notify the student
 *   decline -> set status='declined', notify the student
 *
 * The old GET links are left in place and still work; this file only
 * gives the Review modal a way to act without a page reload.
 *
 * Every statement is owner-scoped so an admin cannot touch another
 * admin's reservations.
 */

session_start();
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "admin") {
    http_response_code(403);
    echo json_encode(["ok" => false, "message" => "Unauthorized."]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

function reply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

/* Shared SELECT: renter, equipment, dates, status and latest payment. */
const RR_SELECT = "
    SELECT
        r.reservation_id,
        r.user_id,
        r.quantity,
        r.start_date,
        r.end_date,
        r.status,
        r.total_price,
        u.first_name,
        u.last_name,
        u.student_number,
        e.equipment_id,
        e.equipment_name,
        e.available_quantity,
        e.requires_school_approval,
        a.status AS school_status,
        COALESCE(
            (SELECT t.payment_status
               FROM transactions t
              WHERE t.reservation_id = r.reservation_id
              ORDER BY t.payment_date DESC
              LIMIT 1),
            'unpaid'
        ) AS payment_status
    FROM reservations r
    INNER JOIN users u      ON r.user_id = u.user_id
    INNER JOIN equipment e  ON r.equipment_id = e.equipment_id
    LEFT JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
";

/* ------------------------------------------------------------------
   LIST - the Rental Request table
------------------------------------------------------------------ */
if ($action === "list") {
    $stmt = $conn->prepare(
        RR_SELECT . " WHERE e.owner_id = ? ORDER BY r.reservation_id DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    reply(true, "Requests loaded.", ["requests" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

/* ------------------------------------------------------------------
   FETCH ONE - fills the Review modal
------------------------------------------------------------------ */
if ($action === "fetch") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid reservation id.");
    }

    $stmt = $conn->prepare(RR_SELECT . " WHERE r.reservation_id = ? AND e.owner_id = ?");
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Reservation not found.");
    }

    reply(true, "Request loaded.", ["request" => $row]);
}

/* ------------------------------------------------------------------
   APPROVE
   Mirrors the existing ?approve= handler in rental_request.php so the
   stock maths, transaction row and notification stay identical.
------------------------------------------------------------------ */
if ($action === "approve") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid reservation id.");
    }

    // Load the reservation and confirm this admin owns the equipment.
    $check = $conn->prepare(
        "SELECT r.quantity, r.total_price, r.user_id, r.status,
                e.equipment_id, e.equipment_name, e.available_quantity
         FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         WHERE r.reservation_id = ? AND e.owner_id = ?"
    );
    $check->bind_param("ii", $id, $userId);
    $check->execute();
    $data = $check->get_result()->fetch_assoc();

    if (!$data) {
        reply(false, "Reservation not found.");
    }

    // Already decided - do not double-decrement the stock.
    if ($data["status"] !== "pending") {
        reply(false, "This request has already been " . $data["status"] . ".");
    }

    if ((int)$data["available_quantity"] < (int)$data["quantity"]) {
        reply(false, "Not enough quantity available for this equipment.");
    }

    $conn->begin_transaction();

    try {
        // 1. Take the units out of stock.
        $stock = $conn->prepare(
            "UPDATE equipment
             SET available_quantity = available_quantity - ?
             WHERE equipment_id = ?"
        );
        $stock->bind_param("ii", $data["quantity"], $data["equipment_id"]);
        $stock->execute();

        // 2. Mark the reservation approved.
        $approve = $conn->prepare(
            "UPDATE reservations SET status = 'approved' WHERE reservation_id = ?"
        );
        $approve->bind_param("i", $id);
        $approve->execute();

        // 3. Record the payment owed, same as the original handler.
        // user_id is stored so a transaction can always be traced back to
        // the student who owes it; it used to be left NULL.
        $payment = $conn->prepare(
            "INSERT INTO transactions (reservation_id, user_id, amount, payment_status)
             VALUES (?, ?, ?, 'pending')"
        );
        $payment->bind_param("iid", $id, $data["user_id"], $data["total_price"]);
        $payment->execute();

        // 4. Tell the student.
        $notification = $conn->prepare(
            "INSERT INTO notifications (user_id, message) VALUES (?, ?)"
        );
        $message = "Your rental request for " . $data["equipment_name"] . " was approved by the equipment owner.";
        if ((int)($data["requires_school_approval"] ?? 0) === 1) {
            $message .= " The transaction will now be submitted for School Management witnessing.";
        }
        $notification->bind_param("is", $data["user_id"], $message);
        $notification->execute();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Rental approve failed: " . $e->getMessage());
        reply(false, "Could not approve this reservation. Please try again.");
    }

    reply(true, "Reservation approved successfully.");
}

/* ------------------------------------------------------------------
   REJECT
   Mirrors the existing ?decline= handler, which stores the status as
   'declined'. That value is kept on purpose: rental_history.php and the
   student pages already read it, and the table below labels it
   "Rejected" for the admin.
------------------------------------------------------------------ */
if ($action === "reject") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid reservation id.");
    }

    $info = $conn->prepare(
        "SELECT r.user_id, r.status, e.equipment_name
         FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         WHERE r.reservation_id = ? AND e.owner_id = ?"
    );
    $info->bind_param("ii", $id, $userId);
    $info->execute();
    $row = $info->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Reservation not found.");
    }

    if ($row["status"] !== "pending") {
        reply(false, "This request has already been " . $row["status"] . ".");
    }

    $decline = $conn->prepare(
        "UPDATE reservations SET status = 'declined' WHERE reservation_id = ?"
    );
    $decline->bind_param("i", $id);
    $decline->execute();

    $notification = $conn->prepare(
        "INSERT INTO notifications (user_id, message) VALUES (?, ?)"
    );
    $message = "Your rental request for " . $row["equipment_name"] . " was declined by the equipment owner.";
    $notification->bind_param("is", $row["user_id"], $message);
    $notification->execute();

    reply(true, "Reservation rejected successfully.");
}

reply(false, "Unknown action.");

