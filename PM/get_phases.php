<?php
include 'db.php';

$project_id = $_GET['project_id'] ?? 0;
$phases = [];

$sql = "SELECT id, name, start_date, end_date FROM phases WHERE project_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $project_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $phases[] = [
    'id' => 'phase_' . $row['id'],
    'name' => $row['name'],
    'start' => $row['start_date'],
    'end' => $row['end_date'],
    'progress' => 0
  ];
}

header('Content-Type: application/json');
echo json_encode($phases);