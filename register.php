<?php

require_once "config/database.php";

$message = "";


if (isset($_POST["register"])) {


    $student_number = trim($_POST["student_number"]);
    $first_name = trim($_POST["first_name"]);
    $last_name = trim($_POST["last_name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];



    if ($password !== $confirm_password) {


        $message = "Passwords do not match.";



    } else {



        $check = $conn->prepare(
            "SELECT user_id FROM users
             WHERE student_number = ? OR email = ?"
        );


        $check->bind_param(
            "ss",
            $student_number,
            $email
        );


        $check->execute();

        $check->store_result();



        if ($check->num_rows > 0) {


            $message = "Student number or email already exists.";



        } else {



            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );



            $stmt = $conn->prepare(
                "INSERT INTO users
                (student_number, first_name, last_name, email, password, status)
                VALUES (?, ?, ?, ?, ?, 'active')"
            );



            $stmt->bind_param(
                "sssss",
                $student_number,
                $first_name,
                $last_name,
                $email,
                $hashed_password
            );



            if ($stmt->execute()) {


                $message = "Account created successfully!";


            } else {


                $message = "Something went wrong.";


            }



            $stmt->close();


        }



        $check->close();


    }


}



?>


<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Register | KitaKits</title>


<link rel="stylesheet" href="css/style.css">


</head>



<body class="auth-body">





<div class="auth-container">





<h1 class="logo">

KitaKits

</h1>





<h2>

Create Account

</h2>



<p class="subtitle">

Register to start using KitaKits.

</p>







<?php if(!empty($message)): ?>
<div class="alert-box <?php echo strpos($message, 'successfully') !== false ? 'alert-success' : 'alert-error'; ?>" id="regAlert">
    <div class="alert-icon">
        <svg viewBox="0 0 24 24">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
        </svg>
    </div>
    <div class="alert-content">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <button type="button" class="alert-close" onclick="document.getElementById('regAlert').remove()" title="Dismiss">&times;</button>
</div>
<?php endif; ?>

<form method="POST" autocomplete="off">







<div class="two-column">



<div class="input-group">


<label>

First Name

</label>


<input

type="text"

name="first_name"

required>


</div>






<div class="input-group">


<label>

Last Name

</label>


<input

type="text"

name="last_name"

required>


</div>



</div>









<div class="input-group">


<label>

Student Number

</label>


<input

type="text"

name="student_number"

required>


</div>








<div class="input-group">


<label>

Email Address

</label>


<input

type="email"

name="email"

required>


</div>









<div class="input-group password-group">


<label>

Password

</label>


<input

type="password"

name="password"

id="register-password"

required>


<span class="eye" onclick="toggleRegisterPassword()">

👁

</span>



</div>








<div class="input-group password-group">


<label>

Confirm Password

</label>


<input

type="password"

name="confirm_password"

id="confirm-password"

required>


<span class="eye" onclick="toggleConfirmPassword()">

👁

</span>



</div>









<button

type="submit"

name="register"

class="auth-btn">

Register

</button>






</form>







<p class="bottom-text">


Already have an account?


<a href="index.php">

Login here

</a>


</p>







</div>









<script>


function toggleRegisterPassword(){


let pass=document.getElementById("register-password");



if(pass.type==="password"){

pass.type="text";


}else{


pass.type="password";


}


}







function toggleConfirmPassword(){


let pass=document.getElementById("confirm-password");



if(pass.type==="password"){

pass.type="text";


}else{


pass.type="password";


}


}



</script>







</body>

</html>