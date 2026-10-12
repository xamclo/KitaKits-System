<?php
session_start();
require_once '../config/database.php';
if(!isset($_SESSION['user_id']) || $_SESSION['role_view']!='student'){header('Location: ../index.php');exit();}
if($_SERVER['REQUEST_METHOD']=='POST'){
$stmt=$conn->prepare("INSERT INTO reports(user_id,reservation_id,subject,issue_type,description) VALUES(?,?,?,?,?)");
$stmt->bind_param('iisss',$_SESSION['user_id'],$_POST['reservation_id'],$_POST['subject'],$_POST['issue_type'],$_POST['description']);
$stmt->execute();
}
?>
<!DOCTYPE html><html><head><title>Report Issue</title><link rel="stylesheet" href="../css/style.css"></head><body>
<div class="container"><h2>Report an Issue</h2><form method="post"><input name="reservation_id" placeholder="Reservation ID" required><input name="subject" placeholder="Subject" required><input name="issue_type" placeholder="Issue Type" required><textarea name="description" placeholder="Describe issue" required></textarea><button>Submit Report</button></form></div>
</body></html>
