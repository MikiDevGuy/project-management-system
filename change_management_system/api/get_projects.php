<?php
// api/get_projects.php
require_once '../db.php';

$query = "SELECT id as project_id, name as project_name FROM projects ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$projects = $result->fetch_all(MYSQLI_ASSOC);

header('Content-Type: application/json');
echo json_encode($projects);
?>