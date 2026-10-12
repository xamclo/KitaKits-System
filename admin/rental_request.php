<?php

session_start();

require_once "../config/database.php";


if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin"){

    header("Location: ../index.php");
    exit();

}


$user_id = $_SESSION["user_id"];




// ==========================
// APPROVE REQUEST
// ==========================


if(isset($_GET["approve"])){

    $reservation_id = $_GET["approve"];


    $conn->begin_transaction();


    try{


        // GET RESERVATION INFORMATION

        $check = $conn->prepare(

        "
        SELECT

        reservations.quantity,
        reservations.total_price,
        reservations.user_id,
        reservations.status,

        equipment.equipment_id,
        equipment.available_quantity,
        equipment.equipment_name,
        equipment.requires_school_approval


        FROM reservations


        INNER JOIN equipment

        ON reservations.equipment_id = equipment.equipment_id


        WHERE reservations.reservation_id=?

        AND equipment.owner_id=?

        "

        );


        $check->bind_param(
            "ii",
            $reservation_id,
            $user_id
        );


        $check->execute();


        $data = $check
        ->get_result()
        ->fetch_assoc();



        if(!$data){

            throw new Exception("Reservation not found");

        }




        // Idempotency guard: only a PENDING request may be approved.

        // Without this, approving the same request twice (a double-click,

        // or a browser refresh re-sending the same ?approve= link) deducted

        // the stock again and inserted a SECOND transaction row.

        if($data["status"] !== "pending"){

            throw new Exception("Request is not pending");

        }

        if($data["available_quantity"] < $data["quantity"]){

            throw new Exception("Not enough quantity");

        }






        // UPDATE EQUIPMENT STOCK


        $stock = $conn->prepare(

        "
        UPDATE equipment

        SET available_quantity =
        available_quantity - ?

        WHERE equipment_id=?

        "

        );


        $stock->bind_param(

            "ii",

            $data["quantity"],

            $data["equipment_id"]

        );


        $stock->execute();






        // UPDATE RESERVATION STATUS


        $approve = $conn->prepare(

        "
        UPDATE reservations

        SET status='approved'

        WHERE reservation_id=?

        "

        );


        $approve->bind_param(

            "i",

            $reservation_id

        );


        $approve->execute();






        // CREATE PAYMENT TRANSACTION


        $payment = $conn->prepare(

        "
        INSERT INTO transactions

        (
        reservation_id,
        user_id,
        amount,
        payment_status
        )

        VALUES

        (?,?,?, 'pending')

        "

        );


        $payment->bind_param(

        "iid",

        $reservation_id,

        $data["user_id"],

        $data["total_price"]

        );


        $payment->execute();






        // CREATE STUDENT NOTIFICATION


        $notification = $conn->prepare(

        "
        INSERT INTO notifications (user_id, message) VALUES (?, ?)

        "

        );


        $message =

        "Your rental request for "
        .$data["equipment_name"].
        " was approved by the equipment owner.";

        if (!empty($data["requires_school_approval"])) {
            $message .= " The transaction will now be submitted for School Management witnessing.";
        }

        $notification->bind_param("is", $data["user_id"], $message);;


        $notification->execute();





        $conn->commit();



    }


    catch(Exception $e){


        $conn->rollback();


    }



    header("Location:rental_request.php");

    exit();

}






// ==========================
// DECLINE REQUEST
// ==========================



if(isset($_GET["decline"])){

    
    $reservation_id=$_GET["decline"];




    // GET STUDENT + EQUIPMENT INFO


    $info=$conn->prepare(

    "
    SELECT

    reservations.user_id,
    reservations.status,

    reservations.quantity,

    equipment.equipment_id,

    equipment.equipment_name


    FROM reservations


    INNER JOIN equipment

    ON reservations.equipment_id = equipment.equipment_id


    WHERE reservation_id=?

    AND equipment.owner_id=?


    "

    );


    $info->bind_param(

    "ii",

        $reservation_id,
        $user_id

    );


    $info->execute();


    $reservationInfo=$info
    ->get_result()
    ->fetch_assoc();





    // Authorisation + idempotency guards for the decline action.

    // The lookup above is owner-scoped, so $reservationInfo is null when

    // the reservation does not belong to this admin's equipment.

    if(!$reservationInfo){

        header("Location:rental_request.php");

        exit();

    }

    // Only a pending request can be declined. Declining an already

    // approved rental would hide an active rental while its stock stayed

    // deducted, permanently corrupting inventory.

    if($reservationInfo["status"] === "approved"){

        // Reversing an approval: give the units back to stock first.

        $restore=$conn->prepare(

        "UPDATE equipment SET available_quantity = available_quantity + ? WHERE equipment_id=?"

        );

        $restore->bind_param("ii", $reservationInfo["quantity"], $reservationInfo["equipment_id"]);

        $restore->execute();

        $restore->close();

    }


    // Anything already finished (completed/returned/declined) is final.

    if(!in_array($reservationInfo["status"], array("pending","approved"), true)){

        header("Location:rental_request.php");

        exit();

    }


    // UPDATE STATUS


    $decline=$conn->prepare(

    "
    UPDATE reservations

    SET status='declined'

    WHERE reservation_id=?

    "

    );


    $decline->bind_param(

        "i",

        $reservation_id

    );


    $decline->execute();





    // CREATE NOTIFICATION


    if($reservationInfo){


        $notification=$conn->prepare(

        "
        INSERT INTO notifications (user_id, message) VALUES (?, ?)

        "

        );



        $message =

        "Your rental request for "
        .$reservationInfo["equipment_name"].
        " was declined by the equipment owner.";

        $notification->bind_param("is", $reservationInfo["user_id"], $message);;



        $notification->execute();



    }



    header("Location:rental_request.php");

    exit();


}

