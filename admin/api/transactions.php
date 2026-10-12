<?php
/**
 * Transactions endpoint for the admin dashboard.
 *
 * ADDITION ONLY. It reads the existing `transactions` table and joins
 * through `reservations` to reach the renter and the equipment, because
 * `transactions.user_id` is NULL for the rows the approve flow creates.
 *
 * No schema change was needed - transactions already stores
 * transaction_id, reservation_id, amount, payment_status, payment_method
 * and payment_date.
 *
 * Every statement is owner-scoped through the equipment join.
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

/**
 * Shared SELECT. is_paid is resolved in SQL so the badge and the action
 * button can never disagree with what is stored.
 */
const TX_SELECT = "
    SELECT
        t.transaction_id,
        t.reservation_id,
        t.amount,
        t.payment_status,
        t.payment_method,
        t.payment_date,
        r.start_date,
        r.end_date,
        r.status AS reservation_status,
        u.first_name,
        u.last_name,
        e.equipment_id,
        e.equipment_name,
        CASE WHEN LOWER(t.payment_status) = 'paid' THEN 1 ELSE 0 END AS is_paid
    FROM transactions t
    INNER JOIN reservations r ON t.reservation_id = r.reservation_id
    INNER JOIN users u        ON r.user_id = u.user_id
    INNER JOIN equipment e    ON r.equipment_id = e.equipment_id
";

/* ------------------------------------------------------------------
   LIST
------------------------------------------------------------------ */
if ($action === "list") {
    $stmt = $conn->prepare(
        TX_SELECT . " WHERE e.owner_id = ? ORDER BY t.transaction_id DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    reply(true, "Transactions loaded.", ["transactions" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

/* ------------------------------------------------------------------
   FETCH ONE - fills the receipt modal
------------------------------------------------------------------ */
if ($action === "fetch") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid transaction id.");
    }

    $stmt = $conn->prepare(TX_SELECT . " WHERE t.transaction_id = ? AND e.owner_id = ?");
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Transaction not found.");
    }

    reply(true, "Transaction loaded.", ["transaction" => $row]);
}

/* ------------------------------------------------------------------
   MARK AS PAID
   Sets the existing payment_status column. Nothing else is written, so
   the student payments page and this page stay in sync.
------------------------------------------------------------------ */
if ($action === "mark_paid") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid transaction id.");
    }

    $check = $conn->prepare(
        "SELECT t.payment_status
         FROM transactions t
         INNER JOIN reservations r ON t.reservation_id = r.reservation_id
         INNER JOIN equipment e    ON r.equipment_id = e.equipment_id
         WHERE t.transaction_id = ? AND e.owner_id = ?"
    );
    $check->bind_param("ii", $id, $userId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Transaction not found.");
    }

    if (strtolower($row["payment_status"]) === "paid") {
        reply(false, "This transaction is already marked as Paid.");
    }

    $update = $conn->prepare(
        "UPDATE transactions SET payment_status = 'paid' WHERE transaction_id = ?"
    );
    $update->bind_param("i", $id);

    if (!$update->execute()) {
        error_log("Transaction mark-paid failed: " . $update->error);
        reply(false, "Could not update the payment. Please try again.");
    }

    $update->close();

    /* ------------------------------------------------------------------
       TELL THE STUDENT

       Every other rental event (approve, decline, return) already writes a
       notification for this student. A confirmed payment did not, so the
       student had no way to learn that a payment was recorded other than
       reloading the Payments page. Nothing is invented here - it only
       reports the transaction that was just updated.
    ------------------------------------------------------------------ */
    $notice = $conn->prepare(
        "SELECT r.user_id, e.equipment_name, t.amount, t.reservation_id
         FROM transactions t
         INNER JOIN reservations r ON t.reservation_id = r.reservation_id
         INNER JOIN equipment e   ON r.equipment_id = e.equipment_id
         WHERE t.transaction_id = ?"
    );
    $notice->bind_param("i", $id);

    if ($notice->execute()) {
        $paid = $notice->get_result()->fetch_assoc();

        if ($paid && !empty($paid["user_id"])) {
            $msg = "Payment of PHP " . number_format((float)$paid["amount"], 2)
                 . " for " . $paid["equipment_name"]
                 . " (RES-" . (int)$paid["reservation_id"] . ") has been confirmed.";

            // Copied into real variables: bind_param() cannot bind an
            // inline expression such as (int)$paid["user_id"].
            $notifyUser = (int)$paid["user_id"];

            $notif = $conn->prepare(
                "INSERT INTO notifications (user_id, message) VALUES (?, ?)"
            );
            $notif->bind_param("is", $notifyUser, $msg);
            $notif->execute();
            $notif->close();
        }
    }

    $notice->close();

    reply(true, "Payment status updated to Paid.");
}

reply(false, "Unknown action.");

