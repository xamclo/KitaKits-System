<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$payment_error = null;

// ==============================================================
// PAYMENT RECORDS (single authoritative query)
// ==============================================================
// WHY THIS QUERY IS SHAPED THIS WAY
//
// The old page started FROM transactions and INNER JOINed the
// reservations. That silently hid every rental obligation that has
// no transaction row yet - which is why only ONE row was ever shown
// even though the student has several payable reservations.
//
// Here the RESERVATION is the record of the rental obligation and the
// transaction is the optional payment record hanging off it, so a
// LEFT JOIN keeps unpaid rentals visible. Nothing is created or
// manufactured here: this only reads what already exists.
//
// Eligibility mirrors the existing approval workflow -
// admin/rental_request.php inserts the transaction when it approves a
// request - so only approved-or-later rentals are payable. Pending,
// declined and cancelled reservations are excluded, so a student is
// never charged for a request that was not approved.
//
// The amount prefers the agreed transaction amount when one exists
// and otherwise falls back to the stored reservation total, so a
// later change to equipment.rental_price can never rewrite a
// historical charge.
//
// The payment subquery is grouped by reservation_id, so duplicate
// transaction rows can never inflate the counts.
$paySql = "
    SELECT
        r.reservation_id,
        r.start_date,
        r.end_date,
        r.status      AS reservation_status,
        r.created_at  AS reservation_created,
        COALESCE(t.amount, r.total_price) AS amount,
        t.payment_status,
        t.payment_date,
        e.equipment_name,
        IFNULL(t.payment_status, 'pending') AS agg_status
    FROM reservations r
    INNER JOIN equipment e ON r.equipment_id = e.equipment_id
    LEFT JOIN (
        SELECT reservation_id,
               SUM(amount)       AS amount,
               MAX(payment_date) AS payment_date,
               CASE
                   WHEN SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN 1 ELSE 0 END) > 0
                   THEN 'paid' ELSE 'pending'
               END               AS payment_status
        FROM transactions
        GROUP BY reservation_id
    ) t ON t.reservation_id = r.reservation_id
    WHERE r.user_id = ?
      AND LOWER(r.status) IN ('approved','ongoing','overdue','completed','returned')
    ORDER BY r.reservation_id DESC
";

$rows = [];

