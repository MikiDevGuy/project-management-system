<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once 'config/database.php';
require_once 'config/functions.php';
require_once 'config/mail_helper.php';

$conn = getDBConnection();
checkAuth();

// Define helper functions if they don't exist
if (!function_exists('hasRole')) {
    function hasRole($role) {
        return isset($_SESSION['system_role']) && $_SESSION['system_role'] === $role;
    }
}

if (!function_exists('hasAnyRole')) {
    function hasAnyRole($roles) {
        if (!isset($_SESSION['system_role'])) return false;
        return in_array($_SESSION['system_role'], (array)$roles);
    }
}

if (!function_exists('logActivity')) {
    function logActivity($conn, $userId, $action, $description, $entityType = null, $entityId = null) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssi", $userId, $action, $description, $entityType, $entityId);
        return mysqli_stmt_execute($stmt);
    }
}

if (!function_exists('sendNotification')) {
    function sendNotification($conn, $userId, $title, $message, $type = 'info', $module = null, $moduleId = null) {
        $sql = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "issssi", $userId, $title, $message, $type, $module, $moduleId);
        return mysqli_stmt_execute($stmt);
    }
}

// Get event ID from query string if specified
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle AJAX requests - MUST BE BEFORE ANY HTML OUTPUT
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    // Clear any output buffering
    ob_clean();
    
    $ajaxAction = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';
    
    // Debug log
    error_log("Tasks AJAX Action: " . $ajaxAction);
    error_log("POST Data: " . print_r($_POST, true));
    error_log("GET Data: " . print_r($_GET, true));
    
    switch ($ajaxAction) {
        case 'add_task':
            handleAddTask($conn);
            break;
        case 'edit_task':
            handleEditTask($conn);
            break;
        case 'delete_task':
            handleDeleteTask($conn);
            break;
        case 'update_status':
            handleUpdateTaskStatus($conn);
            break;
        case 'bulk_update':
            handleBulkUpdateTasks($conn);
            break;
        case 'get_task':
            getTaskDetails($conn);
            break;
        case 'export_tasks':
            exportTasks($conn, $eventId);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $ajaxAction]);
    }
    exit();
}

// Initialize variables
$events = [];
$tasks = [];
$users = [];

// Statistics
$stats = [
    'total' => 0,
    'open' => 0,
    'assigned' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'verified' => 0,
    'closed' => 0,
    'overdue' => 0
];

$error = '';
$success = '';
$message = '';
$messageType = '';

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#8B1E3F',
    'secondary' => '#4A0E21',
    'accent' => '#C49A6C'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;

// Get events for dropdown
try {
    $eventsQuery = "SELECT e.*, 
                    (SELECT COUNT(*) FROM event_tasks WHERE event_id = e.id) as task_count
                    FROM events e 
                    ORDER BY e.start_datetime DESC";
    $eventsResult = mysqli_query($conn, $eventsQuery);
    if ($eventsResult) {
        while ($row = mysqli_fetch_assoc($eventsResult)) {
            $events[] = $row;
        }
    }
} catch(Exception $e) {
    error_log("Error fetching events: " . $e->getMessage());
    $error = "Error loading events data.";
}

// Get users for assignment
try {
    $usersQuery = "SELECT id, username, email FROM users WHERE is_active = 1 ORDER BY username";
    $usersResult = mysqli_query($conn, $usersQuery);
    if ($usersResult) {
        while ($row = mysqli_fetch_assoc($usersResult)) {
            $users[] = $row;
        }
    }
} catch(Exception $e) {
    error_log("Error fetching users: " . $e->getMessage());
}

// Get tasks with all details
try {
    if ($eventId > 0) {
        $tasksQuery = "
            SELECT et.*, e.event_name, e.start_datetime as event_date,
                   u.username as assigned_name, u.email as assigned_email,
                   creator.username as creator_name
            FROM event_tasks et
            JOIN events e ON et.event_id = e.id
            LEFT JOIN users u ON et.assigned_to = u.id
            LEFT JOIN users creator ON et.created_by = creator.id
            WHERE et.event_id = ?
            ORDER BY 
                CASE et.status
                    WHEN 'Open' THEN 1
                    WHEN 'Assigned' THEN 2
                    WHEN 'In Progress' THEN 3
                    WHEN 'Completed' THEN 4
                    WHEN 'Verified' THEN 5
                    WHEN 'Closed' THEN 6
                    ELSE 7
                END,
                CASE et.priority
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 3
                    ELSE 4
                END,
                et.due_date ASC,
                et.created_at DESC
        ";
        $stmt = mysqli_prepare($conn, $tasksQuery);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $tasksQuery = "
            SELECT et.*, e.event_name, e.start_datetime as event_date,
                   u.username as assigned_name, u.email as assigned_email,
                   creator.username as creator_name
            FROM event_tasks et
            JOIN events e ON et.event_id = e.id
            LEFT JOIN users u ON et.assigned_to = u.id
            LEFT JOIN users creator ON et.created_by = creator.id
            ORDER BY 
                CASE et.status
                    WHEN 'Open' THEN 1
                    WHEN 'Assigned' THEN 2
                    WHEN 'In Progress' THEN 3
                    WHEN 'Completed' THEN 4
                    WHEN 'Verified' THEN 5
                    WHEN 'Closed' THEN 6
                    ELSE 7
                END,
                CASE et.priority
                    WHEN 'high' THEN 1
                    WHEN 'medium' THEN 2
                    WHEN 'low' THEN 3
                    ELSE 4
                END,
                et.due_date ASC,
                et.created_at DESC
        ";
        $result = mysqli_query($conn, $tasksQuery);
    }
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Set default values for missing fields
            $row['start_date'] = $row['start_date'] ?? null;
            $row['due_date'] = $row['due_date'] ?? null;
            $row['completed_at'] = $row['completed_at'] ?? null;
            $row['estimated_hours'] = $row['estimated_hours'] ?? null;
            $row['description'] = $row['description'] ?? '';
            
            // Calculate overdue status
            if ($row['due_date'] && $row['status'] != 'Completed' && $row['status'] != 'Verified' && $row['status'] != 'Closed') {
                $dueDate = new DateTime($row['due_date']);
                $today = new DateTime();
                $today->setTime(0, 0, 0);
                $dueDate->setTime(0, 0, 0);
                
                if ($dueDate < $today) {
                    $row['is_overdue'] = true;
                    $row['days_overdue'] = $today->diff($dueDate)->days;
                } else {
                    $row['is_overdue'] = false;
                    $row['days_until_due'] = $today->diff($dueDate)->days;
                }
            } else {
                $row['is_overdue'] = false;
                $row['days_overdue'] = 0;
            }
            
            $tasks[] = $row;
            
            // Update statistics
            $stats['total']++;
            switch($row['status']) {
                case 'Open':
                    $stats['open']++;
                    break;
                case 'Assigned':
                    $stats['assigned']++;
                    break;
                case 'In Progress':
                    $stats['in_progress']++;
                    break;
                case 'Completed':
                    $stats['completed']++;
                    break;
                case 'Verified':
                    $stats['verified']++;
                    break;
                case 'Closed':
                    $stats['closed']++;
                    break;
            }
            
            if (!empty($row['is_overdue']) && $row['is_overdue']) {
                $stats['overdue']++;
            }
        }
    }
} catch(Exception $e) {
    error_log("Error fetching tasks: " . $e->getMessage());
    $error = "Error loading tasks data.";
}

