<?php
include 'db.php';

$activity_id = $_GET['activity_id'] ?? 0;
$subs = [];

$sql = "SELECT sa.*, u.name AS owner 
  FROM sub_activities sa
  JOIN users u ON sa.assigned_to = u.id
  WHERE sa.activity_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $activity_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $subs[] = [
    'id' => 'sub_' . $row['id'],
    'name' => $row['name'],
    'start' => $row['start_date'],
    'end' => $row['end_date'],
    'status' => $row['status'],
    'owner' => $row['owner'],
    'progress' => 0
  ];
}

header('Content-Type: application/json');
echo json_encode($subs);
