<?php
include 'db.php';

$id = $_POST['id'];
$type = $_POST['type'];
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];

if (!$id || !$type || !$start_date || !$end_date) {
    http_response_code(400);
    echo "Missing required data.";
    exit;
}

if ($type === 'phase') {
    $stmt = $conn->prepare("UPDATE phases SET start_date = ?, end_date = ? WHERE id = ?");
} elseif ($type === 'activity') {
    $stmt = $conn->prepare("UPDATE activities SET start_date = ?, end_date = ? WHERE id = ?");
} elseif ($type === 'sub-activity') {
    $stmt = $conn->prepare("UPDATE sub_activities SET start_date = ?, end_date = ? WHERE id = ?");
} else {
    http_response_code(400);
    echo "Invalid type.";
    exit;
}

$stmt->bind_param("ssi", $start_date, $end_date, $id);
$success = $stmt->execute();
$stmt->close();
$conn->close();

if ($success) {
    echo "✅ Date updated!";
} else {
    http_response_code(500);
    echo "❌ Update failed.";
}
