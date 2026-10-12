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
    "e.requires_school_approval = 1 AND rv.status = 'pending' AND r.returned_at IS NOT NULL",
    "r.returned_at ASC");
smPageHead("Return Verifications", "Return Verifications",
    "Independent verification of returned high-value equipment and its recorded condition.", "returns");
?>
<div class="sm-panel">
    <div class="sm-panel-head"><h2>Return Verifications</h2></div>
<?php if (!$rows) { smEmpty("No return is awaiting School Management verification."); } else { ?>
    <div class="table-responsive">
    <table class="sm-table">
      <thead><tr>
        <th>Reservation ID</th><th>Equipment</th><th>Borrower</th><th>Owner</th>
        <th>Date Returned</th><th>Reported Condition</th><th>Verification</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $t) { ?>
        <tr>
          <td><strong>RES-<?php echo $t["reservation_id"]; ?></strong></td>
          <td><div class="sm-eq"><?php echo smThumb($t["equipment"]["image"], $t["equipment"]["name"]); ?>
            <div class="sm-eq-name"><?php echo htmlspecialchars($t["equipment"]["name"]); ?></div></div></td>
          <td><?php echo htmlspecialchars($t["student"]["name"]); ?></td>
          <td><?php echo htmlspecialchars($t["owner"]["name"]); ?></td>
          <td><?php echo htmlspecialchars(sm_dt($t["dates"]["returned"])); ?></td>
          <td><?php echo htmlspecialchars((string)$t["fulfillment"]["return_condition"] ?: "Not recorded"); ?></td>
          <td><span class="sm-badge-pill <?php echo $t["verification"]["css"]; ?>"><?php echo htmlspecialchars($t["verification"]["state"]); ?></span></td>
          <td><button class="sm-btn-view" onclick="smOpenReturn(<?php echo $t["reservation_id"]; ?>)">Verify Return Record</button></td>
        </tr>
      <?php } ?>
</tbody>
    </table>
    </div>
    <?php } ?>
</div>
<?php include __DIR__ . "/modals.php"; ?>
<?php smPageFoot(); ?>