// Get selected event details if event ID is provided
$selectedEvent = null;
if ($eventId > 0) {
    foreach ($events as $event) {
        if ($event['id'] == $eventId) {
            $selectedEvent = $event;
            break;
        }
    }
}

// Get message from URL if redirected
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'success';
}

// Helper Functions for Handlers

function handleAddTask($conn) {
    // Clear output buffer
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $taskName = mysqli_real_escape_string($conn, trim($_POST['task_name'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $startDate = !empty($_POST['start_date']) ? mysqli_real_escape_string($conn, $_POST['start_date']) : null;
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'medium');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Open');
    $estimatedHours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    $createdBy = $_SESSION['user_id'];
    
    // Validation
    $errors = [];
    if (!$eventId) $errors[] = 'Event is required';
    if (empty($taskName)) $errors[] = 'Task name is required';
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        exit();
    }
    
    // Check if task with same name already exists for this event
    $checkSql = "SELECT id FROM event_tasks WHERE event_id = ? AND task_name = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "is", $eventId, $taskName);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'A task with this name already exists for this event']);
        exit();
    }
    
    // Insert new task
    $sql = "INSERT INTO event_tasks (event_id, task_name, description, assigned_to, start_date, due_date, priority, status, estimated_hours, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isssisssdi", $eventId, $taskName, $description, $assignedTo, $startDate, $dueDate, $priority, $status, $estimatedHours, $createdBy);
    
    if (mysqli_stmt_execute($stmt)) {
        $taskId = mysqli_insert_id($conn);
        
        // Get event info for log
        $eventSql = "SELECT event_name FROM events WHERE id = ?";
        $eventStmt = mysqli_prepare($conn, $eventSql);
        mysqli_stmt_bind_param($eventStmt, "i", $eventId);
        mysqli_stmt_execute($eventStmt);
        $eventResult = mysqli_stmt_get_result($eventStmt);
        $event = mysqli_fetch_assoc($eventResult);
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'added', "Added task '{$taskName}' to event '{$event['event_name']}'", 'task', $taskId);
        
        // Send notification to assigned user
        if ($assignedTo) {
            sendNotification($conn, $assignedTo, "New Task Assigned", 
                "You have been assigned a new task: {$taskName} for event: {$event['event_name']}", 
                'info', 'task', $taskId);
        }
        
        echo json_encode(['success' => true, 'message' => 'Task added successfully', 'id' => $taskId]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Error adding task: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleEditTask($conn) {
    // Clear output buffer
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $eventId = intval($_POST['event_id'] ?? 0);
    $taskName = mysqli_real_escape_string($conn, trim($_POST['task_name'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $startDate = !empty($_POST['start_date']) ? mysqli_real_escape_string($conn, $_POST['start_date']) : null;
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'medium');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Open');
    $estimatedHours = !empty($_POST['estimated_hours']) ? floatval($_POST['estimated_hours']) : null;
    
    // Validation
    $errors = [];
    if (!$taskId) $errors[] = 'Invalid task ID';
    if (!$eventId) $errors[] = 'Event is required';
    if (empty($taskName)) $errors[] = 'Task name is required';
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        exit();
    }
    
    // Check if task with same name already exists for this event (excluding current task)
    $checkSql = "SELECT id FROM event_tasks WHERE event_id = ? AND task_name = ? AND id != ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "isi", $eventId, $taskName, $taskId);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'A task with this name already exists for this event']);
        exit();
    }
    
    // Get old status for notification
    $oldSql = "SELECT status, assigned_to, task_name FROM event_tasks WHERE id = ?";
    $oldStmt = mysqli_prepare($conn, $oldSql);
    mysqli_stmt_bind_param($oldStmt, "i", $taskId);
    mysqli_stmt_execute($oldStmt);
    $oldResult = mysqli_stmt_get_result($oldStmt);
    $oldTask = mysqli_fetch_assoc($oldResult);
    
    // Update task
    $sql = "UPDATE event_tasks SET 
                event_id = ?, 
                task_name = ?, 
                description = ?, 
                assigned_to = ?, 
                start_date = ?, 
                due_date = ?, 
                priority = ?, 
                status = ?,
                estimated_hours = ?,
                updated_at = NOW()
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isssisssdi", $eventId, $taskName, $description, $assignedTo, $startDate, $dueDate, $priority, $status, $estimatedHours, $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        // If status changed to Completed, set completed_at
        if ($status == 'Completed' && $oldTask['status'] != 'Completed') {
            $updateCompletedSql = "UPDATE event_tasks SET completed_at = NOW() WHERE id = ?";
            $updateCompletedStmt = mysqli_prepare($conn, $updateCompletedSql);
            mysqli_stmt_bind_param($updateCompletedStmt, "i", $taskId);
            mysqli_stmt_execute($updateCompletedStmt);
        }
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'updated', "Updated task: {$taskName}", 'task', $taskId);
        
        // Send notifications for status change
        if ($oldTask['status'] != $status) {
            // Notify creator if task completed
            if ($status == 'Completed') {
                $creatorSql = "SELECT created_by FROM event_tasks WHERE id = ?";
                $creatorStmt = mysqli_prepare($conn, $creatorSql);
                mysqli_stmt_bind_param($creatorStmt, "i", $taskId);
                mysqli_stmt_execute($creatorStmt);
                $creatorResult = mysqli_stmt_get_result($creatorStmt);
                $creator = mysqli_fetch_assoc($creatorResult);
                
                if ($creator && $creator['created_by'] != $assignedTo) {
                    sendNotification($conn, $creator['created_by'], "Task Completed", 
                        "Task '{$taskName}' has been marked as completed", 
                        'success', 'task', $taskId);
                }
            }
        }
        
        // Notify new assignee
        if ($oldTask['assigned_to'] != $assignedTo && $assignedTo) {
            sendNotification($conn, $assignedTo, "Task Assigned", 
                "You have been assigned to task: {$taskName}", 
                'info', 'task', $taskId);
        }
        
        echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating task: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleDeleteTask($conn) {
    // Clear output buffer
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $taskId = intval($_POST['task_id'] ?? $_GET['id'] ?? 0);
    
    if (!$taskId) {
        echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
        exit();
    }
    
    // Get task info for logging
    $infoSql = "SELECT task_name FROM event_tasks WHERE id = ?";
    $infoStmt = mysqli_prepare($conn, $infoSql);
    mysqli_stmt_bind_param($infoStmt, "i", $taskId);
    mysqli_stmt_execute($infoStmt);
    $infoResult = mysqli_stmt_get_result($infoStmt);
    $task = mysqli_fetch_assoc($infoResult);
    $taskName = $task['task_name'] ?? 'Unknown';
    
    // Delete task
    $sql = "DELETE FROM event_tasks WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], 'deleted', "Deleted task: {$taskName}", 'task', $taskId);
        echo json_encode(['success' => true, 'message' => 'Task deleted successfully']);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting task: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleUpdateTaskStatus($conn) {
    // Clear output buffer
    ob_clean();
    
    $taskId = intval($_POST['task_id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    if (!$taskId || !$status) {
        echo json_encode(['success' => false, 'message' => 'Task ID and status are required']);
        exit();
    }
    
    // Check permission
    $checkSql = "SELECT et.*, e.event_name, e.organizer_id 
                FROM event_tasks et
                JOIN events e ON et.event_id = e.id
                WHERE et.id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $taskId);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);
    $task = mysqli_fetch_assoc($result);
    
    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit();
    }
    
    $canUpdate = hasAnyRole(['super_admin', 'admin', 'pm_manager']) || 
                 $_SESSION['user_id'] == $task['assigned_to'] || 
                 $_SESSION['user_id'] == $task['organizer_id'] ||
                 $_SESSION['user_id'] == $task['created_by'];
    
    if (!$canUpdate) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $oldStatus = $task['status'];
    
    $sql = "UPDATE event_tasks SET status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $status, $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        // If status changed to Completed, set completed_at
        if ($status == 'Completed' && $oldStatus != 'Completed') {
            $updateCompletedSql = "UPDATE event_tasks SET completed_at = NOW() WHERE id = ?";
            $updateCompletedStmt = mysqli_prepare($conn, $updateCompletedSql);
            mysqli_stmt_bind_param($updateCompletedStmt, "i", $taskId);
            mysqli_stmt_execute($updateCompletedStmt);
        }
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'status_updated', 
            "Task '{$task['task_name']}' status changed from {$oldStatus} to {$status}", 
            'task', $taskId);
        
        // Notify creator if task completed
        if ($status == 'Completed' && $task['created_by'] != $_SESSION['user_id']) {
            sendNotification($conn, $task['created_by'], "Task Completed", 
                "Task '{$task['task_name']}' has been marked as completed", 
                'success', 'task', $taskId);
        }
        
        echo json_encode(['success' => true, 'message' => 'Task status updated successfully']);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating status: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleBulkUpdateTasks($conn) {
    // Clear output buffer
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $taskIds = $_POST['task_ids'] ?? [];
    $action = $_POST['bulk_action'] ?? '';
    
    if (empty($taskIds) || empty($action)) {
        echo json_encode(['success' => false, 'message' => 'Task IDs and action are required']);
        exit();
    }
    
    $ids = implode(',', array_map('intval', $taskIds));
    $success = false;
    $message = '';
    
    switch($action) {
        case 'open':
            $sql = "UPDATE event_tasks SET status = 'Open' WHERE id IN ($ids)";
            $message = 'Tasks marked as Open';
            break;
        case 'assigned':
            $sql = "UPDATE event_tasks SET status = 'Assigned' WHERE id IN ($ids)";
            $message = 'Tasks marked as Assigned';
            break;
        case 'in_progress':
            $sql = "UPDATE event_tasks SET status = 'In Progress' WHERE id IN ($ids)";
            $message = 'Tasks marked as In Progress';
            break;
        case 'completed':
            $sql = "UPDATE event_tasks SET status = 'Completed', completed_at = NOW() WHERE id IN ($ids)";
            $message = 'Tasks marked as Completed';
            break;
        case 'verified':
            $sql = "UPDATE event_tasks SET status = 'Verified' WHERE id IN ($ids)";
            $message = 'Tasks marked as Verified';
            break;
        case 'closed':
            $sql = "UPDATE event_tasks SET status = 'Closed' WHERE id IN ($ids)";
            $message = 'Tasks marked as Closed';
            break;
        case 'delete':
            $sql = "DELETE FROM event_tasks WHERE id IN ($ids)";
            $message = 'Tasks deleted';
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
    }
    
    if (mysqli_query($conn, $sql)) {
        $count = mysqli_affected_rows($conn);
        logActivity($conn, $_SESSION['user_id'], 'bulk_update', "Performed '{$action}' on {$count} tasks", 'task', 0);
        echo json_encode(['success' => true, 'message' => "{$count} {$message}"]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Error performing bulk action: ' . mysqli_error($conn)]);
        exit();
    }
}

// FIXED: getTaskDetails function - improved error handling and debugging
function getTaskDetails($conn) {
    // Clear output buffer
    ob_clean();
    
    // Check both GET and POST for ID
    $taskId = intval($_GET['id'] ?? $_POST['id'] ?? 0);
    
    error_log("getTaskDetails called with ID: " . $taskId);
    
    if (!$taskId) {
        echo json_encode(['success' => false, 'message' => 'Task ID is required']);
        exit();
    }
    
    // First, check if task exists
    $checkSql = "SELECT id FROM event_tasks WHERE id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $taskId);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) == 0) {
        echo json_encode(['success' => false, 'message' => 'Task not found with ID: ' . $taskId]);
        exit();
    }
    
    $sql = "SELECT et.*, e.event_name, e.start_datetime as event_date,
                   u.username as assigned_name, u.email as assigned_email,
                   creator.username as creator_name
            FROM event_tasks et
            JOIN events e ON et.event_id = e.id
            LEFT JOIN users u ON et.assigned_to = u.id
            LEFT JOIN users creator ON et.created_by = creator.id
            WHERE et.id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        error_log("Database error in getTaskDetails: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    $task = mysqli_fetch_assoc($result);
    
    if ($task) {
        // Set default values for missing fields
        $task['start_date'] = $task['start_date'] ?? null;
        $task['due_date'] = $task['due_date'] ?? null;
        $task['completed_at'] = $task['completed_at'] ?? null;
        $task['estimated_hours'] = $task['estimated_hours'] ?? null;
        $task['description'] = $task['description'] ?? '';
        
        error_log("Task found: " . print_r($task, true));
        echo json_encode(['success' => true, 'data' => $task]);
        exit();
    } else {
        error_log("Task not found after query: " . $taskId);
        echo json_encode(['success' => false, 'message' => 'Task data could not be retrieved for ID: ' . $taskId]);
        exit();
    }
}

function exportTasks($conn, $eventId) {
    $format = $_GET['format'] ?? 'csv';
    
    if ($eventId > 0) {
        $sql = "SELECT 
                    et.task_name as 'Task Name',
                    et.description as 'Description',
                    e.event_name as 'Event',
                    u.username as 'Assigned To',
                    u.email as 'Assignee Email',
                    et.start_date as 'Start Date',
                    et.due_date as 'Due Date',
                    et.priority as 'Priority',
                    et.status as 'Status',
                    creator.username as 'Created By',
                    et.created_at as 'Created Date',
                    et.completed_at as 'Completed Date',
                    et.estimated_hours as 'Estimated Hours'
                FROM event_tasks et
                JOIN events e ON et.event_id = e.id
                LEFT JOIN users u ON et.assigned_to = u.id
                LEFT JOIN users creator ON et.created_by = creator.id
                WHERE et.event_id = ?
                ORDER BY et.created_at DESC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $sql = "SELECT 
                    et.task_name as 'Task Name',
                    et.description as 'Description',
                    e.event_name as 'Event',
                    u.username as 'Assigned To',
                    u.email as 'Assignee Email',
                    et.start_date as 'Start Date',
                    et.due_date as 'Due Date',
                    et.priority as 'Priority',
                    et.status as 'Status',
                    creator.username as 'Created By',
                    et.created_at as 'Created Date',
                    et.completed_at as 'Completed Date',
                    et.estimated_hours as 'Estimated Hours'
                FROM event_tasks et
                JOIN events e ON et.event_id = e.id
                LEFT JOIN users u ON et.assigned_to = u.id
                LEFT JOIN users creator ON et.created_by = creator.id
                ORDER BY et.created_at DESC";
        $result = mysqli_query($conn, $sql);
    }
    
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="tasks_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        $first = true;
        
        while ($row = mysqli_fetch_assoc($result)) {
            if ($first) {
                fputcsv($output, array_keys($row));
                $first = false;
            }
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    } elseif ($format == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="tasks_export_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr>";
        $first = true;
        $row = mysqli_fetch_assoc($result);
        if ($row) {
            foreach (array_keys($row) as $header) {
                echo "<th>" . htmlspecialchars($header) . "</th>";
            }
            echo "</tr>";
            
            // Output first row
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
            
            // Output remaining rows
            while ($row = mysqli_fetch_assoc($result)) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
                }
                echo "</tr>";
            }
        }
        echo "</table>";
        exit();
    }
}

