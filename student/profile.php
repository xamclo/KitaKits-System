<?php

session_start();

require_once "../config/database.php";


if (!isset($_SESSION["user_id"]) || $_SESSION["role_view"] !== "student") {
    header("Location: ../index.php");
    exit();
}


$user_id = $_SESSION["user_id"];



// CSRF token: one per session, reused across pages.
if (empty($_SESSION["csrf_token"])) {

$_SESSION["csrf_token"] = bin2hex(random_bytes(32));

}



// ---------------------------------------------------------

// SAVE EDIT PROFILE

// Only the authenticated student's own row is ever touched.
// password / role / student_number are NOT part of this form.

// ---------------------------------------------------------

$profile_errors = [];
$profile_saved  = false;



if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_profile"])) {



    // CSRF

    if (!hash_equals($_SESSION["csrf_token"], (string)($_POST["csrf_token"] ?? ""))) {

$profile_errors[] = "Your session expired. Please try again.";

}



    $first_name     = trim($_POST["first_name"] ?? "");
    $last_name      = trim($_POST["last_name"] ?? "");
    $email          = strtolower(trim($_POST["email"] ?? ""));
    $program        = trim($_POST["program"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");



    // Validate

    if ($first_name === "" || $last_name === "") {

$profile_errors[] = "Full name is required.";

}

    if (mb_strlen($first_name) > 60 || mb_strlen($last_name) > 60) {

$profile_errors[] = "Name is too long (60 characters max).";

}

    if ($email === "") {

$profile_errors[] = "Email is required.";

} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

$profile_errors[] = "Please enter a valid email address.";

}

    if (mb_strlen($program) > 120) {

$profile_errors[] = "Program is too long (120 characters max).";

}

    if ($contact_number !== "" && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact_number)) {

$profile_errors[] = "Please enter a valid contact number.";

}



    // Duplicate email conflict (another account already owns it)

    if (!$profile_errors) {

$dup = $conn->prepare("SELECT user_id FROM users WHERE LOWER(email)=? AND user_id<>? LIMIT 1");
        $dup->bind_param("si", $email, $user_id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {

$profile_errors[] = "That email is already used by another account.";

}
        $dup->close();

}



    // Update

    if (!$profile_errors) {

$up = $conn->prepare(
            "UPDATE users SET first_name=?, last_name=?, email=?, program=?, contact_number=? WHERE user_id=?"
        );
        $up->bind_param("sssssi", $first_name, $last_name, $email, $program, $contact_number, $user_id);

        if ($up->execute()) {

// Keep the session copy in step so the header shows the new name.
            $_SESSION["first_name"] = $first_name;
            $_SESSION["last_name"]  = $last_name;
            $_SESSION["email"]      = $email;

            $up->close();

            // Post/Redirect/Get: a browser refresh cannot resubmit the update.
            $_SESSION["profile_flash"] = "Profile updated successfully.";
            header("Location: profile.php");
            exit();

}

        $profile_errors[] = "Unable to save your profile. Please try again.";
        $up->close();

}

}



// Show the success banner once, then clear it.

if (!empty($_SESSION["profile_flash"])) {

$profile_saved = true;
    unset($_SESSION["profile_flash"]);

}


// GET USER INFORMATION

$query = $conn->prepare(
    "
    SELECT
    first_name,
    last_name,
    student_number,
    email,
    program,
    contact_number
    FROM users
    WHERE user_id=?
    "
);


$query->bind_param("i", $user_id);

$query->execute();

$user = $query->get_result()->fetch_assoc();


if (!$user) {
    header("Location: ../index.php");
    exit();
}


// INITIAL AVATAR LETTER AND ROLE LABEL

$full_name = trim($user["first_name"] . " " . $user["last_name"]);

$initial = strtoupper(mb_substr($full_name, 0, 1, "UTF-8"));

$role_label = ($_SESSION["role_view"] === "admin") ? "Admin" : "Student";

?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Profile | KitaKits</title>


<link rel="stylesheet" href="../css/style.css">


<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../css/student-brand.css?v=3">
<link rel="stylesheet" href="../css/student-sidebar.css?v=3">
<link rel="stylesheet" href="../css/student-header.css">


</head>



<body class="new-dashboard">




<!-- SIDEBAR -->

<?php $__sbActive = "profile"; include __DIR__ . "/../includes/sidebar_student.php"; ?>


<!-- CONTENT -->

<div class="dashboard-content" id="content">


<div class="top-header">

<div>
<h1>
Profile
</h1>

<p>
Manage your personal information.
</p>

</div>


<?php include "../includes/student_header_actions.php"; ?>


</div>




<!-- PROFILE CARD -->

<?php $__edit = !empty($profile_errors); ?>

