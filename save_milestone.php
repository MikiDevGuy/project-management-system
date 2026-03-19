<?php
session_start();
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
$id = $_POST['id'] ?? 0;
$project_id = $_POST['project_id'] ?? 0;
$phase_id = $_POST['phase_id'] ?? null;
$title = $_POST['title'] ?? 'Changed title';
$description = $_POST['description'] ?? 'changed description';
$date = $_POST['date'] ?? '';
$color = $_POST['color'] ?? '#273274';
$created_by = $_SESSION['user_id'];

if ($id) {
    // Update existing milestone
    $stmt = $conn->prepare("UPDATE milestones SET title=?, description=?, start_date=?, end_date=?, color=?, updated_by=? WHERE id=?");
    $stmt->bind_param("sssssii", $title, $description, $date, $date, $color, $created_by, $id);
} else {
    // Insert new milestone
    $stmt = $conn->prepare("INSERT INTO milestones (project_id, phase_id, title, description, start_date, end_date, color, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssssi", $project_id, $phase_id, $title, $description, $date, $date, $color, $created_by);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $stmt->error]);
}
}
else {
    echo 'there is no post request made';
}
?>