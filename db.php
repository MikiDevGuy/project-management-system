<?php 
$host = "localhost";
$user = "root";
$password = "";
$database = "Project_manager";
//let create connection with the db with the following code 
$conn = mysqli_connect($host,$user, $password, $database);
//check connection
if(!$conn){
    die("connection Failed!" . mysqli_connect_error());
}
//echo "Connected to Database successfully!";

?>