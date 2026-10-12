<?php
/**
 * School Management access control + shared helpers.
 *
 * ROLE MODEL (additive, no existing behaviour changed)
 *   users.role IS NULL -> legacy account. Keeps the existing
 *                        Student/Administrator selector exactly as before.
 *   users.role = 'school_management' -> School Management. No selector is shown
 *                        and the role is forced server-side on every request,
 *                        so the browser cannot escalate it.
 *
 * Every page and API in /school includes this first. Nothing in the
 * Student or Admin areas is modified by this file.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Hard server-side guard. Redirects rather than trusting any client state. */
function requireSchoolRole(): void
{
    if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "school") {
        header("Location: ../index.php");
        exit();
    }

    // A first-login account cannot use the dashboard until it sets a new
    // password. This page is the only exception.
    if (!empty($_SESSION["must_change_password"])
        && basename($_SERVER["SCRIPT_NAME"] ?? "") !== "change_password.php") {
        header("Location: change_password.php");
        exit();
    }
}

/** True when the signed-in account is a School Management user. */
function isSchoolUser(): bool
{
    return isset($_SESSION["user_id"]) && ($_SESSION["role_view"] ?? "") === "school";
}

function schoolUserId(): int
{
    return (int)($_SESSION["user_id"] ?? 0);
}

/**
 * Append-only audit trail. Never rewrites history - every call adds a row.
 *
 * The acting role is stored on the event itself so the timeline can always
 * say WHO acted, even if the account's role changes later.
 */
function auditEvent(
    $conn,
    ?int $reservationId,
    ?int $actorId,
    string $action,
    ?string $previous = null,
    ?string $new = null,
    ?string $notes = null
): void {
    $role = ($_SESSION["role_view"] ?? null) ?: null;

    $stmt = $conn->prepare(
        "INSERT INTO transaction_audit_logs
            (reservation_id, actor_user_id, actor_role, action_type,
             previous_status, new_status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issssss", $reservationId, $actorId, $role, $action, $previous, $new, $notes);
    $stmt->execute();
    $stmt->close();
}

/* ------------------------------------------------------------------
   SCHOOL POLICY

   The high-value designation follows an authorised school rule rather than
   the owner's preference alone. While the policy threshold is NULL there is
   no automatic rule and the owner decides (the original behaviour). Once a
   threshold is set by the school, any item at or above it is high-value and
   the owner cannot un-tick the box to bypass approval.
------------------------------------------------------------------ */

/** Configured threshold, or null when the school has set no automatic rule. */
function schoolHighValueThreshold($conn): ?float
{
    $res = $conn->query(
        "SELECT policy_value FROM school_policy_config
         WHERE policy_key = 'high_value_threshold' LIMIT 1"
    );
    $row = $res ? $res->fetch_row() : null;
    if ($res) {
        $res->free();
    }
    if (!$row || $row[0] === null || $row[0] === "") {
        return null;
    }
    return (float)$row[0];
}

/**
 * True when policy forces this item to be high-value regardless of what
 * the owner ticked.
 */
function policyForcesHighValue($conn, $declaredValue): bool
{
    $threshold = schoolHighValueThreshold($conn);
    if ($threshold === null) {
        return false;
    }
    if ($declaredValue === null || $declaredValue === "") {
        return false;
    }
    return (float)$declaredValue >= $threshold;
}

/** Notification for a real event, to a real user. */
function notifyUser($conn, int $userId, string $message): void
{
    if ($userId <= 0) {
        return;
    }
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param("is", $userId, $message);
    $stmt->execute();
    $stmt->close();
}

/** Every School Management user, used to alert the institutional reviewers. */
function notifyAllSchoolUsers($conn, string $message, ?int $exceptUserId = null): int
{
    $rows = $conn->query("SELECT user_id FROM users WHERE role = 'school_management' AND status = 'active'");
    $sent = 0;
    while ($r = $rows->fetch_assoc()) {
        $id = (int)$r["user_id"];
        if ($exceptUserId !== null && $id === $exceptUserId) {
            continue;
        }
        notifyUser($conn, $id, $message);
        $sent++;
    }
    $rows->free();
    return $sent;
}

/** JSON reply helper for the /school/api endpoints. */
function schoolReply(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(["ok" => $ok, "message" => $message], $extra));
    exit;
}

/** Guarded JSON reply: unauthenticated requests get 401 and nothing else. */
function schoolApiGuard($conn): int
{
    header("Content-Type: application/json; charset=utf-8");

    if (!isSchoolUser()) {
        http_response_code(401);
        echo json_encode(["ok" => false, "message" => "Unauthorized."]);
        exit;
    }
    return schoolUserId();
}

/**
 * School witness state for one reservation, derived from the linked
 * request row rather than from reservations.status.
 *
 * not_required | pending_witness | witnessed | flagged
 */
function schoolWitnessState($conn, int $reservationId): array
{
    $stmt = $conn->prepare(
        "SELECT * FROM school_approval_requests WHERE reservation_id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $reservationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ["state" => "none", "row" => null];
    }
    return ["state" => strtolower($row["status"]), "row" => $row];
}

/** Fulfillment state, deliberately separate from school approval. */
function fulfillmentState(array $reservation): string
{
    $status = strtolower(trim((string)$reservation["status"]));
    $returned = !empty($reservation["returned_at"]) && stripos((string)$reservation["returned_at"], "0000") === false;

    if (in_array($status, ["declined", "rejected", "cancelled", "canceled"], true)) {
        return "declined";
    }
    if ($status === "completed") {
        return "completed";
    }
    if ($returned) {
        return "return_submitted";
    }
    if ($status === "approved") {
        // Release is recorded as an audit event, never inferred from a date.
        return "released";
    }
    if ($status === "pending") {
        return "awaiting_release";
    }
    return $status;
}