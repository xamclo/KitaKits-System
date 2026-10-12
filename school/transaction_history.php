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
$rows = smRows($conn, "e.requires_school_approval = 1", "r.created_at DESC", 300);
smPageHead("Transaction History", "Transaction History",
    "Complete institutional audit record of every high-value rental transaction.", "history");
?>
<div class="sm-panel">
    <div class="sm-panel-head"><h2>Transaction History</h2></div>
<?php if (!$rows) { smEmpty("No high-value transaction has been recorded yet."); } else { ?>
    <div class="table-responsive">
    <table class="sm-table">
      <thead><tr>
        <th>Transaction ID</th><th>Equipment</th><th>Borrower</th><th>Owner</th>
        <th>Requested</th><th>School Witness</th><th>Return Verification</th>
        <th>Rental Status</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t) { ?>
        <tr>
          <td><strong>TX-<?php echo $t["reservation_id"]; ?></strong></td>
          <td><div class="sm-eq"><?php echo smThumb($t["equipment"]["image"], $t["equipment"]["name"]); ?>
            <div class="sm-eq-name"><?php echo htmlspecialchars($t["equipment"]["name"]); ?></div></div></td>
          <td><?php echo htmlspecialchars($t["student"]["name"]); ?></td>
          <td><?php echo htmlspecialchars($t["owner"]["name"]); ?></td>
          <td><?php echo htmlspecialchars(sm_d($t["dates"]["requested"])); ?></td>
          <td><span class="sm-badge-pill <?php echo $t["school"]["css"]; ?>"><?php echo htmlspecialchars($t["school"]["state"]); ?></span></td>
          <td><span class="sm-badge-pill <?php echo $t["verification"]["css"]; ?>"><?php echo htmlspecialchars($t["verification"]["state"]); ?></span></td>
          <td><span class="sm-badge-pill <?php echo $t["fulfillment"]["css"]; ?>"><?php echo htmlspecialchars($t["fulfillment"]["state"]); ?></span></td>
          <td><button class="sm-btn-view" onclick="smOpenHistory(<?php echo $t["reservation_id"]; ?>)">View Details</button></td>
        </tr>
      <?php } ?>
</tbody>
    </table>
    </div>
    <?php } ?>
</div>
<?php include __DIR__ . "/modals.php"; ?>
<?php smPageFoot(); ?>
