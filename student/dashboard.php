<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student"){

    header("Location: ../index.php");
    exit();

}


$user_id = $_SESSION["user_id"];



// ==============================
// STATISTICS
// ==============================


// TOTAL RENTALS

$totalQuery = $conn->prepare(
"
SELECT COUNT(*) AS total

FROM reservations

WHERE user_id=?

"
);


$totalQuery->bind_param(
"i",
$user_id
);


$totalQuery->execute();


$totalRentals = $totalQuery
->get_result()
->fetch_assoc()["total"] ?? 0;





// UPCOMING RESERVATIONS

$upcomingQuery = $conn->prepare(
"
SELECT COUNT(*) AS total

FROM reservations

WHERE user_id=?

AND status IN ('pending','approved')

AND start_date >= CURDATE()

"
);


$upcomingQuery->bind_param(
"i",
$user_id
);


$upcomingQuery->execute();



$upcoming = $upcomingQuery
->get_result()
->fetch_assoc()["total"] ?? 0;
// OVERDUE RENTALS

// An item is overdue when the rental has already started, its return

// date has passed and nothing has been returned yet. This card used

// to print a hardcoded 0, so it could never be correct.

$overdueQuery = $conn->prepare(

"
SELECT COUNT(*) AS total

FROM reservations

WHERE user_id=?

AND LOWER(status) IN ('approved','ongoing','overdue')

AND start_date <= CURDATE()

AND end_date < CURDATE()

AND (returned_at IS NULL)

"

);

$overdueQuery->bind_param(

"i",

$user_id

);

$overdueQuery->execute();

$overdueRentals = $overdueQuery
->get_result()
->fetch_assoc()["total"] ?? 0;






// ONGOING RENTALS

$activeQuery = $conn->prepare(
"
SELECT COUNT(*) AS total

FROM reservations

WHERE user_id=?

AND status='approved'

AND CURDATE() BETWEEN start_date AND end_date

"
);


$activeQuery->bind_param(
"i",
$user_id
);


$activeQuery->execute();


$activeRentals = $activeQuery
->get_result()
->fetch_assoc()["total"] ?? 0;







// ==============================
// RESERVATION LIST
// ==============================


$reservations = $conn->prepare(
"
SELECT

equipment.equipment_name,

reservations.start_date,

reservations.end_date,

reservations.status


FROM reservations


INNER JOIN equipment

ON reservations.equipment_id = equipment.equipment_id


WHERE reservations.user_id=?


AND reservations.status IN ('pending','approved')


ORDER BY reservations.start_date ASC


LIMIT 5

"
);



$reservations->bind_param(
"i",
$user_id
);



$reservations->execute();


$reservationList = $reservations->get_result();








// ==============================
// NOTIFICATIONS
// ==============================


$notificationQuery = $conn->prepare(
"
SELECT *

FROM notifications

WHERE user_id=?

ORDER BY created_at DESC

LIMIT 4

"
);



$notificationQuery->bind_param(
"i",
$user_id
);



$notificationQuery->execute();


$notificationList = $notificationQuery->get_result();



?>

<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>KitaKits Dashboard</title>



<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">



<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-header.css">

<!-- Sidebar branding only, so the student brand matches the admin one.
     Loaded last so it can override style.css. -->
<link rel="stylesheet" href="../css/student-brand.css?v=<?php echo @filemtime(__DIR__ . "/../css/student-brand.css"); ?>">



</head>



<body class="new-dashboard">





<!-- SIDEBAR -->

<?php $__sbActive = "dashboard"; include __DIR__ . "/../includes/sidebar_student.php"; ?>







<!-- MAIN CONTENT -->

<div class="dashboard-content" id="content">





<div class="top-header">


<div>

<h1>
Welcome,
<?php echo htmlspecialchars($_SESSION["first_name"]); ?> 👋
</h1>


<p>
Here's what's happening with your rentals.
</p>


</div>





<?php include "../includes/student_header_actions.php"; ?>




</div>








<!-- ==========================
STAT CARDS
========================== -->


<div class="stats">





<div class="stat-card">


<div class="stat-icon upcoming">

<i class="fa-regular fa-calendar"></i>

</div>



<h4>
Upcoming Reservations
</h4>



<strong>

<?php echo $upcoming; ?>

</strong>



</div>








<div class="stat-card">


<div class="stat-icon ongoing">

<i class="fa-solid fa-rotate"></i>

</div>



<h4>
Ongoing Rental
</h4>



<strong>

<?php echo $activeRentals; ?>

</strong>



</div>








<div class="stat-card">


<div class="stat-icon overdue">

<i class="fa-solid fa-triangle-exclamation"></i>

</div>



<h4>
Overdue Items
</h4>



<strong>
<?php echo $overdueRentals; ?>
</strong>



</div>








<div class="stat-card">


<div class="stat-icon total">

<i class="fa-solid fa-clock-rotate-left"></i>

</div>



<h4>
Total Rentals
</h4>



<strong>

<?php echo $totalRentals; ?>

</strong>



</div>






</div>


<!-- ==========================
LOWER DASHBOARD SECTION
========================== -->


<div class="dashboard-bottom">





<!-- RESERVATIONS TABLE -->

<div class="reservation-section">


<div class="reservation-box">


<div class="box-title">


<h3>
Upcoming Reservations
</h3>



<a href="reservations.php">
View All
</a>


</div>







<table>


<thead>


<tr>


<th>
Item
</th>


<th>
Pickup
</th>


<th>
Return
</th>


<th>
Status
</th>


</tr>


</thead>







<tbody>



<?php if($reservationList->num_rows > 0): ?>



<?php while($row=$reservationList->fetch_assoc()): ?>



<tr>



<td>

<?php

echo htmlspecialchars(
$row["equipment_name"]
);

?>

</td>





<td>

<?php

echo date(
"M d, Y",
strtotime($row["start_date"])
);

?>

</td>





<td>

<?php

echo date(
"M d, Y",
strtotime($row["end_date"])
);

?>

</td>





<td>


<span class="status <?php echo $row["status"]; ?>">


<?php

echo ucfirst(
$row["status"]
);

?>


</span>


</td>




</tr>




<?php endwhile; ?>





<?php else: ?>



<tr>


<td colspan="4">

No upcoming reservations.

</td>


</tr>



<?php endif; ?>





</tbody>


</table>





</div>


</div>









<!-- NOTIFICATIONS -->


<div class="notification-box">


<div class="box-title">


<h3>
Notifications
</h3>



<a href="notifications.php">
View All
</a>


</div>







<div class="notification-list">





<?php if($notificationList->num_rows > 0): ?>




<?php while($notif=$notificationList->fetch_assoc()): ?>





<div class="notification-item <?php echo $notif["is_read"] == 0 ? "unread":"read"; ?>">





<?php if($notif["is_read"] == 0): ?>


<div class="notification-dot"></div>


<?php endif; ?>







<div class="notification-content">



<p>

<?php

echo htmlspecialchars(
$notif["message"]
);

?>

</p>





<span>

<?php

echo date(
"M d, Y",
strtotime(
$notif["created_at"]
)
);

?>

</span>





</div>




</div>





<?php endwhile; ?>






<?php else: ?>



<p class="empty-notification">

No notifications yet.

</p>




<?php endif; ?>





</div>


</div>






</div>
<!-- END DASHBOARD-BOTTOM -->






</div>
<!-- END CONTENT -->

<!-- The logout dialog is provided by includes/sidebar_student.php and
     driven by js/student-sidebar.js, so it is identical on every
     Student page and is not duplicated here. -->







<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>


</html>
