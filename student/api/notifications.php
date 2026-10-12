<?php
/**
 * Student header notification API.
 *
 * Reuses the existing `notifications` table. Every query is scoped to the
 * authenticated student (session user_id) so no other user's data is exposed.
 *
 * GET  ?action=summary  -> unread_count, latest_id, recent[]
 * POST ?action=read_all -> marks the student's notifications as read
 */

session_start();
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json");

if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {
    http_response_code(401);
    echo json_encode(["error" => "unauthorized"]);
    exit();
}

$user_id = (int)$_SESSION["user_id"];
$action  = $_GET["action"] ?? "summary";

/**
 * Derive a display type from the stored message.
 * Classification only - the message text itself always comes from the
 * database, nothing is hardcoded or invented here.
 */
function notificationType(string $message): array
{
    $m = strtolower($message);

    if (strpos($m, "reject") !== false || strpos($m, "declin") !== false) {
        return ["rejected", "fa-circle-xmark", "Rejected"];
    }
    if (strpos($m, "approv") !== false) {
        return ["approved", "fa-circle-check", "Approved"];
    }
    if (strpos($m, "payment") !== false || strpos($m, "paid") !== false || strpos($m, "receipt") !== false) {
        return ["payment", "fa-receipt", "Payment"];
    }
    if (strpos($m, "overdue") !== false || strpos($m, "late") !== false) {
        return ["overdue", "fa-triangle-exclamation", "Overdue"];
    }
    if (strpos($m, "return") !== false || strpos($m, "due") !== false) {
        return ["return", "fa-clock-rotate-left", "Return"];
    }
    return ["info", "fa-bell", "Notice"];
}

if ($action === "read_all") {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "success"      => true,
        "unread_count" => 0,
    ]);
    exit();
}

// ---------- DEFAULT: summary ----------

$unreadStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->bind_param("i", $user_id);
$unreadStmt->execute();
$unreadCount = (int)(($unreadStmt->get_result()->fetch_assoc()["c"] ?? 0));
$unreadStmt->close();

$recentStmt = $conn->prepare(
    "SELECT notification_id, message, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY notification_id DESC
     LIMIT 6"
);
$recentStmt->bind_param("i", $user_id);
$recentStmt->execute();
$recentResult = $recentStmt->get_result();

$recent = [];
$latestId = 0;

while ($row = $recentResult->fetch_assoc()) {
    $id  = (int)$row["notification_id"];
    $msg = (string)$row["message"];
    $ts  = (string)$row["created_at"];

    if ($id > $latestId) {
        $latestId = $id;
    }

    list($type, $icon, $label) = notificationType($msg);

    $recent[] = [
        "id"         => $id,
        "message"    => $msg,
        "is_read"    => (int)$row["is_read"] === 1,
        "created_at" => $ts,
        "time"       => date("M d, Y g:i A", strtotime($ts)),
        "type"       => $type,
        "icon"       => $icon,
        "label"      => $label,
    ];
}

$recentStmt->close();

echo json_encode([
    "unread_count" => $unreadCount,
    "latest_id"    => $latestId,
    "recent"       => $recent,
]);
