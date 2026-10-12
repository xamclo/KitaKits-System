<?php
session_start();
require_once "../config/database.php";
require_once "../includes/reservation_state.php";
require_once "../includes/school_auth.php";

// ===============================
// STUDENT ACCESS CHECK
// ===============================

if(!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student"){
    header("Location: ../index.php");
    exit();
}

if(!isset($_GET["id"])){
    header("Location: reservations.php");
    exit();
}

$reservation_id = intval($_GET["id"]);
$user_id = $_SESSION["user_id"];

// ===============================
// GET RESERVATION DETAILS
// ===============================
// equipment_image and equipment_condition are selected here so the
// real photo is used, and payment is read from the existing
// transactions table (LEFT JOIN, so a reservation with no payment
// record is still displayed and correctly shown as Unpaid).
$stmt = $conn->prepare(
    "
    SELECT
        r.*,
        e.equipment_name,
        e.category,
        e.description,
        e.rental_price,
        e.equipment_image,
        e.equipment_condition,
        t.payment_status,
        e.requires_school_approval,
        sa.status AS school_status
    FROM reservations r
    LEFT JOIN equipment e ON r.equipment_id = e.equipment_id
    LEFT JOIN transactions t ON t.reservation_id = r.reservation_id
    LEFT JOIN school_approval_requests sa ON sa.reservation_id = r.reservation_id
    WHERE r.reservation_id = ?
    AND r.user_id = ?
    LIMIT 1
    "
);

