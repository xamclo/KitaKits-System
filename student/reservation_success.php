<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student"){

    header("Location: ../index.php");
    exit();

}

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reservation Submitted | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">


</head>


<body class="new-dashboard">



<!-- SIDEBAR -->

<?php $__sbActive = "reservations"; include __DIR__ . "/../includes/sidebar_student.php"; ?>





<!-- MAIN CONTENT -->


<div class="dashboard-content" id="content">



<div class="top-header">


<h1>
Reservation Submitted
</h1>


<?php include "../includes/student_header_actions.php"; ?>


</div>






<div class="success-wrapper">



<div class="success-card">



<div class="success-icon">

<i class="fa-solid fa-check"></i>

</div>





<h1>

Reservation Submitted!

</h1>





<p>

Your equipment reservation request has been submitted successfully.

You will receive a notification once it has been reviewed by the administrator.

</p>







<a href="reservations.php" class="submit-reservation">


View My Reservations


</a>




</div>



</div>





</div>







<script>


</script>



<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
