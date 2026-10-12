<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin"){

    header("Location: ../index.php");
    exit;

}


$user_id = $_SESSION["user_id"];




// TRANSACTIONS


$transactions = $conn->prepare(

"
SELECT


users.first_name,

users.last_name,


equipment.equipment_name,


reservations.total_price,


reservations.status,


reservations.start_date,


reservations.end_date



FROM reservations



INNER JOIN users

ON reservations.user_id = users.user_id



INNER JOIN equipment

ON reservations.equipment_id = equipment.equipment_id



WHERE equipment.owner_id = ?



AND reservations.status='approved'



ORDER BY reservations.reservation_id DESC

"

);



$transactions->bind_param(
"i",
$user_id
);



$transactions->execute();


$result=$transactions->get_result();



?>





<!DOCTYPE html>

<html>


<head>


<title>
Transactions
</title>


<link rel="stylesheet" href="../css/style.css">
<?php include __DIR__ . "/includes/assets.php"; ?>

<!-- Transactions only. Loaded after the shared assets so it cannot
     affect any other page. -->
<link rel="stylesheet" href="../css/transactions.css?v=<?php echo @filemtime(__DIR__ . "/../css/transactions.css"); ?>">


</head>


<body class="new-dashboard">









<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>


<!-- CONTENT -->


<div class="dashboard-content" id="content">





<div class="top-header">


<div>


<h1>
Transactions
</h1>


<p>
Monitor rental payments and records
</p>


</div>



</div>








<div class="reservation-box">





<div class="box-title">
<h3>
Payment &amp; Transaction Records
</h3>
</div>

<table>

<tr>
<th>Transaction</th>
<th>Renter</th>
<th>Equipment</th>
<th>Payment Status</th>
<th>Amount</th>
<th>Action</th>
</tr>

<tbody id="txTableBody">
<tr>
<td colspan="6" style="text-align:center;color:#94A3B8;padding:40px;">
Loading&hellip;
</td>
</tr>
</tbody>

</table>






</div>









</div>






</div>







<!-- TRANSACTION RECEIPT MODAL -->
<div class="tx-modal" id="txModal" role="dialog" aria-modal="true"
     aria-labelledby="txModalTitle">

    <div class="tx-modal__card">

        <h2 class="tx-modal__title" id="txModalTitle">Transaction Receipt</h2>
        <p class="tx-modal__sub">Payment record for this rental.</p>

        <div class="tx-alert" id="txAlert"></div>

        <div class="tx-receipt">
            <div class="tx-receipt__row">
                <span class="tx-receipt__label">Transaction</span>
                <span class="tx-receipt__value" id="txRef">-</span>
            </div>
            <div class="tx-receipt__row">
                <span class="tx-receipt__label">Equipment</span>
                <span class="tx-receipt__value" id="txEquipment">-</span>
            </div>
            <div class="tx-receipt__row">
                <span class="tx-receipt__label">Renter</span>
                <span class="tx-receipt__value" id="txRenter">-</span>
            </div>
            <div class="tx-receipt__row">
                <span class="tx-receipt__label">Payment</span>
                <span class="tx-receipt__value" id="txPayment">-</span>
            </div>
            <div class="tx-receipt__row tx-receipt__row--total">
                <span class="tx-receipt__label">Amount</span>
                <span class="tx-receipt__value" id="txAmount">-</span>
            </div>
        </div>

        <div class="tx-modal__actions">
            <button type="button" class="tx-btn--close" data-tx-close>Close</button>
        </div>

    </div>
</div>

<!-- TOAST -->
<div class="tx-toast" id="txToast" role="status" aria-live="polite"></div>

<script src="../js/transactions.js?v=<?php echo @filemtime(__DIR__ . "/../js/transactions.js"); ?>"></script>


</body>


</html>
