<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION["user_id"];

// FILTER VALUES
$search = $_GET["search"] ?? "";
$category = $_GET["category"] ?? "";
$sort = $_GET["sort"] ?? "";

// EQUIPMENT QUERY
// The base query only enforces what the student is allowed to rent.
// Category filtering, sorting and search narrowing are applied live in
// js/equipment-list.js against these same records, so switching a
// dropdown never strands the student in a pre-filtered result set.
$sql = "
    SELECT *
    FROM equipment
    WHERE status = 'available'
    AND available_quantity > 0
    AND owner_id != ?
";

$params = [$user_id];
$types = "i";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// CATEGORY LIST
$categoryStmt = $conn->prepare("
    SELECT DISTINCT category
    FROM equipment
    WHERE status = 'available'
    AND available_quantity > 0
    AND owner_id != ?
    ORDER BY category ASC
");
$categoryStmt->bind_param("i", $user_id);
$categoryStmt->execute();
$categories = $categoryStmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment | KitaKits</title>
    <link rel="stylesheet" href="../css/style.css?v=200">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">
    <link rel="stylesheet" href="../css/equipment-list.css">
</head>
<body class="new-dashboard">

    <!-- SIDEBAR -->
    <?php $__sbActive = "equipment"; include __DIR__ . "/../includes/sidebar_student.php"; ?>

    <!-- MAIN CONTENT -->
    <div class="dashboard-content" id="content">
        <div class="top-header">
            <div>
                <h1>Available Equipment</h1>
                <p>Browse equipment available for rental.</p>
            </div>

            <?php include "../includes/student_header_actions.php"; ?>
        </div>

        <!-- SEARCH / FILTER -->
            <form method="GET" class="equipment-filter">


            <div class="search-container">

            <i class="fa-solid fa-magnifying-glass"></i>

            <input
            type="text"
            name="search"
            id="equipmentSearch"
            placeholder="Search equipment..."
            value="<?php echo htmlspecialchars($search); ?>">

            </div>


            <select name="category" id="categoryFilter">

            <option value=""<?php echo $category === "" ? " selected" : ""; ?>>
            All Categories
            </option>


            <?php while($cat = $categories->fetch_assoc()): ?>

            <option value="<?php echo htmlspecialchars($cat["category"]); ?>"<?php echo $category === $cat["category"] ? " selected" : ""; ?>>

            <?php echo htmlspecialchars($cat["category"]); ?>

            </option>

            <?php endwhile; ?>


            </select>



            <select name="sort" id="sortFilter">


            <option value=""<?php echo $sort === "" ? " selected" : ""; ?>>
            Sort By
            </option>


            <option value="price_low"<?php echo $sort === "price_low" ? " selected" : ""; ?>>
            Price Low to High
            </option>


            <option value="price_high"<?php echo $sort === "price_high" ? " selected" : ""; ?>>
            Price High to Low
            </option>


            <option value="quantity"<?php echo $sort === "quantity" ? " selected" : ""; ?>>
            Availability
            </option>


            </select>



            </form>

        <!-- EQUIPMENT GRID -->
        <div class="equipment-grid" id="equipmentGrid">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($equipment = $result->fetch_assoc()): ?>
                    <div class="equipment-card"
                         data-category="<?php echo htmlspecialchars((string)$equipment["category"]); ?>"
                         data-name="<?php echo htmlspecialchars((string)$equipment["equipment_name"]); ?>"
                         data-price="<?php echo (float)$equipment["rental_price"]; ?>"
                         data-qty="<?php echo (int)$equipment["available_quantity"]; ?>">
                        <div class="equipment-image">
                            <?php if (!empty($equipment["equipment_image"])): ?>
                                <img
                                    src="../uploads/<?php echo htmlspecialchars($equipment["equipment_image"]); ?>"
                                    alt="<?php echo htmlspecialchars($equipment["equipment_name"]); ?>"
                                >
                            <?php else: ?>
                                <i class="fa-solid fa-image"></i>
                            <?php endif; ?>
                        </div>

                        <span class="category-badge">
                            <?php echo htmlspecialchars($equipment["category"]); ?>
                        </span>

                        <h2><?php echo htmlspecialchars($equipment["equipment_name"]); ?></h2>

                        <p class="equipment-description">
                            <?php echo htmlspecialchars($equipment["description"]); ?>
                        </p>

                        <h3>
                            ₱<?php echo number_format($equipment["rental_price"], 2); ?>
                            <span class="per-day">/ day</span>
                        </h3>

                        <div class="available-badge">
                            <i class="fa-solid fa-circle"></i>
                            <?php echo (int)$equipment["available_quantity"]; ?> Available
                        </div>

                        <div class="equipment-actions">
                            <a class="details-btn" href="equipment_detail.php?id=<?php echo $equipment["equipment_id"]; ?>">
                                View Details
                            </a>

                            <a class="reserve-btn" href="equipment_detail.php?id=<?php echo $equipment["equipment_id"]; ?>">
                                <i class="fa-solid fa-cart-shopping"></i>
                                Reserve
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-equipment">
                    <h3>No Equipment Available</h3>
                    <p>Try changing your search or filter.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="../js/equipment-list.js"></script>
    <script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>
</html>
