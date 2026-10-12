<?php
/**
 * School Management - Dashboard.
 *
 * The four counters and the Recent Transactions table are rendered from
 * the real database on the server. No number here is hardcoded.
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/school_auth.php";
require_once __DIR__ . "/../includes/school_queries.php";
require_once __DIR__ . "/../includes/school_layout.php";

requireSchoolRole();

$pendingApprovals = (int)$conn->query(
    "SELECT COUNT(*) FROM school_approval_requests a
     INNER JOIN reservations r ON a.reservation_id = r.reservation_id
     WHERE a.status = 'pending' AND r.status IN ('pending','approved')"
)->fetch_row()[0];

    $activeRentals = (int)$conn->query(
        "SELECT COUNT(*) FROM reservations r
         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
         WHERE e.requires_school_approval = 1
           AND r.fulfillment_status = 'active'
           AND r.returned_at IS NULL"
    )->fetch_row()[0];

$returnVerifications = (int)$conn->query(
    "SELECT COUNT(*) FROM school_return_verifications v
     INNER JOIN reservations r ON v.reservation_id = r.reservation_id
     WHERE v.status = 'pending' AND r.returned_at IS NOT NULL"
)->fetch_row()[0];

$reportedIssues = (int)$conn->query(
    "SELECT COUNT(*) FROM reports WHERE status IN ('open','pending','under_review')"
)->fetch_row()[0];

$recentRes = $conn->query(
    smTransactionSql() . "
     WHERE e.requires_school_approval = 1
     ORDER BY GREATEST(r.created_at, IFNULL(r.returned_at,'1970-01-01'),
                       IFNULL(a.reviewed_at,'1970-01-01'),
                       IFNULL(rv.verified_at,'1970-01-01')) DESC
     LIMIT 12"
);
$recent = [];
while ($r = $recentRes->fetch_assoc()) {
    $recent[] = smShapeRow($r);
}
$recentRes->free();

smPageHead(
    "School Management Dashboard",
    "School Management Dashboard",
    "Monitor and witness high-value equipment transactions for transparency and accountability.",
    "dashboard"
);
?>

<div class="sm-cards">

    <div class="sm-card">
        <div class="sm-card-top">
            <div class="sm-card-icon purple"><i class="fa-solid fa-clipboard-check"></i></div>
        </div>
        <div>
            <h3>Pending Witness Requests</h3>
            <div class="count"><?php echo $pendingApprovals; ?></div>
        </div>
        <a href="pending_witness_requests.php">View pending &rarr;</a>
    </div>

    <div class="sm-card">
        <div class="sm-card-top">
            <div class="sm-card-icon green"><i class="fa-solid fa-laptop-code"></i></div>
        </div>
        <div>
            <h3>Active Rentals</h3>
            <div class="count"><?php echo $activeRentals; ?></div>
        </div>
        <a href="active_rentals.php">View active &rarr;</a>
    </div>

    <div class="sm-card">
        <div class="sm-card-top">
            <div class="sm-card-icon blue"><i class="fa-solid fa-rotate-left"></i></div>
        </div>
        <div>
            <h3>Return Verifications</h3>
            <div class="count"><?php echo $returnVerifications; ?></div>
        </div>
        <a href="return_verifications.php">View returns &rarr;</a>
    </div>

    <div class="sm-card">
        <div class="sm-card-top">
            <div class="sm-card-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
        </div>
        <div>
            <h3>Reported Issues</h3>
            <div class="count"><?php echo $reportedIssues; ?></div>
        </div>
        <a href="reports.php">View reports &rarr;</a>
    </div>
</div>

<div class="sm-panel">
    <div class="sm-panel-head">
        <h2>Recent Transactions</h2>
        <a class="sm-link-btn" href="transaction_history.php">View All Transactions &rarr;</a>
    </div>

    <?php if (count($recent) === 0): ?>
        <div class="sm-empty">
            <i class="fa-solid fa-clipboard-list" style="font-size:26px;display:block;margin-bottom:10px;"></i>
            No high-value transactions recorded yet.
        </div>
<?php else: ?>
        <div class="table-responsive">
        <table class="sm-table">
            <thead>
                <tr>
                    <th>Date</th><th>Equipment</th><th>Student</th>
                    <th>Admin / Owner</th><th>Type</th><th>Status</th><th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $tx): ?>
                <?php
                $isReturn   = !empty($tx["dates"]["returned"]);
                $typeLabel  = $isReturn ? "Return" : "Borrow";
                $typeClass  = $isReturn ? "return" : "borrow";
                ?>
                <tr>
                    <td><?php echo htmlspecialchars(date("M d, Y", strtotime((string)$tx["dates"]["requested"]))); ?></td>
                    <td>
                        <div class="sm-eq">
                            <?php echo smThumb($tx["equipment"]["image"], $tx["equipment"]["name"]); ?>
                            <div>
                                <div class="sm-eq-name"><?php echo htmlspecialchars($tx["equipment"]["name"]); ?></div>
                                <div class="sm-sub"><?php echo htmlspecialchars((string)$tx["equipment"]["category"]); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="sm-person">
                            <span class="sm-ava stu"><?php echo strtoupper(mb_substr((string)$tx["student"]["first"], 0, 1)); ?></span>
                            <div>
                                <div><?php echo htmlspecialchars($tx["student"]["name"]); ?></div>
                                <div class="sm-sub"><?php echo htmlspecialchars((string)$tx["student"]["number"]); ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="sm-person">
                            <span class="sm-ava owner"><?php echo strtoupper(mb_substr((string)$tx["owner"]["first"], 0, 1)); ?></span>
                            <div><?php echo htmlspecialchars($tx["owner"]["name"]); ?></div>
                        </div>
                    </td>
                    <td><span class="sm-badge-pill <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                    <td><span class="sm-badge-pill <?php echo $tx["fulfillment"]["css"]; ?>"><?php echo htmlspecialchars($tx["fulfillment"]["state"]); ?></span></td>
                    <td>
                        <button class="sm-btn-view" onclick="smOpenHistory(<?php echo $tx["reservation_id"]; ?>)">View Details</button>
                    </td>
                </tr>
            <?php endforeach; ?>
</tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/modals.php"; ?>
<?php smPageFoot(); ?>

<script>
function smOpenHistory(id) {
    smFetchHistory(id, function (res) {
        if (!res.ok) { smToast(res.message, "err"); return; }
        smRenderHistory(res.tx, res.timeline);
        smModal.open("smHistoryModal");
    });
}
</script>
