<?php
include 'db.php';

$id = $_GET['id'];
$project_id = $_GET['project_id'];

$conn->query("DELETE FROM test_cases WHERE id = $id");

header("Location: view_project.php?id=$project_id");
exit;
?>
