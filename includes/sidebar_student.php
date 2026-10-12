<?php
/**
 * Student Sidebar - single shared component
 *
 * WHY THIS FILE EXISTS
 * The sidebar was duplicated by hand on every Student page and the copies
 * had drifted apart:
 *   - only dashboard.php had the real brand mark (icon + wordmark); every
 *     other page used a bare "KitaKits" text span
 *   - several pages used the raw U+2630 hamburger character instead of the
 *     Font Awesome bars icon
 *   - reservations.php and reservation_details.php used fa-cube for the
 *     Equipment item while every other page used fa-box, so that icon
 *     appeared to change when navigating
 *   - every page repeated the menu and the collapse wiring
 *
 * student/dashboard.php is the working reference, so its markup is
 * reproduced here verbatim. The visual design is unchanged - this is the
 * same structure the Dashboard already rendered.
 *
 * Usage (replaces the whole <div class="side-menu"> ... </div> block):
 *   <?php
 *     $__sbActive = "equipment";
 *     include __DIR__ . "/../includes/sidebar_student.php";
 *   ?>
 *
 * $__sbActive accepts dashboard | equipment | reservations | payments |
 * history | notifications | profile. Sub-pages pass their parent key so
 * the correct item stays highlighted.
 */

$__sbActive = $__sbActive ?? "";

// Normalise so a sub-page can pass any alias of its parent.
$__sbAliases = [
    "dashboard"           => "dashboard",
    "equipment"           => "equipment",
    "equipment_detail"    => "equipment",
    "equipment_details"   => "equipment",
    "reservation"         => "equipment",
    "reservations"        => "reservations",
    "reservation_details" => "reservations",
    "reservation_success" => "reservations",
    "report_issue"        => "reservations",
    "payments"            => "payments",
    "history"             => "history",
    "notifications"       => "notifications",
    "profile"             => "profile",
];

$__sbKey = $__sbAliases[strtolower(trim((string)$__sbActive))] ?? "";

/**
 * Emits one menu link. The icon class is defined ONCE here, which is what
 * guarantees an item keeps the same icon no matter which page renders it.
 */
$__sbLink = function ($href, $icon, $label, $key) use ($__sbKey) {
    echo '<a href="' . $href . '"' . (($__sbKey === $key) ? ' class="active"' : '') . '>';
    echo '<span class="menu-icon"><i class="fa-solid ' . $icon . '"></i></span>';
    echo '<span class="menu-text">' . $label . '</span>';
    echo '</a>';
};
?>
<div class="side-menu" id="sidebar">

    <!-- Brand: identical to the working Student Dashboard -->
    <div class="brand">
        <span class="brand-mark">
            <i class="fa-solid fa-box-open"></i>
            <span class="brand-name">KitaKits</span>
        </span>
        <button class="menu-btn" id="menuBtn" type="button" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <!-- Student account block -->
    <?php include __DIR__ . "/sidebar_user.php"; ?>

    <?php
    $__sbLink("dashboard.php",     "fa-house",             "Dashboard",       "dashboard");
    $__sbLink("equipment.php",     "fa-box",               "Equipment",       "equipment");
    $__sbLink("reservations.php",  "fa-calendar-check",    "My Reservations", "reservations");
    $__sbLink("payments.php",      "fa-credit-card",       "Payments",        "payments");
    $__sbLink("history.php",       "fa-clock-rotate-left", "Rental History",  "history");
    $__sbLink("notifications.php", "fa-bell",              "Notifications",   "notifications");
    $__sbLink("profile.php",       "fa-user",              "Profile",         "profile");
    ?>

    <!-- Same markup and ids as admin/includes/sidebar.php, so the logout
         dialog is identical on both sides and js/student-sidebar.js can
         drive it without any per-page script. -->
    <a class="logout" id="studentLogout" href="../logout.php"
       title="Logout" aria-label="Logout">

        <span class="menu-icon"><i class="fa-solid fa-right-from-bracket"></i></span>

        <span class="menu-text">Logout</span>

    </a>
</div>


<!-- LOGOUT CONFIRMATION -->
<!-- Shown by js/student-sidebar.js. Kept here so every Student page gets
     the same dialog without duplicating markup (this is what was missing
     on profile.php, reservation.php and reservation_success.php). -->
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
