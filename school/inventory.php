<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/school_auth.php";
require_once __DIR__ . "/../includes/school_layout.php";
requireSchoolRole();

// Read-only monitoring. School Management never edits student equipment
// here: no delete, no price change, no ownership change.
$res = $conn->query(
    "SELECT e.*, u.first_name, u.last_name,
            (SELECT COALESCE(SUM(r.quantity),0) FROM reservations r
              WHERE r.equipment_id = e.equipment_id) AS total_rented,
            (SELECT COUNT(*) FROM reservations r
              WHERE r.equipment_id = e.equipment_id
                AND r.status = 'approved' AND r.returned_at IS NULL) AS active_rentals
     FROM equipment e
     INNER JOIN users u ON e.owner_id = u.user_id
     WHERE e.requires_school_approval = 1
     ORDER BY e.equipment_name ASC"
);
$items = [];
while ($x = $res->fetch_assoc()) {
    $x["owner_name"] = trim($x["first_name"] . " " . $x["last_name"]);
    $items[] = $x;
}
$res->free();

smPageHead("Inventory Overview", "Inventory Overview",
    "Monitoring view of high-value equipment registered under school oversight.", "inventory");
?>
<div class="sm-panel">
    <div class="sm-panel-head"><h2>Monitored Equipment</h2></div>
    <?php if (!$items) { ?>
        <div class="sm-empty"><i class="fa-solid fa-boxes-stacked" style="font-size:26px;display:block;margin-bottom:10px;"></i>
            No equipment is currently classified as high-value.</div>
<?php } else { ?>
    <div class="table-responsive">
    <table class="sm-table">
      <thead><tr>
        <th>Equipment</th><th>Category</th><th>Owner</th><th>Classification</th>
        <th>Declared Value</th><th>Total Qty</th><th>Available</th>
        <th>Active Rentals</th><th>Condition</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $x) { ?>
        <tr>
          <td><div class="sm-eq"><?php echo smThumb($x["equipment_image"], $x["equipment_name"]); ?>
            <div><div class="sm-eq-name"><?php echo htmlspecialchars($x["equipment_name"]); ?></div>
            <div class="sm-sub"><?php echo htmlspecialchars((string)$x["asset_code"] ?: "No asset ID"); ?></div></div></div></td>
          <td><?php echo htmlspecialchars((string)$x["category"]); ?></td>
          <td><?php echo htmlspecialchars($x["owner_name"]); ?></td>
          <td><span class="sm-badge-pill pending">High-Value</span></td>
          <td><?php echo $x["declared_value"] !== null && $x["declared_value"] !== ""
                ? "PHP ".htmlspecialchars(smMoney2($x["declared_value"])) : "Not recorded"; ?></td>
          <td><?php echo (int)$x["total_rented"]; ?></td>
          <td><strong><?php echo (int)$x["available_quantity"]; ?></strong></td>
          <td><?php echo (int)$x["active_rentals"]; ?></td>
          <td><?php echo htmlspecialchars((string)$x["equipment_condition"] ?: "-"); ?></td>
          <td><button class="sm-btn-view" onclick="smShowInv(<?php echo (int)$x["equipment_id"]; ?>)">View Details</button></td>
        </tr>
      <?php } ?>
</tbody>
    </table>
    </div>
    <?php } ?>
</div>

<div class="sm-modal" id="smInvModal" role="dialog" aria-modal="true">
  <div class="sm-modal-card">
    <div class="sm-modal-head">
      <h3>Equipment Details</h3>
      <button class="sm-modal-close" type="button" onclick="smModal.close('smInvModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sm-modal-body">
      <div class="sm-equip-hero" id="smInvHero"></div>
      <dl class="sm-kv" id="smInvKv"></dl>
      <div class="sm-section-title">Declared Accessories</div>
      <div id="smInvAcc"></div>
    </div>
    <div class="sm-modal-foot">
      <button class="sm-btn-cancel" type="button" onclick="smModal.close('smInvModal')">Close</button>
    </div>
  </div>
</div>

<?php smPageFoot(); ?>
<script>
var SM_INV = <?php echo json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function smShowInv(id) {
    var x = SM_INV.filter(function (e) { return parseInt(e.equipment_id, 10) === parseInt(id, 10); })[0];
    if (!x) { smToast("Equipment not found.", "err"); return; }

    var img = x.equipment_image
        ? '<img src="../uploads/' + smEsc(encodeURIComponent(String(x.equipment_image).split("/").pop())) + '" alt="">'
        : '<span class="sm-thumb-lg"><i class="fa-solid fa-box"></i></span>';
    document.getElementById("smInvHero").innerHTML = img +
        '<div><div class="sm-eq-name" style="font-size:15px;">' + smEsc(x.equipment_name) + "</div>" +
        '<div class="sm-sub">' + smEsc(x.category || "-") + "</div></div>";

    document.getElementById("smInvKv").innerHTML =
        "<dt>Asset ID</dt><dd>" + smEsc(x.asset_code || "Not recorded") + "</dd>" +
        "<dt>Registered Owner</dt><dd>" + smEsc(x.owner_name) + "</dd>" +
        "<dt>Classification</dt><dd>High-Value (School witnessing required)</dd>" +
        "<dt>Declared Value</dt><dd>" + (x.declared_value !== null && x.declared_value !== "" ? "PHP " + smMoney(x.declared_value) : "Not recorded") + "</dd>" +
        "<dt>Total Quantity</dt><dd>" + smEsc(x.total_rented) + "</dd>" +
        "<dt>Available Quantity</dt><dd>" + smEsc(x.available_quantity) + "</dd>" +
        "<dt>Active Rentals</dt><dd>" + smEsc(x.active_rentals) + "</dd>" +
        "<dt>Condition</dt><dd>" + smEsc(x.equipment_condition || "-") + "</dd>" +
        "<dt>Rental Price</dt><dd>PHP " + smMoney(x.rental_price) + " / day</dd>";

    var acc = (x.accessories || "").split(/[\r\n,]+/).map(function (s) { return s.trim(); })
              .filter(function (s) { return s !== ""; });
    document.getElementById("smInvAcc").innerHTML = acc.length
        ? '<ul class="sm-checklist">' + acc.map(function (a) {
            return '<li><i class="fa-solid fa-circle-check"></i>' + smEsc(a) + "</li>";
          }).join("") + "</ul>"
        : '<div class="sm-sub">No accessories recorded for this item.</div>';

    smModal.open("smInvModal");
}
</script>
