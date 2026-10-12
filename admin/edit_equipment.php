<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin") {

header("Location: ../index.php");
exit;

}



if (!isset($_GET["id"])) {

header("Location: manage_equipment.php");
exit;

}



$id = $_GET["id"];
$user_id = $_SESSION["user_id"];



// GET EQUIPMENT DATA

$stmt = $conn->prepare(
"SELECT * FROM equipment 
WHERE equipment_id = ? AND owner_id = ?"
);


$stmt->bind_param("ii",$id,$user_id);

$stmt->execute();


$result = $stmt->get_result();



if ($result->num_rows !== 1) {

header("Location: manage_equipment.php");
exit;

}



$equipment = $result->fetch_assoc();




$message = "";




// UPDATE

if ($_SERVER["REQUEST_METHOD"] === "POST") {



$equipment_name = trim($_POST["equipment_name"]);
$category = trim($_POST["category"]);
$description = trim($_POST["description"]);
$rental_price = $_POST["rental_price"];
$quantity = $_POST["available_quantity"];
$status = $_POST["status"];

// School Management classification (optional, owner controlled).
$requires_school = isset($_POST["requires_school_approval"]) ? 1 : 0;
$declared_value = trim($_POST["declared_value"] ?? "");
$accessories = trim($_POST["accessories"] ?? "");

// Asset codes are never typed by the admin. Preserve the existing code so
// it stays accessible; a new one is generated below only if the item
// becomes high-value and still has no code.
$asset_code = $equipment["asset_code"] ?? null;

// ---- Backend validation (mirrors admin/api/equipment.php) ----
$DESCRIPTION_MAX = 300;
if (mb_strlen($equipment_name) === 0) {
    $message = "Equipment name is required.";
} elseif (mb_strlen($equipment_name) > 150) {
    $message = "Equipment name is too long (max 150 characters).";
} elseif (mb_strlen($category) === 0) {
    $message = "Category is required.";
} elseif (!is_numeric($rental_price) || (float)$rental_price < 0) {
    $message = "Please enter a valid rental price.";
} elseif (!ctype_digit((string)$quantity) || (int)$quantity < 1) {
    $message = "Total units must be a whole number of at least 1.";
} elseif (mb_strlen($description) > $DESCRIPTION_MAX) {
    $message = "Description is too long. Maximum is " . $DESCRIPTION_MAX . " characters.";
}

// School policy can override the owner: an item at or above the
// configured threshold is high-value whether or not the box was ticked.
if ($message === "") {
    require_once __DIR__ . "/../includes/school_auth.php";
    if (policyForcesHighValue($conn, $declared_value)) {
        $requires_school = 1;
    }
}

// When flagged high-value, every required high-value field must be
// completed before the row is saved.
if ($message === "" && $requires_school === 1) {
    if ($declared_value === "" || !is_numeric($declared_value) || (float)$declared_value <= 0) {
        $message = "Declared Value is required for high-value items.";
    } elseif ($accessories === "") {
        $message = "Included Accessories is required for high-value items.";
    }
}

// If the item stopped being high-value, clear the code so the column only
// ever holds real codes for high-value rows. Otherwise keep it.
if ($message === "" && $requires_school !== 1) {
    $asset_code = null;
}

if ($message === "") {

$update = $conn->prepare(

"UPDATE equipment SET

equipment_name=?,
category=?,
description=?,
rental_price=?,
available_quantity=?,
status=?,
requires_school_approval=?,
declared_value=?,
asset_code=?,
accessories=?

WHERE equipment_id=? AND owner_id=?"

);



$update->bind_param(

"sssdiiiiiis",

$equipment_name,
$category,
$description,
$rental_price,
$quantity,
$status,
$requires_school,
$declared_value === "" ? null : $declared_value,
$asset_code === "" ? null : $asset_code,
$accessories === "" ? null : $accessories,
$id,
$user_id

);



if($update->execute()){

    // If this edit made the item high-value and it still has no code,
    // generate a unique one now.
    if ($requires_school === 1 && (string)($asset_code ?? "") === "") {
        do {
            $generated = "HV-" . $id . "-" . bin2hex(random_bytes(3));
            $chk = $conn->prepare("SELECT COUNT(*) AS n FROM equipment WHERE asset_code = ?");
            $chk->bind_param("s", $generated);
            $chk->execute();
            $n = (int)($chk->get_result()->fetch_assoc()["n"] ?? 0);
            $chk->close();
        } while ($n > 0);

        $code = $conn->prepare(
            "UPDATE equipment SET asset_code = ? WHERE equipment_id = ? AND owner_id = ?"
        );
        $code->bind_param("sii", $generated, $id, $user_id);
        $code->execute();
        $code->close();
    }


header("Location: manage_equipment.php");
exit;


} else {


$message="Update failed.";

}



}



}


?>



<!DOCTYPE html>

<html>

