<?php
session_start();
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {

$name = $_POST['name'];
$status = $_POST['status'];
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$desc = $_POST['description'];
$department_id = $_POST['department_id'];
if(!$department_id){
    echo "department id is not set from the form";
}
$user_id = $_SESSION['user_id'];
if(!$user_id){
    echo "user id not found from the session";
}
//$null = null;

$stmt = $conn->prepare("INSERT INTO projects (name, description, status, start_date, end_date, created_by, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssii", $name, $desc, $status, $start_date, $end_date, $user_id, $department_id);
$stmt->execute();
}

header("Location: index.php");
exit;
?>
