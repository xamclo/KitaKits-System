<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin") {

header("Location: ../index.php");
exit;

}

// Needed for the owner_id check on the DELETE below.
$user_id = (int)$_SESSION["user_id"];


if (!isset($_GET["id"])) {

header("Location: manage_equipment.php");
exit;

}


$id = (int)$_GET["id"];

if ($id < 1) {

header("Location: manage_equipment.php");
exit;

}


/*

SAFE DELETE

The previous code ran a bare DELETE and always failed with:

    ERROR 1451 - foreign key constraint fails (reservations_ibfk_2)

because reservations.equipment_id still referenced the row. That made
delete look broken even when it was safe to do.

Three outcomes instead:

  1. Active/pending rental  -> refuse; nothing is destroyed.
  2. History exists         -> ARCHIVE. Reservations and transactions
                               stay intact, but the item disappears from
                               Student listings because that page reads
                               only status='available'.
  3. No linked rows at all  -> real DELETE.

Ownership is always taken from the session, never from the URL, so an
admin cannot delete another owner's equipment by editing the id.

*/


// Load the item, scoped to this owner. Scoping the SELECT as well as the
// DELETE means a foreign id fails exactly like a missing one.
$stmt = $conn->prepare("SELECT equipment_id, equipment_name FROM equipment WHERE equipment_id = ? AND owner_id = ?");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$item) {

$_SESSION["eq_flash"] = "Equipment not found, or you do not own it.";
$_SESSION["eq_flash_type"] = "err";
header("Location: manage_equipment.php");
exit;

}

$name = $item["equipment_name"];


// 1. Is anything currently in flight?
$live = $conn->prepare(
    "SELECT COUNT(*) AS n FROM reservations WHERE equipment_id = ? AND status IN ('pending','approved','ongoing','overdue')"
);
$live->bind_param("i", $id);
$live->execute();
$activeCount = (int)($live->get_result()->fetch_assoc()["n"] ?? 0);
$live->close();

if ($activeCount > 0) {

$_SESSION["eq_flash"] = "This equipment cannot be deleted while it has an active or pending rental.";
$_SESSION["eq_flash_type"] = "err";
header("Location: manage_equipment.php");
exit;

}


// 2. Any history at all? If so, archive rather than destroy.
$hist = $conn->prepare("SELECT COUNT(*) AS n FROM reservations WHERE equipment_id = ?");
$hist->bind_param("i", $id);
$hist->execute();
$historyCount = (int)($hist->get_result()->fetch_assoc()["n"] ?? 0);
$hist->close();


if ($historyCount > 0) {

// Archive keeps every historical reservation and transaction readable
// while removing the item from the Student equipment listings.
$arch = $conn->prepare("UPDATE equipment SET status = 'archived' WHERE equipment_id = ? AND owner_id = ?");
$arch->bind_param("ii", $id, $user_id);
$ok = $arch->execute();
$arch->close();

$_SESSION["eq_flash"] = $ok
    ? "\"$name\" was archived because it has rental history. Past reservations and transactions were kept."
    : "Failed to archive equipment.";
$_SESSION["eq_flash_type"] = $ok ? "ok" : "err";
header("Location: manage_equipment.php");
exit;

}


// 3. Nothing linked anywhere -> safe to remove for real.
$stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id = ? AND owner_id = ?");
$stmt->bind_param("ii", $id, $user_id);

if ($stmt->execute()) {

$_SESSION["eq_flash"] = "Equipment deleted successfully.";
$_SESSION["eq_flash_type"] = "ok";

} else {

$_SESSION["eq_flash"] = "Failed to delete equipment.";
$_SESSION["eq_flash_type"] = "err";

}

$stmt->close();

header("Location: manage_equipment.php");
exit;

?>
