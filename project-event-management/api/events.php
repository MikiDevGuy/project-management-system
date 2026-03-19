<?php
require_once '../config/database.php';
require_once '../config/functions.php';
checkAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'add') {
        // Add new event
        $eventName = sanitizeInput($conn, $_POST['event_name']);
        $projectId = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
        $eventType = sanitizeInput($conn, $_POST['event_type']);
        $organizerId = intval($_POST['organizer_id']);
        $startDatetime = sanitizeInput($conn, $_POST['start_datetime']);
        $endDatetime = !empty($_POST['end_datetime']) ? sanitizeInput($conn, $_POST['end_datetime']) : null;
        $location = sanitizeInput($conn, $_POST['location']);
        $description = sanitizeInput($conn, $_POST['description'] ?? '');
        $status = sanitizeInput($conn, $_POST['status']);
        $priority = sanitizeInput($conn, $_POST['priority']);
        
        $sql = "INSERT INTO events (event_name, project_id, event_type, organizer_id, start_datetime, end_datetime, location, description, status, priority) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sississsss", $eventName, $projectId, $eventType, $organizerId, $startDatetime, $endDatetime, $location, $description, $status, $priority);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Event added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error adding event: ' . mysqli_error($conn)]);
        }
        
    } elseif ($action === 'edit') {
        // Edit existing event
        $eventId = intval($_POST['id']);
        $eventName = sanitizeInput($conn, $_POST['event_name']);
        $projectId = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
        $eventType = sanitizeInput($conn, $_POST['event_type']);
        $organizerId = intval($_POST['organizer_id']);
        $startDatetime = sanitizeInput($conn, $_POST['start_datetime']);
        $endDatetime = !empty($_POST['end_datetime']) ? sanitizeInput($conn, $_POST['end_datetime']) : null;
        $location = sanitizeInput($conn, $_POST['location']);
        $description = sanitizeInput($conn, $_POST['description'] ?? '');
        $status = sanitizeInput($conn, $_POST['status']);
        $priority = sanitizeInput($conn, $_POST['priority']);
        
        // Check if user has permission to edit
        $checkSql = "SELECT organizer_id FROM events WHERE id = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "i", $eventId);
        mysqli_stmt_execute($checkStmt);
        $result = mysqli_stmt_get_result($checkStmt);
        $event = mysqli_fetch_assoc($result);
        
        if (!$event || ($_SESSION['user_id'] != $event['organizer_id'] && !hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager'))) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this event']);
            exit();
        }
        
        $sql = "UPDATE events SET 
                event_name = ?, 
                project_id = ?, 
                event_type = ?, 
                organizer_id = ?, 
                start_datetime = ?, 
                end_datetime = ?, 
                location = ?, 
                description = ?, 
                status = ?, 
                priority = ? 
                WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sississsssi", $eventName, $projectId, $eventType, $organizerId, $startDatetime, $endDatetime, $location, $description, $status, $priority, $eventId);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating event: ' . mysqli_error($conn)]);
        }
    }
}
?>