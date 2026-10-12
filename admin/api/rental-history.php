<?php
/**
 * Rental History endpoint for the admin dashboard.
 *
 * ADDITION ONLY. Nothing here changes how reservations are created,
 * approved or declined - it only records that equipment came back, and
 * that is what the Rental History table reads.
 *
 * The two fields it writes were added to the existing `reservations`
 * table (no new table was created):
 *
 *   returned_at       DATETIME NULL  - when the equipment came back
 *   return_condition  VARCHAR(50)    - Excellent | Good | Fair | Damaged
 *
 * Every statement is owner-scoped through the equipment join, so an
 * admin can never read or update another admin's rentals.
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

function reply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

$action = $_POST["action"] ?? ($_GET["action"] ?? "");

const RETURN_CONDITIONS = ["Excellent", "Good", "Fair", "Damaged"];

/**
 * Shared SELECT. history_state is computed in SQL so the table, the
 * badges and the summary can never disagree:
 *
 *   returned -> returned_at recorded
 *   overdue  -> end_date passed and nothing returned yet
 *   ongoing  -> still inside (or before) the rental window
 */
const RH_SELECT = "
    SELECT
        r.reservation_id,
        r.quantity,
        r.start_date,
        r.end_date,
        r.status,
        r.returned_at,
        r.return_condition,
        u.first_name,
        u.last_name,
        e.equipment_id,
        e.equipment_name,
        COALESCE(
            (SELECT t.payment_status
               FROM transactions t
              WHERE t.reservation_id = r.reservation_id
              ORDER BY t.payment_date DESC
              LIMIT 1),
            'unpaid'
        ) AS payment_status,
        CASE
            WHEN r.returned_at IS NOT NULL THEN 'returned'
            WHEN r.end_date < CURDATE()     THEN 'overdue'
            ELSE 'ongoing'
        END AS history_state
    FROM reservations r
    INNER JOIN users u     ON r.user_id = u.user_id
    INNER JOIN equipment e ON r.equipment_id = e.equipment_id
";