<head>

<title>Edit Equipment</title>
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/manage-equipment.css?v=<?php echo @filemtime(__DIR__ . "/../css/manage-equipment.css"); ?>">
<?php include __DIR__ . "/includes/assets.php"; ?>


</head>


<body class="new-dashboard">

<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>

<!-- CONTENT -->

<div class="dashboard-content" id="content">




<h1>
Edit Equipment
</h1>



<?php if($message): ?>

<p>
<?php echo $message; ?>
</p>

<?php endif; ?>





<form method="POST">



<label>
Equipment Name
</label>

<br>

<input
type="text"
name="equipment_name"
value="<?php echo $equipment["equipment_name"]; ?>"
required>


<br><br>




<label>
Category
</label>

<br>

<input
type="text"
name="category"
value="<?php echo $equipment["category"]; ?>"
required>



<br><br>




<label>
Description
</label>

<br>

<textarea name="description" id="editDescription" maxlength="300" placeholder="Short notes about this equipment"><?php echo $equipment["description"]; ?></textarea>
<span class="eq-field__counter" id="editDescCounter">0 / 300</span>



<br><br>




<label>
Rental Price
</label>

<br>

<input
type="number"
step="0.01"
name="rental_price"
value="<?php echo $equipment["rental_price"]; ?>"
required>



<br><br>




<label>
Quantity
</label>

<br>

<input
type="number"
name="available_quantity"
value="<?php echo $equipment["available_quantity"]; ?>"
required>



<br><br>




<label>
Status
</label>

<br>


<select name="status">


<option value="available"
<?php if($equipment["status"]=="available") echo "selected"; ?>>
Available
</option>



<option value="unavailable"
<?php if($equipment["status"]=="unavailable") echo "selected"; ?>>
Unavailable
</option>



<option value="maintenance"
<?php if($equipment["status"]=="maintenance") echo "selected"; ?>>
Maintenance
</option>


</select>



<br><br>



    <label>High-Value Equipment</label>
    <br>
    <div class="eq-check">
        <span class="eq-check__control">
            <input type="checkbox" name="requires_school_approval" id="editRequiresSchool" value="1" style="width:auto;"
            <?php echo (!empty($equipment["requires_school_approval"]) ? "checked" : ""); ?>>
        </span>
        <span class="eq-check__text">Requires School Management Approval</span>
    </div>
    <span style="font-size:12px;color:#64748b;">Only tick this if your school policy classifies this item as high-value.</span>
    <br><br>

    <div class="eq-hv-fields" id="editHvFields" aria-hidden="<?php echo (!empty($equipment["requires_school_approval"]) ? "false" : "true"); ?>" style="<?php echo (!empty($equipment["requires_school_approval"]) ? "" : "display:none;"); ?>">
        <span class="eq-hv-fields__title">High-value item details</span>

        <label>Declared Value (PHP) <span class="eq-field__req">*</span></label>
        <br>
        <input type="number" step="0.01" min="0" name="declared_value" id="editDeclaredValue"
            value="<?php echo $equipment["declared_value"] !== null ? htmlspecialchars((string)$equipment["declared_value"]) : ""; ?>">
        <br><br>

        <label>Included Accessories <span class="eq-field__req">*</span></label>
        <br>
        <input type="text" name="accessories" id="editAccessories"
            value="<?php echo htmlspecialchars((string)($equipment["accessories"] ?? "")); ?>">
        <br><br>

        <span class="eq-hv-fields__hint">Asset codes are generated automatically by the system.</span>
    </div>

<button type="submit">

Save Changes

</button>



</form>




<br>

<a href="manage_equipment.php">
Back
</a>




</div>

<script>
(function () {
    "use strict";
    var DESCRIPTION_MAX = 300;

    // Show/hide the high-value required-fields block. Clicking the
    // checkbox itself only - the surrounding text is a <span>, not a
    // <label>, so it never toggles the box.
    var checkbox = document.getElementById("editRequiresSchool");
    var hvBlock = document.getElementById("editHvFields");
    if (checkbox && hvBlock) {
        checkbox.addEventListener("change", function () {
            var on = checkbox.checked;
            hvBlock.style.display = on ? "" : "none";
            hvBlock.setAttribute("aria-hidden", on ? "false" : "true");
        });
    }

    // Description character counter.
    var desc = document.getElementById("editDescription");
    var counter = document.getElementById("editDescCounter");
    if (desc && counter) {
        function update() {
            var len = desc.value.length;
            counter.textContent = len + " / " + DESCRIPTION_MAX;
            counter.classList.toggle("is-near-limit", (DESCRIPTION_MAX - len) <= 30 && (DESCRIPTION_MAX - len) > 0);
            counter.classList.toggle("is-over-limit", len > DESCRIPTION_MAX);
        }
        desc.addEventListener("input", update);
        update();
    }
})();
</script>

</body>

</html>
