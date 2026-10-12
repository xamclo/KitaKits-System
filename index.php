<?php

session_start();

require_once "config/database.php";

$message = "";


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $account_type = $_POST["account_type"] ?? "";

    if (!$email || !$password) {
        $message = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id, student_number, first_name, last_name,
             email, password, status, role, must_change_password
             FROM users
             WHERE email = ?"
        );

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                $userRole = isset($user["role"]) ? $user["role"] : null;

                if ($userRole === "school_management") {
                    if ($user["status"] !== "active") {
                        $message = "Your account is inactive. Please contact administrator.";
                    } elseif (password_verify($password, $user["password"])) {
                        $_SESSION["user_id"] = $user["user_id"];
                        $_SESSION["student_number"] = $user["student_number"];
                        $_SESSION["first_name"] = $user["first_name"];
                        $_SESSION["last_name"] = $user["last_name"];
                        $_SESSION["email"] = $user["email"];
                        $_SESSION["role_view"] = "school";

                        if (!empty($user["must_change_password"])) {
                            $_SESSION["must_change_password"] = 1;
                            header("Location: school/change_password.php");
                            exit();
                        }

                        header("Location: school/dashboard.php");
                        exit();
                    } else {
                        $message = "Invalid email or password.";
                    }
                } else {
                    if (!$account_type) {
                        $message = "Please select an account type before logging in.";
                    } elseif ($user["status"] !== "active") {
                        $message = "Your account is inactive. Please contact administrator.";
                    } elseif (password_verify($password, $user["password"])) {
                        $_SESSION["user_id"] = $user["user_id"];
                        $_SESSION["student_number"] = $user["student_number"];
                        $_SESSION["first_name"] = $user["first_name"];
                        $_SESSION["last_name"] = $user["last_name"];
                        $_SESSION["email"] = $user["email"];

                        if ($account_type === "student") {
                            if ($userRole && $userRole !== "student") {
                                $message = "Invalid account type for this email.";
                            } else {
                                $_SESSION["role_view"] = "student";
                                header("Location: student/dashboard.php");
                                exit();
                            }
                        } elseif ($account_type === "admin") {
                            if ($userRole && $userRole !== "admin") {
                                $message = "Invalid account type for this email.";
                            } else {
                                $_SESSION["role_view"] = "admin";
                                header("Location: admin/dashboard.php");
                                exit();
                            }
                        } else {
                            $message = "Invalid account type selected.";
                        }
                    } else {
                        $message = "Invalid email or password.";
                    }
                }
            } else {
                $message = "Account not registered.";
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
    <title>Login | KitaKits</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- SweetAlert2 for clean, minimal alerts -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>


<body class="auth-body">



<div class="auth-container">



<h1 class="logo">

KitaKits

</h1>




<h2>

Welcome Back!

</h2>



<p class="subtitle">

Login to your account.

</p>





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



<label class="account-label">

Account Type

</label>



<div class="account-buttons">



<input

type="hidden"

name="account_type"

id="account_type"

value="">





<button

type="button"

class="account"

onclick="selectAccount(this,'student')">

Student

</button>







<button

type="button"

class="account"

onclick="selectAccount(this,'admin')">

Administrator

</button>



</div>









<button

type="submit"

class="auth-btn">

Login

</button>





</form>








<p class="bottom-text">


Don't have an account?


<a href="register.php">

Register here

</a>



</p>







</div>






<script>
// Prevent and clear browser autofill on page load
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
// Clean, minimal SweetAlert2 error popup with auto-refresh on dismiss
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
            // Automatically refresh the page to clear fields and reset state
            window.location.href = window.location.pathname;
        });
    } else {
        // Fallback if CDN is unavailable
        alert(<?php echo json_encode($message); ?>);
        window.location.href = window.location.pathname;
    }
});
<?php endif; ?>

function selectAccount(button, type) {
    let buttons = document.querySelectorAll(".account");
    buttons.forEach(btn => {
        btn.classList.remove("active");
    });
    button.classList.add("active");
    document.getElementById("account_type").value = type;
}

function togglePassword() {
    let password = document.getElementById("password");
    if (password.type === "password") {
        password.type = "text";
    } else {
        password.type = "password";
    }
}

// Require account type selection before login for normal users
document.querySelector("form").addEventListener("submit", function(e) {
    const accountType = document.getElementById("account_type").value;
    const email = document.getElementById("email").value;
    if (!accountType && email !== "schooladmin.up@phinmaed.com") {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Please select account type',
            text: 'Choose Student or Administrator before logging in.',
            confirmButtonColor: '#315FD3',
            confirmButtonText: 'OK'
        });
    }
});
</script>



</body>

</html>
