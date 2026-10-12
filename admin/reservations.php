<?php

session_start();

require_once "../config/database.php";
require_once "../includes/school_auth.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin") {

header("Location: ../index.php");
exit;

}

$user_id = (int)$_SESSION["user_id"];

// APPROVE / REJECT - redirect to the proper API endpoints
// The actual approve/decline logic lives in admin/api/rental-request.php
// and admin/rental_request.php to avoid duplicate stock deduction.


if(isset($_GET["action"]) && isset($_GET["id"])) {

    $reservation_id = (int)$_GET["id"];
    $action = $_GET["action"];

    // Redirect to the proper handler that includes stock checks,
    // idempotency guards, notifications, and audit logging.
    if ($action === "approve") {
        header("Location: rental_request.php?approve=" . $reservation_id);
        exit;
    }
    if ($action === "reject") {
        header("Location: rental_request.php?decline=" . $reservation_id);
        exit;
    }

    header("Location: reservations.php");
    exit;

}


$result = $conn->query(
"
SELECT
reservations.reservation_id,
users.first_name,
users.last_name,
equipment.equipment_name,
reservations.quantity,
reservations.start_date,
reservations.end_date,
reservations.total_price,
reservations.status,
equipment.requires_school_approval,
(SELECT a.status FROM school_approval_requests a WHERE a.reservation_id = reservations.reservation_id LIMIT 1) AS school_status


FROM reservations


JOIN users
ON reservations.user_id = users.user_id


JOIN equipment
ON reservations.equipment_id = equipment.equipment_id
WHERE equipment.owner_id = $user_id
ORDER BY reservations.created_at DESC"
);


?>

<!DOCTYPE html>

<html>

<head>

<title>Reservations</title>
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="../css/style.css">
<?php include __DIR__ . "/includes/assets.php"; ?>


</head>


<body class="new-dashboard">

<!-- SIDEBAR -->
<?php include __DIR__ . "/includes/sidebar.php"; ?>

<!-- CONTENT -->

<div class="dashboard-content" id="content">


<div class="top-header">
<div>
<h1>
Manage Reservations
</h1>
</div>
</div>

<div class="reservation-box">
<table>

<tr>

<th>Student</th>
<th>Equipment</th>
<th>Quantity</th>
<th>Date</th>
<th>Total</th>
<th>Status</th>
<th>Action</th>

</tr>



<?php while($row=$result->fetch_assoc()): ?>


<tr>


<td>
<?php echo $row["first_name"]." ".$row["last_name"]; ?>
</td>


<td>
<?php echo $row["equipment_name"]; ?>
</td>


<td>
<?php echo $row["quantity"]; ?>
</td>


<td>
<?php echo $row["start_date"]; ?>
-
<?php echo $row["end_date"]; ?>
</td>


<td>
&#8369;<?php echo number_format($row["total_price"],2); ?>
</td>


<td>
<?php echo $row["status"]; ?>
</td>




<td>

<?php if($row["status"]=="pending"): ?>


<a href="?action=approve&id=<?php echo $row["reservation_id"]; ?>">
Approve
</a>


|
<a href="?action=reject&id=<?php echo $row["reservation_id"]; ?>">
Reject
</a>
<?php elseif($row["status"]=="approved" && !empty($row["requires_school_approval"]) && empty($row["school_status"])): ?>

|
<a href="../admin/api/school-approval.php?action=request_approval&id=<?php echo $row["reservation_id"]; ?>" style="color:#315FD3;">
Submit for School Witness
</a>
<?php else: ?>

Done
<?php endif; ?>

</td>

</tr>


<?php endwhile; ?>


</table>

</div>

</body>

</html>