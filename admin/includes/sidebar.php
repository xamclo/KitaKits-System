<?php
/**
 * Shared Admin Sidebar — the single source of truth for admin navigation.
 *
 * Every page under /admin MUST include this instead of writing its own
 * sidebar markup, otherwise icons, spacing and active state will drift
 * between pages:
 *
 *   <?php include __DIR__ . "/includes/sidebar.php"; ?>
 *
 * Also load, in this order, in <head>:
 *   ../css/style.css
 *   ../css/admin-sidebar.css   <-- sidebar layout lives here
 *   font-awesome CDN
 *
 * and in <body> (or before </body>):
 *   <script src="../js/admin-sidebar.js"></script>
 *
 * Requires: an active admin session and $conn from config/database.php
 * (pulled in by includes/sidebar_user.php).
 */

// Map helper: the "Manage Equipment" page has a legacy twin
// (equipment.php). Both should light up the same menu item.
$adminPage = basename($_SERVER["PHP_SELF"] ?? "");
$adminMenu = [
    ["file" => "dashboard.php",         "label" => "Dashboard",         "icon" => "fa-gauge-high"],
    ["file" => "manage_equipment.php",  "label" => "Manage Equipment",  "icon" => "fa-boxes-stacked"],
    ["file" => "rental_request.php",    "label" => "Rental Request",    "icon" => "fa-clipboard-list"],
    ["file" => "rental_history.php",    "label" => "Rental History",    "icon" => "fa-clock-rotate-left"],
    ["file" => "transactions.php",      "label" => "Transactions",      "icon" => "fa-money-bill-transfer"],
];

// Aliases -> keep the highlight correct on secondary pages.
$adminAliases = [
    "equipment.php"      => "manage_equipment.php",
    "add_equipment.php"  => "manage_equipment.php",
    "edit_equipment.php" => "manage_equipment.php",
    "reservations.php"   => "rental_request.php",
];

$adminActive = $adminAliases[$adminPage] ?? $adminPage;
?>
<div class="side-menu" id="sidebar">

    <div class="brand">

        <span class="brand-mark">
            <i class="fa-solid fa-box-open"></i>
            <span class="brand-name">KitaKits</span>
        </span>

        <button class="menu-btn" id="menuBtn" type="button" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>

    </div>

    <?php include __DIR__ . "/../../includes/sidebar_user.php"; ?>

<?php foreach ($adminMenu as $item): ?>
    <a class="<?php echo $adminActive === $item["file"] ? "active" : ""; ?>"
       href="<?php echo $item["file"]; ?>"
       title="<?php echo htmlspecialchars($item["label"]); ?>"
       aria-label="<?php echo htmlspecialchars($item["label"]); ?>">

        <span class="menu-icon">
            <i class="fa-solid <?php echo $item["icon"]; ?>"></i>
        </span>

        <span class="menu-text">
            <?php echo $item["label"]; ?>
        </span>

    </a>
<?php endforeach; ?>

    <a class="logout" id="adminLogout" href="../logout.php"
       title="Logout" aria-label="Logout">

        <span class="menu-icon">
            <i class="fa-solid fa-right-from-bracket"></i>
        </span>

        <span class="menu-text">
            Logout
        </span>

    </a>

</div>


<!-- LOGOUT CONFIRMATION -->
<!-- Shown by js/admin-sidebar.js. Kept here so every admin page
     gets the same dialog without duplicating markup. -->
<div class="logout-modal" id="logoutModal" role="dialog" aria-modal="true"
     aria-labelledby="logoutModalTitle" aria-describedby="logoutModalText">

    <div class="logout-modal__card">

        <div class="logout-modal__icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>

        <h3 class="logout-modal__title" id="logoutModalTitle">
            Confirm Logout
        </h3>

        <p class="logout-modal__text" id="logoutModalText">
            Are you sure you want to log out?
        </p>

        <div class="logout-modal__actions">

            <button type="button" class="logout-modal__btn logout-modal__btn--cancel"
                    id="logoutCancel">
                Cancel
            </button>

            <button type="button" class="logout-modal__btn logout-modal__btn--confirm"
                    id="logoutConfirm">
                Yes, Logout
            </button>

        </div>

    </div>

</div>

