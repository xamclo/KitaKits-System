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



$equipment_id = intval($_GET["id"]);

$user_id = $_SESSION["user_id"];





$stmt = $conn->prepare(

"SELECT *
FROM equipment
WHERE equipment_id = ?
AND owner_id != ?
AND available_quantity > 0"

);


$stmt->bind_param(
"ii",
$equipment_id,
$user_id
);


$stmt->execute();


$result = $stmt->get_result();



if($result->num_rows == 0){

    header("Location: equipment.php");
    exit();

}



$equipment = $result->fetch_assoc();





if($_SERVER["REQUEST_METHOD"]=="POST"){


    $pickup = $_POST["pickup_date"];

    $return = $_POST["return_date"];

    $purpose = $_POST["purpose"];

    $quantity = intval($_POST["quantity"]);



    if(strtotime($return) < strtotime($pickup)){


        echo "<script>
        alert('Return date cannot be before pickup date');
        </script>";

    }

    else{

    if($quantity > $equipment["available_quantity"]){

    echo "<script>
    alert('Requested quantity is not available');
    </script>";

    exit();

}






        // Server-side quantity validation. The HTML min/max attributes are
        // only a convenience; without this a request could store quantity
        // 0 (or a negative number) and create a reservation that can never
        // be billed or fulfilled.
        if($quantity < 1){
        echo "<script>
        alert('Please request at least 1 item');
        </script>";
        exit();
        }
        $days = ceil(
            (strtotime($return)-strtotime($pickup))
            /86400
        );


        if($days < 1){

            $days = 1;

        }



        // Charge every unit reserved: days x price x quantity.
        // Previously the quantity was ignored, so a student who
        // reserved 3 items was only billed for 1.
        $total_price =
        $days * $equipment["rental_price"] * $quantity;

        // Duplicate-submission guard: a double-click or a browser refresh
        // re-POSTs this same form, which used to create two identical
        // pending reservations (and two future payment obligations).
        $dupe=$conn->prepare(
        "SELECT reservation_id
         FROM reservations
         WHERE user_id=?
           AND equipment_id=?
           AND start_date=?
           AND end_date=?
           AND LOWER(status) IN ('pending','approved')
         LIMIT 1"
        );

        $dupe->bind_param("iiss", $user_id, $equipment_id, $pickup, $return);
        $dupe->execute();
        $dupeRow=$dupe->get_result()->fetch_assoc();
        $dupe->close();

        if($dupeRow){
            header("Location: reservation_success.php?id=".(int)$dupeRow["reservation_id"]);
            exit();
        }

        $insert = $conn->prepare(

        "INSERT INTO reservations

        (
        user_id,
        equipment_id,
        quantity,
        start_date,
        end_date,
        total_price,
        purpose,
        status
        )

        VALUES

        (?,?,?,?,?,?,?,'pending')"

        );




        $insert->bind_param(

        "iiissds",

        $user_id,
        $equipment_id,
        $quantity,
        $pickup,
        $return,
        $total_price,
        $purpose

        );




            if($insert->execute()){


            $reservation_id = $conn->insert_id;

            // Tell the equipment owner a new request exists, and record the
            // event so School Management history shows a real submission.
            // Fails silently: a notification problem must never lose a booking.
            try {
                $audit = $conn->prepare(
                    "INSERT INTO transaction_audit_logs
                        (reservation_id, actor_user_id, actor_role, action_type,
                         previous_status, new_status, notes)
                     VALUES (?, ?, 'student', 'reservation_submitted', 'none', 'pending', NULL)"
                );
                $audit->bind_param("ii", $reservation_id, $user_id);
                $audit->execute();
                $audit->close();

                $ownerMsg = "You have a new rental request (RES-" . $reservation_id
                          . ") for " . $equipment["equipment_name"] . " from "
                          . trim($_SESSION["first_name"] . " " . $_SESSION["last_name"]) . ".";
                if(!empty($equipment["requires_school_approval"])){
                    $ownerMsg .= " This high-value transaction will be submitted for School Management witnessing.";
                }
                $n = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $n->bind_param("is", $equipment["owner_id"], $ownerMsg);
                $n->execute();
                $n->close();
            } catch (Exception $e) {
                error_log("Reservation notification failed: " . $e->getMessage());
            }



            header(
            "Location: reservation_success.php?id=".$reservation_id
            );


            exit();


        }


    }



}


?>



<!DOCTYPE html>

<html lang="en">


<head>


<title>Make Reservation | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">


<link rel="stylesheet"

href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/reservation-form.css">


</head>



<body class="new-dashboard reservation-page">





<!-- SIDEBAR -->

<?php $__sbActive = "equipment"; include __DIR__ . "/../includes/sidebar_student.php"; ?>









<!-- CONTENT -->


<div class="dashboard-content" id="content">



<div class="top-header">


<div>

<h1>

Make a Reservation

</h1>


</div>


</div>





<a href="equipment_detail.php?id=<?php echo $equipment_id; ?>" class="back-btn">


<i class="fa-solid fa-arrow-left"></i>

Back


</a>







<div class="reservation-card">





<div class="reservation-product">





<div class="product-image">


<?php if(!empty($equipment["equipment_image"])): ?>


<img src="../uploads/<?php echo $equipment["equipment_image"]; ?>">


<?php else: ?>


<i class="fa-solid fa-box"></i>


<?php endif; ?>


</div>







<div>


<h2>

<?php echo htmlspecialchars($equipment["equipment_name"]); ?>

</h2>


<p>

₱<?php echo number_format($equipment["rental_price"],2); ?>/day

</p>


</div>



</div>








<form method="POST">





<label>

Pickup Date

</label>


<input

type="date"

name="pickup_date"

required>







<label>

Return Date

</label>


<input

type="date"

name="return_date"

required>




<label>

Quantity

</label>


<input

type="number"

name="quantity"

value="1"

min="1"

max="<?php echo $equipment['available_quantity']; ?>"

required>



<label>

Purpose / Notes

</label>


<textarea

name="purpose"

placeholder="Purpose of rental...">

</textarea>








<div class="total-box">


<p id="priceText">

₱<?php echo number_format($equipment["rental_price"],2); ?>/day × 1 day

</p>



<h2>

Total Price

</h2>



<h1 id="totalPrice">

₱<?php echo number_format($equipment["rental_price"],2); ?>

</h1>



</div>






<button type="submit" class="submit-reservation">


Submit Reservation


</button>






</form>






</div>





</div>








<script>


const pickup = document.querySelector(
'input[name="pickup_date"]'
);


const returnDate = document.querySelector(
'input[name="return_date"]'
);



const price =
<?php echo $equipment["rental_price"]; ?>;




function calculateTotal(){


if(pickup.value && returnDate.value){



let start = new Date(pickup.value);

let end = new Date(returnDate.value);



let days = Math.ceil(
(end-start)/(1000*60*60*24)
);



if(days < 1){

days = 1;

}




let total = days * price;



document.getElementById("priceText").innerHTML =

"₱"+price.toFixed(2)+"/day × "+days+" days";




document.getElementById("totalPrice").innerHTML =

"₱"+total.toFixed(2);



}



}



pickup.addEventListener(
"change",
calculateTotal
);



returnDate.addEventListener(
"change",
calculateTotal
);




</script>






<script src="../js/student-sidebar.js"></script>
</body>


</html>
