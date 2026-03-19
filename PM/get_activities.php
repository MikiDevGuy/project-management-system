<?php
include 'db.php';

$phase_id = $_GET['phase_id'] ?? 0;
$activities = [];

$sql = "SELECT id, name, start_date, end_date FROM activities WHERE phase_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $phase_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $activities[] = [
    'id' => 'activity_' . $row['id'],
    'name' => $row['name'],
    'start' => $row['start_date'],
    'end' => $row['end_date'],
    'progress' => 0
  ];
}

header('Content-Type: application/json');
echo json_encode($activities);