// Helper Functions for UI
function getTaskStatusBadge($status) {
    switch ($status) {
        case 'Open': return 'bg-secondary';
        case 'Assigned': return 'bg-info';
        case 'In Progress': return 'bg-primary';
        case 'Completed': return 'bg-success';
        case 'Verified': return 'bg-success';
        case 'Closed': return 'bg-dark';
        default: return 'bg-secondary';
    }
}

function getPriorityBadge($priority) {
    switch ($priority) {
        case 'high': return 'bg-danger';
        case 'medium': return 'bg-warning';
        case 'low': return 'bg-success';
        default: return 'bg-secondary';
    }
}

function getPriorityIcon($priority) {
    switch ($priority) {
        case 'high': return 'fa-arrow-up';
        case 'medium': return 'fa-minus';
        case 'low': return 'fa-arrow-down';
        default: return 'fa-circle';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management - Dashen Bank BSPM</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --dashen-primary: <?php echo $custom_colors['primary']; ?>;
            --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
            --dashen-accent: <?php echo $custom_colors['accent']; ?>;
            --dashen-success: #28a745;
            --dashen-danger: #dc3545;
            --dashen-warning: #ffc107;
            --dashen-info: #17a2b8;
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --header-height: 80px;
            --border-radius: 16px;
        }

        /* Dark Mode Variables */
        body.dark-mode {
            background: #1a1a1a;
            color: #e0e0e0;
        }

        body.dark-mode .card,
        body.dark-mode .stat-card,
        body.dark-mode .table tbody tr,
        body.dark-mode .modal-content {
            background: #2d2d2d;
            border-color: #404040;
            color: #e0e0e0;
        }

        body.dark-mode .text-muted {
            color: #b0b0b0 !important;
        }

        body.dark-mode .table thead th {
            background: #333333;
            color: #b0b0b0;
        }

        body.dark-mode .table tbody tr:hover {
            background: #333333;
        }

        body.dark-mode .btn-action {
            background: #333333;
            color: #b0b0b0;
        }

        body.dark-mode .btn-action:hover {
            background: var(--dashen-primary);
            color: white;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f5f7;
            color: #333;
            overflow-x: hidden;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - var(--sidebar-width));
        }

        .main-content.expanded {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }

        .content-wrapper {
            padding: 30px;
            margin-top: var(--header-height);
            background: #f5f5f7;
        }

        body.dark-mode .content-wrapper {
            background: #1a1a1a;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 4px;
        }

        .page-header p {
            color: #5f6368;
            margin: 0;
        }

        body.dark-mode .page-header p {
            color: #b0b0b0;
        }

        /* Cards */
        .card {
            background: white;
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid #e8eaed;
            margin-bottom: 30px;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            padding: 20px 24px;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .card-body {
            padding: 24px;
        }

        /* Event Info Card */
        .event-info-card {
            background: linear-gradient(135deg, rgba(139, 30, 63, 0.05) 0%, rgba(74, 14, 33, 0.05) 100%);
            border-left: 4px solid var(--dashen-primary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e8eaed;
            border-left: 4px solid var(--dashen-primary);
            transition: var(--transition);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #5f6368;
            font-weight: 500;
        }

        body.dark-mode .stat-value {
            color: #e0e0e0;
        }

        body.dark-mode .stat-label {
            color: #b0b0b0;
        }

        /* Buttons */
        .btn-dashen {
            background: var(--dashen-primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-dashen:hover {
            background: var(--dashen-secondary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
        }

        .btn-outline-dashen {
            background: transparent;
            color: var(--dashen-primary);
            border: 2px solid var(--dashen-primary);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-outline-dashen:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: #f8f9fa;
            color: #5f6368;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-action:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-action.delete:hover { background: var(--dashen-danger); }
        .btn-action.success:hover { background: var(--dashen-success); }
        .btn-action.warning:hover { background: var(--dashen-warning); }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-primary { background: rgba(139, 30, 63, 0.1); color: var(--dashen-primary); }
        .badge-success { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        .badge-warning { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .badge-danger { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .badge-info { background: rgba(23, 162, 184, 0.1); color: #17a2b8; }
        .badge-secondary { background: rgba(108, 117, 125, 0.1); color: #6c757d; }
        .badge-dark { background: rgba(52, 58, 64, 0.1); color: #343a40; }

        body.dark-mode .badge-dark { background: rgba(52, 58, 64, 0.3); color: #e0e0e0; }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead th {
            background: #f8f9fa;
            color: #5f6368;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            padding: 16px;
            border: none;
        }

        .table tbody tr {
            transition: var(--transition);
            border-bottom: 1px solid #e8eaed;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table td {
            padding: 16px;
            vertical-align: middle;
        }

        body.dark-mode .table thead th {
            background: #333333;
            color: #b0b0b0;
        }

        body.dark-mode .table tbody tr:hover {
            background: #333333;
        }

        /* Task priority indicators */
        .task-priority-high {
            border-left: 4px solid #dc3545 !important;
        }

        .task-priority-medium {
            border-left: 4px solid #ffc107 !important;
        }

        .task-priority-low {
            border-left: 4px solid #28a745 !important;
        }

        .task-overdue {
            background-color: rgba(220, 53, 69, 0.05) !important;
        }

        body.dark-mode .task-overdue {
            background-color: rgba(220, 53, 69, 0.15) !important;
        }

        .task-completed {
            background-color: rgba(40, 167, 69, 0.05) !important;
            opacity: 0.8;
        }

        body.dark-mode .task-completed {
            background-color: rgba(40, 167, 69, 0.15) !important;
        }

        .task-completed .task-name {
            text-decoration: line-through;
            color: #6c757d;
        }

        /* Avatar */
        .avatar-circle-small {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success { background: rgba(40, 167, 69, 0.1); color: #28a745; border: 1px solid #28a745; }
        .alert-danger { background: rgba(220, 53, 69, 0.1); color: #dc3545; border: 1px solid #dc3545; }
        .alert-warning { background: rgba(255, 193, 7, 0.1); color: #ffc107; border: 1px solid #ffc107; }
        .alert-info { background: rgba(23, 162, 184, 0.1); color: #17a2b8; border: 1px solid #17a2b8; }

        /* Modal */
        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 20px 24px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e8eaed;
        }

        body.dark-mode .modal-footer {
            border-top-color: #404040;
        }

        /* Form Elements */
        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
        }

        body.dark-mode .form-label {
            color: #e0e0e0;
        }

        .form-control, .form-select {
            border: 1px solid #dadce0;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(139, 30, 63, 0.1);
            outline: none;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #333333;
            border-color: #404040;
            color: #e0e0e0;
        }

        .form-text {
            color: #6c757d;
            font-size: 0.8rem;
        }

        body.dark-mode .form-text {
            color: #b0b0b0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            opacity: 0.5;
            color: var(--dashen-primary);
            font-size: 4rem;
        }

        .empty-state h4 {
            margin: 15px 0 10px;
            font-weight: 600;
        }

        /* Bulk Actions */
        .bulk-actions {
            position: sticky;
            bottom: 20px;
            z-index: 100;
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .content-wrapper {
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="<?php echo $dark_mode ? 'dark-mode' : ''; ?>">
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content <?php echo isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'] ? 'expanded' : ''; ?>" id="mainContent">
        <!-- Header -->
        <?php include 'includes/header.php'; ?>
        
        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Task Management</h1>
                    <p>Manage and track event-related tasks</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($eventId > 0): ?>
                    <a href="event_details.php?id=<?php echo $eventId; ?>" class="btn-outline-dashen">
                        <i class="fas fa-arrow-left"></i> Back to Event
                    </a>
                    <?php endif; ?>
                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                    <button class="btn-dashen" onclick="openAddTaskModal()">
                        <i class="fas fa-plus"></i> Add Task
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $messageType == 'success' ? 'check-circle' : ($messageType == 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Event Info (if selected) -->
            <?php if ($selectedEvent): ?>
            <div class="event-info-card">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5 class="mb-2" style="color: var(--dashen-primary);"><?php echo htmlspecialchars($selectedEvent['event_name']); ?></h5>
                        <p class="mb-0">
                            <i class="fas fa-calendar me-2"></i>
                            <?php echo date('F j, Y g:i A', strtotime($selectedEvent['start_datetime'])); ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge badge-info">
                            <i class="fas fa-tasks me-1"></i> <?php echo $selectedEvent['task_count'] ?? 0; ?> Tasks
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Task Statistics -->
            <div class="stats-grid">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="stat-icon" style="background: rgba(139, 30, 63, 0.1); color: var(--dashen-primary);">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Tasks</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #6c757d;" onclick="filterByStatus('Open')">
                    <div class="stat-icon" style="background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['open']; ?></div>
                    <div class="stat-label">Open</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #17a2b8;" onclick="filterByStatus('Assigned')">
                    <div class="stat-icon" style="background: rgba(23, 162, 184, 0.1); color: #17a2b8;">
                        <i class="fas fa-user-tag"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['assigned']; ?></div>
                    <div class="stat-label">Assigned</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #0d6efd;" onclick="filterByStatus('In Progress')">
                    <div class="stat-icon" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #28a745;" onclick="filterByStatus('Completed')">
                    <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #dc3545;" onclick="filterByStatus('overdue')">
                    <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['overdue']; ?></div>
                    <div class="stat-label">Overdue</div>
                </div>
            </div>
            
            <!-- Filters Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Tasks</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">Event</label>
                                <select class="form-select" id="eventFilter" name="event_id">
                                    <option value="">All Events</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?php echo $event['id']; ?>" 
                                            <?php echo ($eventId == $event['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($event['event_name']); ?>
                                        (<?php echo $event['task_count'] ?? 0; ?> tasks)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="statusFilter" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="Open">Open</option>
                                    <option value="Assigned">Assigned</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Verified">Verified</option>
                                    <option value="Closed">Closed</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Priority</label>
                                <select class="form-select" id="priorityFilter" name="priority">
                                    <option value="">All</option>
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-flex gap-2 w-100">
                                    <button type="submit" class="btn-dashen flex-grow-1">
                                        <i class="fas fa-search"></i> Filter
                                    </button>
                                    <a href="tasks.php<?php echo $eventId ? '?event_id=' . $eventId : ''; ?>" class="btn-outline-dashen">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tasks Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        <?php echo $eventId ? 'Event Tasks' : 'All Tasks'; ?>
                        <span class="badge bg-primary ms-2"><?php echo count($tasks); ?> tasks</span>
                    </h5>
                    <div class="d-flex gap-2">
                        <?php if (!empty($tasks)): ?>
                        <button class="btn-outline-dashen btn-sm" onclick="exportTasks('csv')">
                            <i class="fas fa-file-csv"></i> CSV
                        </button>
                        <button class="btn-outline-dashen btn-sm" onclick="exportTasks('excel')">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                        <button class="btn-outline-dashen btn-sm" id="bulkActionsBtn" style="display: none;">
                            <i class="fas fa-tasks"></i> Bulk Actions
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($tasks)): ?>
                    <div class="table-responsive">
                        <table class="table" id="tasksTable">
                            <thead>
                                <tr>
                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                    <th width="40px">
                                        <input type="checkbox" class="form-check-input" id="selectAll">
                                    </th>
                                    <?php endif; ?>
                                    <th>ID</th>
                                    <th>Task Details</th>
                                    <?php if (!$eventId): ?>
                                    <th>Event</th>
                                    <?php endif; ?>
                                    <th>Assigned To</th>
                                    <th>Due Date</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $index => $task): 
                                    $priorityClass = 'task-priority-' . ($task['priority'] ?? 'medium');
                                    $rowClass = '';
                                    if (!empty($task['is_overdue']) && $task['is_overdue']) $rowClass = 'task-overdue';
                                    if ($task['status'] == 'Completed' || $task['status'] == 'Verified' || $task['status'] == 'Closed') $rowClass = 'task-completed';
                                ?>
                                <tr class="<?php echo $rowClass; ?> <?php echo $priorityClass; ?>" data-status="<?php echo $task['status']; ?>" data-priority="<?php echo $task['priority'] ?? 'medium'; ?>">
                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                    <td>
                                        <input type="checkbox" class="form-check-input task-select" value="<?php echo $task['id']; ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td><code>#<?php echo $task['id']; ?></code></td>
                                    <td>
                                        <div class="task-name">
                                            <strong><?php echo htmlspecialchars($task['task_name']); ?></strong>
                                            <?php if (!empty($task['is_overdue']) && $task['is_overdue']): ?>
                                            <span class="badge bg-danger ms-2">Overdue <?php echo $task['days_overdue'] ?? 0; ?>d</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($task['description'])): ?>
                                        <div class="text-muted small mt-1">
                                            <?php echo htmlspecialchars(substr($task['description'], 0, 100)); ?>
                                            <?php if (strlen($task['description']) > 100): ?>...<?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="small text-muted mt-1">
                                            <i class="far fa-calendar-plus me-1"></i>
                                            Created: <?php echo date('M j, Y', strtotime($task['created_at'])); ?>
                                            <?php if (!empty($task['creator_name'])): ?> by <?php echo htmlspecialchars($task['creator_name']); ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <?php if (!$eventId): ?>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($task['event_name']); ?>
                                        </span>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if (!empty($task['assigned_name'])): ?>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle-small me-2">
                                                <?php echo strtoupper(substr($task['assigned_name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <?php echo htmlspecialchars($task['assigned_name']); ?>
                                                <?php if (!empty($task['assigned_email'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($task['assigned_email']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($task['due_date'])): ?>
                                        <div>
                                            <?php echo date('M j, Y', strtotime($task['due_date'])); ?>
                                            <?php if (!empty($task['start_date'])): ?>
                                            <br><small class="text-muted">Start: <?php echo date('M j', strtotime($task['start_date'])); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getPriorityBadge($task['priority'] ?? 'medium'); ?>">
                                            <i class="fas <?php echo getPriorityIcon($task['priority'] ?? 'medium'); ?> me-1"></i>
                                            <?php echo ucfirst($task['priority'] ?? 'medium'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager']) || (!empty($task['assigned_to']) && $_SESSION['user_id'] == $task['assigned_to'])): ?>
                                        <select class="form-select form-select-sm status-select" 
                                                data-id="<?php echo $task['id']; ?>"
                                                onchange="updateTaskStatus(this)">
                                            <option value="Open" <?php echo ($task['status'] == 'Open') ? 'selected' : ''; ?>>Open</option>
                                            <option value="Assigned" <?php echo ($task['status'] == 'Assigned') ? 'selected' : ''; ?>>Assigned</option>
                                            <option value="In Progress" <?php echo ($task['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="Completed" <?php echo ($task['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                            <option value="Verified" <?php echo ($task['status'] == 'Verified') ? 'selected' : ''; ?>>Verified</option>
                                            <option value="Closed" <?php echo ($task['status'] == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge <?php echo getTaskStatusBadge($task['status']); ?>">
                                            <?php echo $task['status']; ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if (!empty($task['completed_at'])): ?>
                                        <div class="text-success small mt-1">
                                            <i class="fas fa-check me-1"></i>
                                            Completed: <?php echo date('M j', strtotime($task['completed_at'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action" onclick="viewTask(<?php echo $task['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager']) || (!empty($task['created_by']) && $_SESSION['user_id'] == $task['created_by'])): ?>
                                            <button class="btn-action" onclick="editTask(<?php echo $task['id']; ?>)" title="Edit Task">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-action delete" onclick="deleteTask(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['task_name'])); ?>')" title="Delete Task">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Bulk Actions -->
                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="fw-bold" id="selectedCount">0</span>
                                    <span>selected</span>
                                    <select class="form-select form-select-sm" style="width: 150px;" id="bulkAction">
                                        <option value="">Select Action</option>
                                        <option value="open">Mark as Open</option>
                                        <option value="assigned">Mark as Assigned</option>
                                        <option value="in_progress">Mark as In Progress</option>
                                        <option value="completed">Mark as Completed</option>
                                        <option value="verified">Mark as Verified</option>
                                        <option value="closed">Mark as Closed</option>
                                        <option value="delete">Delete Selected</option>
                                    </select>
                                    <button class="btn-dashen btn-sm" onclick="executeBulkAction()">
                                        Apply
                                    </button>
                                    <button class="btn-outline-dashen btn-sm" onclick="clearSelection()">
                                        Clear
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-tasks"></i>
                        <h4>No tasks found</h4>
                        <p class="text-muted">
                            <?php echo $eventId ? 'No tasks created for this event yet.' : 'No tasks found in the system.'; ?>
                        </p>
                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                        <button class="btn-dashen" onclick="openAddTaskModal()">
                            <i class="fas fa-plus"></i> Create Your First Task
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Add/Edit Task Modal -->
    <div class="modal fade" id="taskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="taskModalTitle">Add New Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="taskForm">
                    <input type="hidden" name="ajax_action" id="ajaxAction" value="add_task">
                    <input type="hidden" name="task_id" id="taskId" value="">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Event *</label>
                                <select class="form-select" name="event_id" id="modalEventId" required>
                                    <option value="">Select Event</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?php echo $event['id']; ?>" 
                                            <?php echo ($eventId == $event['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($event['event_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Task Name *</label>
                                <input type="text" class="form-control" name="task_name" id="taskName" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="taskDescription" rows="3" 
                                      placeholder="Describe the task details, requirements, and any additional information..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned To</label>
                                <select class="form-select" name="assigned_to" id="taskAssignedTo">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority" id="taskPriority">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" id="taskStartDate" 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="form-text">When work should begin</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" id="taskDueDate" 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="form-text">Deadline for completion</div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="taskStatus">
                                    <option value="Open">Open</option>
                                    <option value="Assigned">Assigned</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Verified">Verified</option>
                                    <option value="Closed">Closed</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Estimated Hours</label>
                                <input type="number" class="form-control" name="estimated_hours" id="taskEstimatedHours" 
                                       min="0" step="0.5" placeholder="e.g., 4.5">
                                <div class="form-text">Estimated time to complete (optional)</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-dashen" id="saveTaskBtn">
                            <i class="fas fa-save me-2"></i> Save Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Task Modal -->
    <div class="modal fade" id="viewTaskModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Task Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewTaskContent">
                    <!-- Content loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        if ($('#tasksTable').length) {
            $('#tasksTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    emptyTable: "No tasks found",
                    info: "Showing _START_ to _END_ of _TOTAL_ tasks",
                    infoEmpty: "Showing 0 to 0 of 0 tasks",
                    infoFiltered: "(filtered from _MAX_ total tasks)",
                    lengthMenu: "Show _MENU_ tasks",
                    search: "Search:"
                }
            });
        }
        
        // Select All checkbox
        $('#selectAll').change(function() {
            $('.task-select').prop('checked', $(this).prop('checked'));
            updateBulkActions();
        });
        
        // Individual checkbox change
        $(document).on('change', '.task-select', function() {
            updateBulkActions();
            const allChecked = $('.task-select:checked').length === $('.task-select').length;
            $('#selectAll').prop('checked', allChecked);
        });
        
        // Update bulk actions visibility
        function updateBulkActions() {
            const selectedCount = $('.task-select:checked').length;
            if (selectedCount > 0) {
                $('#bulkActions').show();
                $('#selectedCount').text(selectedCount);
                $('#bulkActionsBtn').show();
            } else {
                $('#bulkActions').hide();
                $('#bulkActionsBtn').hide();
            }
        }
        
        // Clear selection
        window.clearSelection = function() {
            $('.task-select').prop('checked', false);
            $('#selectAll').prop('checked', false);
            $('#bulkActions').hide();
        };
        
        // Execute bulk action
        window.executeBulkAction = function() {
            const action = $('#bulkAction').val();
            if (!action) {
                Swal.fire('Warning', 'Please select an action', 'warning');
                return;
            }
            
            const selectedIds = [];
            $('.task-select:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                Swal.fire('Warning', 'No tasks selected', 'warning');
                return;
            }
            
            if (action === 'delete') {
                Swal.fire({
                    title: 'Delete Tasks?',
                    html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> tasks?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Yes, delete them!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                ajax_action: 'bulk_update',
                                task_ids: selectedIds,
                                bulk_action: action
                            },
                            dataType: 'json',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            success: function(response) {
                                if (response.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Success',
                                        text: response.message,
                                        timer: 1500,
                                        showConfirmButton: false
                                    }).then(() => {
                                        location.reload();
                                    });
                                } else {
                                    Swal.fire('Error', response.message, 'error');
                                }
                            },
                            error: function(xhr, status, error) {
                                Swal.fire('Error', 'Failed to execute bulk action: ' + error, 'error');
                            }
                        });
                    }
                });
            } else {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        ajax_action: 'bulk_update',
                        task_ids: selectedIds,
                        bulk_action: action
                    },
                    dataType: 'json',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Updated',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', response.message, 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire('Error', 'Failed to execute bulk action: ' + error, 'error');
                    }
                });
            }
        };
        
        // Open Add Task Modal
        window.openAddTaskModal = function() {
            $('#ajaxAction').val('add_task');
            $('#taskId').val('');
            $('#taskModalTitle').text('Add New Task');
            $('#taskForm')[0].reset();
            $('#modalEventId').val('<?php echo $eventId; ?>');
            $('#taskStatus').val('Open');
            $('#taskPriority').val('medium');
            $('#taskModal').modal('show');
        };
        
        // Task Form Submit
        $('#taskForm').submit(function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = $('#saveTaskBtn');
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Saving...').prop('disabled', true);
            
            const formData = $(this).serialize();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        $('#taskModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        submitBtn.html(originalText).prop('disabled', false);
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    submitBtn.html(originalText).prop('disabled', false);
                    Swal.fire('Error', 'Failed to save task: ' + error, 'error');
                }
            });
        });
        
        // Edit Task
        window.editTask = function(taskId) {
            // Show loading
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: window.location.href + '&ajax_action=get_task&id=' + taskId,
                type: 'GET',
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#ajaxAction').val('edit_task');
                        $('#taskId').val(response.data.id);
                        $('#modalEventId').val(response.data.event_id);
                        $('#taskName').val(response.data.task_name);
                        $('#taskDescription').val(response.data.description || '');
                        $('#taskAssignedTo').val(response.data.assigned_to || '');
                        $('#taskStartDate').val(response.data.start_date || '');
                        $('#taskDueDate').val(response.data.due_date || '');
                        $('#taskPriority').val(response.data.priority || 'medium');
                        $('#taskStatus').val(response.data.status || 'Open');
                        $('#taskEstimatedHours').val(response.data.estimated_hours || '');
                        
                        $('#taskModalTitle').text('Edit Task');
                        $('#taskModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load task details: ' + error, 'error');
                }
            });
        };
        
        // View Task
        window.viewTask = function(taskId) {
            // Show loading
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: window.location.href + '&ajax_action=get_task&id=' + taskId,
                type: 'GET',
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        const task = response.data;
                        
                        const statusClass = task.status === 'Open' ? 'bg-secondary' : 
                                          task.status === 'Assigned' ? 'bg-info' :
                                          task.status === 'In Progress' ? 'bg-primary' :
                                          task.status === 'Completed' ? 'bg-success' :
                                          task.status === 'Verified' ? 'bg-success' :
                                          task.status === 'Closed' ? 'bg-dark' : 'bg-secondary';
                        
                        const priorityClass = task.priority === 'high' ? 'bg-danger' :
                                            task.priority === 'medium' ? 'bg-warning' :
                                            task.priority === 'low' ? 'bg-success' : 'bg-secondary';
                        
                        const html = `
                            <h5>${escapeHtml(task.task_name)}</h5>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Event:</strong> ${escapeHtml(task.event_name)}</p>
                                    <p><strong>Event Date:</strong> ${task.event_date ? new Date(task.event_date).toLocaleDateString() : 'N/A'}</p>
                                    <p><strong>Assigned To:</strong> ${task.assigned_name ? escapeHtml(task.assigned_name) + (task.assigned_email ? ' (' + escapeHtml(task.assigned_email) + ')' : '') : 'Unassigned'}</p>
                                    <p><strong>Created By:</strong> ${task.creator_name ? escapeHtml(task.creator_name) : 'System'}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Status:</strong> <span class="badge ${statusClass}">${task.status}</span></p>
                                    <p><strong>Priority:</strong> <span class="badge ${priorityClass}">${task.priority}</span></p>
                                    <p><strong>Start Date:</strong> ${task.start_date ? new Date(task.start_date).toLocaleDateString() : 'Not set'}</p>
                                    <p><strong>Due Date:</strong> ${task.due_date ? new Date(task.due_date).toLocaleDateString() : 'Not set'}</p>
                                    <p><strong>Estimated Hours:</strong> ${task.estimated_hours ? task.estimated_hours : 'Not set'}</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <h6>Description</h6>
                                <p>${task.description ? escapeHtml(task.description) : 'No description provided'}</p>
                            </div>
                        `;
                        
                        $('#viewTaskContent').html(html);
                        $('#viewTaskModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load task details: ' + error, 'error');
                }
            });
        };
        
        // Delete Task
        window.deleteTask = function(taskId, taskName) {
            Swal.fire({
                title: 'Delete Task?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(taskName)}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'delete_task',
                            task_id: taskId
                        },
                        dataType: 'json',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted',
                                    text: response.message,
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire('Error', 'Failed to delete task: ' + error, 'error');
                        }
                    });
                }
            });
        };
        
        // Update Task Status
        window.updateTaskStatus = function(select) {
            const taskId = $(select).data('id');
            const status = $(select).val();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'update_status',
                    task_id: taskId,
                    status: status
                },
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Updated',
                            text: response.message,
                            timer: 1000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                        // Reset to previous value
                        location.reload();
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'Failed to update status: ' + error, 'error');
                    location.reload();
                }
            });
        };
        
        // Filter by status
        window.filterByStatus = function(status) {
            if (status === 'overdue') {
                // Could implement custom filtering for overdue tasks
                // For now, just reload with no filter
                $('#statusFilter').val('');
                $('#filterForm').submit();
            } else if (status === 'all') {
                $('#statusFilter').val('');
                $('#filterForm').submit();
            } else {
                $('#statusFilter').val(status);
                $('#filterForm').submit();
            }
        };
        
        // Export tasks
        window.exportTasks = function(format) {
            const eventId = '<?php echo $eventId; ?>';
            window.location.href = `tasks.php?ajax_action=export_tasks&event_id=${eventId}&format=${format}`;
        };
        
        // Reset modal on close
        $('#taskModal').on('hidden.bs.modal', function() {
            if ($('#ajaxAction').val() !== 'edit_task') {
                $('#taskForm')[0].reset();
            }
        });
        
        // Helper functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                const mainContent = document.getElementById('mainContent');
                const sidebar = document.getElementById('sidebar');
                setTimeout(() => {
                    if (sidebar.classList.contains('collapsed')) {
                        mainContent.classList.add('expanded');
                    } else {
                        mainContent.classList.remove('expanded');
                    }
                }, 50);
            });
        }
        
        // Keyboard shortcuts
        $(document).keydown(function(e) {
            // Ctrl+T to open add task modal
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                openAddTaskModal();
            }
            // Escape to close modals
            if (e.key === 'Escape') {
                $('.modal').modal('hide');
            }
        });
    });
    </script>
</body>
</html>