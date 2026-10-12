<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student"){

    header("Location: ../index.php");
    exit();

}



if(!isset($_GET["id"])){

    header("Location: equipment.php");
    exit();

}



$id = $_GET["id"];

$user_id = $_SESSION["user_id"];




// ===============================
// GET EQUIPMENT DETAILS
// ===============================


$stmt = $conn->prepare(

"
SELECT 
equipment.*,

users.first_name AS owner_first_name,
users.last_name AS owner_last_name

FROM equipment

JOIN users

ON equipment.owner_id = users.user_id

WHERE equipment.equipment_id = ?

AND equipment.status = 'available'

AND equipment.available_quantity > 0

AND equipment.owner_id != ?

"

);



$stmt->bind_param(

"ii",

$id,

$user_id

);



$stmt->execute();



$result = $stmt->get_result();



if($result->num_rows == 0){

    header("Location: equipment.php");

    exit();

}



$equipment = $result->fetch_assoc();



?>



<!DOCTYPE html>

<html lang="en">


<head>


<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Equipment Details | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- Equipment Details spacing only. Loaded last so it can override style.css. -->
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">

<link rel="stylesheet" href="../css/equipment-detail.css?v=<?php echo @filemtime(__DIR__ . "/../css/equipment-detail.css"); ?>">


</head>



<body class="new-dashboard">





<!-- SIDEBAR -->

<?php $__sbActive = "equipment"; include __DIR__ . "/../includes/sidebar_student.php"; ?>






<!-- MAIN CONTENT -->


<div class="dashboard-content" id="content">



<div class="top-header">


<div>

<h1>
Equipment Details
</h1>

</div>



<?php include "../includes/student_header_actions.php"; ?>


</div>







<a href="equipment.php" class="back-btn">

<i class="fa-solid fa-arrow-left"></i>

Back to List

</a>








<div class="detail-card">





<div class="detail-image">


<?php if(!empty($equipment["equipment_image"])): ?>


<img 
src="../uploads/<?php echo htmlspecialchars($equipment["equipment_image"]); ?>"
alt="<?php echo htmlspecialchars($equipment["equipment_name"]); ?>">




<?php else: ?>


<i class="fa-solid fa-box-open"></i>


<?php endif; ?>


</div>






<div class="detail-info">



<span class="category">

<?php echo htmlspecialchars($equipment["category"]); ?>

</span>





<h2>

<?php echo htmlspecialchars($equipment["equipment_name"]); ?>

</h2>





<h3>

₱<?php echo number_format($equipment["rental_price"],2); ?>

<span>

/day

</span>

</h3>







<p>

<?php echo htmlspecialchars($equipment["description"]); ?>

</p>

<div class="owner-info">

<strong>
Owner:
</strong>

<?php

echo htmlspecialchars(
$equipment["owner_first_name"]
." ".
$equipment["owner_last_name"]
);

?>

</div>






<div class="detail-stats">



<div>

<strong>
Available:
</strong>

<?php echo (int)($equipment["available_quantity"] ?? 0); ?>

units


</div>





<?php
// The equipment table stores only "available_quantity" (verified against
// information_schema), so a total cannot be derived reliably. The row is
// omitted instead of printing an invented value or a PHP warning.
$detailTotal = null;
foreach (["total_quantity", "quantity"] as $detailKey) {
    if (isset($equipment[$detailKey]) && $equipment[$detailKey] !== "" && $equipment[$detailKey] !== null) {
        $detailTotal = (int)$equipment[$detailKey];
        break;
    }
}
if ($detailTotal !== null): ?>
<div>
<strong>
Total Quantity:
</strong>

<?php echo $detailTotal; ?>

units

</div>
<?php endif; ?>




<div>

<strong>
Condition:
</strong>

<?php
echo htmlspecialchars(trim((string)($equipment["equipment_condition"] ?? "")) ?: "Not specified"); ?>


</div>




</div>








<a class="reserve-now"

href="reservation.php?id=<?php echo $equipment["equipment_id"]; ?>">


Reserve Now


</a>





</div>





</div>







</div>










<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
