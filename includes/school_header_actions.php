<?php
/**
 * School Management page header (bell + account block).
 *
 * Every value is read from the signed-in account and from the database.
 * The unread badge is the real notification count for this user.
 */
$__smUid = (int)($_SESSION["user_id"] ?? 0);

$__smUnread = 0;
if ($__smUid > 0 && isset($conn)) {
    $__smStmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $__smStmt->bind_param("i", $__smUid);
    $__smStmt->execute();
    $__smUnread = (int)(($__smStmt->get_result()->fetch_assoc()["c"] ?? 0));
    $__smStmt->close();
}

$__smFirst = trim((string)($_SESSION["first_name"] ?? "School"));
$__smLast  = trim((string)($_SESSION["last_name"] ?? ""));
$__smFull  = trim($__smFirst . " " . $__smLast);
$__smInit  = strtoupper(mb_substr($__smFirst !== "" ? $__smFirst : "S", 0, 1));
?>
<div class="sm-head-right">

    <button class="sm-bell" type="button" id="smBell" title="Notifications" aria-label="Notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if ($__smUnread > 0): ?>
            <span class="dot" id="smBellDot"><?php echo $__smUnread > 99 ? "99+" : $__smUnread; ?></span>
        <?php else: ?>
            <span class="dot hidden" id="smBellDot"></span>
        <?php endif; ?>
    </button>

    <div class="sm-account">
        <div class="sm-avatar" id="smAvatar"><?php echo htmlspecialchars($__smInit); ?></div>
        <div>
            <div class="sm-account-name" id="smAcctName"><?php echo htmlspecialchars($__smFull); ?></div>
            <div class="sm-account-role">School Administrator</div>
        </div>
        <i class="fa-solid fa-chevron-down sm-caret"></i>
    </div>
</div>