<?php 
$host = "localhost";
$user = "root";
$password = "";
$database = "project_manager";

// let's create a connection with the DB using the following code
$conn = mysqli_connect($host, $user, $password, $database);

// check connection
if (!$conn) {
    die("Connection Failed! " . mysqli_connect_error());
}
// echo "Connected to Database successfully!";
?>