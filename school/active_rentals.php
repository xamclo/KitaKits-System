<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/school_auth.php";
require_once __DIR__ . "/../includes/school_queries.php";
require_once __DIR__ . "/../includes/school_layout.php";
requireSchoolRole();
function smRows($conn, string $where, string $order, int $limit = 200): array {
    $res = $conn->query(smTransactionSql() . " WHERE " . $where . " ORDER BY " . $order . " LIMIT " . (int)$limit);
    $out = [];
    while ($r = $res->fetch_assoc()) { $out[] = smShapeRow($r); }
    $res->free();
    return $out;
}
function smEmpty(string $msg): void {
    echo '<div class="sm-empty"><i class="fa-solid fa-inbox" style="font-size:26px;display:block;margin-bottom:10px;"></i>'
       . htmlspecialchars($msg) . "</div>";
}
?>
<?php
$rows = smRows($conn,
    "e.requires_school_approval = 1 AND r.fulfillment_status = 'active' AND r.returned_at IS NULL",
    "r.start_date ASC");
smPageHead("Active Rentals", "Active Rentals",
    "High-value equipment currently released and in the borrower's possession, monitored by School Management.", "active");
?>
<div class="sm-panel">
    <div class="sm-panel-head"><h2>Active Rentals</h2></div>
<?php if (!$rows) { smEmpty("No high-value rental is currently active."); } else { ?>
    <div class="table-responsive">
    <table class="sm-table">
      <thead><tr>
        <th>Rental ID</th><th>Equipment</th><th>Student</th><th>Owner</th>
        <th>Pickup</th><th>Expected Return</th><th>Rental Status</th><th>School Witness</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t) { ?>
        <tr>
          <td><strong>RES-<?php echo $t["reservation_id"]; ?></strong></td>
          <td><div class="sm-eq"><?php echo smThumb($t["equipment"]["image"], $t["equipment"]["name"]); ?>
            <div><div class="sm-eq-name"><?php echo htmlspecialchars($t["equipment"]["name"]); ?></div>
            <div class="sm-sub"><?php echo htmlspecialchars((string)$t["equipment"]["asset_code"] ?: "No asset ID"); ?></div></div></div></td>
          <td><div><?php echo htmlspecialchars($t["student"]["name"]); ?>
            <div class="sm-sub"><?php echo htmlspecialchars((string)$t["student"]["number"]); ?></div></div></td>
          <td><?php echo htmlspecialchars($t["owner"]["name"]); ?></td>
          <td><?php echo htmlspecialchars(sm_d($t["dates"]["start"])); ?></td>
          <td><?php echo htmlspecialchars(sm_d($t["dates"]["end"])); ?></td>
          <td><span class="sm-badge-pill <?php echo $t["fulfillment"]["css"]; ?>"><?php echo htmlspecialchars($t["fulfillment"]["state"]); ?></span></td>
          <td><span class="sm-badge-pill <?php echo $t["school"]["css"]; ?>"><?php echo htmlspecialchars($t["school"]["state"]); ?></span></td>
          <td><button class="sm-btn-view" onclick="smOpenActive(<?php echo $t["reservation_id"]; ?>)">View Details</button></td>
        </tr>
      <?php } ?>
</tbody>
    </table>
    </div>
    <?php } ?>
</div>
<?php include __DIR__ . "/modals.php"; ?>
<?php smPageFoot(); ?>