<?php if ($profile_saved): ?>
<div class="profile-alert profile-alert-ok">Profile updated successfully.</div>
<?php endif; ?>

<?php if ($profile_errors): ?>
<div class="profile-alert profile-alert-err">
<?php foreach ($profile_errors as $__e): ?>
<div><?php echo htmlspecialchars($__e); ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="profile-card">


<div class="profile-header">

<div class="profile-avatar">
<?php echo htmlspecialchars($initial); ?>
</div>

<div class="profile-header-info">

<h2>
<?php echo htmlspecialchars($full_name); ?>
</h2>

<span class="role-badge">
<?php echo htmlspecialchars($role_label); ?>
</span>

</div>

</div>


<!-- VIEW MODE -->

<div id="profileView"<?php echo $__edit ? ' style="display:none"' : ''; ?>>

<h3 class="profile-section-title">
Student Information
</h3>


<div class="profile-info-grid">

<span class="info-label">Full Name</span>
<span class="info-value"><?php echo htmlspecialchars($full_name); ?></span>


<span class="info-label">Student ID</span>
<span class="info-value"><?php echo htmlspecialchars($user["student_number"] ?? "-"); ?></span>


<span class="info-label">Email</span>
<span class="info-value"><?php echo htmlspecialchars($user["email"] ?? "-"); ?></span>


<span class="info-label">Program</span>
<span class="info-value"><?php echo htmlspecialchars($user["program"] ?? "-"); ?></span>


<span class="info-label">Contact Number</span>
<span class="info-value"><?php echo htmlspecialchars($user["contact_number"] ?? "-"); ?></span>


</div>


<button type="button" class="btn-edit-profile" id="btnEditProfile">
Edit Profile
</button>

</div>



<!-- EDIT MODE -->

<div id="profileEdit"<?php echo $__edit ? '' : ' style="display:none"'; ?>>

<form method="post" action="profile.php" id="profileForm" novalidate>

<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
<input type="hidden" name="update_profile" value="1">


<h3 class="profile-section-title">
Edit Student Information
</h3>


<div class="profile-info-grid">

<label class="info-label" for="pfFirst">First Name</label>
<input class="info-value" type="text" id="pfFirst" name="first_name" maxlength="60" required value="<?php echo htmlspecialchars($_POST["first_name"] ?? $user["first_name"] ?? ""); ?>">


<label class="info-label" for="pfLast">Last Name</label>
<input class="info-value" type="text" id="pfLast" name="last_name" maxlength="60" required value="<?php echo htmlspecialchars($_POST["last_name"] ?? $user["last_name"] ?? ""); ?>">


<span class="info-label">Student ID</span>
<input class="info-value" type="text" value="<?php echo htmlspecialchars($user["student_number"] ?? "-"); ?>" disabled title="Student ID cannot be changed">


<label class="info-label" for="pfEmail">Email</label>
<input class="info-value" type="email" id="pfEmail" name="email" maxlength="150" required value="<?php echo htmlspecialchars($_POST["email"] ?? $user["email"] ?? ""); ?>">


<label class="info-label" for="pfProgram">Program</label>
<input class="info-value" type="text" id="pfProgram" name="program" maxlength="120" value="<?php echo htmlspecialchars($_POST["program"] ?? $user["program"] ?? ""); ?>">


<label class="info-label" for="pfContact">Contact Number</label>
<input class="info-value" type="text" id="pfContact" name="contact_number" maxlength="20" value="<?php echo htmlspecialchars($_POST["contact_number"] ?? $user["contact_number"] ?? ""); ?>">


</div>


<div class="profile-form-actions">

<button type="submit" class="btn-edit-profile">
Save Changes
</button>

<button type="button" class="btn-cancel-profile" id="btnCancelProfile">
Cancel
</button>

</div>

</form>

</div>


</div>


</div>




<script>
// Toggle between view and edit. Cancel discards unsaved changes
// because the fields are never written until Save is pressed.
(function () {
    var view  = document.getElementById('profileView');
    var edit  = document.getElementById('profileEdit');
    var bEdit = document.getElementById('btnEditProfile');
    var bCan  = document.getElementById('btnCancelProfile');
    if (!view || !edit || !bEdit || !bCan) return;

    bEdit.addEventListener('click', function () {
        view.style.display = 'none';
        edit.style.display = 'block';
        var f = document.getElementById('pfFirst');
        if (f) f.focus();
    });

    bCan.addEventListener('click', function () {
        edit.style.display = 'none';
        view.style.display = 'block';
        var form = document.getElementById('profileForm');
        if (form) form.reset();
    });
})();
</script>


<script src="../js/student-header.js"></script>

<script src="../js/student-sidebar.js"></script>
</body>

</html>
