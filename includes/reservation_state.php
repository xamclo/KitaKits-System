<?php
/**
 * Shared rental-state helpers for the Student "My Reservations" and
 * "Reservation Details" pages.
 *
 * WHY THIS FILE EXISTS
 * Both pages previously mapped reservation states independently and
 * disagreed with each other - reservation_details.php showed
 * "Item Returned" as complete whenever status was 'approved', while
 * the list page treated every 'approved' row the same way. A
 * reservation could therefore be labelled Completed while the
 * returned stage was still inactive.
 *
 * This single helper is the one place that decides which tab a
 * reservation belongs to and which timeline stages are genuinely
 * reached, so the badge, the tab and the timeline can never
 * contradict each other.
 *
 * It only READS existing columns (status, returned_at,
 * return_condition). It never invents a timestamp and never
 * derives "completed" from a date that has simply passed.
 */

/** Human label for a stored status value. Admin writes 'declined'. */
function reservationStatusLabel(string $status): string
{
    $map = [
        "pending"   => "Pending",
        "approved"  => "Approved",
        "declined"  => "Rejected",
        "rejected"  => "Rejected",
        "cancelled" => "Cancelled",
        "canceled"  => "Cancelled",
        "completed" => "Completed",
        "ongoing"   => "Ongoing",
        "overdue"   => "Overdue",
        "returned"  => "Returned",
    ];

    $key = strtolower(trim($status));

    return $map[$key] ?? ucfirst($key);
}

/** True when a return has genuinely been recorded. */
function hasReturnRecord($returnedAt): bool
{
    $ret = trim((string)$returnedAt);

    return $ret !== "" && stripos($ret, "0000") === false;
}

/**
 * Which of the four prototype tabs a reservation belongs to.
 *
 * upcoming  - pending, or approved but pickup has not arrived yet
 * ongoing   - actively out on rental, not yet returned
 * completed - officially finished
 * cancelled - declined / cancelled
 *
 * An expired end date alone NEVER implies completed.
 */
function reservationTab(string $status, $startDate = null, $returnedAt = null): string
{
    $key = strtolower(trim($status));

    if (in_array($key, ["declined", "rejected", "cancelled", "canceled"], true)) {
        return "cancelled";
    }

    if (in_array($key, ["completed", "returned"], true)) {
        return "completed";
    }

    if (hasReturnRecord($returnedAt)) {
        return "completed";
    }

    if ($key === "pending") {
        return "upcoming";
    }

    if ($key === "approved" || $key === "ongoing" || $key === "overdue") {
        // Approved counts as ongoing once the scheduled pickup day arrives.
        if (!empty($startDate) && strtotime($startDate) <= strtotime(date("Y-m-d"))) {
            return "ongoing";
        }
        return "upcoming";
    }

    return "upcoming";
}

/**
 * The four rental-timeline stages.
 *
 * Every stage is driven by recorded data only:
 *  1. approved  -> admin set the status to approved (or later)
 *  2. picked up -> the scheduled start date has been reached while
 *                 the rental is still active
 *  3. returned  -> returned_at is actually recorded
 *  4. completed -> status is completed/returned
 *
 * Stage 3 can therefore never show as complete for an approved but
 * un-returned rental, which was the reported inconsistency.
 * Timestamps are only rendered when they genuinely exist.
 */
function rentalStages(array $r): array
{
    $status = strtolower(trim((string)($r["status"] ?? "")));
    $start  = $r["start_date"] ?? null;
    $ret    = $r["returned_at"] ?? null;

    $hasReturn   = hasReturnRecord($ret);
    $isApproved  = in_array($status, ["approved", "ongoing", "overdue", "completed", "returned"], true);
    $isCompleted = in_array($status, ["completed", "returned"], true);

    $pickedUp = $isApproved && !$isCompleted
        && !empty($start)
        && strtotime($start) <= strtotime(date("Y-m-d"));

    $approvedTime = ($isApproved && !empty($r["created_at"]))
        ? date("M d, Y", strtotime($r["created_at"])) : "";

    $pickedTime = $pickedUp ? date("M d, Y", strtotime($start)) : "";
    $returnTime = $hasReturn ? date("M d, Y g:i A", strtotime((string)$ret)) : "";

    $doneTime = "";
    if ($isCompleted) {
        $doneTime = $hasReturn
            ? date("M d, Y", strtotime((string)$ret))
            : (!empty($r["created_at"]) ? date("M d, Y", strtotime($r["created_at"])) : "");
    }

    return [
        ["key" => "approved", "label" => "Reservation Approved", "time" => $approvedTime, "active" => $isApproved],
        ["key" => "picked_up", "label" => "Item Picked Up", "time" => $pickedTime, "active" => $pickedUp || $hasReturn || $isCompleted],
        ["key" => "returned", "label" => "Item Returned", "time" => $returnTime, "active" => $hasReturn],
        ["key" => "completed", "label" => "Checked & Completed", "time" => $doneTime, "active" => $isCompleted],
    ];
}

/**
 * Whether the student may submit a return right now.
 *
 * A rental that has not actually been picked up yet is not eligible,
 * which is why the scheduled start date is checked here. Pending,
 * declined/cancelled, already-returned and finished rentals are all
 * ineligible, and that is what prevents duplicate submissions.
 */
function canMarkReturned(array $r): bool
{
    $status = strtolower(trim((string)($r["status"] ?? "")));
    $start  = $r["start_date"] ?? null;

    if (in_array($status, ["pending", "declined", "rejected", "cancelled", "canceled", "completed", "returned"], true)) {
        return false;
    }

    if (hasReturnRecord($r["returned_at"] ?? null)) {
        return false;
    }

    if (!in_array($status, ["approved", "ongoing", "overdue"], true)) {
        return false;
    }

    // Approved but not yet picked up: the rental has not started.
    if (empty($start) || strtotime($start) > strtotime(date("Y-m-d"))) {
        return false;
    }

    return true;
}


