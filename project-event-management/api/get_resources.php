<?php
require_once '../config/database.php';
require_once '../config/functions.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Resource ID is required']);
    exit;
}

$resourceId = intval($_GET['id']);

try {
    // Fixed SQL - removed vendor join since vendor_id doesn't exist
    $sql = "
        SELECT er.*, e.event_name
        FROM event_resources er
        JOIN events e ON er.event_id = e.id
        WHERE er.id = ?
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($resource = mysqli_fetch_assoc($result)) {
        echo json_encode($resource);
    } else {
        echo json_encode(['error' => 'Resource not found']);
    }
} catch(Exception $e) {
    echo json_encode(['error' => 'Error fetching resource: ' . $e->getMessage()]);
}
?>