$stmt->bind_param("ii", $reservation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Authorisation: the query is already scoped to the signed-in
// student, so a row count of zero means "not yours" or "missing".
if($result->num_rows == 0){
    header("Location: reservations.php");
    exit();
}

$reservation = $result->fetch_assoc();
$stmt->close();

// Shortcuts used by the template below.
$statusLabel = reservationStatusLabel($reservation["status"]);
$stages      = rentalStages($reservation);
$allowReturn = canMarkReturned($reservation);
$isPaid      = isset($reservation["payment_status"])
                && strtolower($reservation["payment_status"]) === "paid";

// Only show the photo when the stored file actually exists on disk,
// so we never emit a broken image URL.
$imageFile = trim((string)($reservation["equipment_image"] ?? ""));
$imagePath = "";
if($imageFile !== ""){
    $candidate = __DIR__ . "/../uploads/" . basename($imageFile);
    if(is_file($candidate)){
        $imagePath = "../uploads/" . rawurlencode(basename($imageFile));
    }
}

// Existing issue types are reused; the list below only adds the
// prototype entries that were not stored yet.
$issueTypes = ["Damaged Equipment", "Missing Parts", "Equipment Malfunction",
               "Pickup or Return Problem", "Late Return", "Missing Item",
               "Damaged Item", "Other"];
$formError = "";

// ===============================
// MARK AS RETURNED (student action)
// ===============================
// The student submits the return; the equipment owner / admin still
// performs the return verification. This records the action in
// returned_at WITHOUT restoring inventory and WITHOUT marking the
// rental completed, so the existing workflow is not bypassed.
if(isset($_POST["return_item"])){

    // Re-check eligibility server side (never trust the button state).
    $recheck = $conn->prepare(
        "SELECT status, start_date, returned_at FROM reservations WHERE reservation_id = ? AND user_id = ?"
    );
    $recheck->bind_param("ii", $reservation_id, $user_id);
    $recheck->execute();
    $reRow = $recheck->get_result()->fetch_assoc();
    $recheck->close();

    if(!$reRow || !canMarkReturned($reRow)){
        $formError = "This reservation is not eligible for a return right now.";
    } else {
        // Duplicate guard: only write when no return has been recorded.
        $mark = $conn->prepare(
            "UPDATE reservations SET returned_at = NOW(), fulfillment_status = 'return_submitted' WHERE reservation_id = ? AND user_id = ? AND (returned_at IS NULL)"
        );
        $mark->bind_param("ii", $reservation_id, $user_id);
        $mark->execute();
        $affected = $mark->affected_rows;
        $mark->close();

            if($affected > 0){
                $notif = $conn->prepare(
                    "INSERT INTO notifications (user_id, message) VALUES (?, ?)"
                );
                $msg = "Return submitted for RES-" . $reservation_id . ". Awaiting equipment owner inspection.";
                $notif->bind_param("is", $user_id, $msg);
                $notif->execute();
                $notif->close();

                // The owner must be told so they can inspect the item, and the
                // return becomes a real event in the transaction history.
                try {
                    $ownerLookup = $conn->prepare(
                        "SELECT e.owner_id, e.equipment_name, e.requires_school_approval
                         FROM reservations r
                         INNER JOIN equipment e ON r.equipment_id = e.equipment_id
                         WHERE r.reservation_id = ?"
                    );
                    $ownerLookup->bind_param("i", $reservation_id);
                    $ownerLookup->execute();
                    $ownerRow = $ownerLookup->get_result()->fetch_assoc();
                    $ownerLookup->close();

                    if($ownerRow){
$ownerMsg = "Return submitted for RES-" . $reservation_id
                              . " (" . $ownerRow["equipment_name"] . "). Please inspect and record the return condition as the equipment owner.";
                        $ownerId = (int)$ownerRow["owner_id"];
                        $on = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        $on->bind_param("is", $ownerId, $ownerMsg);
                        $on->execute();
                        $on->close();
                    }

                    $audit = $conn->prepare(
                        "INSERT INTO transaction_audit_logs
                            (reservation_id, actor_user_id, actor_role, action_type,
                             previous_status, new_status, notes)
                         VALUES (?, ?, 'student', 'return_submitted', 'active', 'return_submitted', NULL)"
                    );
                    $audit->bind_param("ii", $reservation_id, $user_id);
                    $audit->execute();
                    $audit->close();

                    // For high-value equipment, also create the School Management
                    // return witness record so it can be independently verified.
                    if (!empty($ownerRow["requires_school_approval"])) {
                        $rv = $conn->prepare(
                            "INSERT INTO school_return_verifications (reservation_id, status)
                             VALUES (?, 'pending')
                             ON DUPLICATE KEY UPDATE status = 'pending'"
                        );
                        $rv->bind_param("i", $reservation_id);
                        $rv->execute();
                        $rv->close();

                        $smMsg = "A high-value return record (RES-" . $reservation_id . ") is awaiting your verification after the equipment owner inspects it.";
                        notifyAllSchoolUsers($conn, $smMsg);
                    }
                } catch (Exception $e) {
                    error_log("Return notification failed: " . $e->getMessage());
                }


                header("Location: reservation_details.php?id=" . $reservation_id . "&returned=1");
                exit();
            }

        $formError = "A return was already submitted for this reservation.";
    }
}


// ===============================
// REPORT AN ISSUE (+ optional attachment)
// ===============================
if(isset($_POST["submit_report"])){

    $issueType = trim((string)($_POST["issue_type"] ?? ""));
    $desc      = trim((string)($_POST["description"] ?? ""));

    if($issueType === "" || $desc === ""){
        $formError = "Please choose an issue type and describe the issue.";
    } elseif(!in_array($issueType, $issueTypes, true)){
        $formError = "Please choose a valid issue type.";
    } else {
        // ---- Optional attachment (stored in the existing uploads dir) ----
        $attachment = null;
        $uploadDir  = __DIR__ . "/../uploads";
        $maxBytes   = 5 * 1024 * 1024;            // 5 MB
        $allowedExt = ["jpg","jpeg","png","gif","webp","pdf"];

        if(isset($_FILES["attachment"]) && $_FILES["attachment"]["error"] !== UPLOAD_ERR_NO_FILE){
            $up = $_FILES["attachment"];

            if($up["error"] !== UPLOAD_ERR_OK){
                $formError = "The attachment could not be uploaded. Please try again.";
            } elseif($up["size"] > $maxBytes){
                $formError = "Attachment is too large. Maximum size is 5 MB.";
            } else {
                $ext = strtolower(pathinfo($up["name"], PATHINFO_EXTENSION));
                if(!in_array($ext, $allowedExt, true)){
                    $formError = "Attachment must be an image or PDF file.";
                } elseif(!is_dir($uploadDir)){
                    $formError = "Upload folder is unavailable.";
                } else {
                    // Regenerated filename: never trust the user-supplied name.
                    $safeName = "issue_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                    if(move_uploaded_file($up["tmp_name"], $uploadDir . "/" . $safeName)){
                        $attachment = $safeName;
                    } else {
                        $formError = "The attachment could not be saved.";
                    }
                }
            }
        }

        if($formError === ""){
            // Reservation ownership was already enforced by the main query.
            $report = $conn->prepare(
                "INSERT INTO reports (user_id, reservation_id, subject, issue_type, description, attachment) VALUES (?,?,?,?,?,?)"
            );
            $subject = "Issue on RES-" . $reservation_id;
            $report->bind_param("iissss",
                $user_id, $reservation_id, $subject,
                $issueType, $desc, $attachment
            );

            if($report->execute()){
                $report->close();
                // The success modal is only shown after the DB write
                // actually succeeded.
                header("Location: reservation_details.php?id=" . $reservation_id . "&reported=1");
                exit();
            }

            $report->close();
            $formError = "Your report could not be saved. Please try again.";
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservation Details | KitaKits</title>
<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">
<link rel="stylesheet" href="../css/student-reservations.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body class="new-dashboard res-page">

<!-- SIDEBAR (same component as the other Student pages) -->
<?php $__sbActive = "reservations"; include __DIR__ . "/../includes/sidebar_student.php"; ?>

<!-- MAIN CONTENT -->
<div class="dashboard-content" id="content">

    <div class="top-header">
        <div>
            <h1>Reservation Details</h1>
            <p>Review your rental details and progress.</p>
        </div>
        <?php include "../includes/student_header_actions.php"; ?>
    </div>

    <a href="reservations.php" class="back-btn">
        <i class="fa-solid fa-arrow-left"></i> Back to My Reservations
    </a>

    <?php if(isset($_GET["returned"])): ?>
        <div class="status-note" style="background:#DCFCE7;color:#166534;">
            <i class="fa-solid fa-circle-check" style="color:#16A34A;"></i>
            Item marked as returned successfully.
        </div>
    <?php endif; ?>

    <?php if($formError !== ""): ?>
        <div class="form-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($formError); ?></div>
    <?php endif; ?>

    <div class="details-container">
        <div class="details-card">

            <!-- EQUIPMENT THUMBNAIL -->
            <div class="details-image">
                <?php if($imagePath !== ""): ?>
                    <img src="<?php echo $imagePath; ?>" alt="<?php echo htmlspecialchars((string)$reservation["equipment_name"]); ?>">
                <?php else: ?>
                    <i class="fa-solid fa-box"></i>
                <?php endif; ?>
            </div>

            <!-- EQUIPMENT + RENTAL INFORMATION -->
            <div class="details-info">
                <h1><?php echo htmlspecialchars((string)$reservation["equipment_name"]); ?></h1>
                <p class="reservation-number">Reservation ID: RES-<?php echo (int)$reservation["reservation_id"]; ?></p>

                <div style="margin-bottom:18px;">
                    <span class="status-badge <?php echo strtolower($reservation["status"]); ?>"><?php echo $statusLabel; ?></span>
                </div>

                <div class="detail-grid">
                    <div>
                        <label>Pickup Date</label>
                        <p><?php echo date("F j, Y", strtotime($reservation["start_date"])); ?></p>
                    </div>
                    <div>
                        <label>Return Date</label>
                        <p><?php echo date("F j, Y", strtotime($reservation["end_date"])); ?></p>
                    </div>
                    <div>
                        <label>Price / Day</label>
                        <p>&#8369;<?php echo number_format((float)$reservation["rental_price"], 2); ?></p>
                    </div>
                    <div>
                        <label>Total Amount</label>
                        <p>&#8369;<?php echo number_format((float)$reservation["total_price"], 2); ?></p>
                    </div>
                    <div>
                        <label>Payment Status</label>
                        <p>
                            <?php if($isPaid): ?><span class="pay-badge paid">Paid</span>
                            <?php else: ?><span class="pay-badge unpaid">Unpaid</span><?php endif; ?>
                        </p>
                    </div>
                    <?php
                    /* School Management witness state is READ from school_approval_requests
                        and is shown only for genuinely high-value equipment, so a
                        normal rental looks exactly as it did before. */
                    if(!empty($reservation["requires_school_approval"])):
                        $smLabel = "Not Requested"; $smCls = "unpaid";
                        if(!empty($reservation["school_status"])){
                            $ss = strtolower((string)$reservation["school_status"]);
                            if($ss === "pending"){  $smLabel = "Owner Approved — Pending Witness Record"; $smCls = "unpaid"; }
                            elseif($ss === "approved"){ $smLabel = "Transaction Witnessed — Ready for Handover"; $smCls = "paid"; }
                            elseif($ss === "rejected"){ $smLabel = "Transaction Flagged"; $smCls = "unpaid"; }
                        }
                    ?>
                    <div>
                        <label>School Management Witness</label>
                        <p><span class="pay-badge <?php echo $smCls; ?>"><?php echo $smLabel; ?></span></p>
                    </div>
                    <?php endif; ?>
                    <div>

                        <label>Category</label>
                        <p><?php echo htmlspecialchars((string)($reservation["category"] ?? "-")); ?></p>
                    </div>
                </div>

                <?php if(!empty($reservation["description"])): ?>
                    <p style="color:#64748B;font-size:14px;line-height:1.6;margin-bottom:18px;">
                        <?php echo htmlspecialchars((string)$reservation["description"]); ?>
                    </p>
                <?php endif; ?>

                <?php if(!empty($reservation["admin_notes"])): ?>
                    <div class="admin-note" style="margin-bottom:18px;">
                        <h3>Admin Notes</h3>
                        <p><?php echo htmlspecialchars((string)$reservation["admin_notes"]); ?></p>
                    </div>
                <?php endif; ?>

                <!-- FOUR-STAGE RENTAL TIMELINE -->
                <h3 style="font-size:17px;color:#1E293B;margin-bottom:6px;">Rental Timeline</h3>
                <div class="timeline">
                    <?php foreach($stages as $stage): ?>
                        <div class="tl-stage<?php echo $stage["active"] ? " done" : ""; ?>">
                            <div class="tl-dot">
                                <i class="fa-solid <?php echo $stage["active"] ? "fa-check" : "fa-circle"; ?>"></i>
                            </div>
                            <div>
                                <div class="tl-label"><?php echo $stage["label"]; ?></div>
                                <?php if($stage["time"] !== ""): ?>
                                    <div class="tl-time"><?php echo $stage["time"]; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                // Honest note when a completed rental has no recorded
                // return. We do NOT invent the missing timestamp.
                $stagesByKey = [];
                foreach($stages as $s){ $stagesByKey[$s["key"]] = $s; }
                $stranded = $stagesByKey["completed"]["active"] && !$stagesByKey["returned"]["active"];
                if($stranded): ?>
                    <div class="status-note">
                        <i class="fa-solid fa-circle-info"></i>
                        This rental is marked Completed but no return was ever recorded.
                        Please contact your equipment owner to verify the return.
                    </div>
                <?php endif; ?>

                <!-- ACTIONS -->
                <div class="detail-actions">
                    <?php if($allowReturn): ?>
                        <form method="POST" style="flex:1 1 200px;display:flex;">
                            <button type="submit" name="return_item" class="return-btn">
                                <i class="fa-solid fa-rotate-left"></i> Mark as Returned
                            </button>
                        </form>
                    <?php elseif(hasReturnRecord($reservation["returned_at"])): ?>
                        <button type="button" class="return-btn" disabled>
                            <i class="fa-solid fa-check"></i> Return Submitted
                        </button>
                    <?php endif; ?>

                    <?php if(!in_array(strtolower($reservation["status"]), ["declined","rejected","cancelled","canceled"], true)): ?>
                        <button type="button" class="issue-btn" onclick="openIssue()">
                            <i class="fa-solid fa-triangle-exclamation"></i> Report an Issue
                        </button>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

</div>

<!-- The logout dialog comes from includes/sidebar_student.php and is
     driven by js/student-sidebar.js. -->

<!-- REPORT AN ISSUE MODAL -->
<div class="issue-modal" id="issueModal">
    <div class="issue-box">
        <form method="POST" id="issueForm" enctype="multipart/form-data">
            <h2>Report an Issue</h2>

            <label for="issueType">Issue Type <span class="req">*</span></label>
            <select name="issue_type" id="issueType" required>
                <option value="">Select issue</option>
                <?php foreach($issueTypes as $it): ?>
                    <option value="<?php echo htmlspecialchars($it); ?>"<?php echo (($issueType ?? "") === $it) ? " selected" : ""; ?>><?php echo htmlspecialchars($it); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="issueDesc">Description <span class="req">*</span></label>
            <textarea name="description" id="issueDesc" placeholder="Please describe the issue in detail..." required><?php echo htmlspecialchars($desc ?? ""); ?></textarea>

            <label>Screenshot / Attachment <span style="color:#94A3B8;font-weight:400;">(optional)</span></label>
            <div class="file-picker">
                <label class="file-btn" for="issueFile">
                    <i class="fa-solid fa-paperclip"></i> + Click to attach a file
                </label>
                <input type="file" name="attachment" id="issueFile" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                <span class="file-name" id="fileName">No file chosen</span>
            </div>
            <p class="file-hint">Accepted: JPG, PNG, GIF, WEBP or PDF. Maximum 5 MB.</p>

            <div class="issue-actions">
                <button type="button" class="cancel-issue" onclick="closeIssue()">Cancel</button>
                <button type="submit" name="submit_report" class="submit-issue" id="submitIssue">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<!-- REPORT SUBMITTED CONFIRMATION -->
<?php if(isset($_GET["reported"])): ?>
<div class="confirm-modal open" id="confirmModal">
    <div class="confirm-box">
        <div class="confirm-check"><i class="fa-solid fa-check"></i></div>
        <h3>Report Submitted!</h3>
        <p>Your report has been submitted successfully. Thank you for letting us know.</p>
        <button type="button" class="confirm-close" onclick="closeConfirm()">Close</button>
    </div>
</div>
<?php endif; ?>

<script>
function openIssue(){
    document.getElementById("issueModal").classList.add("open");
}
function closeIssue(){
    document.getElementById("issueModal").classList.remove("open");
}
function closeConfirm(){
    document.getElementById("confirmModal").classList.remove("open");
}

// Show the chosen attachment name.
document.getElementById("issueFile").addEventListener("change", function(){
    document.getElementById("fileName").textContent = this.files[0] ? this.files[0].name : "No file chosen";
});

// Client-side guard so a double click cannot submit twice.
document.getElementById("issueForm").addEventListener("submit", function(){
    var btn = document.getElementById("submitIssue");
    btn.disabled = true;
    btn.textContent = "Submitting...";
});

// Click outside / Esc to dismiss.
document.getElementById("issueModal").addEventListener("click", function(e){
    if(e.target === this){ closeIssue(); }
});
document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){ closeIssue(); }
});
</script>

<script src="../js/student-header.js"></script>
<script src="../js/student-sidebar.js"></script>
</body>
</html>
