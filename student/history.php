<?php

session_start();


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {

    header("Location: ../index.php");
    exit();

}


require_once "../config/database.php";


$user_id = $_SESSION["user_id"];



// Rental History reads the same authoritative reservation records used by
// My Reservations and Reservation Details, and reuses the shared state
// helpers so the status shown here can never contradict them.
//
// The previous query filtered to status IN ('completed','returned') only,
// which hid every other real rental state. This one lists the rentals that
// actually happened - finished, active, overdue, declined or cancelled -
// while still leaving pending (not yet started) requests to My Reservations.
//
// return_condition is the condition recorded when an item came back. It is
// deliberately NOT equipment.equipment_condition, which is the item's
// listed condition before the rental started. Using that one would report
// every returned item as "Good" regardless of any inspection.
require_once __DIR__ . "/../includes/reservation_state.php";

$history = $conn->prepare(
    "
    SELECT
        reservations.reservation_id,
        reservations.start_date,
        reservations.end_date,
        reservations.total_price,
        reservations.status,
        reservations.returned_at,
        reservations.return_condition,
        equipment.equipment_name,
        equipment.category
    FROM reservations
    INNER JOIN equipment
    ON reservations.equipment_id = equipment.equipment_id
    WHERE reservations.user_id = ?
      AND LOWER(reservations.status) IN
          ('approved','ongoing','overdue','completed','returned','declined','cancelled')
    ORDER BY reservations.reservation_id DESC"
);



$history->bind_param(
    "i",
    $user_id
);



$history->execute();



$result = $history->get_result();



?>



<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Rental History | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-history.css?v=2">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">


</head>



<body class="new-dashboard hist-page">



<?php $__sbActive = "history"; include __DIR__ . "/../includes/sidebar_student.php"; ?>








<div class="dashboard-content" id="content">



<div class="top-header">


<div>

<h1>

Rental History

</h1>


<p>

View your completed rentals.

</p>


</div>



<?php include "../includes/student_header_actions.php"; ?>



</div>









<div class="reservation-box">


<div class="box-title">

<h3>

Previous Rentals

</h3>

</div>






<table>
    <thead>
        <tr>
            <th>Item</th>
            <th>Dates</th>
            <th>Status</th>
            <th>Condition</th>
        </tr>
    </thead>
    <tbody>
    <?php if($result && $result->num_rows > 0): ?>
    <?php while($row=$result->fetch_assoc()): ?>
        <?php
        // One shared source of truth for the label, so this page can never
        // disagree with My Reservations or Reservation Details.
        $statusLabel = reservationStatusLabel((string)$row["status"]);
        $statusKey   = strtolower((string)$row["status"]);

        // Overdue is derived from the real dates AND the real return record:
        // a rental whose end date has passed is only overdue while nothing
        // has been recorded as returned. A recorded return stops it being
        // overdue regardless of the date.
        $hasReturn = hasReturnRecord($row["returned_at"] ?? null);
        $isOverdue = !$hasReturn
            && in_array($statusKey, ["approved","ongoing","overdue"], true)
            && !empty($row["end_date"])
            && strtotime($row["end_date"]) < strtotime(date("Y-m-d"));

        if ($isOverdue) { $statusKey = "overdue"; $statusLabel = "Overdue"; }

        // Only the recorded return inspection is reported. When none exists
        // the cell says so rather than defaulting to "Good".
        $condition = trim((string)($row["return_condition"] ?? ""));
        $condition = ($condition === "" || stripos($condition,"0000") !== false)
            ? "Not Yet Inspected"
            : $condition;
        $condClass = strtolower(preg_replace('/[^a-z]/i','', $condition));
        ?>
        <tr>
            <td>
                <div class="item-name"><?php echo htmlspecialchars((string)$row["equipment_name"]); ?></div>
                <div class="reservation-id">RES-<?php echo (int)$row["reservation_id"]; ?></div>
            </td>
            <td>
                <div class="res-dates">
                    <?php echo date("M j, Y", strtotime($row["start_date"])); ?><span class="dash">–</span><?php echo date("M j, Y", strtotime($row["end_date"])); ?>
                </div>
            </td>
            <td>
                <span class="status-badge <?php echo htmlspecialchars($statusKey); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
            </td>
            <td>
                <span class="cond-pill <?php echo htmlspecialchars($condClass); ?>"><?php echo htmlspecialchars($condition); ?></span>
            </td>
        </tr>
    <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="4" class="empty-row">
                <i class="fa-solid fa-clock-rotate-left"></i>
                No rental history available.
            </td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>




</div>




</div>












<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
