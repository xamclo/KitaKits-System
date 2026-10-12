<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/school_auth.php";
require_once __DIR__ . "/../includes/school_queries.php";
require_once __DIR__ . "/../includes/school_layout.php";
requireSchoolRole();

$res = $conn->query(
    "SELECT rp.*, u.first_name, u.last_name, e.equipment_name, e.category,
            stu.first_name AS s_first, stu.last_name AS s_last, stu.student_number,
            ow.first_name AS o_first, ow.last_name AS o_last,
            r.start_date, r.end_date
     FROM reports rp
     LEFT JOIN users u        ON rp.user_id = u.user_id
     LEFT JOIN reservations r ON rp.reservation_id = r.reservation_id
     LEFT JOIN equipment e    ON r.equipment_id = e.equipment_id
     LEFT JOIN users stu      ON r.user_id = stu.user_id
     LEFT JOIN equipment eo   ON r.equipment_id = eo.equipment_id
     LEFT JOIN users ow       ON eo.owner_id = ow.user_id
     ORDER BY rp.report_id DESC LIMIT 300"
);
$reports = [];
while ($x = $res->fetch_assoc()) {
    $x["reporter"] = trim(($x["first_name"] ?? "") . " " . ($x["last_name"] ?? ""));
    $x["student"]  = trim(($x["s_first"] ?? "") . " " . ($x["s_last"] ?? ""));
    $x["owner"]    = trim(($x["o_first"] ?? "") . " " . ($x["o_last"] ?? ""));
    $reports[] = $x;
}
$res->free();

function reportStatusPill(string $s): string
{
    $s = strtolower($s);
    if ($s === "resolved") return "completed";
    if ($s === "under_review") return "verification";
    return "issue";
}

smPageHead("Reports / Issues", "Reports / Issues",
    "Concerns reported by either party, recorded for institutional review.", "reports");
?>
<div class="sm-panel">
    <div class="sm-panel-head"><h2>Reported Issues</h2></div>
    <?php if (!$reports) { ?>
        <div class="sm-empty"><i class="fa-solid fa-check-circle" style="font-size:26px;display:block;margin-bottom:10px;"></i>
        No issues have been reported.</div>
    <?php } else { ?>
    <div class="table-responsive">
    <table class="sm-table">
      <thead><tr>
        <th>Report ID</th><th>Date</th><th>Equipment</th><th>Reported By</th>
        <th>Other Party</th><th>Issue Type</th><th>Status</th><th>Action</th>
      </tr></thead>
      <tbody>
      <?php foreach ($reports as $x) { ?>
        <tr>
          <td><strong>REP-<?php echo (int)$x["report_id"]; ?></strong></td>
          <td><?php echo htmlspecialchars(sm_d($x["created_at"])); ?></td>
          <td><?php echo htmlspecialchars((string)$x["equipment_name"]); ?></td>
          <td><?php echo htmlspecialchars($x["reporter"] !== "" ? $x["reporter"] : "Unknown"); ?></td>
          <td><?php echo htmlspecialchars($x["owner"] !== "" ? $x["owner"] : "-"); ?></td>
          <td><?php echo htmlspecialchars((string)$x["issue_type"]); ?></td>
          <td><span class="sm-badge-pill <?php echo reportStatusPill((string)$x["status"]); ?>"><?php echo htmlspecialchars(ucfirst((string)$x["status"])); ?></span></td>
          <td><button class="sm-btn-view" onclick="smOpenReport(<?php echo (int)$x["report_id"]; ?>)">View Report</button></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    </div>
    <?php } ?>
</div>

<div class="sm-modal" id="smReportModal" role="dialog" aria-modal="true">
  <div class="sm-modal-card">
    <div class="sm-modal-head">
      <h3>Report Details</h3>
      <button class="sm-modal-close" type="button" onclick="smModal.close('smReportModal')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="sm-modal-body">
      <div class="sm-section-title">Issue</div>
      <dl class="sm-kv" id="smRepKv"></dl>
      <div class="sm-section-title">Description</div>
      <div id="smRepDesc" class="sm-sub"></div>
      <div class="sm-section-title">Evidence</div>
      <div id="smRepEvidence"></div>
      <div class="sm-section-title">Recorded Actions</div>
      <div id="smRepNotes" class="sm-sub"></div>
      <div class="sm-section-title">Add Investigation Note</div>
      <select id="smRepStatus" style="width:100%;padding:9px;border:1px solid #e6e9f2;border-radius:9px;font-family:inherit;font-size:13px;margin-bottom:8px;">
        <option value="open">Open</option>
        <option value="under_review">Under Review</option>
        <option value="resolved">Resolved</option>
      </select>
      <textarea id="smRepNote" rows="3" style="width:100%;padding:10px;border:1px solid #e6e9f2;border-radius:9px;font-family:inherit;font-size:13px;" placeholder="Document your finding or request clarification..."></textarea>
    </div>
    <div class="sm-modal-foot">
      <button class="sm-btn-cancel" type="button" onclick="smModal.close('smReportModal')">Cancel</button>
      <button class="sm-link-btn" type="button" id="smRepSave">Record Note</button>
    </div>
  </div>
</div>

<?php smPageFoot(); ?>
<script>
var SM_REPORTS = <?php echo json_encode($reports, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

var currentReportId = 0;
function smOpenReport(id) {
    var x = SM_REPORTS.filter(function (r) { return parseInt(r.report_id, 10) === parseInt(id, 10); })[0];
    if (!x) { smToast("Report not found.", "err"); return; }
    currentReportId = parseInt(x.report_id, 10);
    document.getElementById("smRepKv").innerHTML =
        "<dt>Issue Type</dt><dd>" + smEsc(x.issue_type || "-") + "</dd>" +
        "<dt>Equipment</dt><dd>" + smEsc(x.equipment_name || "-") + "</dd>" +
        "<dt>Reported By</dt><dd>" + smEsc(x.reporter || "-") + "</dd>" +
        "<dt>Student Borrower</dt><dd>" + smEsc(x.student || "-") + "</dd>" +
        "<dt>Equipment Owner</dt><dd>" + smEsc(x.owner || "-") + "</dd>" +
        "<dt>Rental Dates</dt><dd>" + smEsc(x.start_date || "-") + " to " + smEsc(x.end_date || "-") + "</dd>" +
        "<dt>Status</dt><dd>" + smEsc(x.status || "-") + "</dd>";
    document.getElementById("smRepDesc").textContent = x.description || "No description recorded.";
    document.getElementById("smRepEvidence").innerHTML = x.attachment
        ? '<img class="sm-thumb-lg" src="../uploads/' + smEsc(encodeURIComponent(x.attachment)) + '" alt="Evidence" style="max-width:220px;height:150px;">'
        : '<div class="sm-sub">No evidence uploaded.</div>';
    document.getElementById("smRepNotes").textContent = x.admin_notes || "No actions recorded yet.";
    document.getElementById("smRepStatus").value = x.status || "open";
    document.getElementById("smRepNote").value = "";
    smModal.open("smReportModal");
}

document.getElementById("smRepSave").addEventListener("click", function () {
    var note = document.getElementById("smRepNote").value.trim();
    if (!note) { smToast("Please enter an investigation note.", "err"); return; }
    smPost("api/index.php", {
        action: "reports_note",
        report_id: currentReportId,
        notes: note,
        status: document.getElementById("smRepStatus").value
    }).then(function (r) {
        smToast(r.message, r.ok ? "ok" : "err");
        if (r.ok) setTimeout(smReload, 700);
    });
});
</script>
