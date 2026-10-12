<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {
    header("Location: ../index.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/reservation_state.php";

$user_id = $_SESSION["user_id"];

// Tabs: upcoming / ongoing / completed / cancelled.
// The mapping lives in includes/reservation_state.php so this list,
// the status badge and the details timeline can never disagree.
$activeTab = $_GET["tab"] ?? "upcoming";
if (!in_array($activeTab, ["upcoming", "ongoing", "completed", "cancelled"], true)) {
    $activeTab = "upcoming";
}

// LEFT JOIN keeps a reservation visible even if its equipment row or
// payment record is missing. Payment is derived from an actual
// transactions row - an approved reservation is NOT auto-paid.
$reservations = $conn->prepare(
    "
    SELECT
        r.reservation_id,
        r.user_id,
        r.equipment_id,
        r.start_date,
        r.end_date,
        r.total_price,
        r.status,
        r.returned_at,
        r.created_at,
        e.equipment_name,
        e.category,
        t.payment_status,
        e.requires_school_approval,
        a.status AS school_status,
        r.fulfillment_status
    FROM reservations r
    LEFT JOIN equipment e ON r.equipment_id = e.equipment_id
    LEFT JOIN transactions t ON t.reservation_id = r.reservation_id
    LEFT JOIN school_approval_requests a ON a.reservation_id = r.reservation_id
    WHERE r.user_id = ?
    ORDER BY r.reservation_id DESC
    "
);

$reservations->bind_param("i", $user_id);
$reservations->execute();
$result = $reservations->get_result();

// Group the real records into the four tabs, and count each bucket
// for the tab labels.
$tabRows = ["upcoming" => [], "ongoing" => [], "completed" => [], "cancelled" => []];

while ($r = $result->fetch_assoc()) {
    $r["tab"] = reservationTab($r["status"], $r["start_date"], $r["returned_at"]);
    $tabRows[$r["tab"]][] = $r;
}

// Only a real transactions row marked paid counts as paid.
$isPaid = function ($row) {
    return isset($row["payment_status"]) && strtolower($row["payment_status"]) === "paid";
};

$fmtDate = function ($d) {
    $ts = strtotime($d);
    return $ts ? date("M j, Y", $ts) : $d;
};
?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>My Reservations | KitaKits</title>

<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">
<link rel="stylesheet" href="../css/student-reservations.css">

<link rel="stylesheet"

href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body class="new-dashboard res-page">

<!-- SIDEBAR -->

<?php $__sbActive = "reservations"; include __DIR__ . "/../includes/sidebar_student.php"; ?>

<!-- MAIN CONTENT -->

<div class="dashboard-content" id="content">

<div class="top-header">

<div>

<h1>

My Reservations

</h1>

<p>

Track your equipment rental requests.

</p>

</div>

<?php include "../includes/student_header_actions.php"; ?>
</div>


<div class="reservation-container">

<!-- TABS: four states derived from the real reservation records -->
<div class="reservation-tabs">
<?php
$tabLabels = ["upcoming" => "Upcoming", "ongoing" => "Ongoing", "completed" => "Completed", "cancelled" => "Cancelled"];
foreach ($tabLabels as $key => $label):
    $cls = ($activeTab === $key) ? "tab active" : "tab";
    $n = count($tabRows[$key]);
?>
    <a class="<?php echo $cls; ?>" href="reservations.php?tab=<?php echo $key; ?>">
        <?php echo $label; ?>
        <span class="tab-count"><?php echo $n; ?></span>
    </a>
<?php endforeach; ?>
</div>

<!-- RESERVATION TABLE -->
<div class="reservation-card">
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Dates</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($tabRows[$activeTab]) > 0): ?>
            <?php foreach ($tabRows[$activeTab] as $row): ?>
                <?php $statusLabel = reservationStatusLabel($row["status"]); ?>
                <tr>
                    <td>
                        <div class="item-name"><?php echo htmlspecialchars($row["equipment_name"] ?? "Equipment"); ?></div>
                        <div class="reservation-id">RES-<?php echo (int)$row["reservation_id"]; ?></div>
                    </td>
                    <td>
                        <div class="res-dates">
                            <?php echo $fmtDate($row["start_date"]); ?><span class="dash">–</span><?php echo $fmtDate($row["end_date"]); ?>
                        </div>
                    </td>
                    <td>
                        <span class="status-badge <?php echo strtolower($row["status"]); ?>"><?php echo $statusLabel; ?></span>
                    </td>
                    <td>
                        <?php if ($isPaid($row)): ?>
                            <span class="pay-badge paid">Paid</span>
                        <?php else: ?>
                            <span class="pay-badge unpaid">Unpaid</span>
                        <?php endif; ?>

                        <?php
                        /* High-value rentals show the institutional step as a
                           separate indicator. It never replaces the rental
                           status and never appears for ordinary equipment. */
                        if(!empty($row["requires_school_approval"])):
                            $smState = strtolower((string)($row["school_status"] ?? ""));
                            if($smState === ""){ $smTxt = "School Witness Required";
                            } elseif($smState === "pending"){ $smTxt = "Owner Approved — Pending Witness Record";
                            } elseif($smState === "approved"){ $smTxt = "Transaction Witnessed — Ready for Handover";
                            } else { $smTxt = "Transaction Flagged"; }
                        ?>
                            <span class="pay-badge unpaid" style="display:block;margin-top:6px;"><?php echo $smTxt; ?></span>
                        <?php endif; ?>

                    </td>
                    <td>
                        <a href="reservation_details.php?id=<?php echo (int)$row["reservation_id"]; ?>" class="view-btn">View</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" class="empty-row">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    No <?php echo strtolower($tabLabels[$activeTab]); ?> reservations found.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</div>
</div>





<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
