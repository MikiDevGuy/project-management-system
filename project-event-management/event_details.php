<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
require_once 'config/database.php';
require_once 'config/functions.php';

$conn = getDBConnection();
checkAuth();

// Define helper functions if they don't exist in functions.php
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
    function logActivity($conn, $userId, $action, $description, $entityType = null, $entityId = null, $oldValue = null, $newValue = null) {
        $sql = "INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, old_value, new_value, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssiss", $userId, $action, $description, $entityType, $entityId, $oldValue, $newValue);
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

// Create event_attendance table if it doesn't exist
$createTableSQL = "CREATE TABLE IF NOT EXISTS `event_attendance` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `event_id` int(11) NOT NULL,
    `attendee_id` int(11) NOT NULL,
    `day_number` int(11) NOT NULL,
    `attendance_status` enum('present','absent','late','excused','not_recorded') DEFAULT 'not_recorded',
    `check_in_time` datetime DEFAULT NULL,
    `check_out_time` datetime DEFAULT NULL,
    `recorded_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_daily_attendance` (`event_id`, `attendee_id`, `day_number`),
    KEY `event_id` (`event_id`),
    KEY `attendee_id` (`attendee_id`),
    KEY `recorded_by` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

mysqli_query($conn, $createTableSQL);

if (!isset($_GET['id'])) {
    header('Location: events.php');
    exit();
}

$eventId = intval($_GET['id']);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_attendee':
            handleAddAttendee($conn, $eventId);
            break;
        case 'edit_attendee':
            handleEditAttendee($conn);
            break;
        case 'delete_attendee':
            handleDeleteAttendee($conn);
            break;
        case 'add_task':
            handleAddTask($conn, $eventId);
            break;
        case 'edit_task':
            handleEditTask($conn);
            break;
        case 'delete_task':
            handleDeleteTask($conn);
            break;
        case 'update_task_status':
            handleUpdateTaskStatus($conn);
            break;
        case 'add_resource':
            handleAddResource($conn, $eventId);
            break;
        case 'edit_resource':
            handleEditResource($conn);
            break;
        case 'delete_resource':
            handleDeleteResource($conn);
            break;
        case 'update_resource_status':
            handleUpdateResourceStatus($conn);
            break;
        case 'record_attendance':
            handleRecordAttendance($conn, $eventId);
            break;
        case 'update_event_status':
            handleUpdateEventStatus($conn, $eventId);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Handle GET requests for fetching data
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    $ajaxAction = $_GET['ajax'] ?? '';
    
    switch ($ajaxAction) {
        case 'get_attendee':
            getAttendee($conn);
            break;
        case 'get_task':
            getTask($conn);
            break;
        case 'get_resource':
            getResource($conn);
            break;
        case 'get_attendance':
            getAttendance($conn, $eventId);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Get event details
$sql = "SELECT e.*, p.name as project_name, u.username as organizer_name,
        u.email as organizer_email,
        DATEDIFF(e.end_datetime, e.start_datetime) + 1 as total_days_calc
        FROM events e 
        LEFT JOIN projects p ON e.project_id = p.id 
        LEFT JOIN users u ON e.organizer_id = u.id 
        WHERE e.id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$event = mysqli_fetch_assoc($result);

if (!$event) {
    header('Location: events.php');
    exit();
}

// Set total_days if not set
if (!isset($event['total_days']) || $event['total_days'] < 1) {
    $event['total_days'] = $event['total_days_calc'] > 0 ? $event['total_days_calc'] : 1;
}

// Get attendees
$sql = "SELECT ea.*, u.username, u.email, u.profile_picture
        FROM event_attendees ea 
        JOIN users u ON ea.user_id = u.id 
        WHERE ea.event_id = ? 
        ORDER BY 
            CASE ea.rsvp_status
                WHEN 'Confirmed' THEN 1
                WHEN 'Registered' THEN 2
                WHEN 'Invited' THEN 3
                WHEN 'Pending' THEN 4
                WHEN 'Maybe' THEN 5
                WHEN 'Declined' THEN 6
                WHEN 'Cancelled' THEN 7
                WHEN 'No-Show' THEN 8
                ELSE 9
            END,
            u.username ASC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$attendees = [];
while ($row = mysqli_fetch_assoc($result)) {
    $attendees[] = $row;
}

// Get tasks
$sql = "SELECT et.*, u.username as assigned_name, u.email as assigned_email
        FROM event_tasks et 
        LEFT JOIN users u ON et.assigned_to = u.id 
        WHERE et.event_id = ? 
        ORDER BY 
            CASE et.status
                WHEN 'Open' THEN 1
                WHEN 'Assigned' THEN 2
                WHEN 'In Progress' THEN 3
                WHEN 'Completed' THEN 4
                WHEN 'Verified' THEN 5
                WHEN 'Closed' THEN 6
                WHEN 'Not Started' THEN 7
                WHEN 'Cancelled' THEN 8
                ELSE 9
            END,
            et.due_date ASC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$tasks = [];
while ($row = mysqli_fetch_assoc($result)) {
    $tasks[] = $row;
}

// Get resources
$sql = "SELECT * FROM event_resources 
        WHERE event_id = ? 
        ORDER BY 
            CASE status
                WHEN 'Requested' THEN 1
                WHEN 'Approved' THEN 2
                WHEN 'Ordered' THEN 3
                WHEN 'Allocated' THEN 4
                WHEN 'Delivered' THEN 5
                WHEN 'In Use' THEN 6
                WHEN 'Returned' THEN 7
                WHEN 'Released' THEN 8
                ELSE 9
            END,
            created_at DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$resources = [];
while ($row = mysqli_fetch_assoc($result)) {
    $resources[] = $row;
}

// Get attendance records
$sql = "SELECT a.*, u.username, u.email, u.id as user_id
        FROM event_attendance a
        JOIN event_attendees ea ON a.attendee_id = ea.id
        JOIN users u ON ea.user_id = u.id
        WHERE a.event_id = ? 
        ORDER BY a.day_number, u.username";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$attendance = [];
while ($row = mysqli_fetch_assoc($result)) {
    $attendance[] = $row;
}

// Get activity log
$sql = "SELECT al.*, u.username 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE (al.entity_type = 'event' AND al.entity_id = ?) 
           OR (al.entity_type = 'attendee' AND al.entity_id IN (SELECT id FROM event_attendees WHERE event_id = ?))
           OR (al.entity_type = 'task' AND al.entity_id IN (SELECT id FROM event_tasks WHERE event_id = ?))
           OR (al.entity_type = 'resource' AND al.entity_id IN (SELECT id FROM event_resources WHERE event_id = ?))
        ORDER BY al.created_at DESC
        LIMIT 50";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iiii", $eventId, $eventId, $eventId, $eventId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$activityLog = [];
while ($row = mysqli_fetch_assoc($result)) {
    $activityLog[] = $row;
}

// Get users for dropdowns
$users = [];
$sql = "SELECT id, username, email FROM users WHERE is_active = 1 ORDER BY username";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
}

// Calculate statistics
$totalAttendees = count($attendees);
$confirmedAttendees = 0;
$pendingAttendees = 0;
$registeredAttendees = 0;
$invitedAttendees = 0;
$declinedAttendees = 0;
$maybeAttendees = 0;
$cancelledAttendees = 0;
$noShowAttendees = 0;

foreach ($attendees as $attendee) {
    switch ($attendee['rsvp_status']) {
        case 'Confirmed':
            $confirmedAttendees++;
            break;
        case 'Registered':
            $registeredAttendees++;
            break;
        case 'Invited':
            $invitedAttendees++;
            break;
        case 'Pending':
            $pendingAttendees++;
            break;
        case 'Declined':
            $declinedAttendees++;
            break;
        case 'Maybe':
            $maybeAttendees++;
            break;
        case 'Cancelled':
            $cancelledAttendees++;
            break;
        case 'No-Show':
            $noShowAttendees++;
            break;
    }
}

