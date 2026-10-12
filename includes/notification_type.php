<?php
/**
 * Notification type detection
 *
 * WHY THIS FILE EXISTS
 * The notifications table stores only free text, so the page needs a
 * single, shared way to work out what kind of event a message describes
 * in order to pick an icon and a colour.
 *
 * It reads the REAL message text that the existing workflow writes
 * (admin approval, decline, payment confirmation, the return flow added
 * to reservation_details.php, plus overdue reminders). Nothing is
 * invented and no notification is created here - this only classifies
 * rows that already exist.
 *
 * The Student Notifications page uses it. Anything unmatched safely
 * falls back to a neutral "bell" type rather than guessing.
 */

/**
 * @return array{type:string,label:string,icon:string}
 */
function notificationType(string $message): array
{
    $m = strtolower($message);

    // Order matters: "payment confirmed" must win over the generic
    // "reservation" wording that some messages also contain.
    $rules = [
        "payment" => [
            "needle"  => ["payment", "paid", "transaction"],
            "type"    => "payment",
            "label"   => "Payment",
            "icon"    => "fa-receipt",
        ],
        "approved" => [
            "needle"  => ["approved", "approval"],
            "type"    => "approved",
            "label"   => "Reservation Approved",
            "icon"    => "fa-circle-check",
        ],
        "rejected" => [
            "needle"  => ["declined", "rejected", "cancelled", "canceled"],
            "type"    => "rejected",
            "label"   => "Reservation Rejected",
            "icon"    => "fa-circle-xmark",
        ],
        "overdue" => [
            "needle"  => ["overdue", "late", "past due"],
            "type"    => "overdue",
            "label"   => "Overdue Rental",
            "icon"    => "fa-triangle-exclamation",
        ],
        "return" => [
            "needle"  => ["return submitted", "returned", "awaiting equipment verification"],
            "type"    => "return",
            "label"   => "Return Update",
            "icon"    => "fa-rotate-left",
        ],
        "reminder" => [
            "needle"  => ["reminder", "due", "upcoming"],
            "type"    => "reminder",
            "label"   => "Upcoming Return",
            "icon"    => "fa-bell",
        ],
        "completed" => [
            "needle"  => ["completed", "checked"],
            "type"    => "completed",
            "label"   => "Rental Completed",
            "icon"    => "fa-flag-checkered",
        ],
    ];

    foreach ($rules as $key => $rule) {
        foreach ($rule["needle"] as $needle) {
            if (strpos($m, $needle) !== false) {
                return [
                    "type"  => $rule["type"],
                    "label" => $rule["label"],
                    "icon"  => $rule["icon"],
                ];
            }
        }
    }

    // Fallback for any future message shape.
    return [
        "type"  => "general",
        "label" => "Notification",
        "icon"  => "fa-bell",
    ];
}

/**
 * Short human-friendly timestamp, e.g. "2 hours ago". Falls back to the
 * plain date when the record is older than a week.
 */
function notificationTimeAgo(string $datetime, ?int $nowTs = null): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return "";
    }

    // The caller can pass the database's own NOW() so the "ago" value is
    // measured against the same clock that wrote created_at. Without it
    // PHP's time() is used, which can sit in a different timezone from
    // MySQL and report a negative or wildly wrong interval.
    $now = $nowTs !== null ? $nowTs : time();
    $diff = $now - $ts;

    // A small negative value just means "moments apart"; anything larger
    // means a clock mismatch, so fall back to the plain date rather than
    // printing a nonsensical "in 5 hours".
    if ($diff < -300) {
        return date("M j, Y g:i A", $ts);
    }
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 60)     return "Just now";
    if ($diff < 3600)   return floor($diff / 60) . " min ago";

    if ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
    }

    if ($diff < 604800) {
        $days = (int)floor($diff / 86400);
        return $days . " day" . ($days > 1 ? "s" : "") . " ago";
    }

    return date("M j, Y g:i A", $ts);
}
