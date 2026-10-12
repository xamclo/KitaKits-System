<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin") {
    header("Location: ../index.php");
    exit();
}





$stmt = $conn->prepare(
    "SELECT *
     FROM equipment
     WHERE owner_id = ?"
);

$stmt->bind_param(
    "i",
    $_SESSION["user_id"]
);

$stmt->execute();

$result = $stmt->get_result();

?>


<!DOCTYPE html>
<html>

<head>

<title>Manage Equipment</title>
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="../css/style.css">
<?php include __DIR__ . "/includes/assets.php"; ?>


</head>


<body class="new-dashboard">

<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>

<!-- CONTENT -->

<div class="dashboard-content" id="content">



<h1>
Manage Equipment
</h1>


<a href="add_equipment.php">
+ Add Equipment
</a>


<br><br>



<div class="reservation-box">
<table>


<tr>

<th>ID</th>
<th>Name</th>
<th>Category</th>
<th>Price</th>
<th>Quantity</th>
<th>Status</th>
<th>Action</th>

</tr>



<?php while($row = $result->fetch_assoc()): ?>


<tr>


<td>
<?php echo $row["equipment_id"]; ?>
</td>


<td>
<?php echo $row["equipment_name"]; ?>
</td>


<td>
<?php echo $row["category"]; ?>
</td>


<td>
₱<?php echo $row["rental_price"]; ?>
</td>


<td>
<?php echo $row["available_quantity"]; ?>
</td>


<td>
<?php echo $row["status"]; ?>
</td>


<td>

<a href="edit_equipment.php?id=<?php echo $row["equipment_id"]; ?>">
Edit
</a>


|

<a href="delete_equipment.php?id=<?php echo $row["equipment_id"]; ?>">
Delete
</a>


</td>


</tr>


<?php endwhile; ?>


</table>
</div>
</div>

</body>

</html>