$totalTasks = count($tasks);
$openTasks = 0;
$assignedTasks = 0;
$inProgressTasks = 0;
$completedTasks = 0;
$verifiedTasks = 0;
$closedTasks = 0;
$notStartedTasks = 0;
$cancelledTasks = 0;

foreach ($tasks as $task) {
    switch ($task['status']) {
        case 'Open':
            $openTasks++;
            break;
        case 'Assigned':
            $assignedTasks++;
            break;
        case 'In Progress':
            $inProgressTasks++;
            break;
        case 'Completed':
            $completedTasks++;
            break;
        case 'Verified':
            $verifiedTasks++;
            break;
        case 'Closed':
            $closedTasks++;
            break;
        case 'Not Started':
            $notStartedTasks++;
            break;
        case 'Cancelled':
            $cancelledTasks++;
            break;
    }
}

$totalResources = count($resources);
$requestedResources = 0;
$approvedResources = 0;
$orderedResources = 0;
$allocatedResources = 0;
$deliveredResources = 0;
$inUseResources = 0;
$returnedResources = 0;
$releasedResources = 0;

foreach ($resources as $resource) {
    switch ($resource['status']) {
        case 'Requested':
            $requestedResources++;
            break;
        case 'Approved':
            $approvedResources++;
            break;
        case 'Ordered':
            $orderedResources++;
            break;
        case 'Allocated':
            $allocatedResources++;
            break;
        case 'Delivered':
            $deliveredResources++;
            break;
        case 'In Use':
            $inUseResources++;
            break;
        case 'Returned':
            $returnedResources++;
            break;
        case 'Released':
            $releasedResources++;
            break;
    }
}

// Attendance statistics
$totalAttendanceDays = count($attendance);
$presentCount = 0;
$absentCount = 0;
$lateCount = 0;
$excusedCount = 0;
$notRecordedCount = 0;

foreach ($attendance as $record) {
    switch ($record['attendance_status']) {
        case 'present':
            $presentCount++;
            break;
        case 'absent':
            $absentCount++;
            break;
        case 'late':
            $lateCount++;
            break;
        case 'excused':
            $excusedCount++;
            break;
        case 'not_recorded':
            $notRecordedCount++;
            break;
    }
}

