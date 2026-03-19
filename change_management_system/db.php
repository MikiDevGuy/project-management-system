<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'Project_manager';

$conn = mysqli_connect($host, $user, $password, $database);
if(!$conn){
    die("connection Failed!" . mysqli_connect_error());
}
?>