try {
    $stmt = $conn->prepare($paySql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    error_log("Database error in payments.php: " . $e->getMessage());
    $payment_error = "Unable to load payment records at this moment.";
} catch (Exception $e) {
    error_log("Error in payments.php: " . $e->getMessage());
    $payment_error = "An unexpected error occurred.";
}

// The three cards and the table all read this one list, so
// Total Transactions always equals Paid + Unpaid.
$totalCount = count($rows);
$paidCount   = 0;
$unpaidCount = 0;

foreach ($rows as $r) {
    if (strtolower((string)($r["agg_status"] ?? "")) === "paid") {
        $paidCount++;
    } else {
        $unpaidCount++;
    }
}

// Cache used by the receipt modal. It is built from the rows already
// fetched for this student, so the modal can never show a receipt for
// a row that is not on this page and can never leak another
// student's payment data.
$receiptData = [];
foreach ($rows as $r) {
    $receiptData[] = [
        "reservation_id" => (int)$r["reservation_id"],
        "equipment"      => (string)$r["equipment_name"],
        "pickup_date"    => date("F j, Y", strtotime($r["start_date"])),
        "return_date"    => date("F j, Y", strtotime($r["end_date"])),
        "amount"         => number_format((float)$r["amount"], 2),
        "paid"           => strtolower((string)($r["agg_status"] ?? "")) === "paid",
    ];
}

$receiptJson = json_encode($receiptData,
    JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
?>



<!DOCTYPE html>

<html lang="en">


<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Payments | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">
<link rel="stylesheet" href="../css/student-payments.css">


</head>



<body class="new-dashboard pay-page">




<?php $__sbActive = "payments"; include __DIR__ . "/../includes/sidebar_student.php"; ?>


<!-- CONTENT -->

<div class="dashboard-content" id="content">





<div class="top-header">


<div>

<h1>
Payments
</h1>


<p>
View your rental payment information.
</p>


</div>





<?php include "../includes/student_header_actions.php"; ?>



</div>








<!-- ==========================
PAYMENT SUMMARY
========================== -->
<!-- All three cards and the table below are computed from the same
     $rows list, so Total Transactions always equals Paid + Unpaid. -->
<div class="stats">
    <div class="stat-card total">
        <div class="stat-icon total"><i class="fa-solid fa-receipt"></i></div>
        <h4>Total Transactions</h4>
        <strong><?php echo (int)$totalCount; ?></strong>
    </div>
    <div class="stat-card ongoing">
        <div class="stat-icon ongoing"><i class="fa-solid fa-circle-check"></i></div>
        <h4>Paid</h4>
        <strong><?php echo (int)$paidCount; ?></strong>
    </div>
    <div class="stat-card unpaidcard">
        <div class="stat-icon unpaidcard"><i class="fa-solid fa-circle-exclamation"></i></div>
        <h4>Unpaid</h4>
        <strong><?php echo (int)$unpaidCount; ?></strong>
    </div>
</div>

<!-- ==========================
TRANSACTION HISTORY TABLE
========================== -->


<div class="reservation-box">
    <div class="box-title">
        <h3>Transaction History</h3>
    </div>
    <table>
        <thead>
            <tr>
                <th>Reservation ID</th>
                <th>Equipment</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if($payment_error !== null): ?>
            <tr><td colspan="6" class="empty-row"><?php echo htmlspecialchars($payment_error); ?></td></tr>
        <?php elseif(count($rows) > 0): ?>
            <?php foreach($rows as $row): ?>
                <?php
                $isPaidRow = strtolower((string)($row["agg_status"] ?? "")) === "paid";
                // Paid rows show the real recorded payment date; unpaid
                // rows fall back to the date the reservation was raised,
                // which is when the obligation actually appeared.
                $dateSource = ($isPaidRow && !empty($row["payment_date"]))
                    ? $row["payment_date"]
                    : ($row["reservation_created"] ?? null);
                $dateShown = $dateSource ? date("F j, Y", strtotime($dateSource)) : "-";
                ?>
                <tr>
                    <td><span class="tx-res">RES-<?php echo (int)$row["reservation_id"]; ?></span></td>
                    <td><?php echo htmlspecialchars((string)$row["equipment_name"]); ?></td>
                    <td class="tx-amount">&#8369;<?php echo number_format((float)$row["amount"], 2); ?></td>
                    <td>
                        <span class="status <?php echo $isPaidRow ? "paid" : "unpaid"; ?>">
                            <?php echo $isPaidRow ? "Paid" : "Unpaid"; ?>
                        </span>
                    </td>
                    <td><?php echo $dateShown; ?></td>
                    <td>
                        <button type="button" class="receipt-btn"
                                data-res="<?php echo (int)$row["reservation_id"]; ?>"
                                onclick="openReceipt(<?php echo (int)$row["reservation_id"]; ?>)">
                            View Receipt
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" class="empty-row">
                    <i class="fa-solid fa-receipt"></i>
                    No payment records found.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ==========================
PAYMENT RECEIPT MODAL
========================== -->
<!-- Populated from the rows already loaded for THIS student, so a
     receipt can never show another student's reservation. -->
<div class="receipt-modal" id="receiptModal">
    <div class="receipt-box">
        <h3>Payment Receipt</h3>
        <p class="receipt-sub">Summary of your rental payment obligation.</p>
        <button type="button" class="receipt-x" onclick="closeReceipt()" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="receipt-rows">
            <div class="receipt-row"><span class="lbl">Reservation ID</span><span class="val" id="rcRes">-</span></div>
            <div class="receipt-row"><span class="lbl">Equipment</span><span class="val" id="rcEq">-</span></div>
            <div class="receipt-row"><span class="lbl">Pickup Date</span><span class="val" id="rcPick">-</span></div>
            <div class="receipt-row"><span class="lbl">Return Date</span><span class="val" id="rcRet">-</span></div>
            <div class="receipt-row"><span class="lbl">Amount</span><span class="val amount" id="rcAmt">-</span></div>
            <div class="receipt-row"><span class="lbl">Payment Status</span><span class="val" id="rcSt">-</span></div>
        </div>

        <button type="button" class="receipt-close" onclick="closeReceipt()">Close</button>
    </div>
</div>







</div>




<!-- The logout dialog comes from includes/sidebar_student.php and is driven by js/student-sidebar.js. -->

<!-- Receipt data is rendered from the rows already loaded above. -->
<script id="receiptData" type="application/json"><?php echo $receiptJson; ?></script>

<script>
/* ---------------- Payment Receipt modal ---------------- */
// Receipts come from the server-rendered JSON for THIS student, so the
// modal always shows the row that was actually clicked.
const RECEIPTS = JSON.parse(document.getElementById("receiptData").textContent || "[]");

function openReceipt(resId){
    const rec = RECEIPTS.find(function(r){ return r.reservation_id === Number(resId); });
    if (!rec) { return; }

    document.getElementById("rcRes").textContent  = "RES-" + rec.reservation_id;
    document.getElementById("rcEq").textContent   = rec.equipment;
    document.getElementById("rcPick").textContent = rec.pickup_date;
    document.getElementById("rcRet").textContent  = rec.return_date;
    document.getElementById("rcAmt").textContent  = "\u20B1" + rec.amount;

    // Unpaid rows are shown as a payment summary only - the page never
    // claims a payment was confirmed unless the database records it.
    const st = document.getElementById("rcSt");
    st.textContent = rec.paid ? "Paid" : "Unpaid";
    st.className = "val " + (rec.paid ? "paid" : "unpaid");

    document.getElementById("receiptModal").classList.add("open");
}

function closeReceipt(){
    document.getElementById("receiptModal").classList.remove("open");
}

// Close on X, Close button, backdrop click and Escape.
document.getElementById("receiptModal").addEventListener("click", function(e){
    if (e.target === this) { closeReceipt(); }
});
window.addEventListener("keydown", function(event){
    if (event.key === "Escape") {
        closeReceipt();
    }
});
</script>
<script src="../js/student-header.js"></script>
<script src="../js/student-sidebar.js"></script>
</body>
</html>
