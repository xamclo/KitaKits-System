<?php
/**
 * Sidebar User Identifier
 * Displays the logged-in user's avatar initial, full name and role.
 * Include right after the sidebar brand block and before the Dashboard link:
 *
 *   <?php include "../includes/sidebar_user.php"; ?>
 *
 * Requires an active session (user_id, role_view) and $conn from config/database.php.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . "/../config/database.php";
}

$sb_user_id = (int)($_SESSION["user_id"] ?? 0);
$sb_first = (string)($_SESSION["first_name"] ?? "");
$sb_last = (string)($_SESSION["last_name"] ?? "");
$sb_role_view = (string)($_SESSION["role_view"] ?? "student");

// Always refresh identity from the database using the active session user ID
if ($sb_user_id > 0 && isset($conn)) {
    $sb_stmt = $conn->prepare(
        "SELECT first_name, last_name FROM users WHERE user_id = ?"
    );

    if ($sb_stmt) {
        $sb_stmt->bind_param("i", $sb_user_id);
        $sb_stmt->execute();
        $sb_row = $sb_stmt->get_result()->fetch_assoc();

        if ($sb_row) {
            $sb_first = $sb_row["first_name"] ?? $sb_first;
            $sb_last = $sb_row["last_name"] ?? $sb_last;
        }

        $sb_stmt->close();
    }
}

$sb_full_name = trim($sb_first . " " . $sb_last);
if ($sb_full_name === "") {
    $sb_full_name = "Guest";
}

$sb_initial = strtoupper(mb_substr($sb_full_name, 0, 1, "UTF-8"));
$sb_role_label = ($sb_role_view === "admin") ? "Admin" : "Student";
?>
<div class="user-identifier">
    <div class="user-avatar"><?php echo htmlspecialchars($sb_initial); ?></div>
    <div class="user-meta">
        <span class="user-name"><?php echo htmlspecialchars($sb_full_name); ?></span>
        <span class="user-role"><?php echo htmlspecialchars($sb_role_label); ?></span>
    </div>
</div>
