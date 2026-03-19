<?php
require_once '../config/database.php';
require_once '../config/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Task ID is required']);
    exit;
}

$taskId = intval($_GET['id']);

try {
    $sql = "
        SELECT et.*, e.event_name, u.username as assigned_name
        FROM event_tasks et
        JOIN events e ON et.event_id = e.id
        JOIN users u ON et.assigned_to = u.id
        WHERE et.id = ?
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($task = mysqli_fetch_assoc($result)) {
        echo json_encode($task);
    } else {
        echo json_encode(['error' => 'Task not found']);
    }
} catch(Exception $e) {
    echo json_encode(['error' => 'Error fetching task: ' . $e->getMessage()]);
}
?>