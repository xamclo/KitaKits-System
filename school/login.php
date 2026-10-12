<?php

session_start();

require_once __DIR__ . "/../config/database.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!$email || !$password) {
        $message = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, email, password, status, role, must_change_password
             FROM users
             WHERE email = ? AND role = 'school_management'"
        );

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if ($user["status"] !== "active") {
                    $message = "Your account is inactive. Please contact administrator.";
                } elseif (password_verify($password, $user["password"])) {
                    $_SESSION["user_id"] = $user["user_id"];
                    $_SESSION["email"] = $user["email"];
                    $_SESSION["role_view"] = "school";

                    if (!empty($user["must_change_password"])) {
                        $_SESSION["must_change_password"] = 1;
                        header("Location: change_password.php");
                        exit();
                    }

                    header("Location: dashboard.php");
                    exit();
                } else {
                    $message = "Invalid email or password.";
                }
            } else {
                $message = "Account not registered or not authorized for School Management.";
            }

            $stmt->close();
        } else {
            $message = "Unable to process login at this time.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management Login | KitaKits</title>
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="auth-body">
    <div class="auth-container">
        <h1 class="logo">KitaKits</h1>
        <h2>School Management Login</h2>
        <p class="subtitle">Login to access the School Management Dashboard.</p>

        <form method="POST" autocomplete="off">
            <div class="input-group">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value=""
                    autocomplete="off"
                    required>
            </div>

            <div class="input-group password-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    name="password"
                    id="password"
                    value=""
                    autocomplete="new-password"
                    required>
                <span class="eye" onclick="togglePassword()" title="Toggle password visibility">
                    👁
                </span>
            </div>

            <button type="submit" class="auth-btn">Login</button>
        </form>

        <p class="bottom-text">
            <a href="../index.php">Back to main login</a>
        </p>
    </div>

    <script>
    window.addEventListener("DOMContentLoaded", function() {
        const emailField = document.getElementById("email");
        const passwordField = document.getElementById("password");
        if (emailField) {
            emailField.value = "";
        }
        if (passwordField) {
            passwordField.value = "";
        }
    });

    <?php if (!empty($message)): ?>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Swal !== "undefined") {
            Swal.fire({
                icon: 'error',
                title: 'Login Error',
                text: <?php echo json_encode($message); ?>,
                confirmButtonColor: '#315FD3',
                confirmButtonText: 'OK',
                allowOutsideClick: false,
                allowEscapeKey: true,
                customClass: {
                    popup: 'minimal-swal-popup',
                    title: 'minimal-swal-title',
                    htmlContainer: 'minimal-swal-text',
                    confirmButton: 'minimal-swal-btn'
                },
                showClass: {
                    popup: 'swal2-noanimation'
                },
                hideClass: {
                    popup: ''
                }
            }).then(function() {
                window.location.href = window.location.pathname;
            });
        } else {
            alert(<?php echo json_encode($message); ?>);
            window.location.href = window.location.pathname;
        }
    });
    <?php endif; ?>

    function togglePassword() {
        let password = document.getElementById("password");
        if (password.type === "password") {
            password.type = "text";
        } else {
            password.type = "password";
        }
    }
    </script>
</body>
</html>
