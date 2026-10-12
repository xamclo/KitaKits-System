<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin"){

    header("Location: ../index.php");
    exit;

}


$user_id = $_SESSION["user_id"];





// RENTAL HISTORY


$history = $conn->prepare(

"
SELECT


users.first_name,

users.last_name,


equipment.equipment_name,


reservations.quantity,


reservations.start_date,


reservations.end_date,


reservations.status



FROM reservations



INNER JOIN users

ON reservations.user_id = users.user_id



INNER JOIN equipment

ON reservations.equipment_id = equipment.equipment_id



WHERE equipment.owner_id = ?



AND reservations.status IN ('approved','completed')



ORDER BY reservations.reservation_id DESC

"

);



$history->bind_param(
"i",
$user_id
);



$history->execute();


$result=$history->get_result();



?>





<!DOCTYPE html>

<html>


<head>

<title>
Rental History
</title>


<link rel="stylesheet" href="../css/style.css">
<?php include __DIR__ . "/includes/assets.php"; ?>

<!-- Rental History only. Loaded after the shared assets so it cannot
     affect any other page. -->
<link rel="stylesheet" href="../css/rental-history.css?v=<?php echo @filemtime(__DIR__ . "/../css/rental-history.css"); ?>">

<!-- Shared admin table UI (Rental Requests + Rental History). Loaded last
     so both tables share the same typography, spacing and badges. -->
<link rel="stylesheet" href="../css/admin-tables.css?v=<?php echo @filemtime(__DIR__ . "/../css/admin-tables.css"); ?>">


</head>



<body class="new-dashboard rh-page">








<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>


<!-- CONTENT -->


<div class="dashboard-content" id="content">





<div class="top-header">


<div>


<h1>
Rental History
</h1>


<p>
Track previous equipment rentals
</p>


</div>



</div>








<div class="reservation-box">



<table>

<tr>
<th>Renter</th>
<th>Equipment</th>
<th>Rental Period</th>
<th>Condition</th>
<th>Status</th>
</tr>

<tbody id="rhTableBody">
<tr>
<td colspan="5" style="text-align:center;color:#94A3B8;padding:40px;">
Loading&hellip;
</td>
</tr>
</tbody>

</table>






</div>









</div>






</div>







<!-- MARK RETURNED MODAL -->
<div class="rh-modal" id="rhModal" role="dialog" aria-modal="true"
     aria-labelledby="rhModalTitle">

    <div class="rh-modal__card">

        <div class="rh-modal__head">
            <div>
                <h2 class="rh-modal__title" id="rhModalTitle">Mark as Returned</h2>
                <p class="rh-modal__sub">Record when and in what state the equipment came back.</p>
            </div>
            <button type="button" class="rh-modal__close" data-rh-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="rh-alert" id="rhAlert"></div>

        <div class="rh-modal__summary">
            <div class="rh-modal__row">
                <span class="rh-modal__label">Renter</span>
                <span class="rh-modal__value" id="rhRenter">-</span>
            </div>
            <div class="rh-modal__row">
                <span class="rh-modal__label">Equipment</span>
                <span class="rh-modal__value" id="rhEquipment">-</span>
            </div>
            <div class="rh-modal__row">
                <span class="rh-modal__label">Rental Period</span>
                <span class="rh-modal__value" id="rhDates">-</span>
            </div>
        </div>

        <div class="rh-field">
            <label for="rhCondition">Returned Condition</label>
            <select id="rhCondition">
                <option value="Excellent">Excellent</option>
                <option value="Good" selected>Good</option>
                <option value="Fair">Fair</option>
                <option value="Damaged">Damaged</option>
            </select>
        </div>

        <div class="rh-field">
            <label for="rhReturnedAt">Return Date</label>
            <input type="date" id="rhReturnedAt">
        </div>

        <div class="rh-modal__actions">
            <button type="button" class="rh-btn--cancel" data-rh-close>Cancel</button>
            <button type="button" class="rh-btn--save" id="rhSave">
                <i class="fa-solid fa-check"></i> Save Return
            </button>
        </div>

    </div>
</div>

<!-- TOAST -->
<div class="rh-toast" id="rhToast" role="status" aria-live="polite"></div>

<script src="../js/rental-history.js?v=<?php echo @filemtime(__DIR__ . "/../js/rental-history.js"); ?>"></script>


</body>


</html>
