<?php
/**
 * First-login password change for a School Management account.
 *
 * A pre-authorized School Management account is created with a temporary
 * password and must_change_password = 1. It can do nothing else until the
 * password is changed here. No password is ever stored in this file.
 */

session_start();
require_once __DIR__ . "/../config/database.php";

if (!isset($_SESSION["user_id"]) || ($_SESSION["role_view"] ?? "") !== "school") {
    header("Location: ../index.php");
    exit();
}

// Already changed: do not ask again. This is what stops the page from
// re-prompting on every visit once the default password is replaced.
if (empty($_SESSION["must_change_password"])) {
    header("Location: dashboard.php");
    exit();
}

/**
 * The default School Management password. A first-login change must not
 * simply re-submit this value, otherwise the account would keep its
 * published credentials forever. Compared in constant time.
 */
const SCHOOL_DEFAULT_PASSWORD = "schooladmin2026";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $uid     = (int)$_SESSION["user_id"];
    $current = (string)($_POST["current_password"] ?? "");
    $new     = (string)($_POST["new_password"] ?? "");
    $conf    = (string)($_POST["confirm_password"] ?? "");

// Load the stored hash so the current password can be verified.
    $row = $conn->prepare("SELECT password FROM users WHERE user_id = ? AND role = 'school_management'");
    $row->bind_param("i", $uid);
    $row->execute();
    $stored = $row->get_result()->fetch_assoc();
    $row->close();

    if (!$stored) {
        $error = "The account could not be found. Please sign in again.";
    } elseif (!password_verify($current, $stored["password"])) {
        $error = "The current password is incorrect.";
    } elseif (strlen($new) < 8) {
        $error = "Please choose a password of at least 8 characters.";
    } elseif (hash_equals(SCHOOL_DEFAULT_PASSWORD, $new)) {
        $error = "The new password must be different from the default password.";
    } elseif ($new !== $conf) {
        $error = "The two passwords do not match.";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE user_id = ? AND role = 'school_management'");
        $stmt->bind_param("si", $hash, $uid);

        if ($stmt->execute() && $stmt->affected_rows === 1) {
            unset($_SESSION["must_change_password"]);
            session_regenerate_id(true);
            header("Location: dashboard.php");
            exit();
        }
        $stmt->close();
        $error = "The password could not be updated. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | KitaKits</title>
    <link rel="stylesheet" href="../css/school-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .sm-card-box {
            background:#fff; border-radius:16px; padding:32px; width:100%; max-width:420px;
            box-shadow:0 10px 40px rgba(15,23,42,.12);
        }
        .sm-card-box h1 { font-size:21px; margin:0 0 6px; }
        .sm-card-box p.sub { color:#64748b; font-size:13px; margin:0 0 22px; }
        label { display:block; font-size:12px; font-weight:700; color:#475569; margin:14px 0 6px; }
        input {
            width:100%; padding:11px 12px; border:1px solid #e6e9f2; border-radius:9px;
            font-family:inherit; font-size:14px;
        }
        button {
            width:100%; margin-top:22px; padding:12px; border:none; border-radius:10px;
            background:#6b46e0; color:#fff; font-weight:700; font-size:14px;
            font-family:inherit; cursor:pointer;
        }
        button:hover { background:#7c5cf0; }
        .err {
            background:#fee2e2; color:#b91c1c; padding:10px 12px; border-radius:8px;
            font-size:13px; margin-bottom:8px;
        }
    </style>
</head>
<body>

<div class="sm-card-box">
    <h1>Change Your Password</h1>
    <p class="sub">You are signed in as School Management. Set a new password to continue.</p>

    <?php if ($error !== ""): ?>
        <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <label for="cp0">Current Password</label>
        <input type="password" name="current_password" id="cp0" required>

        <label for="np">New Password</label>
        <input type="password" name="new_password" id="np" required minlength="8">

        <label for="cp">Confirm New Password</label>
        <input type="password" name="confirm_password" id="cp" required minlength="8">

        <button type="submit">Save New Password</button>
    </form>
</div>

</body>
</html>
