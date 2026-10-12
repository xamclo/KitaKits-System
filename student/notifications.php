<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {

    header("Location: ../index.php");
    exit();

}


$user_id = $_SESSION["user_id"];





// Opening the Notifications page marks the student's notifications as read,
// so the header bell unread count and the read/unread dots stay in sync.
// Scoped to the signed-in student only - no other record is touched.
require_once __DIR__ . "/../includes/notification_type.php";

// ==============================================================
// READ STATE - changed only on an explicit student action
// ==============================================================
// Opening this page deliberately does NOT mark notifications as read.
// A notification is flagged read only when the student clicks its own
// "Mark as read" control, or presses "Mark all as read". That keeps the
// header bell badge honest and satisfies the requirement that merely
// visiting the page must not clear the unread count.
if (isset($_POST["mark_all_read"])) {
    $markAll = $conn->prepare(
        "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
    );
    $markAll->bind_param("i", $user_id);
    $markAll->execute();
    $markAll->close();

    header("Location: notifications.php");
    exit();
}

if (isset($_POST["mark_read_id"])) {
    $oneId = intval($_POST["mark_read_id"]);

    // Scoped by user_id, so a student can never mark somebody else's row.
    $markOne = $conn->prepare(
        "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?"
    );
    $markOne->bind_param("ii", $oneId, $user_id);
    $markOne->execute();
    $markOne->close();

    // Redirect after the write so a refresh cannot re-post it.
    $backTab = $_POST["tab"] ?? "all";
    if (!in_array($backTab, ["all", "unread", "read"], true)) {
        $backTab = "all";
    }
    header("Location: notifications.php?tab=" . $backTab);
    exit();
}

// ==============================================================
// TABS + RECORDS
// ==============================================================
$activeTab = $_GET["tab"] ?? "all";
if (!in_array($activeTab, ["all", "unread", "read"], true)) {
    $activeTab = "all";
}

// The bell badge and the Unread tab must use the same source, so the
// count is taken from the notifications table with the same predicate.
$unreadCount = 0;
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND (is_read IS NULL OR is_read = 0)"
);
$countStmt->bind_param("i", $user_id);
$countStmt->execute();
if ($crow = $countStmt->get_result()->fetch_assoc()) {
    $unreadCount = (int)($crow["c"] ?? 0);
}
$countStmt->close();

// The tab filter is applied in SQL so each tab shows exactly the records
// it should. Read/unread state is never faked in the markup.
switch ($activeTab) {
    case "unread":
        $tabWhere = " AND (n.is_read IS NULL OR n.is_read = 0)";
        break;
    case "read":
        $tabWhere = " AND n.is_read = 1";
        break;
    default:
        $tabWhere = "";
}

$notificationQuery = $conn->prepare(
    "SELECT n.notification_id, n.message, n.is_read, n.created_at
     FROM notifications n
     WHERE n.user_id = ?" . $tabWhere . "
     ORDER BY n.created_at DESC, n.notification_id DESC"
);

// Use the database clock for the relative timestamps so "x ago" always
// agrees with the created_at value MySQL stored.
$nowTs = null;
if ($nowRow = $conn->query("SELECT UNIX_TIMESTAMP(NOW()) AS ts")->fetch_assoc()) {
    $nowTs = (int)($nowRow["ts"] ?? 0);
    if ($nowTs <= 0) { $nowTs = null; }
}

$notificationQuery->bind_param("i", $user_id);
$notificationQuery->execute();
$notifications = $notificationQuery->get_result();



?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<title>Notifications | KitaKits</title>

<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-notifications.css?v=2">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">


</head>


<body class="new-dashboard notif-page">


<?php $__sbActive = "notifications"; include __DIR__ . "/../includes/sidebar_student.php"; ?>





<div class="dashboard-content" id="content">


<div class="top-header">

<div>

<h1>

Notifications

</h1>


<p>

Stay updated with your rental activities.

</p>


</div>


<?php include "../includes/student_header_actions.php"; ?>


</div>







<div class="notification-box">


<div class="box-title">

<h3>

Recent Notifications

</h3>

</div>





<!-- TABS: All / Unread / Read. The unread count comes from the same rows the header bell counts. -->
<div class="notif-tabs">
<?php
$tabMeta = ["all"=>["All",null], "unread"=>["Unread",$unreadCount], "read"=>["Read",null]];
foreach ($tabMeta as $key => $meta):
    $cls = ($activeTab === $key) ? "notif-tab active" : "notif-tab";
?>
    <a class="<?php echo $cls; ?>" href="notifications.php?tab=<?php echo $key; ?>">
        <?php echo $meta[0]; ?>
        <?php if ($key === "unread" && $unreadCount > 0): ?>
            <span class="notif-tab-count"><?php echo (int)$unreadCount; ?></span>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
</div>

<div class="notification-list">
<?php if ($notifications && $notifications->num_rows > 0): ?>
    <?php while ($row = $notifications->fetch_assoc()): ?>
        <?php
        $isUnread = ($row["is_read"] === null || (int)$row["is_read"] === 0);
        $type     = notificationType((string)$row["message"]);
        $ago      = notificationTimeAgo((string)$row["created_at"], $nowTs);
        ?>
        <div class="notif-row <?php echo $isUnread ? "unread" : "read"; ?>">
            <span class="notif-icon <?php echo htmlspecialchars($type["type"]); ?>">
                <i class="fa-solid <?php echo htmlspecialchars($type["icon"]); ?>"></i>
            </span>
            <div class="notif-body">
                <div class="notif-msg"><?php echo htmlspecialchars((string)$row["message"]); ?></div>
                <div class="notif-meta">
                    <span class="notif-type"><?php echo htmlspecialchars($type["label"]); ?></span>
                    <span class="notif-sep">&middot;</span>
                    <span class="notif-time" title="<?php echo date("M j, Y g:i A", strtotime($row["created_at"])); ?>"><?php echo htmlspecialchars($ago); ?></span>
                </div>
            </div>
            <?php if ($isUnread): ?>
                <form method="POST" class="notif-action">
                    <input type="hidden" name="mark_read_id" value="<?php echo (int)$row["notification_id"]; ?>">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                    <button type="submit" class="notif-mark">Mark as read</button>
                </form>
            <?php else: ?>
                <span class="notif-readtag">Read</span>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
<?php else: ?>
    <div class="notif-empty">
        <i class="fa-solid fa-bell"></i>
        <?php
        echo $activeTab === "unread" ? "You have no unread notifications."
            : ($activeTab === "read" ? "No read notifications yet." : "No notifications yet.");
        ?>
    </div>
<?php endif; ?>
</div>

<?php if ($unreadCount > 0): ?>
<form method="POST" class="notif-markall-wrap">
    <button type="submit" name="mark_all_read" class="notif-markall">Mark all as read</button>
</form>
<?php endif; ?>



</div>




</div>



<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
