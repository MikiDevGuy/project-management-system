<?php
include 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'];
$start = $data['start'];
$end = $data['end'];

if (strpos($id, 'phase_') === 0) {
    $id = str_replace('phase_', '', $id);
    $stmt = $conn->prepare("UPDATE phases SET start_date = ?, end_date = ? WHERE id = ?");
} elseif (strpos($id, 'act_') === 0) {
    $id = str_replace('act_', '', $id);
    $stmt = $conn->prepare("UPDATE activities SET start_date = ?, end_date = ? WHERE id = ?");
} else {
    die("Invalid ID");
}

$stmt->bind_param("ssi", $start, $end, $id);
$stmt->execute();
$stmt->close();
echo "Updated!";
