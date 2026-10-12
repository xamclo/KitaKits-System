<?php
/**
 * School Management sidebar.
 *
 * This is a SEPARATE component from includes/sidebar_student.php and
 * admin/includes/sidebar.php. Neither of those files is modified or
 * included here, so the Student and Admin layouts cannot change.
 *
 * Seven sections, exactly as specified.
 *
 * Usage:
 *   <?php $__smActive = "dashboard"; include __DIR__ . "/../../includes/sidebar_school.php"; ?>
 */

// This file is included from smPageHead(), which is a function, so the
// caller's $conn is not automatically in scope here. Import it explicitly
// so the counters below always have a connection to query.
global $conn;

$__smActive = $__smActive ?? "dashboard";

/**
 * Each item declares its own counter query. A counter is only shown when
 * the query returns a value greater than zero, so no number on screen is
 * ever a placeholder.
 */
$__smItems = [
    [
        "key"   => "dashboard",
        "file"  => "dashboard.php",
        "label" => "Dashboard",
        "icon"  => "fa-gauge-high",
        "badge" => null,
    ],
    [
        "key"   => "approvals",
        "file"  => "pending_witness_requests.php",
        "label" => "Pending Witness Requests",
        "icon"  => "fa-clipboard-check",
        "badge" => "SELECT COUNT(*) FROM school_approval_requests a
                    INNER JOIN reservations r ON a.reservation_id = r.reservation_id
                    WHERE a.status = 'pending' AND r.status IN ('pending','approved')",
    ],
    [
        "key"   => "active",
        "file"  => "active_rentals.php",
        "label" => "Active Rentals",
        "icon"  => "fa-laptop-code",
        "badge" => "SELECT COUNT(DISTINCT r.reservation_id) FROM reservations r
                    INNER JOIN equipment e ON r.equipment_id = e.equipment_id
                    WHERE e.requires_school_approval = 1
                      AND r.fulfillment_status = 'active' AND r.returned_at IS NULL",
    ],
    [
        "key"   => "returns",
        "file"  => "return_verifications.php",
        "label" => "Return Verifications",
        "icon"  => "fa-rotate-left",
        "badge" => "SELECT COUNT(*) FROM school_return_verifications v
                    INNER JOIN reservations r ON v.reservation_id = r.reservation_id
                    WHERE v.status = 'pending' AND r.returned_at IS NOT NULL",
    ],
    [
        "key"   => "reports",
        "file"  => "reports.php",
        "label" => "Reports / Issues",
        "icon"  => "fa-triangle-exclamation",
        "badge" => "SELECT COUNT(*) FROM reports WHERE status IN ('open','pending','under_review')",
    ],
    [
        "key"   => "history",
        "file"  => "transaction_history.php",
        "label" => "Transaction History",
        "icon"  => "fa-clock-rotate-left",
        "badge" => null,
    ],
    [
        "key"   => "inventory",
        "file"  => "inventory.php",
        "label" => "Inventory Overview",
        "icon"  => "fa-boxes-stacked",
        "badge" => null,
    ],
];

// Resolve the counters once, server-side.
$__smCounts = [];
foreach ($__smItems as $__smIt) {
    if (!empty($__smIt["badge"])) {
        $__smRes = $conn->query($__smIt["badge"]);
        $__smCounts[$__smIt["key"]] = $__smRes ? (int)($__smRes->fetch_row()[0] ?? 0) : 0;
        if ($__smRes) {
            $__smRes->free();
        }
    } else {
        $__smCounts[$__smIt["key"]] = 0;
    }
}
?>
<div class="side-menu sm-side" id="sidebar">

    <div class="sm-brand">
        <span class="sm-brand-mark">
            <i class="fa-solid fa-school"></i>
        </span>
        <span class="sm-brand-name">KitaKits</span>
        <button class="menu-btn" id="menuBtn" type="button" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <div class="sm-role-label">SCHOOL MANAGEMENT</div>

    <?php foreach ($__smItems as $__smIt): ?>
        <?php $__smN = $__smCounts[$__smIt["key"]]; ?>
        <a class="<?php echo $__smActive === $__smIt["key"] ? "active" : ""; ?>"
           href="<?php echo htmlspecialchars($__smIt["file"]); ?>"
           title="<?php echo htmlspecialchars($__smIt["label"]); ?>"
           aria-label="<?php echo htmlspecialchars($__smIt["label"]); ?>">

            <span class="menu-icon"><i class="fa-solid <?php echo $__smIt["icon"]; ?>"></i></span>

            <span class="menu-text"><?php echo htmlspecialchars($__smIt["label"]); ?></span>

            <?php if ($__smN > 0): ?>
                <span class="sm-badge"><?php echo $__smN; ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>

    <a class="logout" id="schoolLogout" href="../logout.php"
       title="Logout" aria-label="Logout">
        <span class="menu-icon"><i class="fa-solid fa-right-from-bracket"></i></span>
        <span class="menu-text">Logout</span>
    </a>
</div>


<!-- LOGOUT CONFIRMATION -->
<!-- Identical behaviour to the Student and Admin dialogs, but its own
     copy so the School Management stylesheet stays self-contained. -->
<div class="logout-modal" id="logoutModal" role="dialog" aria-modal="true"
     aria-labelledby="logoutModalTitle" aria-describedby="logoutModalText">

    <div class="logout-modal__card">
        <div class="logout-modal__icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>

        <h3 class="logout-modal__title" id="logoutModalTitle">Confirm Logout</h3>

        <p class="logout-modal__text" id="logoutModalText">
            Are you sure you want to log out?
        </p>

        <div class="logout-modal__actions">
            <button type="button" class="logout-modal__btn logout-modal__btn--cancel"
                    id="logoutCancel">Cancel</button>
            <button type="button" class="logout-modal__btn logout-modal__btn--confirm"
                    id="logoutConfirm">Yes, Logout</button>
        </div>
    </div>
</div>