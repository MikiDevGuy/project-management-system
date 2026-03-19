<?php
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get project ID from request
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($project_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid project ID']);
    exit;
}

// Check user permissions
$role = $_SESSION['system_role'] ?? 'viewer';
$user_id = $_SESSION['user_id'];

if ($role === 'super_admin' || $role === 'admin') {
    // Admins can see all projects
    $stmt = $conn->prepare("SELECT name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
} else {
    // Regular users can only see projects they're assigned to
    $stmt = $conn->prepare("
        SELECT p.name 
        FROM projects p
        JOIN project_users pu ON p.id = pu.project_id
        WHERE p.id = ? AND pu.user_id = ?
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $project = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'project_name' => htmlspecialchars($project['name'])
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Project not found or access denied'
    ]);
}

$stmt->close();
$conn->close();
?>