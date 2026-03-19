<?php
// load_status_summary.php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit("Unauthorized");
}

include 'db.php'; // Your database connection file

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Project ID is required']);
    exit();
}

$statusSummary = [
    'phases' => ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'total' => 0],
    'activities' => ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'total' => 0],
    'sub_activities' => ['completed' => 0, 'in_progress' => 0, 'pending' => 0, 'total' => 0]
];

// --- Get Phase Status ---
$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM phases WHERE project_id = ? GROUP BY status");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusSummary['phases'][$row['status']] = (int)$row['count'];
    $statusSummary['phases']['total'] += (int)$row['count'];
}
$stmt->close();
// Account for statuses not explicitly 'completed' or 'in_progress' as 'pending'
$statusSummary['phases']['pending'] = $statusSummary['phases']['total'] - $statusSummary['phases']['completed'] - $statusSummary['phases']['in_progress'];


// --- Get Activity Status ---
// Join activities with phases to filter by project_id
$stmt = $conn->prepare("
    SELECT a.status, COUNT(*) as count 
    FROM activities a
    JOIN phases p ON a.phase_id = p.id
    WHERE p.project_id = ? 
    GROUP BY a.status
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusSummary['activities'][$row['status']] = (int)$row['count'];
    $statusSummary['activities']['total'] += (int)$row['count'];
}
$stmt->close();
$statusSummary['activities']['pending'] = $statusSummary['activities']['total'] - $statusSummary['activities']['completed'] - $statusSummary['activities']['in_progress'];


// --- Get Sub-Activity Status ---
// Join sub_activities with activities and phases to filter by project_id
$stmt = $conn->prepare("
    SELECT sa.status, COUNT(*) as count 
    FROM sub_activities sa
    JOIN activities a ON sa.activity_id = a.id
    JOIN phases p ON a.phase_id = p.id
    WHERE p.project_id = ? 
    GROUP BY sa.status
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $statusSummary['sub_activities'][$row['status']] = (int)$row['count'];
    $statusSummary['sub_activities']['total'] += (int)$row['count'];
}
$stmt->close();
$statusSummary['sub_activities']['pending'] = $statusSummary['sub_activities']['total'] - $statusSummary['sub_activities']['completed'] - $statusSummary['sub_activities']['in_progress'];


$conn->close();

echo json_encode($statusSummary);
?>