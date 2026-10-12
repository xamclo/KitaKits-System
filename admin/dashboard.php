<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "admin") {
    header("Location: ../index.php");
    exit;
}


$user_id = (int)$_SESSION["user_id"];

$admin_first = (string)($_SESSION["first_name"] ?? "A");

$admin_initial = strtoupper(mb_substr($admin_first, 0, 1, "UTF-8"));


// SAFE DEFAULTS — page renders even if a query fails
$totalEquipment = 0;
$pendingRequest = 0;
$activeRentals = 0;
$overdueItems = 0;
$availableUnits = 0;
$rentedUnits = 0;
$weeklyData = [0, 0, 0, 0]; // Week 1 (oldest) ... Week 4 (current)
$recentRows = [];


try {

    // ============================
    // CARD 1 — TOTAL EQUIPMENT
    // ============================
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM equipment WHERE owner_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $totalEquipment = (int)($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();


    // ============================
    // CARD 2 — PENDING REQUESTS
    // ============================
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM reservations
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
           AND reservations.status = 'pending'"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pendingRequest = (int)($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();


    // ============================
    // CARD 3 — ACTIVE RENTALS
    // (approved and today within rental period)
    // ============================
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM reservations
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
           AND reservations.status = 'approved'
           AND CURDATE() BETWEEN reservations.start_date AND reservations.end_date"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $activeRentals = (int)($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();


    // ============================
    // CARD 4 — OVERDUE ITEMS
    // (past return date, still active)
    // ============================
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM reservations
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
           AND reservations.status = 'approved'
           AND reservations.end_date < CURDATE()"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $overdueItems = (int)($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();


    // ============================
    // RENTAL OVERVIEW — LAST 4 WEEKS
    // ============================
    $stmt = $conn->prepare(
        "SELECT reservations.created_at
         FROM reservations
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
           AND reservations.created_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY)"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $weekResult = $stmt->get_result();

    $today = new DateTime("today");

    while ($wRow = $weekResult->fetch_assoc()) {
        $createdDate = new DateTime(
            date("Y-m-d", strtotime($wRow["created_at"]))
        );

        $daysAgo = (int)$today->diff($createdDate)->days;

        if ($daysAgo <= 27) {
            $bucket = 3 - intdiv($daysAgo, 7); // 0=Week1 ... 3=Week4
            $weeklyData[$bucket]++;
        }
    }

    $stmt->close();


    // ============================
    // EQUIPMENT STATUS — INVENTORY UNITS
    //
    // FIX 5: units must never silently disappear from the chart.
    //   * available_quantity is decremented on approve, so it already
    //     reflects the units that are physically free.
    //   * equipment flagged maintenance/unavailable is NOT free stock,
    //     so it is excluded from "Available".
    //   * overdue approved rentals (past end_date) are still out, so
    //     they are counted as Rented together with in-window rentals.
    // ============================
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(available_quantity), 0) AS units
         FROM equipment
         WHERE owner_id = ?
           AND COALESCE(status, 'available') = 'available'"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $availableUnits = (int)($stmt->get_result()->fetch_assoc()["units"] ?? 0);
    $stmt->close();

    // Still out = approved AND (in rental window OR overdue and not returned)
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(reservations.quantity), 0) AS units
         FROM reservations
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
           AND reservations.status = 'approved'
           AND reservations.end_date >= CURDATE()"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rentedUnits = (int)($stmt->get_result()->fetch_assoc()["units"] ?? 0);
    $stmt->close();

    $totalUnits = $availableUnits + $rentedUnits;
    $availablePct = $totalUnits > 0
        ? (int)round($availableUnits / $totalUnits * 100)
        : 0;
    $rentedPct = $totalUnits > 0 ? 100 - $availablePct : 0;
    $hasInventory = $totalUnits > 0;



    // ============================
    // RECENT RENTALS
    // ============================
    $stmt = $conn->prepare(
        "SELECT
            users.student_number,
            users.first_name,
            users.last_name,
            equipment.equipment_name,
            reservations.start_date,
            reservations.end_date,
            reservations.status
         FROM reservations
         INNER JOIN users ON reservations.user_id = users.user_id
         INNER JOIN equipment ON reservations.equipment_id = equipment.equipment_id
         WHERE equipment.owner_id = ?
         ORDER BY reservations.reservation_id DESC
         LIMIT 5"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $recentRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} catch (mysqli_sql_exception $e) {
    error_log("Admin dashboard query failed: " . $e->getMessage());
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard | KitaKits</title>

<link rel="stylesheet" href="../css/style.css">

<?php include __DIR__ . "/includes/assets.php"; ?>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

</head>


<body class="new-dashboard">


<!-- SIDEBAR -->

<?php include __DIR__ . "/includes/sidebar.php"; ?>


<!-- CONTENT -->

<div class="dashboard-content" id="content">




<div class="top-header">

<div>

<h1>
Dashboard
</h1>

<p>
Admin Overview
</p>

</div>


<div class="profile-area">

<span>
<?php echo htmlspecialchars($admin_initial); ?>
</span>

</div>

</div>




<!-- SUMMARY CARDS -->

<div class="stats">


<div class="stat-card">

<div class="stat-icon total">
<i class="fa-solid fa-box"></i>
</div>

<h4>
Total Equipment
</h4>

<strong>
<?php echo $totalEquipment; ?>
</strong>

</div>


<div class="stat-card">

<div class="stat-icon pending">
<i class="fa-solid fa-hourglass-half"></i>
</div>

<h4>
Pending Requests
</h4>

<strong>
<?php echo $pendingRequest; ?>
</strong>

</div>


<div class="stat-card">

<div class="stat-icon ongoing">
<i class="fa-solid fa-circle-check"></i>
</div>

<h4>
Active Rentals
</h4>

<strong>
<?php echo $activeRentals; ?>
</strong>

</div>


<div class="stat-card">

<div class="stat-icon overdue">
<i class="fa-solid fa-triangle-exclamation"></i>
</div>

<h4>
Overdue Items
</h4>

<strong>
<?php echo $overdueItems; ?>
</strong>

</div>


</div>
<!-- ANALYTICS -->

<div class="dashboard-charts">


<div class="chart-card">

<div class="box-title">

<h3>
Rental Overview
</h3>

</div>


<div class="chart-wrap">
<canvas id="rentalChart"></canvas>
</div>

</div>


<div class="chart-card">

<div class="box-title">

<h3>
Equipment Status
</h3>

</div>


<?php if ($hasInventory): ?>

<div class="chart-wrap pie">
<canvas id="equipmentChart"></canvas>
</div>


<div class="chart-legend">

<span>
<span class="dot" style="background:#22C55E;"></span>
Available <?php echo $availablePct; ?>%
</span>


<span>
<span class="dot" style="background:#315FD3;"></span>
Rented <?php echo $rentedPct; ?>%
</span>


</div>

<?php else: ?>

<div class="chart-wrap pie" style="display:flex;align-items:center;justify-content:center;color:#94A3B8;font-size:14px;">
No equipment added yet.
</div>

<?php endif; ?>

</div>


</div>




<!-- RECENT RENTALS -->

<div class="reservation-box recent-rentals-box">


<div class="box-title">

<h3>
Recent Rentals
</h3>


<a class="btn-view-all" href="rental_history.php">
View All
</a>

</div>


<table>

<thead>

<tr>

<th>
Student ID
</th>

<th>
User
</th>

<th>
Item
</th>

<th>
Pickup Date
</th>

<th>
Return Date
</th>

<th>
Status
</th>

</tr>

</thead>


<tbody>

<?php if (!empty($recentRows)): ?>

<?php foreach ($recentRows as $row): ?>

<?php

// Overdue = approved but past return date
$displayStatus = $row["status"];

if (
    $displayStatus === "approved"
    && $row["end_date"] < date("Y-m-d")
) {
    $displayStatus = "overdue";
}

$badgeClass = in_array(
    $displayStatus,
    ["pending", "approved", "overdue", "completed", "cancelled"],
    true
) ? $displayStatus : "pending";

?>

<tr>

<td>
<?php echo htmlspecialchars($row["student_number"] ?? "-"); ?>
</td>

<td>
<?php
echo htmlspecialchars(
    trim(($row["first_name"] ?? "") . " " . ($row["last_name"] ?? ""))
);
?>
</td>

<td>
<?php echo htmlspecialchars($row["equipment_name"] ?? "-"); ?>
</td>

<td>
<?php echo date("M d, Y", strtotime($row["start_date"])); ?>
</td>

<td>
<?php echo date("M d, Y", strtotime($row["end_date"])); ?>
</td>

<td>

<span class="status-badge <?php echo $badgeClass; ?>">
<?php echo ucfirst($displayStatus); ?>
</span>

</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>

<td colspan="6" style="text-align:center;color:#94A3B8;">
No rental records yet.
</td>

</tr>

<?php endif; ?>

</tbody>

</table>


</div>


</div>
<!-- END CONTENT -->




<script>


// SIDEBAR COLLAPSE

// Sidebar collapse: see js/admin-sidebar.js (loaded in <body>)


// RENTAL OVERVIEW — LINE CHART (Chart.js)
if (typeof Chart !== "undefined") {

    new Chart(document.getElementById("rentalChart"), {
        type: "line",
        data: {
            labels: ["Week 1", "Week 2", "Week 3", "Week 4"],
            datasets: [{
                label: "Rentals",
                data: <?php echo json_encode($weeklyData); ?>,
                borderColor: "#315FD3",
                backgroundColor: "rgba(49, 95, 211, 0.08)",
                borderWidth: 2,
                fill: true,
                tension: 0.35,
                pointBackgroundColor: "#315FD3",
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, stepSize: 1 }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });


    // EQUIPMENT STATUS — PIE CHART (Chart.js)
    const equipmentCanvas = document.getElementById("equipmentChart");

    if (equipmentCanvas) {

        new Chart(equipmentCanvas, {
            type: "pie",
            data: {
                labels: ["Available", "Rented"],
                datasets: [{
                    data: [
                        <?php echo $availableUnits; ?>,
                        <?php echo $rentedUnits; ?>
                    ],
                    backgroundColor: ["#22C55E", "#315FD3"],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                }
            }
        });

    }

} else {

    // CDN fallback — keep layout usable without Chart.js
    const rentalFallback = document.getElementById("rentalChart");

    if (rentalFallback && rentalFallback.parentElement) {
        rentalFallback.parentElement.innerHTML =
            '<p style="text-align:center;color:#94A3B8;padding-top:120px;">Chart library unavailable (offline).</p>';
    }

}


</script>


</body>

</html>
