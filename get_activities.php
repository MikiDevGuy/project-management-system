<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

$phase_id = isset($_GET['phase_id']) ? (int)$_GET['phase_id'] : 0;

if ($phase_id > 0) {
    $query = "SELECT id, name FROM activities WHERE phase_id = ? ORDER BY name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $phase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    
    echo json_encode($activities);
} else {
    echo json_encode([]);
}
?>