/* ------------------------------------------------------------------
   LIST
------------------------------------------------------------------ */
if ($action === "list") {
    $stmt = $conn->prepare(
        RH_SELECT . "
        WHERE e.owner_id = ?
          AND r.status IN ('approved','completed')
        ORDER BY r.reservation_id DESC"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    reply(true, "History loaded.", ["history" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

/* ------------------------------------------------------------------
   FETCH ONE - fills the Mark Returned modal
------------------------------------------------------------------ */
if ($action === "fetch") {
    $id = (int)($_POST["id"] ?? 0);

    if ($id < 1) {
        reply(false, "Invalid reservation id.");
    }

    $stmt = $conn->prepare(RH_SELECT . " WHERE r.reservation_id = ? AND e.owner_id = ?");
    $stmt->bind_param("ii", $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Reservation not found.");
    }

    reply(true, "Rental loaded.", ["request" => $row]);
}

/* ------------------------------------------------------------------
   MARK RETURNED
   Records the return date and the condition the equipment came back
   in. This is the only write this module performs, and it only fills
   in the two new nullable columns.
------------------------------------------------------------------ */
if ($action === "mark_returned") {
    $id = (int)($_POST["id"] ?? 0);
    $condition = trim($_POST["return_condition"] ?? "");
    $returnedAt = trim($_POST["returned_at"] ?? "");

    if ($id < 1) {
        reply(false, "Invalid reservation id.");
    }

    if (!in_array($condition, RETURN_CONDITIONS, true)) {
        reply(false, "Please choose a valid return condition.");
    }

    // Default to now; the admin may override with the actual return date.
    $date = $returnedAt !== "" ? $returnedAt : date("Y-m-d H:i:s");

    if (strtotime($date) === false) {
        reply(false, "Please enter a valid return date.");
    }

    $check = $conn->prepare(
        "SELECT r.status, r.returned_at, r.return_condition, r.quantity, r.equipment_id,
                e.requires_school_approval
         FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         WHERE r.reservation_id = ? AND e.owner_id = ?"
    );
    $check->bind_param("ii", $id, $userId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();

    if (!$row) {
        reply(false, "Reservation not found.");
    }

    if ($row["status"] !== "approved" && $row["status"] !== "completed") {
        reply(false, "Only approved rentals can be marked as returned.");
    }

    if ($row["returned_at"] !== null && $row["return_condition"] !== null) {
        reply(false, "This rental has already been marked as returned.");
    }

    /* ------------------------------------------------------------------
       RECORDING THE RETURN

       The student marks the item returned first, which writes returned_at.
       The admin then verifies it here and supplies the condition.

       The duplicate guard above is therefore keyed on return_condition, not
       returned_at. Keying it on returned_at (as this file originally did)
       made the two steps mutually exclusive: once the student submitted the
       return, the admin could never verify it, so the condition was never
       recorded, the stock was never returned and the rental could never be
       closed.

       returned_at is only written when the student has not already set it,
       so the real moment of return is never overwritten.
    ------------------------------------------------------------------ */
    $conn->begin_transaction();

    try {
        $update = $conn->prepare(
            "UPDATE reservations
             SET returned_at = COALESCE(returned_at, ?),
                 return_condition = ?,
                 fulfillment_status = 'return_submitted'
             WHERE reservation_id = ?"
        );
        $update->bind_param("ssi", $date, $condition, $id);

        if (!$update->execute()) {
            throw new RuntimeException("return update failed: " . $update->error);
        }

        // Give the reserved units back to the equipment stock.
        // IDEMPOTENT: only restore stock if this reservation hasn't been
        // completed yet (status still 'approved'). The handover API's
        // inspect_return action may have already restored stock when the
        // admin physically inspected the returned equipment. Once the
        // reservation is 'completed', stock has already been restored.
        $restored = false;
        if (strtolower($row["status"]) === "approved") {
            $restockQty = (int)$row["quantity"];
            $restockEquipmentId = (int)$row["equipment_id"];

            $restock = $conn->prepare(
                "UPDATE equipment
                 SET available_quantity = available_quantity + ?
                 WHERE equipment_id = ?"
            );
            $restock->bind_param("ii", $restockQty, $restockEquipmentId);

            if (!$restock->execute()) {
                throw new RuntimeException("restock failed: " . $restock->error);
            }
            $restored = $restock->affected_rows > 0;
            $restock->close();
        }

        $update->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Rental history return failed: " . $e->getMessage());
        reply(false, "Could not save the return. Please try again.");
    }

    /* ------------------------------------------------------------------
       OPEN A SCHOOL VERIFICATION

       High-value equipment needs an independent institutional check before
       the rental is closed. Opening the record here keeps the owner's
       inspection and the school's verification clearly separate: the owner
       has now recorded a return, the school has not yet verified it.

       INSERT IGNORE means a second owner submission can never create a
       second verification row.
    ------------------------------------------------------------------ */
    if ((int)$row["requires_school_approval"] === 1) {
        $open = $conn->prepare(
            "INSERT IGNORE INTO school_return_verifications
                (reservation_id, condition_report, status)
             VALUES (?, ?, 'pending')"
        );
        $report = trim((string)($_POST["condition_report"] ?? $condition));
        $open->bind_param("is", $id, $report);
        $open->execute();
        $open->close();

        $notify = $conn->prepare(
            "SELECT user_id FROM users WHERE role = 'school_management' AND status = 'active'"
        );
        $notify->execute();
        $res = $notify->get_result();
        while ($s = $res->fetch_assoc()) {
            $sid = (int)$s["user_id"];
            $msg = "Return awaiting School Management verification for RES-" . $id . ".";
            $n = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $n->bind_param("is", $sid, $msg);
            $n->execute();
            $n->close();
        }
        $notify->close();
    }


        auditEvent($conn, $id, (int)($_SESSION["user_id"] ?? 0),
            "owner_inspection", "return_submitted", "for_verification",
            "Owner recorded the return condition; School Management verification opened.");

    reply(true, "Rental marked as returned successfully.");
}

reply(false, "Unknown action.");

