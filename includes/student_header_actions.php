<?php
/**
 * Student header actions (notification bell + profile avatar).
 *
 * Drop-in replacement for the inline `.profile-area` block used by the
 * Student Dashboard pages. Include it from any student page *inside*
 * the existing `.top-header`:
 *
 *   <?php include "../includes/student_header_actions.php"; ?>
 *
 * Data comes from the existing `notifications` table, scoped to the
 * authenticated student. Styling lives in css/student-header.css and the
 * behaviour in js/student-header.js.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . "/../config/database.php";
}

$sh_user_id = (int)($_SESSION["user_id"] ?? 0);

// Keep the existing circular-avatar initial.
$sh_first = trim((string)($_SESSION["first_name"] ?? ""));
if ($sh_first === "") {
    $sh_row = null;
    if ($sh_user_id > 0 && isset($conn)) {
        $sh_stmt = $conn->prepare("SELECT first_name FROM users WHERE user_id = ?");
        if ($sh_stmt) {
            $sh_stmt->bind_param("i", $sh_user_id);
            $sh_stmt->execute();
            $sh_row = $sh_stmt->get_result()->fetch_assoc();
            $sh_stmt->close();
        }
    }
    $sh_first = trim((string)($sh_row["first_name"] ?? ""));
}
$sh_initial = $sh_first !== "" ? strtoupper(mb_substr($sh_first, 0, 1, "UTF-8")) : "?";

// Unread count straight from the database (0 when the student has none).
$sh_unread = 0;

if ($sh_user_id > 0 && isset($conn)) {
    $sh_unread_stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    if ($sh_unread_stmt) {
        $sh_unread_stmt->bind_param("i", $sh_user_id);
        $sh_unread_stmt->execute();
        $sh_unread = (int)($sh_unread_stmt->get_result()->fetch_assoc()["c"] ?? 0);
        $sh_unread_stmt->close();
    }
}

?>
<div class="profile-area">

    <!-- NOTIFICATION BELL: unread counter only (no dropdown) -->
    <span class="sh-bell" id="shBell" role="status" aria-label="Unread notifications" title="Unread notifications">
        <i class="fa-solid fa-bell"></i>
        <span class="sh-bell-count" id="shBellCount"<?php echo $sh_unread > 0 ? "" : ' style="display:none"'; ?>><?php echo $sh_unread > 9 ? "9+" : (int)$sh_unread; ?></span>
    </span>

    <!-- PROFILE AVATAR (links to the existing Profile page) -->
    <a class="sh-avatar-link" href="profile.php" title="My Profile" aria-label="My Profile">
        <span><?php echo htmlspecialchars($sh_initial); ?></span>
    </a>
</div>