// ==========================
// REQUEST LIST
// ==========================


$requests=$conn->prepare(

"
SELECT


reservations.reservation_id,

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



WHERE equipment.owner_id=? 



ORDER BY reservations.reservation_id DESC



"

);



$requests->bind_param(
"i",
$user_id
);



$requests->execute();


$result=$requests->get_result();



?>





<!DOCTYPE html>

<html>


<head>

<title>
Rental Requests
</title>


<link rel="stylesheet" href="../css/style.css">
<?php include __DIR__ . "/includes/assets.php"; ?>

<!-- Rental Request only. Loaded after the shared assets so it cannot
     affect any other page. -->
<link rel="stylesheet" href="../css/rental-request.css?v=<?php echo @filemtime(__DIR__ . "/../css/rental-request.css"); ?>">

<!-- Shared admin table UI (Manage Equipment + Rental Requests).
     Loaded last so both tables share the same typography, spacing,
     badges and action layout. -->
<link rel="stylesheet" href="../css/admin-tables.css?v=<?php echo @filemtime(__DIR__ . "/../css/admin-tables.css"); ?>">





</head>


<body class="new-dashboard rr-page">





<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>


<!-- CONTENT -->

<div class="dashboard-content" id="content">





<div class="top-header">


<div>

<h1>
Rental Requests
</h1>


<p>
Review student equipment reservations
</p>


</div>


</div>

<div class="reservation-box">





<table>

<tr>
<th>Renter</th>
<th>Equipment</th>
<th>Rental Period</th>
<th>Status</th>
<th>School Witness</th>
<th>Actions</th>
</tr>

<tbody id="rrTableBody">
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













<!-- REVIEW REQUEST MODAL -->
<div class="rr-modal" id="rrModal" role="dialog" aria-modal="true"
     aria-labelledby="rrModalTitle">

    <div class="rr-modal__card">

        <div class="rr-modal__head">
            <div>
                <h2 class="rr-modal__title" id="rrModalTitle">Review Request</h2>
                <p class="rr-modal__sub">Check the details before deciding.</p>
            </div>
            <button type="button" class="rr-modal__close" data-rr-close aria-label="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="rr-alert" id="rrAlert"></div>

        <div class="rr-detail">

            <div class="rr-detail__row">
                <span class="rr-detail__label">Renter</span>
                <span class="rr-detail__value" id="rrRenter">-</span>
            </div>

            <div class="rr-detail__row">
                <span class="rr-detail__label">Equipment</span>
                <span class="rr-detail__value" id="rrEquipment">-</span>
            </div>

            <div class="rr-detail__row">
                <span class="rr-detail__label">Pickup</span>
                <span class="rr-detail__value" id="rrPickup">-</span>
            </div>

            <div class="rr-detail__row">
                <span class="rr-detail__label">Return</span>
                <span class="rr-detail__value" id="rrReturn">-</span>
            </div>

            <div class="rr-detail__row">
                <span class="rr-detail__label">Payment</span>
                <span class="rr-detail__value" id="rrPayment">-</span>
            </div>

        </div>

        <div class="rr-modal__actions">
            <button type="button" class="rr-btn--reject" id="rrReject">
                <i class="fa-solid fa-xmark"></i> Reject
            </button>
            <button type="button" class="rr-btn--approve" id="rrApprove">
                <i class="fa-solid fa-check"></i> Approve
            </button>
        </div>

    </div>
</div>

<!-- TOAST -->
<div class="rr-toast" id="rrToast" role="status" aria-live="polite"></div>

<script src="../js/rental-request.js?v=<?php echo @filemtime(__DIR__ . "/../js/rental-request.js"); ?>"></script>

</body>

</html>
