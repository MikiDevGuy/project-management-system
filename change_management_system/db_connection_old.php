<?php
// db_connection.php - Updated to use consistent database
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'test_manager'; // Use same database as main system

$conn = mysqli_connect($host, $user, $password, $database);
if(!$conn){
    die("Connection Failed: " . mysqli_connect_error());
}
?>