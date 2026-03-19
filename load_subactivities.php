<?php
include 'db.php';

$activity_id = $_GET['activity_id'];
$stmt = $conn->prepare("SELECT id, name, start_date, end_date, status FROM sub_activities WHERE activity_id = ?");
$stmt->bind_param("i", $activity_id);
$stmt->execute();
$result = $stmt->get_result();
$subs = [];
while ($row = $result->fetch_assoc()) {
    $row['percent_complete'] = ($row['status'] === 'completed') ? 100 : ($row['status'] === 'in_progress' ? 50 : 0);
    $subs[] = $row;
}
echo json_encode($subs);