// Helper Functions for Handlers
function handleAddAttendee($conn, $eventId) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $userId = intval($_POST['user_id'] ?? 0);
    $role = mysqli_real_escape_string($conn, $_POST['role_in_event'] ?? 'Participant');
    $rsvp = mysqli_real_escape_string($conn, $_POST['rsvp_status'] ?? 'Invited');
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Please select a user']);
        return;
    }
    
    // Check if already added
    $checkSql = "SELECT id FROM event_attendees WHERE event_id = ? AND user_id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "ii", $eventId, $userId);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'User is already an attendee']);
        return;
    }
    
    // Get event details for total days
    $eventSql = "SELECT event_name, total_days, start_datetime FROM events WHERE id = ?";
    $eventStmt = mysqli_prepare($conn, $eventSql);
    mysqli_stmt_bind_param($eventStmt, "i", $eventId);
    mysqli_stmt_execute($eventStmt);
    $eventResult = mysqli_stmt_get_result($eventStmt);
    $event = mysqli_fetch_assoc($eventResult);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Insert attendee
        $sql = "INSERT INTO event_attendees (event_id, user_id, role_in_event, rsvp_status, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiss", $eventId, $userId, $role, $rsvp);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        $attendeeRecordId = mysqli_insert_id($conn);
        
        // Create attendance records for multi-day events
        if ($event && $event['total_days'] > 1) {
            $startDate = new DateTime($event['start_datetime']);
            for ($day = 1; $day <= $event['total_days']; $day++) {
                $attSql = "INSERT INTO event_attendance (event_id, attendee_id, day_number, attendance_status, created_at)
                          VALUES (?, ?, ?, 'not_recorded', NOW())";
                $attStmt = mysqli_prepare($conn, $attSql);
                mysqli_stmt_bind_param($attStmt, "iii", $eventId, $attendeeRecordId, $day);
                if (!mysqli_stmt_execute($attStmt)) {
                    throw new Exception(mysqli_error($conn));
                }
            }
        }
        
        // Get user info
        $userSql = "SELECT username FROM users WHERE id = ?";
        $userStmt = mysqli_prepare($conn, $userSql);
        mysqli_stmt_bind_param($userStmt, "i", $userId);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        $user = mysqli_fetch_assoc($userResult);
        
        mysqli_commit($conn);
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'added', "Added {$user['username']} as attendee to event '{$event['event_name']}'", 'attendee', $attendeeRecordId);
        
        // Send notification to user
        sendNotification($conn, $userId, 'Event Invitation', 
            "You have been added as an attendee to event: {$event['event_name']}", 
            'info', 'event', $eventId);
        
        echo json_encode(['success' => true, 'message' => 'Attendee added successfully']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleEditAttendee($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $attendeeId = intval($_POST['id'] ?? 0);
    $role = mysqli_real_escape_string($conn, $_POST['role_in_event'] ?? 'Participant');
    $rsvp = mysqli_real_escape_string($conn, $_POST['rsvp_status'] ?? 'Invited');
    
    $sql = "UPDATE event_attendees SET role_in_event = ?, rsvp_status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssi", $role, $rsvp, $attendeeId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Attendee updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleDeleteAttendee($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $attendeeId = intval($_POST['id'] ?? 0);
    
    mysqli_begin_transaction($conn);
    
    try {
        // First delete attendance records
        $deleteAttendanceSql = "DELETE FROM event_attendance WHERE attendee_id = ?";
        $deleteAttendanceStmt = mysqli_prepare($conn, $deleteAttendanceSql);
        mysqli_stmt_bind_param($deleteAttendanceStmt, "i", $attendeeId);
        mysqli_stmt_execute($deleteAttendanceStmt);
        
        // Then delete attendee
        $sql = "DELETE FROM event_attendees WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $attendeeId);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Attendee removed successfully']);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleAddTask($conn, $eventId) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $taskName = mysqli_real_escape_string($conn, $_POST['task_name'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
    
    if (empty($taskName)) {
        echo json_encode(['success' => false, 'message' => 'Task name is required']);
        return;
    }
    
    $sql = "INSERT INTO event_tasks (event_id, task_name, description, assigned_to, due_date, priority, status, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'Open', ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isssisi", $eventId, $taskName, $description, $assignedTo, $dueDate, $priority, $_SESSION['user_id']);
    
    if (mysqli_stmt_execute($stmt)) {
        $taskId = mysqli_insert_id($conn);
        
        // Get event info
        $eventSql = "SELECT event_name FROM events WHERE id = ?";
        $eventStmt = mysqli_prepare($conn, $eventSql);
        mysqli_stmt_bind_param($eventStmt, "i", $eventId);
        mysqli_stmt_execute($eventStmt);
        $eventResult = mysqli_stmt_get_result($eventStmt);
        $event = mysqli_fetch_assoc($eventResult);
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'added', "Added task '{$taskName}' to event '{$event['event_name']}'", 'task', $taskId);
        
        // Notify assigned user
        if ($assignedTo) {
            sendNotification($conn, $assignedTo, 'New Task Assigned', 
                "You have been assigned a task for event '{$event['event_name']}': {$taskName}", 
                'info', 'task', $taskId);
        }
        
        echo json_encode(['success' => true, 'message' => 'Task added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleEditTask($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $taskId = intval($_POST['id'] ?? 0);
    $taskName = mysqli_real_escape_string($conn, $_POST['task_name'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Open');
    
    $sql = "UPDATE event_tasks SET task_name = ?, description = ?, assigned_to = ?, 
            due_date = ?, priority = ?, status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssisssi", $taskName, $description, $assignedTo, $dueDate, $priority, $status, $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleDeleteTask($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $taskId = intval($_POST['id'] ?? 0);
    
    $sql = "DELETE FROM event_tasks WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Task deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleUpdateTaskStatus($conn) {
    $taskId = intval($_POST['id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
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
        return;
    }
    
    $canUpdate = hasAnyRole(['super_admin', 'admin', 'pm_manager']) || 
                 ($_SESSION['user_id'] == $task['assigned_to']) || 
                 ($_SESSION['user_id'] == $task['organizer_id']);
    
    if (!$canUpdate) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $sql = "UPDATE event_tasks SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $status, $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        // If status changed to Completed, set completed_at
        if ($status == 'Completed') {
            $updateSql = "UPDATE event_tasks SET completed_at = NOW() WHERE id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "i", $taskId);
            mysqli_stmt_execute($updateStmt);
        }
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'updated', "Updated task '{$task['task_name']}' status to {$status}", 'task', $taskId);
        
        echo json_encode(['success' => true, 'message' => 'Task status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleAddResource($conn, $eventId) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceName = mysqli_real_escape_string($conn, $_POST['resource_name'] ?? '');
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if (empty($resourceName)) {
        echo json_encode(['success' => false, 'message' => 'Resource name is required']);
        return;
    }
    
    $status = 'Requested';
    
    $sql = "INSERT INTO event_resources (event_id, resource_name, resource_type, quantity, notes, status, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ississi", $eventId, $resourceName, $resourceType, $quantity, $notes, $status, $_SESSION['user_id']);
    
    if (mysqli_stmt_execute($stmt)) {
        $resourceId = mysqli_insert_id($conn);
        
        // Get event info
        $eventSql = "SELECT event_name FROM events WHERE id = ?";
        $eventStmt = mysqli_prepare($conn, $eventSql);
        mysqli_stmt_bind_param($eventStmt, "i", $eventId);
        mysqli_stmt_execute($eventStmt);
        $eventResult = mysqli_stmt_get_result($eventStmt);
        $event = mysqli_fetch_assoc($eventResult);
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'added', "Added resource '{$resourceName}' to event '{$event['event_name']}'", 'resource', $resourceId);
        
        // Notify managers about resource request
        if (hasRole('pm_employee')) {
            $managerSql = "SELECT id FROM users WHERE system_role IN ('super_admin', 'admin', 'pm_manager')";
            $managerResult = mysqli_query($conn, $managerSql);
            while ($manager = mysqli_fetch_assoc($managerResult)) {
                sendNotification($conn, $manager['id'], 'Resource Request', 
                    "Resource '{$resourceName}' has been requested for event '{$event['event_name']}'", 
                    'warning', 'resource', $resourceId);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Resource added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleEditResource($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['id'] ?? 0);
    $resourceName = mysqli_real_escape_string($conn, $_POST['resource_name'] ?? '');
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Requested');
    
    $sql = "UPDATE event_resources SET resource_name = ?, resource_type = ?, quantity = ?, notes = ?, status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssissi", $resourceName, $resourceType, $quantity, $notes, $status, $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Resource updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleDeleteResource($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['id'] ?? 0);
    
    $sql = "DELETE FROM event_resources WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Resource deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleUpdateResourceStatus($conn) {
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    $sql = "UPDATE event_resources SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $status, $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Resource status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleRecordAttendance($conn, $eventId) {
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $dayNumber = intval($_POST['day_number'] ?? 1);
    $attendanceStatus = mysqli_real_escape_string($conn, $_POST['attendance_status'] ?? 'present');
    $checkInTime = !empty($_POST['check_in_time']) ? $_POST['check_in_time'] : null;
    $checkOutTime = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] : null;
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Attendee ID required']);
        return;
    }
    
    // Get event total days
    $eventSql = "SELECT total_days FROM events WHERE id = ?";
    $eventStmt = mysqli_prepare($conn, $eventSql);
    mysqli_stmt_bind_param($eventStmt, "i", $eventId);
    mysqli_stmt_execute($eventStmt);
    $eventResult = mysqli_stmt_get_result($eventStmt);
    $event = mysqli_fetch_assoc($eventResult);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        return;
    }
    
    if ($dayNumber > $event['total_days']) {
        echo json_encode(['success' => false, 'message' => 'Day number exceeds total event days']);
        return;
    }
    
    // Check if record exists
    $checkSql = "SELECT id FROM event_attendance WHERE event_id = ? AND attendee_id = ? AND day_number = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "iii", $eventId, $attendeeId, $dayNumber);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    
    if (mysqli_num_rows($checkResult) > 0) {
        // Update existing
        $row = mysqli_fetch_assoc($checkResult);
        $sql = "UPDATE event_attendance SET attendance_status = ?, check_in_time = ?, check_out_time = ?, recorded_by = ? 
                WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssii", $attendanceStatus, $checkInTime, $checkOutTime, $_SESSION['user_id'], $row['id']);
    } else {
        // Insert new
        $sql = "INSERT INTO event_attendance (event_id, attendee_id, day_number, attendance_status, check_in_time, check_out_time, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiisssi", $eventId, $attendeeId, $dayNumber, $attendanceStatus, $checkInTime, $checkOutTime, $_SESSION['user_id']);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        // Calculate attendance rate
        $attendanceSql = "SELECT COUNT(*) as total_days, 
                          SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present_days
                          FROM event_attendance 
                          WHERE event_id = ? AND attendee_id = ?";
        $attendanceStmt = mysqli_prepare($conn, $attendanceSql);
        mysqli_stmt_bind_param($attendanceStmt, "ii", $eventId, $attendeeId);
        mysqli_stmt_execute($attendanceStmt);
        $attendanceResult = mysqli_stmt_get_result($attendanceStmt);
        $attendanceData = mysqli_fetch_assoc($attendanceResult);
        
        $rate = 0;
        if ($event['total_days'] > 0) {
            $rate = round(($attendanceData['present_days'] / $event['total_days']) * 100, 2);
        }
        
        // Update attendee final status
        if ($rate >= 90) {
            $finalStatus = 'Attended';
        } elseif ($rate > 0) {
            $finalStatus = 'Partial Attended';
        } else {
            $finalStatus = 'No-Show';
        }
        
        $updateSql = "UPDATE event_attendees SET attendance_rate = ?, final_status = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "dsi", $rate, $finalStatus, $attendeeId);
        mysqli_stmt_execute($updateStmt);
        
        // Get attendee info for log
        $attendeeSql = "SELECT u.username FROM event_attendees ea JOIN users u ON ea.user_id = u.id WHERE ea.id = ?";
        $attendeeStmt = mysqli_prepare($conn, $attendeeSql);
        mysqli_stmt_bind_param($attendeeStmt, "i", $attendeeId);
        mysqli_stmt_execute($attendeeStmt);
        $attendeeResult = mysqli_stmt_get_result($attendeeStmt);
        $attendee = mysqli_fetch_assoc($attendeeResult);
        
        logActivity($conn, $_SESSION['user_id'], 'recorded', "Recorded attendance for {$attendee['username']} - Day {$dayNumber}", 'attendance', $eventId);
        
        echo json_encode(['success' => true, 'message' => 'Attendance recorded successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function handleUpdateEventStatus($conn, $eventId) {
    $newStatus = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    $validStatuses = ['Draft', 'Submitted', 'Approved', 'Planning', 'Upcoming', 'Ongoing', 'Completed', 'Closed', 'Cancelled', 'Rejected'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    // Check permissions
    $checkSql = "SELECT event_name, status, organizer_id FROM events WHERE id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $eventId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $event = mysqli_fetch_assoc($checkResult);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        return;
    }
    
    $canUpdate = hasAnyRole(['super_admin', 'admin']) || 
                 (hasRole('pm_manager') && in_array($newStatus, ['Approved', 'Rejected', 'Cancelled'])) ||
                 (hasRole('pm_employee') && $newStatus == 'Submitted' && $event['status'] == 'Draft');
    
    if (!$canUpdate) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $oldStatus = $event['status'];
    
    $sql = "UPDATE events SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $newStatus, $eventId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'updated', "Event status changed from {$oldStatus} to {$newStatus}", 'event', $eventId, $oldStatus, $newStatus);
        
        // Notify attendees for important status changes
        if (in_array($newStatus, ['Completed', 'Cancelled', 'Approved'])) {
            $attendeeSql = "SELECT user_id FROM event_attendees WHERE event_id = ? AND rsvp_status IN ('Confirmed', 'Registered')";
            $attendeeStmt = mysqli_prepare($conn, $attendeeSql);
            mysqli_stmt_bind_param($attendeeStmt, "i", $eventId);
            mysqli_stmt_execute($attendeeStmt);
            $attendeeResult = mysqli_stmt_get_result($attendeeStmt);
            
            while ($attendee = mysqli_fetch_assoc($attendeeResult)) {
                sendNotification($conn, $attendee['user_id'], "Event {$newStatus}", 
                    "Event '{$event['event_name']}' has been marked as {$newStatus}", 
                    $newStatus == 'Completed' ? 'success' : 'warning', 'event', $eventId);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Event status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

function getAttendee($conn) {
    $attendeeId = intval($_GET['id'] ?? 0);
    
    $sql = "SELECT ea.*, u.username, u.email 
            FROM event_attendees ea
            JOIN users u ON ea.user_id = u.id
            WHERE ea.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $attendeeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $attendee = mysqli_fetch_assoc($result);
    
    if ($attendee) {
        echo json_encode(['success' => true, 'data' => $attendee]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Attendee not found']);
    }
}

function getTask($conn) {
    $taskId = intval($_GET['id'] ?? 0);
    
    $sql = "SELECT et.*, u.username as assigned_name, u.email as assigned_email
            FROM event_tasks et
            LEFT JOIN users u ON et.assigned_to = u.id
            WHERE et.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $task = mysqli_fetch_assoc($result);
    
    if ($task) {
        echo json_encode(['success' => true, 'data' => $task]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
    }
}

function getResource($conn) {
    $resourceId = intval($_GET['id'] ?? 0);
    
    $sql = "SELECT * FROM event_resources WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $resource = mysqli_fetch_assoc($result);
    
    if ($resource) {
        echo json_encode(['success' => true, 'data' => $resource]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resource not found']);
    }
}

function getAttendance($conn, $eventId) {
    $attendeeId = intval($_GET['attendee_id'] ?? 0);
    
    if ($attendeeId) {
        $sql = "SELECT a.*, u.username, u.email 
                FROM event_attendance a
                JOIN event_attendees ea ON a.attendee_id = ea.id
                JOIN users u ON ea.user_id = u.id
                WHERE a.event_id = ? AND a.attendee_id = ?
                ORDER BY a.day_number";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $eventId, $attendeeId);
    } else {
        $sql = "SELECT a.*, u.username, u.email 
                FROM event_attendance a
                JOIN event_attendees ea ON a.attendee_id = ea.id
                JOIN users u ON ea.user_id = u.id
                WHERE a.event_id = ?
                ORDER BY a.day_number, u.username";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $attendance = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $attendance[] = $row;
    }
    
    echo json_encode(['success' => true, 'data' => $attendance]);
}

// Helper Functions for UI
function getStatusBadge($status) {
    switch ($status) {
        case 'Draft': return 'bg-secondary';
        case 'Submitted': return 'bg-info';
        case 'Approved': return 'bg-success';
        case 'Planning': return 'bg-warning';
        case 'Upcoming': return 'bg-primary';
        case 'Ongoing': return 'bg-info';
        case 'Completed': return 'bg-success';
        case 'Closed': return 'bg-dark';
        case 'Cancelled': return 'bg-danger';
        case 'Rejected': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getPriorityBadge($priority) {
    switch ($priority) {
        case 'High': return 'bg-danger';
        case 'Medium': return 'bg-warning';
        case 'Low': return 'bg-success';
        default: return 'bg-secondary';
    }
}

function getTaskStatusBadge($status) {
    switch ($status) {
        case 'Open': return 'bg-secondary';
        case 'Assigned': return 'bg-info';
        case 'In Progress': return 'bg-primary';
        case 'Completed': return 'bg-success';
        case 'Verified': return 'bg-success';
        case 'Closed': return 'bg-dark';
        case 'Not Started': return 'bg-secondary';
        case 'Cancelled': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getResourceStatusBadge($status) {
    switch ($status) {
        case 'Requested': return 'bg-warning';
        case 'Approved': return 'bg-info';
        case 'Ordered': return 'bg-info';
        case 'Allocated': return 'bg-primary';
        case 'Delivered': return 'bg-success';
        case 'In Use': return 'bg-success';
        case 'Returned': return 'bg-secondary';
        case 'Released': return 'bg-secondary';
        default: return 'bg-secondary';
    }
}

function getRSVPBadge($status) {
    switch ($status) {
        case 'Confirmed': return 'bg-success';
        case 'Registered': return 'bg-info';
        case 'Invited': return 'bg-primary';
        case 'Pending': return 'bg-warning';
        case 'Maybe': return 'bg-info';
        case 'Declined': return 'bg-danger';
        case 'Cancelled': return 'bg-danger';
        case 'No-Show': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getAttendanceBadge($status) {
    switch ($status) {
        case 'present': return 'bg-success';
        case 'absent': return 'bg-danger';
        case 'late': return 'bg-warning';
        case 'excused': return 'bg-info';
        case 'not_recorded': return 'bg-secondary';
        default: return 'bg-secondary';
    }
}

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#8B1E3F',
    'secondary' => '#4A0E21',
    'accent' => '#C49A6C'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($event['event_name']); ?> - Event Details | Dashen Bank BSPM</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        :root {
            --dashen-primary: <?php echo $custom_colors['primary']; ?>;
            --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
            --dashen-accent: <?php echo $custom_colors['accent']; ?>;
            --dashen-success: #28a745;
            --dashen-danger: #dc3545;
            --dashen-warning: #ffc107;
            --dashen-info: #17a2b8;
            
            --gradient-primary: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --header-height: 80px;
            
            --border-radius: 16px;
        }

        body.dark-mode {
            background: #1a1a1a;
            color: #e0e0e0;
        }

        body.dark-mode .card,
        body.dark-mode .modal-content {
            background: #2d2d2d;
            border-color: #404040;
        }

        body.dark-mode .table thead th {
            background: #333333;
            color: #b0b0b0;
        }

        body.dark-mode .table td {
            color: #e0e0e0;
        }

        body.dark-mode .text-muted {
            color: #b0b0b0 !important;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select {
            background: #333333;
            border-color: #404040;
            color: #e0e0e0;
        }

        body.dark-mode .bg-light {
            background-color: #333333 !important;
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
            transition: background-color 0.3s ease, color 0.3s ease;
        }

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
        }

        .card {
            background: white;
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            border: 1px solid #e8eaed;
            margin-bottom: 30px;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        .card-header {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            padding: 20px 24px;
            border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
        }

        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }

        .card-body {
            padding: 24px;
        }

        .btn-back {
            background: transparent;
            color: var(--dashen-primary);
            border: 2px solid var(--dashen-primary);
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .btn-back:hover {
            background: var(--dashen-primary);
            color: white;
        }

        .btn-primary {
            background: var(--dashen-primary);
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: var(--dashen-secondary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline-primary {
            background: transparent;
            color: var(--dashen-primary);
            border: 2px solid var(--dashen-primary);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-outline-primary:hover {
            background: var(--dashen-primary);
            color: white;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .nav-tabs {
            border-bottom: 2px solid #e8eaed;
            margin-bottom: 20px;
        }

        .nav-tabs .nav-link {
            color: #5f6368;
            font-weight: 500;
            border: none;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link:hover {
            color: var(--dashen-primary);
        }

        .nav-tabs .nav-link.active {
            color: var(--dashen-primary);
            background: transparent;
            border-bottom: 3px solid var(--dashen-primary);
            font-weight: 600;
        }

        .nav-tabs .nav-link .badge {
            margin-left: 8px;
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
            transition: all 0.3s ease;
            border-bottom: 1px solid #e8eaed;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table td {
            padding: 16px;
            vertical-align: middle;
        }

        .event-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .event-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: var(--dashen-primary);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .info-item {
            padding: 10px;
        }

        .info-item strong {
            color: var(--dashen-primary);
            display: block;
            margin-bottom: 5px;
        }

        .info-item i {
            margin-right: 8px;
            color: var(--dashen-primary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e8eaed;
            border-radius: var(--border-radius);
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--dashen-primary);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            background: transparent;
            border: 1px solid #e8eaed;
            color: #5f6368;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: var(--dashen-primary);
            color: white;
            border-color: transparent;
        }

        .btn-icon.danger:hover {
            background: #dc3545;
        }

        .btn-icon.success:hover {
            background: #28a745;
        }

        .btn-icon.info:hover {
            background: #17a2b8;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
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

        .form-label {
            font-weight: 500;
            color: #333;
            margin-bottom: 6px;
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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state i {
            opacity: 0.5;
            color: var(--dashen-primary);
        }

        .empty-state p {
            margin: 10px 0 20px;
            color: #5f6368;
        }

        .activity-log {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid #e8eaed;
            transition: background-color 0.3s ease;
        }

        .activity-item:hover {
            background: #f8f9fa;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(139, 30, 63, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--dashen-primary);
        }

        .activity-time {
            font-size: 11px;
            color: #5f6368;
        }

        .attendance-day {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin: 0 4px 4px 0;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .attendance-day.present {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }

        .attendance-day.absent {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .attendance-day.late {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .attendance-day.excused {
            background: rgba(23, 162, 184, 0.2);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.3);
        }

        .attendance-day.not-recorded {
            background: #f8f9fa;
            color: #6c757d;
            border: 1px dashed #dee2e6;
        }

        .attendance-day:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-md);
        }

        .attendance-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .summary-item {
            flex: 1;
            text-align: center;
        }

        .summary-item .count {
            font-size: 20px;
            font-weight: 700;
            color: var(--dashen-primary);
        }

        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        body.dark-mode .spinner-overlay {
            background: rgba(0,0,0,0.8);
        }

        .spinner-overlay.active {
            display: flex;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .event-header {
                flex-direction: column;
                gap: 15px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .attendance-summary {
                flex-direction: column;
                gap: 10px;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body class="<?php echo $dark_mode ? 'dark-mode' : ''; ?>">
    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content <?php echo isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'] ? 'expanded' : ''; ?>" id="mainContent">
        <!-- Header -->
        <?php include 'includes/header.php'; ?>
        
        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Back button -->
            <a href="events.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Events
            </a>
            
            <!-- Event Header Card -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Event Details</h5>
                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                        <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#updateEventStatusModal">
                            <i class="fas fa-edit"></i> Update Status
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="event-header">
                        <div>
                            <h2><?php echo htmlspecialchars($event['event_name']); ?></h2>
                            <p class="text-muted"><?php echo htmlspecialchars($event['event_type']); ?></p>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo getStatusBadge($event['status']); ?> fs-6 p-2">
                                <?php echo $event['status']; ?>
                            </span>
                            <br>
                            <span class="badge <?php echo getPriorityBadge($event['priority']); ?> mt-2 p-2">
                                <?php echo $event['priority']; ?> Priority
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <strong><i class="fas fa-calendar"></i> Date & Time:</strong>
                            <p><?php echo date('F j, Y g:i A', strtotime($event['start_datetime'])); ?></p>
                            <?php if ($event['end_datetime']): ?>
                            <p>to <?php echo date('F j, Y g:i A', strtotime($event['end_datetime'])); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-map-marker-alt"></i> Location:</strong>
                            <p><?php echo htmlspecialchars($event['location']); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-project-diagram"></i> Project:</strong>
                            <p><?php echo htmlspecialchars($event['project_name'] ?? 'Not assigned'); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-user"></i> Organizer:</strong>
                            <p><?php echo htmlspecialchars($event['organizer_name']); ?></p>
                        </div>
                        
                        <div class="info-item">
                            <strong><i class="fas fa-calendar-week"></i> Duration:</strong>
                            <p><?php echo $event['total_days']; ?> day(s)</p>
                        </div>
                    </div>
                    
                    <?php if ($event['description']): ?>
                    <div class="info-item">
                        <strong><i class="fas fa-align-left"></i> Description:</strong>
                        <p class="mt-2"><?php echo nl2br(htmlspecialchars($event['description'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card" onclick="document.getElementById('attendees-tab').click()">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Total Attendees</span>
                        <i class="fas fa-users" style="color: var(--dashen-primary);"></i>
                    </div>
                    <div class="stat-value"><?php echo $totalAttendees; ?></div>
                    <div class="small text-muted">
                        <span class="text-success"><?php echo $confirmedAttendees; ?> Confirmed</span> |
                        <span class="text-warning"><?php echo $pendingAttendees + $invitedAttendees; ?> Pending</span>
                    </div>
                </div>
                
                <div class="stat-card" onclick="document.getElementById('tasks-tab').click()">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Total Tasks</span>
                        <i class="fas fa-tasks" style="color: var(--dashen-primary);"></i>
                    </div>
                    <div class="stat-value"><?php echo $totalTasks; ?></div>
                    <div class="small text-muted">
                        <span class="text-success"><?php echo $completedTasks; ?> Completed</span> |
                        <span class="text-primary"><?php echo $inProgressTasks + $assignedTasks; ?> In Progress</span>
                    </div>
                </div>
                
                <div class="stat-card" onclick="document.getElementById('resources-tab').click()">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Total Resources</span>
                        <i class="fas fa-box" style="color: var(--dashen-primary);"></i>
                    </div>
                    <div class="stat-value"><?php echo $totalResources; ?></div>
                    <div class="small text-muted">
                        <span class="text-success"><?php echo $deliveredResources + $inUseResources; ?> Available</span> |
                        <span class="text-warning"><?php echo $requestedResources + $approvedResources; ?> Requested</span>
                    </div>
                </div>
                
                <div class="stat-card" onclick="document.getElementById('attendance-tab').click()">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Attendance Records</span>
                        <i class="fas fa-clipboard-list" style="color: var(--dashen-primary);"></i>
                    </div>
                    <div class="stat-value"><?php echo $totalAttendanceDays; ?></div>
                    <div class="small text-muted">
                        <span class="text-success"><?php echo $presentCount; ?> Present</span> |
                        <span class="text-danger"><?php echo $absentCount; ?> Absent</span>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <ul class="nav nav-tabs" id="eventTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="attendees-tab" data-bs-toggle="tab" data-bs-target="#attendees" type="button" role="tab">
                        <i class="fas fa-users me-1"></i> Attendees
                        <span class="badge bg-secondary"><?php echo $totalAttendees; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tasks-tab" data-bs-toggle="tab" data-bs-target="#tasks" type="button" role="tab">
                        <i class="fas fa-tasks me-1"></i> Tasks
                        <span class="badge bg-secondary"><?php echo $totalTasks; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="resources-tab" data-bs-toggle="tab" data-bs-target="#resources" type="button" role="tab">
                        <i class="fas fa-box me-1"></i> Resources
                        <span class="badge bg-secondary"><?php echo $totalResources; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab">
                        <i class="fas fa-clipboard-list me-1"></i> Attendance
                        <span class="badge bg-secondary"><?php echo $totalAttendanceDays; ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                        <i class="fas fa-history me-1"></i> Activity Log
                        <span class="badge bg-secondary"><?php echo count($activityLog); ?></span>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- Attendees Tab -->
                <div class="tab-pane fade show active" id="attendees" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Attendees List</h5>
                                <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                                    <i class="fas fa-plus"></i> Add Attendee
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table" id="attendeesTable">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>RSVP Status</th>
                                            <th>Attendance Rate</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($attendees)): ?>
                                        <?php foreach ($attendees as $attendee): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($attendee['username']); ?></td>
                                            <td><?php echo htmlspecialchars($attendee['email']); ?></td>
                                            <td><?php echo htmlspecialchars($attendee['role_in_event']); ?></td>
                                            <td>
                                                <span class="badge <?php echo getRSVPBadge($attendee['rsvp_status']); ?>">
                                                    <?php echo $attendee['rsvp_status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($attendee['attendance_rate'])): ?>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 6px; width: 80px;">
                                                        <div class="progress-bar bg-success" style="width: <?php echo $attendee['attendance_rate']; ?>%"></div>
                                                    </div>
                                                    <small><?php echo $attendee['attendance_rate']; ?>%</small>
                                                </div>
                                                <?php else: ?>
                                                <span class="text-muted">Not recorded</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon edit-attendee" 
                                                            data-id="<?php echo $attendee['id']; ?>"
                                                            data-role="<?php echo htmlspecialchars($attendee['role_in_event']); ?>"
                                                            data-rsvp="<?php echo $attendee['rsvp_status']; ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                    <button class="btn-icon danger delete-attendee" 
                                                            data-id="<?php echo $attendee['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($attendee['username']); ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <div class="empty-state">
                                                    <i class="fas fa-users fa-3x mb-3"></i>
                                                    <p>No attendees yet</p>
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                                                        <i class="fas fa-plus"></i> Add Attendee
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tasks Tab -->
                <div class="tab-pane fade" id="tasks" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Tasks List</h5>
                                <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                                    <i class="fas fa-plus"></i> Add Task
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table" id="tasksTable">
                                    <thead>
                                        <tr>
                                            <th>Task Name</th>
                                            <th>Assigned To</th>
                                            <th>Due Date</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($tasks)): ?>
                                        <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($task['task_name']); ?></strong>
                                                <?php if ($task['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($task['description'], 0, 50)); ?><?php echo strlen($task['description']) > 50 ? '...' : ''; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($task['assigned_name'] ?? 'Unassigned'); ?></td>
                                            <td>
                                                <?php if ($task['due_date']): ?>
                                                    <?php echo date('M j, Y', strtotime($task['due_date'])); ?>
                                                    <?php 
                                                    $dueDate = new DateTime($task['due_date']);
                                                    $today = new DateTime();
                                                    if ($dueDate < $today && $task['status'] != 'Completed' && $task['status'] != 'Closed'): ?>
                                                    <br><small class="text-danger">Overdue</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                Not set
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo getPriorityBadge($task['priority']); ?>">
                                                    <?php echo $task['priority']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo getTaskStatusBadge($task['status']); ?>">
                                                    <?php echo $task['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager']) || 
                                                              (!empty($task['assigned_to']) && $_SESSION['user_id'] == $task['assigned_to'])): ?>
                                                    <?php if ($task['status'] != 'Completed' && $task['status'] != 'Closed'): ?>
                                                    <button class="btn-icon success update-task-status" 
                                                            data-id="<?php echo $task['id']; ?>"
                                                            data-status="<?php echo $task['status']; ?>"
                                                            title="Update Status">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="btn-icon edit-task" 
                                                            data-id="<?php echo $task['id']; ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                    <button class="btn-icon danger delete-task" 
                                                            data-id="<?php echo $task['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($task['task_name']); ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <div class="empty-state">
                                                    <i class="fas fa-tasks fa-3x mb-3"></i>
                                                    <p>No tasks yet</p>
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                                                        <i class="fas fa-plus"></i> Add Task
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Resources Tab -->
                <div class="tab-pane fade" id="resources" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Resources List</h5>
                                <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])): ?>
                                <button class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addResourceModal">
                                    <i class="fas fa-plus"></i> Add Resource
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table" id="resourcesTable">
                                    <thead>
                                        <tr>
                                            <th>Resource Name</th>
                                            <th>Type</th>
                                            <th>Quantity</th>
                                            <th>Status</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($resources)): ?>
                                        <?php foreach ($resources as $resource): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($resource['resource_name']); ?></td>
                                            <td><?php echo htmlspecialchars($resource['resource_type']); ?></td>
                                            <td><?php echo $resource['quantity']; ?></td>
                                            <td>
                                                <span class="badge <?php echo getResourceStatusBadge($resource['status']); ?>">
                                                    <?php echo $resource['status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($resource['notes'] ?? '-'); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                    <?php if ($resource['status'] != 'Delivered' && $resource['status'] != 'Returned' && $resource['status'] != 'Released'): ?>
                                                    <button class="btn-icon success update-resource-status" 
                                                            data-id="<?php echo $resource['id']; ?>"
                                                            data-status="<?php echo $resource['status']; ?>"
                                                            title="Update Status">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button class="btn-icon edit-resource" 
                                                            data-id="<?php echo $resource['id']; ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn-icon danger delete-resource" 
                                                            data-id="<?php echo $resource['id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($resource['resource_name']); ?>"
                                                            title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <div class="empty-state">
                                                    <i class="fas fa-box fa-3x mb-3"></i>
                                                    <p>No resources yet</p>
                                                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])): ?>
                                                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addResourceModal">
                                                        <i class="fas fa-plus"></i> Add Resource
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Tab -->
                <div class="tab-pane fade" id="attendance" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Attendance Tracking</h5>
                        </div>
                        <div class="card-body">
                            <!-- Attendance Summary -->
                            <div class="attendance-summary">
                                <div class="summary-item">
                                    <div class="count"><?php echo $presentCount; ?></div>
                                    <div class="text-success">Present</div>
                                </div>
                                <div class="summary-item">
                                    <div class="count"><?php echo $absentCount; ?></div>
                                    <div class="text-danger">Absent</div>
                                </div>
                                <div class="summary-item">
                                    <div class="count"><?php echo $lateCount; ?></div>
                                    <div class="text-warning">Late</div>
                                </div>
                                <div class="summary-item">
                                    <div class="count"><?php echo $excusedCount; ?></div>
                                    <div class="text-info">Excused</div>
                                </div>
                                <div class="summary-item">
                                    <div class="count"><?php echo $notRecordedCount; ?></div>
                                    <div class="text-secondary">Not Recorded</div>
                                </div>
                            </div>
                            
                            <?php if (!empty($attendees)): ?>
                            <div class="table-responsive">
                                <table class="table" id="attendanceTable">
                                    <thead>
                                        <tr>
                                            <th>Attendee</th>
                                            <th>Status</th>
                                            <th>Daily Attendance</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendees as $attendee): 
                                            // Get attendance for this attendee
                                            $attendeeAttendance = array_filter($attendance, function($a) use ($attendee) {
                                                return $a['attendee_id'] == $attendee['id'];
                                            });
                                            
                                            $attendeeDays = [];
                                            foreach ($attendeeAttendance as $a) {
                                                $attendeeDays[$a['day_number']] = $a['attendance_status'];
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($attendee['username']); ?></td>
                                            <td>
                                                <span class="badge <?php echo getRSVPBadge($attendee['rsvp_status']); ?>">
                                                    <?php echo $attendee['rsvp_status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $totalDays = $event['total_days'] ?? 1;
                                                
                                                for ($day = 1; $day <= $totalDays; $day++): 
                                                    $status = $attendeeDays[$day] ?? 'not-recorded';
                                                ?>
                                                <span class="attendance-day <?php echo $status; ?>" 
                                                      title="Day <?php echo $day; ?>: <?php echo ucfirst(str_replace('_', ' ', $status)); ?>"
                                                      onclick="recordAttendance(<?php echo $attendee['id']; ?>, '<?php echo htmlspecialchars($attendee['username']); ?>', <?php echo $day; ?>)">
                                                    <?php echo $day; ?>
                                                </span>
                                                <?php endfor; ?>
                                            </td>
                                            <td>
                                                <button class="btn-icon info" onclick="recordAttendance(<?php echo $attendee['id']; ?>, '<?php echo htmlspecialchars($attendee['username']); ?>')">
                                                    <i class="fas fa-pen"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                <p>No attendees to track attendance</p>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                                    <i class="fas fa-plus"></i> Add Attendee
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Log Tab -->
                <div class="tab-pane fade" id="activity" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Activity Log</h5>
                        </div>
                        <div class="card-body">
                            <div class="activity-log">
                                <?php if (!empty($activityLog)): ?>
                                <?php foreach ($activityLog as $log): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-<?php 
                                            echo strpos($log['action'], 'add') !== false ? 'plus' : 
                                                (strpos($log['action'], 'edit') !== false ? 'edit' : 
                                                (strpos($log['action'], 'update') !== false ? 'sync' : 
                                                (strpos($log['action'], 'delete') !== false ? 'trash' : 
                                                (strpos($log['action'], 'record') !== false ? 'clipboard-list' : 'circle'))));
                                        ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></div>
                                        <div><?php echo htmlspecialchars($log['description'] ?? $log['action']); ?></div>
                                        <div class="activity-time">
                                            <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-history fa-3x mb-3"></i>
                                    <p>No activity logs yet</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Attendee Modal -->
    <div class="modal fade" id="addAttendeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Attendee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addAttendeeForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_attendee">
                        
                        <div class="mb-3">
                            <label class="form-label">Select User *</label>
                            <select class="form-select" name="user_id" required>
                                <option value="">Choose user...</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username'] . ' (' . $user['email'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role in Event</label>
                            <select class="form-select" name="role_in_event">
                                <option value="Participant">Participant</option>
                                <option value="Speaker">Speaker</option>
                                <option value="VIP">VIP</option>
                                <option value="Volunteer">Volunteer</option>
                                <option value="Staff">Staff</option>
                                <option value="Guest">Guest</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">RSVP Status</label>
                            <select class="form-select" name="rsvp_status">
                                <option value="Invited">Invited</option>
                                <option value="Registered">Registered</option>
                                <option value="Confirmed">Confirmed</option>
                                <option value="Pending">Pending</option>
                                <option value="Maybe">Maybe</option>
                                <option value="Declined">Declined</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Attendee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Attendee Modal -->
    <div class="modal fade" id="editAttendeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Attendee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editAttendeeForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_attendee">
                        <input type="hidden" name="id" id="editAttendeeId">
                        
                        <div class="mb-3">
                            <label class="form-label">User</label>
                            <input type="text" class="form-control" id="editAttendeeName" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role in Event</label>
                            <select class="form-select" name="role_in_event" id="editAttendeeRole">
                                <option value="Participant">Participant</option>
                                <option value="Speaker">Speaker</option>
                                <option value="VIP">VIP</option>
                                <option value="Volunteer">Volunteer</option>
                                <option value="Staff">Staff</option>
                                <option value="Guest">Guest</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">RSVP Status</label>
                            <select class="form-select" name="rsvp_status" id="editAttendeeRSVP">
                                <option value="Invited">Invited</option>
                                <option value="Registered">Registered</option>
                                <option value="Confirmed">Confirmed</option>
                                <option value="Pending">Pending</option>
                                <option value="Maybe">Maybe</option>
                                <option value="Declined">Declined</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Attendee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Task Modal -->
    <div class="modal fade" id="addTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addTaskForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_task">
                        
                        <div class="mb-3">
                            <label class="form-label">Task Name *</label>
                            <input type="text" class="form-control" name="task_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assign To</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div class="modal fade" id="editTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editTaskForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_task">
                        <input type="hidden" name="id" id="editTaskId">
                        
                        <div class="mb-3">
                            <label class="form-label">Task Name *</label>
                            <input type="text" class="form-control" name="task_name" id="editTaskName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editTaskDescription" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assign To</label>
                            <select class="form-select" name="assigned_to" id="editTaskAssignedTo">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date</label>
                                <input type="date" class="form-control" name="due_date" id="editTaskDueDate" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority" id="editTaskPriority">
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="editTaskStatus">
                                <option value="Open">Open</option>
                                <option value="Assigned">Assigned</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Verified">Verified</option>
                                <option value="Closed">Closed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Task Status Modal -->
    <div class="modal fade" id="updateTaskStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Task Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updateTaskStatusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_task_status">
                        <input type="hidden" name="id" id="updateTaskId">
                        
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select class="form-select" name="status" id="updateTaskStatus" required>
                                <option value="Open">Open</option>
                                <option value="Assigned">Assigned</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Verified">Verified</option>
                                <option value="Closed">Closed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Resource Modal -->
    <div class="modal fade" id="addResourceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Resource</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addResourceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_resource">
                        
                        <div class="mb-3">
                            <label class="form-label">Resource Name *</label>
                            <input type="text" class="form-control" name="resource_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Resource Type</label>
                            <select class="form-select" name="resource_type">
                                <option value="Equipment">Equipment</option>
                                <option value="Venue">Venue</option>
                                <option value="Personnel">Personnel</option>
                                <option value="Technology">Technology</option>
                                <option value="Catering">Catering</option>
                                <option value="Stationery">Stationery</option>
                                <option value="Transport">Transport</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" class="form-control" name="quantity" value="1" min="1">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Resource</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Resource Modal -->
    <div class="modal fade" id="editResourceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Resource</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editResourceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_resource">
                        <input type="hidden" name="id" id="editResourceId">
                        
                        <div class="mb-3">
                            <label class="form-label">Resource Name *</label>
                            <input type="text" class="form-control" name="resource_name" id="editResourceName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Resource Type</label>
                            <select class="form-select" name="resource_type" id="editResourceType">
                                <option value="Equipment">Equipment</option>
                                <option value="Venue">Venue</option>
                                <option value="Personnel">Personnel</option>
                                <option value="Technology">Technology</option>
                                <option value="Catering">Catering</option>
                                <option value="Stationery">Stationery</option>
                                <option value="Transport">Transport</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantity</label>
                                <input type="number" class="form-control" name="quantity" id="editResourceQuantity" min="1">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="editResourceStatus">
                                    <option value="Requested">Requested</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Ordered">Ordered</option>
                                    <option value="Allocated">Allocated</option>
                                    <option value="Delivered">Delivered</option>
                                    <option value="In Use">In Use</option>
                                    <option value="Returned">Returned</option>
                                    <option value="Released">Released</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="editResourceNotes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Resource</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Resource Status Modal -->
    <div class="modal fade" id="updateResourceStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Resource Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updateResourceStatusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_resource_status">
                        <input type="hidden" name="id" id="updateResourceId">
                        
                        <div class="mb-3">
                            <label class="form-label">New Status</label>
                            <select class="form-select" name="status" id="updateResourceStatus" required>
                                <option value="Requested">Requested</option>
                                <option value="Approved">Approved</option>
                                <option value="Ordered">Ordered</option>
                                <option value="Allocated">Allocated</option>
                                <option value="Delivered">Delivered</option>
                                <option value="In Use">In Use</option>
                                <option value="Returned">Returned</option>
                                <option value="Released">Released</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Event Status Modal -->
    <div class="modal fade" id="updateEventStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Event Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updateEventStatusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_event_status">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Status</label>
                            <input type="text" class="form-control" value="<?php echo $event['status']; ?>" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Status *</label>
                            <select class="form-select" name="status" required>
                                <option value="">Select new status...</option>
                                <option value="Draft">Draft</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Approved">Approved</option>
                                <option value="Planning">Planning</option>
                                <option value="Upcoming">Upcoming</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                                <option value="Closed">Closed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Changing status will notify all confirmed attendees.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Record Attendance Modal -->
    <div class="modal fade" id="recordAttendanceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="recordAttendanceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_attendance">
                        <input type="hidden" name="attendee_id" id="recordAttendeeId">
                        
                        <div class="mb-3">
                            <label class="form-label">Attendee</label>
                            <input type="text" class="form-control" id="recordAttendeeName" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Day Number *</label>
                            <input type="number" class="form-control" name="day_number" id="recordDayNumber" min="1" max="<?php echo $event['total_days']; ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attendance Status *</label>
                            <select class="form-select" name="attendance_status" required>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="excused">Excused</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Check In Time</label>
                            <input type="datetime-local" class="form-control" name="check_in_time">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Check Out Time</label>
                            <input type="datetime-local" class="form-control" name="check_out_time">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Attendance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    $(document).ready(function() {
        // Show loading spinner
        function showLoading() {
            $('#loadingSpinner').addClass('active');
        }
        
        function hideLoading() {
            $('#loadingSpinner').removeClass('active');
        }
        
        // Add Attendee Form
        $('#addAttendeeForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#addAttendeeModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to add attendee', 'error');
                }
            });
        });

        // Edit Attendee
        $(document).on('click', '.edit-attendee', function() {
            const attendeeId = $(this).data('id');
            const role = $(this).data('role');
            const rsvp = $(this).data('rsvp');
            
            showLoading();
            
            $.ajax({
                url: window.location.href + '&ajax=get_attendee&id=' + attendeeId,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editAttendeeId').val(response.data.id);
                        $('#editAttendeeName').val(response.data.username);
                        $('#editAttendeeRole').val(role);
                        $('#editAttendeeRSVP').val(rsvp);
                        $('#editAttendeeModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to load attendee details', 'error');
                }
            });
        });

        // Edit Attendee Form
        $('#editAttendeeForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editAttendeeModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update attendee', 'error');
                }
            });
        });

        // Delete Attendee
        $(document).on('click', '.delete-attendee', function() {
            const attendeeId = $(this).data('id');
            const attendeeName = $(this).data('name');
            
            Swal.fire({
                title: 'Remove Attendee?',
                html: `Are you sure you want to remove <strong>${attendeeName}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, remove them!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: { action: 'delete_attendee', id: attendeeId },
                        dataType: 'json',
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Removed!',
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
                            hideLoading();
                            console.error('AJAX Error:', error);
                            Swal.fire('Error', 'Failed to remove attendee', 'error');
                        }
                    });
                }
            });
        });

        // Add Task Form
        $('#addTaskForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#addTaskModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to add task', 'error');
                }
            });
        });

        // Edit Task
        $(document).on('click', '.edit-task', function() {
            const taskId = $(this).data('id');
            
            showLoading();
            
            $.ajax({
                url: window.location.href + '&ajax=get_task&id=' + taskId,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editTaskId').val(response.data.id);
                        $('#editTaskName').val(response.data.task_name);
                        $('#editTaskDescription').val(response.data.description || '');
                        $('#editTaskAssignedTo').val(response.data.assigned_to || '');
                        $('#editTaskDueDate').val(response.data.due_date || '');
                        $('#editTaskPriority').val(response.data.priority || 'Medium');
                        $('#editTaskStatus').val(response.data.status || 'Open');
                        $('#editTaskModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to load task details', 'error');
                }
            });
        });

        // Edit Task Form
        $('#editTaskForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editTaskModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update task', 'error');
                }
            });
        });

        // Delete Task
        $(document).on('click', '.delete-task', function() {
            const taskId = $(this).data('id');
            const taskName = $(this).data('name');
            
            Swal.fire({
                title: 'Delete Task?',
                html: `Are you sure you want to delete "<strong>${taskName}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: { action: 'delete_task', id: taskId },
                        dataType: 'json',
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
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
                            hideLoading();
                            console.error('AJAX Error:', error);
                            Swal.fire('Error', 'Failed to delete task', 'error');
                        }
                    });
                }
            });
        });

        // Update Task Status
        $(document).on('click', '.update-task-status', function() {
            const taskId = $(this).data('id');
            const currentStatus = $(this).data('status');
            
            $('#updateTaskId').val(taskId);
            $('#updateTaskStatus').val(currentStatus);
            $('#updateTaskStatusModal').modal('show');
        });

        // Update Task Status Form
        $('#updateTaskStatusForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#updateTaskStatusModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update task status', 'error');
                }
            });
        });

        // Add Resource Form
        $('#addResourceForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#addResourceModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to add resource', 'error');
                }
            });
        });

        // Edit Resource
        $(document).on('click', '.edit-resource', function() {
            const resourceId = $(this).data('id');
            
            showLoading();
            
            $.ajax({
                url: window.location.href + '&ajax=get_resource&id=' + resourceId,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editResourceId').val(response.data.id);
                        $('#editResourceName').val(response.data.resource_name);
                        $('#editResourceType').val(response.data.resource_type);
                        $('#editResourceQuantity').val(response.data.quantity);
                        $('#editResourceStatus').val(response.data.status);
                        $('#editResourceNotes').val(response.data.notes || '');
                        $('#editResourceModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to load resource details', 'error');
                }
            });
        });

        // Edit Resource Form
        $('#editResourceForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#editResourceModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update resource', 'error');
                }
            });
        });

        // Delete Resource
        $(document).on('click', '.delete-resource', function() {
            const resourceId = $(this).data('id');
            const resourceName = $(this).data('name');
            
            Swal.fire({
                title: 'Delete Resource?',
                html: `Are you sure you want to delete "<strong>${resourceName}</strong>"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: { action: 'delete_resource', id: resourceId },
                        dataType: 'json',
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
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
                            hideLoading();
                            console.error('AJAX Error:', error);
                            Swal.fire('Error', 'Failed to delete resource', 'error');
                        }
                    });
                }
            });
        });

        // Update Resource Status
        $(document).on('click', '.update-resource-status', function() {
            const resourceId = $(this).data('id');
            const currentStatus = $(this).data('status');
            
            $('#updateResourceId').val(resourceId);
            $('#updateResourceStatus').val(currentStatus);
            $('#updateResourceStatusModal').modal('show');
        });

        // Update Resource Status Form
        $('#updateResourceStatusForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#updateResourceStatusModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update resource status', 'error');
                }
            });
        });

        // Update Event Status Form
        $('#updateEventStatusForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#updateEventStatusModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to update event status', 'error');
                }
            });
        });

        // Record Attendance
        window.recordAttendance = function(attendeeId, attendeeName, dayNumber) {
            $('#recordAttendeeId').val(attendeeId);
            $('#recordAttendeeName').val(attendeeName);
            $('#recordDayNumber').val(dayNumber || 1);
            $('#recordAttendanceModal').modal('show');
        };

        $('#recordAttendanceForm').submit(function(e) {
            e.preventDefault();
            showLoading();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        $('#recordAttendanceModal').modal('hide');
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
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to record attendance', 'error');
                }
            });
        });

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
            // Ctrl+A to open add attendee modal
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                $('#addAttendeeModal').modal('show');
            }
            // Ctrl+T to open add task modal
            if (e.ctrlKey && e.key === 't') {
                e.preventDefault();
                $('#addTaskModal').modal('show');
            }
            // Ctrl+R to open add resource modal
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                $('#addResourceModal').modal('show');
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