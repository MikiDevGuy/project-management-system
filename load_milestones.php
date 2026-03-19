<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'db.php';

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? null;
$phase_id = $_GET['phase_id'] ?? null;
$activity_id = $_GET['activity_id'] ?? null;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['system_role'];

try {
    if (!$project_id && !$phase_id && !$activity_id) {
        echo json_encode(['error' => 'No filter provided']);
        exit();
    }

    $query = "SELECT m.*, p.name as project_name, ph.name as phase_name, a.name as activity_name 
              FROM milestones m 
              LEFT JOIN projects p ON m.project_id = p.id 
              LEFT JOIN phases ph ON m.phase_id = ph.id 
              LEFT JOIN activities a ON m.activity_id = a.id 
              WHERE 1=1";

    $params = [];
    $types = "";

    if ($project_id) {
        $query .= " AND m.project_id = ?";
        $params[] = $project_id;
        $types .= "i";
    }

    if ($phase_id) {
        $query .= " AND m.phase_id = ?";
        $params[] = $phase_id;
        $types .= "i";
    }

    if ($activity_id) {
        $query .= " AND m.activity_id = ?";
        $params[] = $activity_id;
        $types .= "i";
    }

    // Add permission check for non-super admins
    if ($role !== 'super_admin') {
        $query .= " AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = m.project_id AND pu.user_id = ?)";
        $params[] = $user_id;
        $types .= "i";
    }

    $query .= " ORDER BY m.target_date ASC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $milestones = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => $milestones]);
    $stmt->close();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>