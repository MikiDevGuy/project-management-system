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
    function sendNotification($conn, $userId, $title, $message, $type = 'info', $module = null, $moduleId = null, $sendEmail = true) {
        $sql = "INSERT INTO notifications (user_id, title, message, type, related_module, related_id, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "issssi", $userId, $title, $message, $type, $module, $moduleId);
        return mysqli_stmt_execute($stmt);
    }
}

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

// Create event_attendance table if it doesn't exist
$createAttendanceTable = "CREATE TABLE IF NOT EXISTS `event_attendance` (
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

mysqli_query($conn, $createAttendanceTable);

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
        case 'edit':
            handleEventSave($conn);
            break;
        case 'delete':
            handleEventDelete($conn);
            break;
        case 'get':
            handleEventGet($conn);
            break;
        case 'update_status':
            handleStatusUpdate($conn);
            break;
        case 'bulk_delete':
            handleBulkDelete($conn);
            break;
        case 'export':
            handleExport($conn);
            break;
        case 'add_attendee':
            handleAddAttendee($conn);
            break;
        case 'bulk_add_attendees':
            handleBulkAddAttendees($conn);
            break;
        case 'update_attendee_status':
            handleUpdateAttendeeStatus($conn);
            break;
        case 'remove_attendee':
            handleRemoveAttendee($conn);
            break;
        case 'record_attendance':
            handleRecordAttendance($conn);
            break;
        case 'get_attendance_report':
            handleGetAttendanceReport($conn);
            break;
        case 'add_task':
            handleAddTask($conn);
            break;
        case 'update_task':
            handleUpdateTask($conn);
            break;
        case 'delete_task':
            handleDeleteTask($conn);
            break;
        case 'add_resource':
            handleAddResource($conn);
            break;
        case 'update_resource':
            handleUpdateResource($conn);
            break;
        case 'delete_resource':
            handleDeleteResource($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Handle event save (AJAX) - Enhanced with full SRS fields
function handleEventSave($conn) {
    $eventId = $_POST['id'] ?? null;
    $isEdit = !empty($eventId);
    
    // Validate required fields
    $required = ['event_name', 'event_type', 'organizer_id', 'start_datetime', 'location'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
            return;
        }
    }
    
    // Validate dates
    $start = new DateTime($_POST['start_datetime']);
    if (!empty($_POST['end_datetime'])) {
        $end = new DateTime($_POST['end_datetime']);
        if ($end <= $start) {
            echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
            return;
        }
    }
    
    // Check permissions based on SRS roles
    if ($isEdit) {
        $checkSql = "SELECT organizer_id, status FROM events WHERE id = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "i", $eventId);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $event = mysqli_fetch_assoc($checkResult);
        
        if (!$event) {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            return;
        }
        
        // SRS: Super Admin – Full control, PM Manager – Create, edit, approve, cancel, PM Employee – Create draft events
        if (!hasAnyRole(['super_admin', 'admin'])) {
            if (hasRole('pm_manager')) {
                // PM Manager can edit any event
            } elseif ($_SESSION['user_id'] != $event['organizer_id']) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this event']);
                return;
            } elseif ($event['status'] != 'Draft' && $event['status'] != 'Planning') {
                echo json_encode(['success' => false, 'message' => 'Only draft events can be edited']);
                return;
            }
        }
    } else {
        // SRS: PM Employee – Create draft events, PM Manager – Create events
        if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to create events']);
            return;
        }
        
        // PM Employees can only create draft events
        if (hasRole('pm_employee') && ($_POST['status'] != 'Draft' && $_POST['status'] != 'Planning')) {
            $_POST['status'] = 'Draft';
        }
    }
    
    // Sanitize inputs
    $eventName = mysqli_real_escape_string($conn, $_POST['event_name']);
    $projectId = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $eventType = mysqli_real_escape_string($conn, $_POST['event_type']);
    $organizerId = intval($_POST['organizer_id']);
    $startDatetime = mysqli_real_escape_string($conn, $_POST['start_datetime']);
    $endDatetime = !empty($_POST['end_datetime']) ? mysqli_real_escape_string($conn, $_POST['end_datetime']) : null;
    $location = mysqli_real_escape_string($conn, $_POST['location']);
    $description = !empty($_POST['description']) ? mysqli_real_escape_string($conn, $_POST['description']) : null;
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Draft');
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
    
    // Calculate total days for multi-day events
    $totalDays = 1;
    if ($endDatetime) {
        $startDate = new DateTime($startDatetime);
        $endDate = new DateTime($endDatetime);
        $interval = $startDate->diff($endDate);
        $totalDays = $interval->days + 1;
    }
    
    if ($isEdit) {
        $oldStatus = $event['status'];
        
        $sql = "UPDATE events SET 
                event_name = ?, project_id = ?, event_type = ?, organizer_id = ?,
                start_datetime = ?, end_datetime = ?, location = ?, description = ?,
                status = ?, priority = ?, total_days = ?
                WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisisssssiii", 
            $eventName, $projectId, $eventType, $organizerId,
            $startDatetime, $endDatetime, $location, $description,
            $status, $priority, $totalDays, $eventId
        );
    } else {
        $sql = "INSERT INTO events (event_name, project_id, event_type, organizer_id, 
                start_datetime, end_datetime, location, description, status, priority, total_days, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisisssssii", 
            $eventName, $projectId, $eventType, $organizerId,
            $startDatetime, $endDatetime, $location, $description,
            $status, $priority, $totalDays
        );
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $savedId = $isEdit ? $eventId : mysqli_insert_id($conn);
        
        // Log activity with old/new values for audit trail
        if ($isEdit) {
            logActivity($conn, $_SESSION['user_id'], "Event updated", 
                "Event '{$eventName}' was updated", 'event', $savedId, 
                json_encode(['status' => $oldStatus]), json_encode(['status' => $status]));
            
            // Check for status change notifications
            if ($oldStatus != $status) {
                // SRS Notification: Event status changes
                $attendeesSql = "SELECT ea.id, u.id as user_id FROM event_attendees ea
                                JOIN users u ON ea.user_id = u.id
                                WHERE ea.event_id = ? AND ea.rsvp_status IN ('Confirmed', 'Registered')";
                $attendeesStmt = mysqli_prepare($conn, $attendeesSql);
                mysqli_stmt_bind_param($attendeesStmt, "i", $savedId);
                mysqli_stmt_execute($attendeesStmt);
                $attendeesResult = mysqli_stmt_get_result($attendeesStmt);
                
                while ($attendee = mysqli_fetch_assoc($attendeesResult)) {
                    sendNotification($conn, $attendee['user_id'], "Event Status Changed", 
                        "Event '{$eventName}' status changed from {$oldStatus} to {$status}", 
                        'info', 'event', $savedId, true);
                }
                
                // Notify PM Manager about approval requests
                if ($status == 'Submitted') {
                    $managerSql = "SELECT id FROM users WHERE system_role IN ('pm_manager', 'super_admin', 'admin')";
                    $managerResult = mysqli_query($conn, $managerSql);
                    while ($manager = mysqli_fetch_assoc($managerResult)) {
                        sendNotification($conn, $manager['id'], "Event Approval Required", 
                            "Event '{$eventName}' has been submitted for approval", 
                            'warning', 'event', $savedId, true);
                    }
                }
            }
        } else {
            logActivity($conn, $_SESSION['user_id'], "Event created", 
                "Event '{$eventName}' was created", 'event', $savedId);
            
            // SRS Notification: Event is created
            sendNotification($conn, $organizerId, 'New Event Created', 
                "You have created a new event: {$eventName}", 'success', 'event', $savedId, true);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $isEdit ? 'Event updated successfully' : 'Event created successfully',
            'id' => $savedId
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle bulk add attendees (SRS Section 6)
function handleBulkAddAttendees($conn) {
    $eventId = intval($_POST['event_id'] ?? 0);
    $userIds = $_POST['user_ids'] ?? [];
    $role = mysqli_real_escape_string($conn, $_POST['role'] ?? 'Participant');
    
    if (!$eventId || empty($userIds)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // Get event details for notifications
    $eventSql = "SELECT event_name, total_days, start_datetime, end_datetime FROM events WHERE id = ?";
    $eventStmt = mysqli_prepare($conn, $eventSql);
    mysqli_stmt_bind_param($eventStmt, "i", $eventId);
    mysqli_stmt_execute($eventStmt);
    $eventResult = mysqli_stmt_get_result($eventStmt);
    $event = mysqli_fetch_assoc($eventResult);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        return;
    }
    
    $added = 0;
    $existing = 0;
    $failed = 0;
    
    mysqli_begin_transaction($conn);
    
    try {
        foreach ($userIds as $userId) {
            $userId = intval($userId);
            
            // Check if already registered
            $checkSql = "SELECT id FROM event_attendees WHERE event_id = ? AND user_id = ?";
            $checkStmt = mysqli_prepare($conn, $checkSql);
            mysqli_stmt_bind_param($checkStmt, "ii", $eventId, $userId);
            mysqli_stmt_execute($checkStmt);
            $checkResult = mysqli_stmt_get_result($checkStmt);
            
            if (mysqli_num_rows($checkResult) > 0) {
                $existing++;
                continue;
            }
            
            // Insert attendee with status "Invited" per SRS
            $sql = "INSERT INTO event_attendees (event_id, user_id, role_in_event, rsvp_status, created_at) 
                    VALUES (?, ?, ?, 'Invited', NOW())";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iis", $eventId, $userId, $role);
            
            if (mysqli_stmt_execute($stmt)) {
                $attendeeId = mysqli_insert_id($conn);
                $added++;
                
                // Create attendance records for multi-day events
                if ($event['total_days'] > 1) {
                    $startDate = new DateTime($event['start_datetime']);
                    for ($day = 1; $day <= $event['total_days']; $day++) {
                        $dayDate = clone $startDate;
                        $dayDate->modify('+' . ($day - 1) . ' days');
                        
                        $attSql = "INSERT INTO event_attendance (event_id, attendee_id, day_number, attendance_status, created_at)
                                  VALUES (?, ?, ?, 'not_recorded', NOW())";
                        $attStmt = mysqli_prepare($conn, $attSql);
                        mysqli_stmt_bind_param($attStmt, "iii", $eventId, $attendeeId, $day);
                        mysqli_stmt_execute($attStmt);
                    }
                }
                
                // Send invitation email
                $userSql = "SELECT email, username FROM users WHERE id = ?";
                $userStmt = mysqli_prepare($conn, $userSql);
                mysqli_stmt_bind_param($userStmt, "i", $userId);
                mysqli_stmt_execute($userStmt);
                $userResult = mysqli_stmt_get_result($userStmt);
                $user = mysqli_fetch_assoc($userResult);
                
                if ($user) {
                    // In a real system, you would send actual emails here
                    // For now, we'll log it
                    error_log("Email would be sent to: {$user['email']} for event: {$event['event_name']}");
                }
                
                // In-app notification
                sendNotification($conn, $userId, "Event Invitation", 
                    "You have been invited to: {$event['event_name']}", 'info', 'event', $eventId, false);
            } else {
                $failed++;
            }
        }
        
        mysqli_commit($conn);
        
        logActivity($conn, $_SESSION['user_id'], "Bulk attendees added", 
            "Added {$added} attendees to event ID {$eventId}", 'event', $eventId);
        
        echo json_encode([
            'success' => true,
            'message' => "Added {$added} attendees, {$existing} already registered, {$failed} failed"
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// Handle attendance recording (SRS Section 7)
function handleRecordAttendance($conn) {
    $eventId = intval($_POST['event_id'] ?? 0);
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $dayNumber = intval($_POST['day_number'] ?? 1);
    $status = mysqli_real_escape_string($conn, $_POST['attendance_status'] ?? 'present');
    $checkIn = !empty($_POST['check_in_time']) ? $_POST['check_in_time'] : null;
    $checkOut = !empty($_POST['check_out_time']) ? $_POST['check_out_time'] : null;
    
    if (!$eventId || !$attendeeId || !$dayNumber) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // Get event total days
    $eventSql = "SELECT event_name, total_days FROM events WHERE id = ?";
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
    
    // Check if attendance already recorded for this day
    $checkSql = "SELECT id FROM event_attendance WHERE event_id = ? AND attendee_id = ? AND day_number = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "iii", $eventId, $attendeeId, $dayNumber);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    
    if (mysqli_num_rows($checkResult) > 0) {
        // Update existing
        $row = mysqli_fetch_assoc($checkResult);
        $sql = "UPDATE event_attendance SET 
                attendance_status = ?, check_in_time = ?, check_out_time = ?, recorded_by = ?
                WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssii", $status, $checkIn, $checkOut, $_SESSION['user_id'], $row['id']);
    } else {
        // Insert new
        $sql = "INSERT INTO event_attendance (event_id, attendee_id, day_number, attendance_status, check_in_time, check_out_time, recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiisssi", $eventId, $attendeeId, $dayNumber, $status, $checkIn, $checkOut, $_SESSION['user_id']);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        // Calculate attendance rate and update attendee status if needed
        $attendanceSql = "SELECT * FROM event_attendance WHERE event_id = ? AND attendee_id = ?";
        $attendanceStmt = mysqli_prepare($conn, $attendanceSql);
        mysqli_stmt_bind_param($attendanceStmt, "ii", $eventId, $attendeeId);
        mysqli_stmt_execute($attendanceStmt);
        $attendanceResult = mysqli_stmt_get_result($attendanceStmt);
        
        $attendances = [];
        while ($row = mysqli_fetch_assoc($attendanceResult)) {
            $attendances[] = $row;
        }
        
        $presentCount = 0;
        foreach ($attendances as $a) {
            if ($a['attendance_status'] == 'present') {
                $presentCount++;
            }
        }
        
        $rate = ($event['total_days'] > 0) ? round(($presentCount / $event['total_days']) * 100, 2) : 0;
        
        // Determine final status based on attendance rate
        if ($rate >= 90) {
            $finalStatus = 'Attended';
        } elseif ($rate > 0) {
            $finalStatus = 'Partial Attended';
        } else {
            $finalStatus = 'No-Show';
        }
        
        $updateSql = "UPDATE event_attendees SET attendance_rate = ?, final_status = ? WHERE event_id = ? AND user_id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "dsii", $rate, $finalStatus, $eventId, $attendeeId);
        mysqli_stmt_execute($updateStmt);
        
        logActivity($conn, $_SESSION['user_id'], "Attendance recorded", 
            "Recorded attendance for day {$dayNumber} of event ID {$eventId}", 'event', $eventId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance recorded successfully',
            'attendance_rate' => $rate,
            'classification' => $finalStatus
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle get attendance report
function handleGetAttendanceReport($conn) {
    $eventId = intval($_GET['event_id'] ?? 0);
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Event ID required']);
        return;
    }
    
    // Get event details
    $eventSql = "SELECT event_name, total_days FROM events WHERE id = ?";
    $eventStmt = mysqli_prepare($conn, $eventSql);
    mysqli_stmt_bind_param($eventStmt, "i", $eventId);
    mysqli_stmt_execute($eventStmt);
    $eventResult = mysqli_stmt_get_result($eventStmt);
    $event = mysqli_fetch_assoc($eventResult);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        return;
    }
    
    // Get all attendees with their attendance records
    $sql = "SELECT ea.id, u.id as user_id, u.username, u.email, ea.rsvp_status, ea.attendance_rate, ea.final_status,
                   GROUP_CONCAT(CONCAT(att.day_number, ':', att.attendance_status) ORDER BY att.day_number SEPARATOR '|') as daily_attendance
            FROM event_attendees ea
            JOIN users u ON ea.user_id = u.id
            LEFT JOIN event_attendance att ON ea.id = att.attendee_id AND ea.event_id = att.event_id
            WHERE ea.event_id = ?
            GROUP BY ea.id, u.id, u.username, u.email, ea.rsvp_status, ea.attendance_rate, ea.final_status";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $eventId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $attendees = [];
    $summary = [
        'total_attendees' => 0,
        'full_attendance' => 0,
        'partial_attendance' => 0,
        'absent' => 0,
        'no_show' => 0
    ];
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Parse daily attendance
        $daily = [];
        if ($row['daily_attendance']) {
            $parts = explode('|', $row['daily_attendance']);
            foreach ($parts as $part) {
                list($day, $status) = explode(':', $part);
                $daily[$day] = $status;
            }
        }
        $row['daily_attendance'] = $daily;
        
        // Build complete attendance array for all days
        $row['attendance_by_day'] = [];
        for ($day = 1; $day <= $event['total_days']; $day++) {
            $row['attendance_by_day'][$day] = $daily[$day] ?? 'not_recorded';
        }
        
        $attendees[] = $row;
        
        // Update summary
        $summary['total_attendees']++;
        if ($row['final_status'] == 'Attended') $summary['full_attendance']++;
        elseif ($row['final_status'] == 'Partial Attended') $summary['partial_attendance']++;
        elseif ($row['final_status'] == 'No-Show') $summary['no_show']++;
        else $summary['absent']++;
    }
    
    echo json_encode([
        'success' => true,
        'event' => $event,
        'attendees' => $attendees,
        'summary' => $summary
    ]);
}

// Handle add task (SRS Section 8)
function handleAddTask($conn) {
    $eventId = intval($_POST['event_id'] ?? 0);
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $assignedTo = intval($_POST['assigned_to'] ?? 0);
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
    
    if (!$eventId || empty($title) || !$assignedTo) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // SRS Task Status: Open → Assigned → In Progress → Completed → Verified → Closed
    $sql = "INSERT INTO event_tasks (event_id, task_name, description, assigned_to, due_date, priority, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Open', ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ississi", $eventId, $title, $description, $assignedTo, $dueDate, $priority, $_SESSION['user_id']);
    
    if (mysqli_stmt_execute($stmt)) {
        $taskId = mysqli_insert_id($conn);
        
        // SRS Notification: Task is assigned
        sendNotification($conn, $assignedTo, "Task Assigned", 
            "You have been assigned a task: {$title}", 'info', 'event_task', $taskId, true);
        
        logActivity($conn, $_SESSION['user_id'], "Task added", 
            "Added task '{$title}' to event ID {$eventId}", 'event', $eventId);
        
        echo json_encode(['success' => true, 'message' => 'Task added successfully', 'id' => $taskId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle update task
function handleUpdateTask($conn) {
    $taskId = intval($_POST['task_id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $assignedTo = intval($_POST['assigned_to'] ?? 0);
    $dueDate = !empty($_POST['due_date']) ? mysqli_real_escape_string($conn, $_POST['due_date']) : null;
    $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
    
    if (!$taskId) {
        echo json_encode(['success' => false, 'message' => 'Task ID required']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // Get current task for status change tracking
    $currentSql = "SELECT * FROM event_tasks WHERE id = ?";
    $currentStmt = mysqli_prepare($conn, $currentSql);
    mysqli_stmt_bind_param($currentStmt, "i", $taskId);
    mysqli_stmt_execute($currentStmt);
    $currentResult = mysqli_stmt_get_result($currentStmt);
    $current = mysqli_fetch_assoc($currentResult);
    
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        return;
    }
    
    $sql = "UPDATE event_tasks SET 
            task_name = ?, description = ?, assigned_to = ?, due_date = ?, priority = ?, status = ?
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssisssi", $title, $description, $assignedTo, $dueDate, $priority, $status, $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Check for status change
        if ($current['status'] != $status) {
            sendNotification($conn, $assignedTo, "Task Status Updated", 
                "Task '{$title}' status changed from {$current['status']} to {$status}", 
                'info', 'event_task', $taskId, true);
        }
        
        logActivity($conn, $_SESSION['user_id'], "Task updated", 
            "Updated task ID {$taskId}", 'event_task', $taskId);
        
        echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle delete task
function handleDeleteTask($conn) {
    $taskId = intval($_POST['task_id'] ?? 0);
    
    if (!$taskId) {
        echo json_encode(['success' => false, 'message' => 'Task ID required']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $sql = "DELETE FROM event_tasks WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $taskId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], "Task deleted", 
            "Deleted task ID {$taskId}", 'event_task', $taskId);
        
        echo json_encode(['success' => true, 'message' => 'Task deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle add resource (SRS Section 9)
function handleAddResource($conn) {
    $eventId = intval($_POST['event_id'] ?? 0);
    $resourceName = mysqli_real_escape_string($conn, $_POST['resource_name'] ?? '');
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if (!$eventId || empty($resourceName)) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // SRS Resource Status: Requested → Approved → Allocated → In Use → Released
    $initialStatus = 'Requested';
    
    $sql = "INSERT INTO event_resources (event_id, resource_name, resource_type, quantity, status, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ississi", $eventId, $resourceName, $resourceType, $quantity, $initialStatus, $notes, $_SESSION['user_id']);
    
    if (mysqli_stmt_execute($stmt)) {
        $resourceId = mysqli_insert_id($conn);
        
        // Notify PM Manager about resource request
        if (hasRole('pm_employee')) {
            $managerSql = "SELECT id FROM users WHERE system_role IN ('pm_manager', 'super_admin', 'admin')";
            $managerResult = mysqli_query($conn, $managerSql);
            while ($manager = mysqli_fetch_assoc($managerResult)) {
                sendNotification($conn, $manager['id'], "Resource Request", 
                    "Resource '{$resourceName}' has been requested for event", 
                    'warning', 'event_resource', $resourceId, true);
            }
        }
        
        logActivity($conn, $_SESSION['user_id'], "Resource added", 
            "Added resource '{$resourceName}' to event ID {$eventId}", 'event', $eventId);
        
        echo json_encode(['success' => true, 'message' => 'Resource requested successfully', 'id' => $resourceId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle update resource (for approval workflow)
function handleUpdateResource($conn) {
    $resourceId = intval($_POST['resource_id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    $resourceName = mysqli_real_escape_string($conn, $_POST['resource_name'] ?? '');
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $quantity = intval($_POST['quantity'] ?? 1);
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if (!$resourceId) {
        echo json_encode(['success' => false, 'message' => 'Resource ID required']);
        return;
    }
    
    // Check permissions - only managers can approve resources
    if (in_array($status, ['Approved', 'Allocated', 'In Use'])) {
        if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
            echo json_encode(['success' => false, 'message' => 'Only managers can update resource status']);
            return;
        }
    }
    
    $sql = "UPDATE event_resources SET 
            resource_name = ?, resource_type = ?, quantity = ?, status = ?, notes = ?
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ssissi", $resourceName, $resourceType, $quantity, $status, $notes, $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], "Resource updated", 
            "Updated resource ID {$resourceId} to status {$status}", 'event_resource', $resourceId);
        
        echo json_encode(['success' => true, 'message' => 'Resource updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle delete resource
function handleDeleteResource($conn) {
    $resourceId = intval($_POST['resource_id'] ?? 0);
    
    if (!$resourceId) {
        echo json_encode(['success' => false, 'message' => 'Resource ID required']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $sql = "DELETE FROM event_resources WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], "Resource deleted", 
            "Deleted resource ID {$resourceId}", 'event_resource', $resourceId);
        
        echo json_encode(['success' => true, 'message' => 'Resource deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle add attendee
function handleAddAttendee($conn) {
    $eventId = intval($_POST['event_id'] ?? 0);
    $userId = intval($_POST['user_id'] ?? 0);
    $role = mysqli_real_escape_string($conn, $_POST['role'] ?? 'Participant');
    
    if (!$eventId || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // Check if already registered
    $checkSql = "SELECT id FROM event_attendees WHERE event_id = ? AND user_id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "ii", $eventId, $userId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    
    if (mysqli_num_rows($checkResult) > 0) {
        echo json_encode(['success' => false, 'message' => 'User already registered for this event']);
        return;
    }
    
    // Get event details
    $eventSql = "SELECT event_name, total_days, start_datetime FROM events WHERE id = ?";
    $eventStmt = mysqli_prepare($conn, $eventSql);
    mysqli_stmt_bind_param($eventStmt, "i", $eventId);
    mysqli_stmt_execute($eventStmt);
    $eventResult = mysqli_stmt_get_result($eventStmt);
    $event = mysqli_fetch_assoc($eventResult);
    
    // Insert attendee with status "Invited" per SRS
    $sql = "INSERT INTO event_attendees (event_id, user_id, role_in_event, rsvp_status, created_at) 
            VALUES (?, ?, ?, 'Invited', NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iis", $eventId, $userId, $role);
    
    if (mysqli_stmt_execute($stmt)) {
        $attendeeId = mysqli_insert_id($conn);
        
        // Create attendance records for multi-day events
        if ($event && $event['total_days'] > 1) {
            $startDate = new DateTime($event['start_datetime']);
            for ($day = 1; $day <= $event['total_days']; $day++) {
                $dayDate = clone $startDate;
                $dayDate->modify('+' . ($day - 1) . ' days');
                
                $attSql = "INSERT INTO event_attendance (event_id, attendee_id, day_number, attendance_status, created_at)
                          VALUES (?, ?, ?, 'not_recorded', NOW())";
                $attStmt = mysqli_prepare($conn, $attSql);
                mysqli_stmt_bind_param($attStmt, "iii", $eventId, $attendeeId, $day);
                mysqli_stmt_execute($attStmt);
            }
        }
        
        // Get user info for logging
        $userSql = "SELECT username, email FROM users WHERE id = ?";
        $userStmt = mysqli_prepare($conn, $userSql);
        mysqli_stmt_bind_param($userStmt, "i", $userId);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        $user = mysqli_fetch_assoc($userResult);
        
        // In a real system, send invitation email here
        error_log("Invitation email would be sent to: {$user['email']} for event: {$event['event_name']}");
        
        sendNotification($conn, $userId, "Event Invitation", 
            "You have been invited to: {$event['event_name']}", 'info', 'event', $eventId, false);
        
        logActivity($conn, $_SESSION['user_id'], "Attendee added", 
            "Added user {$user['username']} to event ID {$eventId}", 'event', $eventId);
        
        echo json_encode(['success' => true, 'message' => 'Attendee added successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle update attendee status (SRS Attendee Status Flow)
function handleUpdateAttendeeStatus($conn) {
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    $validStatuses = ['Invited', 'Registered', 'Confirmed', 'Attended', 'Cancelled', 'No-Show'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    // Get current status for audit
    $currentSql = "SELECT ea.*, e.event_name, u.id as user_id, u.username, u.email 
                   FROM event_attendees ea
                   JOIN events e ON ea.event_id = e.id
                   JOIN users u ON ea.user_id = u.id
                   WHERE ea.id = ?";
    $currentStmt = mysqli_prepare($conn, $currentSql);
    mysqli_stmt_bind_param($currentStmt, "i", $attendeeId);
    mysqli_stmt_execute($currentStmt);
    $currentResult = mysqli_stmt_get_result($currentStmt);
    $current = mysqli_fetch_assoc($currentResult);
    
    if (!$current) {
        echo json_encode(['success' => false, 'message' => 'Attendee not found']);
        return;
    }
    
    $oldStatus = $current['rsvp_status'];
    
    $sql = "UPDATE event_attendees SET rsvp_status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $status, $attendeeId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log with old/new values for audit
        logActivity($conn, $_SESSION['user_id'], "Attendee status updated", 
            "Attendee status changed from {$oldStatus} to {$status}", 
            'event_attendee', $attendeeId, $oldStatus, $status);
        
        // Send notification based on status change
        if ($status == 'Confirmed') {
            sendNotification($conn, $current['user_id'], "Attendance Confirmed", 
                "Your attendance for '{$current['event_name']}' has been confirmed", 
                'success', 'event', $current['event_id'], true);
        } elseif ($status == 'Cancelled') {
            sendNotification($conn, $current['user_id'], "Attendance Cancelled", 
                "Your attendance for '{$current['event_name']}' has been cancelled", 
                'warning', 'event', $current['event_id'], true);
        }
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle remove attendee
function handleRemoveAttendee($conn) {
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        return;
    }
    
    // Check permissions
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    // Get attendee info for logging
    $infoSql = "SELECT ea.*, e.event_name, u.username FROM event_attendees ea 
                JOIN events e ON ea.event_id = e.id 
                JOIN users u ON ea.user_id = u.id
                WHERE ea.id = ?";
    $infoStmt = mysqli_prepare($conn, $infoSql);
    mysqli_stmt_bind_param($infoStmt, "i", $attendeeId);
    mysqli_stmt_execute($infoStmt);
    $infoResult = mysqli_stmt_get_result($infoStmt);
    $info = mysqli_fetch_assoc($infoResult);
    
    // Delete attendance records first
    $delAttSql = "DELETE FROM event_attendance WHERE attendee_id = ?";
    $delAttStmt = mysqli_prepare($conn, $delAttSql);
    mysqli_stmt_bind_param($delAttStmt, "i", $attendeeId);
    mysqli_stmt_execute($delAttStmt);
    
    // Delete attendee
    $sql = "DELETE FROM event_attendees WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $attendeeId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], "Attendee removed", 
            "Removed attendee {$info['username']} from event '{$info['event_name']}'", 'event', $info['event_id']);
        
        echo json_encode(['success' => true, 'message' => 'Attendee removed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle event delete (AJAX)
function handleEventDelete($conn) {
    $eventId = intval($_POST['id'] ?? 0);
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
        return;
    }
    
    $checkSql = "SELECT event_name, organizer_id FROM events WHERE id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $eventId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $event = mysqli_fetch_assoc($checkResult);
    
    if (!$event) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        return;
    }
    
    // SRS: Super Admin – Full system control including deletion
    if (!hasAnyRole(['super_admin', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Only administrators can delete events']);
        return;
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Delete in correct order due to foreign keys
        mysqli_query($conn, "DELETE FROM event_attendance WHERE event_id = {$eventId}");
        mysqli_query($conn, "DELETE FROM event_attendees WHERE event_id = {$eventId}");
        mysqli_query($conn, "DELETE FROM event_tasks WHERE event_id = {$eventId}");
        mysqli_query($conn, "DELETE FROM event_resources WHERE event_id = {$eventId}");
        
        $sql = "DELETE FROM events WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_commit($conn);
            logActivity($conn, $_SESSION['user_id'], "Event deleted", 
                "Event '{$event['event_name']}' was deleted", 'event', $eventId);
            
            // Notify about deletion
            sendNotification($conn, $event['organizer_id'], "Event Deleted", 
                "Event '{$event['event_name']}' has been deleted", 'danger', 'event', $eventId, true);
            
            echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
        } else {
            throw new Exception(mysqli_error($conn));
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Error deleting event: ' . $e->getMessage()]);
    }
}

// Handle get single event (AJAX)
function handleEventGet($conn) {
    $eventId = intval($_GET['id'] ?? 0);
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
        return;
    }
    
    $sql = "SELECT e.*, p.name as project_name, u.username as organizer_name 
            FROM events e 
            LEFT JOIN projects p ON e.project_id = p.id 
            LEFT JOIN users u ON e.organizer_id = u.id 
            WHERE e.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $eventId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $event = mysqli_fetch_assoc($result);
    
    if ($event) {
        echo json_encode(['success' => true, 'data' => $event]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
    }
}

// Handle status update (AJAX) - SRS Event Status Workflow
function handleStatusUpdate($conn) {
    $eventId = intval($_POST['id'] ?? 0);
    $newStatus = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    // SRS Event Status Workflow: Draft → Submitted → Approved → Ongoing → Completed → Closed
    // Alternative: Rejected or Cancelled
    $validStatuses = ['Draft', 'Submitted', 'Approved', 'Ongoing', 'Completed', 'Closed', 'Rejected', 'Cancelled', 'Planning', 'Upcoming'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
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
    
    // Check permissions based on SRS
    if (!hasAnyRole(['super_admin', 'admin'])) {
        if (hasRole('pm_manager')) {
            // PM Manager can approve, cancel events
            if (!in_array($newStatus, ['Approved', 'Cancelled', 'Rejected'])) {
                echo json_encode(['success' => false, 'message' => 'PM Managers can only approve, reject, or cancel events']);
                return;
            }
        } elseif (hasRole('pm_employee')) {
            // PM Employees can only submit for approval
            if ($newStatus != 'Submitted') {
                echo json_encode(['success' => false, 'message' => 'PM Employees can only submit events for approval']);
                return;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Permission denied']);
            return;
        }
    }
    
    $oldStatus = $event['status'];
    
    $sql = "UPDATE events SET status = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $newStatus, $eventId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log status change with audit trail
        logActivity($conn, $_SESSION['user_id'], "Event status updated", 
            "Event '{$event['event_name']}' status changed from {$oldStatus} to {$newStatus}", 
            'event', $eventId, $oldStatus, $newStatus);
        
        // SRS Notification: Event status changes
        $attendeesSql = "SELECT ea.id, u.id as user_id FROM event_attendees ea
                        JOIN users u ON ea.user_id = u.id
                        WHERE ea.event_id = ? AND ea.rsvp_status IN ('Confirmed', 'Registered')";
        $attendeesStmt = mysqli_prepare($conn, $attendeesSql);
        mysqli_stmt_bind_param($attendeesStmt, "i", $eventId);
        mysqli_stmt_execute($attendeesStmt);
        $attendeesResult = mysqli_stmt_get_result($attendeesStmt);
        
        while ($attendee = mysqli_fetch_assoc($attendeesResult)) {
            sendNotification($conn, $attendee['user_id'], "Event Status Changed", 
                "Event '{$event['event_name']}' status changed from {$oldStatus} to {$newStatus}", 
                'info', 'event', $eventId, true);
        }
        
        // Special notifications for certain statuses
        if ($newStatus == 'Approved') {
            // SRS Notification: Event is approved
            sendNotification($conn, $event['organizer_id'], "Event Approved", 
                "Your event '{$event['event_name']}' has been approved", 'success', 'event', $eventId, true);
        } elseif ($newStatus == 'Rejected') {
            sendNotification($conn, $event['organizer_id'], "Event Rejected", 
                "Your event '{$event['event_name']}' has been rejected", 'danger', 'event', $eventId, true);
        } elseif ($newStatus == 'Cancelled') {
            // SRS Notification: Event is cancelled
            sendNotification($conn, $event['organizer_id'], "Event Cancelled", 
                "Your event '{$event['event_name']}' has been cancelled", 'warning', 'event', $eventId, true);
        } elseif ($newStatus == 'Completed') {
            sendNotification($conn, $event['organizer_id'], "Event Completed", 
                "Event '{$event['event_name']}' has been marked as completed", 'success', 'event', $eventId, true);
        }
        
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
}

// Handle bulk delete (AJAX)
function handleBulkDelete($conn) {
    $eventIds = $_POST['ids'] ?? [];
    
    if (empty($eventIds) || !is_array($eventIds)) {
        echo json_encode(['success' => false, 'message' => 'No events selected']);
        return;
    }
    
    // SRS: Super Admin – Full system control including deletion
    if (!hasAnyRole(['super_admin', 'admin'])) {
        echo json_encode(['success' => false, 'message' => 'Only administrators can perform bulk delete']);
        return;
    }
    
    $ids = array_map('intval', $eventIds);
    $idList = implode(',', $ids);
    
    mysqli_begin_transaction($conn);
    
    try {
        // Delete in correct order due to foreign keys
        mysqli_query($conn, "DELETE FROM event_attendance WHERE event_id IN ({$idList})");
        mysqli_query($conn, "DELETE FROM event_attendees WHERE event_id IN ({$idList})");
        mysqli_query($conn, "DELETE FROM event_tasks WHERE event_id IN ({$idList})");
        mysqli_query($conn, "DELETE FROM event_resources WHERE event_id IN ({$idList})");
        
        $sql = "DELETE FROM events WHERE id IN ({$idList})";
        if (mysqli_query($conn, $sql)) {
            mysqli_commit($conn);
            logActivity($conn, $_SESSION['user_id'], "Bulk delete", count($ids) . " events were deleted", 'event', 0);
            echo json_encode(['success' => true, 'message' => count($ids) . ' events deleted successfully']);
        } else {
            throw new Exception(mysqli_error($conn));
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => 'Error deleting events: ' . $e->getMessage()]);
    }
}

// Handle export (AJAX) - SRS Non-Functional Requirements: Export to Excel or PDF
function handleExport($conn) {
    $format = $_GET['format'] ?? 'csv';
    $statusFilter = $_GET['status'] ?? '';
    $projectFilter = $_GET['project'] ?? '';
    $typeFilter = $_GET['type'] ?? '';
    
    $whereClauses = [];
    $params = [];
    $types = '';
    
    if (!empty($statusFilter)) {
        $whereClauses[] = "e.status = ?";
        $params[] = $statusFilter;
        $types .= 's';
    }
    
    if (!empty($projectFilter)) {
        $whereClauses[] = "e.project_id = ?";
        $params[] = $projectFilter;
        $types .= 'i';
    }
    
    if (!empty($typeFilter)) {
        $whereClauses[] = "e.event_type = ?";
        $params[] = $typeFilter;
        $types .= 's';
    }
    
    $whereClause = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);
    
    $sql = "SELECT e.*, p.name as project_name, u.username as organizer_name,
            (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendee_count,
            (SELECT COUNT(*) FROM event_tasks WHERE event_id = e.id) as task_count,
            (SELECT COUNT(*) FROM event_resources WHERE event_id = e.id) as resource_count
            FROM events e 
            LEFT JOIN projects p ON e.project_id = p.id 
            LEFT JOIN users u ON e.organizer_id = u.id 
            {$whereClause}
            ORDER BY e.start_datetime DESC";
    
    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, $sql);
    }
    
    $events = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $events[] = $row;
    }
    
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="events_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Event Name', 'Project', 'Type', 'Start Date', 'End Date', 'Location', 
                          'Status', 'Priority', 'Organizer', 'Attendees', 'Tasks', 'Resources', 'Description']);
        
        foreach ($events as $event) {
            fputcsv($output, [
                $event['event_name'],
                $event['project_name'] ?? 'N/A',
                $event['event_type'],
                $event['start_datetime'],
                $event['end_datetime'] ?? 'N/A',
                $event['location'],
                $event['status'],
                $event['priority'],
                $event['organizer_name'],
                $event['attendee_count'] ?? 0,
                $event['task_count'] ?? 0,
                $event['resource_count'] ?? 0,
                $event['description'] ?? ''
            ]);
        }
        
        fclose($output);
        exit();
    } elseif ($format == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="events_export_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th>Event Name</th><th>Project</th><th>Type</th><th>Start Date</th><th>End Date</th>";
        echo "<th>Location</th><th>Status</th><th>Priority</th><th>Organizer</th><th>Attendees</th>";
        echo "<th>Tasks</th><th>Resources</th><th>Description</th></tr>";
        
        foreach ($events as $event) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($event['event_name']) . "</td>";
            echo "<td>" . htmlspecialchars($event['project_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($event['event_type']) . "</td>";
            echo "<td>" . htmlspecialchars($event['start_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($event['end_datetime'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($event['location']) . "</td>";
            echo "<td>" . htmlspecialchars($event['status']) . "</td>";
            echo "<td>" . htmlspecialchars($event['priority']) . "</td>";
            echo "<td>" . htmlspecialchars($event['organizer_name']) . "</td>";
            echo "<td>" . ($event['attendee_count'] ?? 0) . "</td>";
            echo "<td>" . ($event['task_count'] ?? 0) . "</td>";
            echo "<td>" . ($event['resource_count'] ?? 0) . "</td>";
            echo "<td>" . htmlspecialchars($event['description'] ?? '') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        exit();
    } elseif ($format == 'pdf') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'PDF export requires additional library', 'data' => $events]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid export format']);
        exit();
    }
}

// Get filters from URL
$statusFilter = $_GET['status'] ?? '';
$projectFilter = $_GET['project'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$searchQuery = $_GET['search'] ?? '';

$whereClauses = [];
$params = [];
$types = '';

if (!empty($statusFilter)) {
    $whereClauses[] = "e.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if (!empty($projectFilter)) {
    $whereClauses[] = "e.project_id = ?";
    $params[] = $projectFilter;
    $types .= 'i';
}

if (!empty($typeFilter)) {
    $whereClauses[] = "e.event_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

if (!empty($searchQuery)) {
    $whereClauses[] = "(e.event_name LIKE ? OR e.location LIKE ? OR e.description LIKE ?)";
    $searchTerm = "%{$searchQuery}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$whereClause = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$sql = "SELECT e.*, p.name as project_name, u.username as organizer_name,
        (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendee_count,
        (SELECT COUNT(*) FROM event_tasks WHERE event_id = e.id) as task_count,
        (SELECT COUNT(*) FROM event_resources WHERE event_id = e.id) as resource_count
        FROM events e 
        LEFT JOIN projects p ON e.project_id = p.id 
        LEFT JOIN users u ON e.organizer_id = u.id 
        {$whereClause}
        ORDER BY 
            CASE 
                WHEN e.status = 'Ongoing' THEN 1
                WHEN e.status = 'Approved' THEN 2
                WHEN e.status = 'Upcoming' THEN 3
                WHEN e.status = 'Submitted' THEN 4
                WHEN e.status = 'Draft' THEN 5
                WHEN e.status = 'Planning' THEN 6
                WHEN e.status = 'Completed' THEN 7
                WHEN e.status = 'Closed' THEN 8
                WHEN e.status = 'Cancelled' THEN 9
                WHEN e.status = 'Rejected' THEN 10
                ELSE 11
            END,
            e.start_datetime ASC";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $sql);
}

$events = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $events[] = $row;
    }
}

// Get projects for dropdown
$projects = [];
$sql = "SELECT id, name FROM projects ORDER BY name";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $projects[] = $row;
    }
}

// Get organizers for dropdown
$organizers = [];
$sql = "SELECT id, username FROM users WHERE system_role IN ('super_admin', 'admin', 'pm_manager', 'pm_employee') ORDER BY username";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $organizers[] = $row;
    }
}

// Get users for bulk attendee addition
$users = [];
$sql = "SELECT id, username, email FROM users WHERE is_active = 1 ORDER BY username";
$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
}

// Calculate statistics
$stats = [];
$sql = "SELECT COUNT(*) as total FROM events";
$result = mysqli_query($conn, $sql);
$stats['total'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;

$sql = "SELECT COUNT(*) as draft FROM events WHERE status = 'Draft'";
$result = mysqli_query($conn, $sql);
$stats['draft'] = $result ? mysqli_fetch_assoc($result)['draft'] : 0;

$sql = "SELECT COUNT(*) as submitted FROM events WHERE status = 'Submitted'";
$result = mysqli_query($conn, $sql);
$stats['submitted'] = $result ? mysqli_fetch_assoc($result)['submitted'] : 0;

$sql = "SELECT COUNT(*) as approved FROM events WHERE status = 'Approved'";
$result = mysqli_query($conn, $sql);
$stats['approved'] = $result ? mysqli_fetch_assoc($result)['approved'] : 0;

$sql = "SELECT COUNT(*) as ongoing FROM events WHERE status = 'Ongoing'";
$result = mysqli_query($conn, $sql);
$stats['ongoing'] = $result ? mysqli_fetch_assoc($result)['ongoing'] : 0;

$sql = "SELECT COUNT(*) as completed FROM events WHERE status = 'Completed'";
$result = mysqli_query($conn, $sql);
$stats['completed'] = $result ? mysqli_fetch_assoc($result)['completed'] : 0;

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#8B1E3F',
    'secondary' => '#4A0E21',
    'accent' => '#C49A6C'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;

// Helper function for role checking
function hasAnyRole($roles) {
    if (!isset($_SESSION['system_role'])) return false;
    return in_array($_SESSION['system_role'], (array)$roles);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - Dashen Bank BSPM</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --dashen-primary: <?php echo $custom_colors['primary']; ?>;
            --dashen-primary-light: <?php echo $custom_colors['primary'] . '20'; ?>;
            --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
            --dashen-accent: <?php echo $custom_colors['accent']; ?>;
            --dashen-success: #28a745;
            --dashen-danger: #dc3545;
            --dashen-warning: #ffc107;
            --dashen-info: #17a2b8;
            
            --status-draft: #6c757d;
            --status-submitted: #ffc107;
            --status-approved: #28a745;
            --status-ongoing: <?php echo $custom_colors['primary']; ?>;
            --status-completed: #20c997;
            --status-closed: #17a2b8;
            --status-rejected: #dc3545;
            --status-cancelled: #fd7e14;
            
            --gradient-primary: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            --gradient-success: linear-gradient(135deg, #66BB6A, #43A047);
            --gradient-warning: linear-gradient(135deg, #FFB74D, #FF9800);
            --gradient-danger: linear-gradient(135deg, #EF5350, #E53935);
            --gradient-info: linear-gradient(135deg, #4FC3F7, #039BE5);
            
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.02);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px rgba(0,0,0,0.15);
            
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --header-height: 80px;
            
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 20px;
            
            --transition-fast: 0.15s ease;
            --transition-base: 0.3s ease;
        }

        body.dark-mode {
            --bg-primary: #0f0f13;
            --bg-secondary: #1a1a24;
            --bg-card: #242430;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --border-color: #2a2a35;
            --hover-color: #2f2f3a;
            --table-hover: #2a2a35;
        }

        body:not(.dark-mode) {
            --bg-primary: #f5f5f7;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #1a1a24;
            --text-secondary: #6b6b7b;
            --border-color: #e8eaed;
            --hover-color: #f8f9fa;
            --table-hover: #f8f9fa;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
            transition: background-color var(--transition-base);
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left var(--transition-base);
            width: calc(100% - var(--sidebar-width));
        }

        .main-content.expanded {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }

        .content-wrapper {
            padding: 30px;
            flex: 1;
            transition: all var(--transition-base);
            margin-top: var(--header-height);
            background: var(--bg-primary);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            animation: slideDown 0.6s ease-out;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-header .breadcrumb {
            margin: 0;
            padding: 0;
            background: transparent;
        }

        .page-header .breadcrumb-item a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color var(--transition-fast);
        }

        .page-header .breadcrumb-item a:hover {
            color: var(--dashen-primary);
        }

        .page-header .breadcrumb-item.active {
            color: var(--dashen-primary);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 20px;
            transition: all var(--transition-base);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity var(--transition-base);
            z-index: 1;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card:hover::before {
            opacity: 0.03;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--border-radius-md);
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
        }

        .stat-icon i {
            font-size: 20px;
            color: white;
        }

        .stat-content {
            position: relative;
            z-index: 2;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .filters-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 24px;
            margin-bottom: 24px;
        }

        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }

        .filter-select, .filter-input {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            color: var(--text-primary);
            padding: 10px 12px;
            font-size: 14px;
            transition: all var(--transition-fast);
            width: 100%;
        }

        .filter-select:focus, .filter-input:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(139, 30, 63, 0.1);
            outline: none;
        }

        .btn-filter {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            font-size: 14px;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
            cursor: pointer;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-reset {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 20px;
            border-radius: var(--border-radius-md);
            font-weight: 600;
            font-size: 14px;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
            text-decoration: none;
        }

        .btn-reset:hover {
            background: var(--hover-color);
            color: var(--text-primary);
            transform: translateY(-2px);
        }

        .table-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            overflow: hidden;
        }

        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h5 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-header h5 i {
            color: var(--dashen-primary);
        }

        .table-actions {
            display: flex;
            gap: 10px;
        }

        .btn-table {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 8px 16px;
            border-radius: var(--border-radius-md);
            font-size: 13px;
            font-weight: 600;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .btn-table:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
        }

        .btn-table.danger:hover {
            background: var(--gradient-danger);
        }

        .dataTables_wrapper {
            padding: 20px;
        }

        .dataTables_length select,
        .dataTables_filter input {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            color: var(--text-primary);
            padding: 6px 10px;
        }

        table.dataTable {
            border-collapse: separate;
            border-spacing: 0;
        }

        table.dataTable thead th {
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 16px 12px;
            border-bottom: 2px solid var(--border-color);
        }

        table.dataTable tbody tr:hover {
            background: var(--table-hover);
        }

        table.dataTable tbody td {
            padding: 16px 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
        }

        .badge-custom {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-draft {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid rgba(108, 117, 125, 0.2);
        }

        .badge-submitted {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .badge-approved {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .badge-ongoing {
            background: rgba(139, 30, 63, 0.1);
            color: var(--dashen-primary);
            border: 1px solid rgba(139, 30, 63, 0.2);
        }

        .badge-completed {
            background: rgba(32, 201, 151, 0.1);
            color: #20c997;
            border: 1px solid rgba(32, 201, 151, 0.2);
        }

        .badge-closed {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
        }

        .badge-rejected {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .badge-cancelled {
            background: rgba(253, 126, 20, 0.1);
            color: #fd7e14;
            border: 1px solid rgba(253, 126, 20, 0.2);
        }

        .badge-priority-high {
            background: rgba(239, 83, 80, 0.1);
            color: #EF5350;
            border: 1px solid rgba(239, 83, 80, 0.2);
        }

        .badge-priority-medium {
            background: rgba(255, 183, 77, 0.1);
            color: #FFB74D;
            border: 1px solid rgba(255, 183, 77, 0.2);
        }

        .badge-priority-low {
            background: rgba(102, 187, 106, 0.1);
            color: #66BB6A;
            border: 1px solid rgba(102, 187, 106, 0.2);
        }

        .badge-attendee {
            background: rgba(23, 162, 184, 0.1);
            color: #17a2b8;
            border: 1px solid rgba(23, 162, 184, 0.2);
        }

        .badge-task {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }

        .badge-resource {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            margin: 0 2px;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
        }

        .btn-icon.danger:hover {
            background: var(--gradient-danger);
        }

        .btn-icon.info:hover {
            background: var(--gradient-info);
        }

        .btn-icon.success:hover {
            background: var(--gradient-success);
        }

        .btn-icon.warning:hover {
            background: var(--gradient-warning);
        }

        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
        }

        .modal-header {
            background: var(--gradient-primary);
            color: white;
            border-radius: var(--border-radius-xl) var(--border-radius-xl) 0 0;
            padding: 20px 24px;
            border-bottom: none;
        }

        .modal-header .modal-title {
            font-weight: 700;
            font-size: 18px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .modal-body {
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            color: var(--text-primary);
            padding: 10px 12px;
            font-size: 14px;
            transition: all var(--transition-fast);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(139, 30, 63, 0.1);
            outline: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            border-radius: 40px;
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            color: var(--text-secondary);
        }

        .empty-state h6 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
            font-size: 14px;
        }

        .nav-tabs-custom {
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .nav-tabs-custom .nav-link {
            color: var(--text-secondary);
            font-weight: 600;
            padding: 12px 20px;
            border: none;
            border-bottom: 2px solid transparent;
        }

        .nav-tabs-custom .nav-link:hover {
            border-color: transparent;
            color: var(--dashen-primary);
        }

        .nav-tabs-custom .nav-link.active {
            color: var(--dashen-primary);
            background: transparent;
            border-bottom: 2px solid var(--dashen-primary);
        }

        .attendance-day {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 2px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-fast);
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
            background: var(--bg-primary);
            color: var(--text-secondary);
            border: 1px dashed var(--border-color);
        }

        .attendance-day:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-md);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
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
            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .table-actions {
                flex-wrap: wrap;
            }
            .content-wrapper {
                padding: 15px;
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
                    <h1>Event Management</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item active">Events</li>
                        </ol>
                    </nav>
                </div>
                <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])): ?>
                <button class="btn-filter" id="addEventBtn">
                    <i class="fas fa-plus"></i> Create New Event
                </button>
                <?php endif; ?>
            </div>
            
            <!-- Statistics Cards - SRS Dashboard View -->
            <div class="stats-grid" data-aos="fade-up">
                <div class="stat-card" onclick="window.location.href='events.php'">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
                
                <div class="stat-card" onclick="window.location.href='events.php?status=Draft'">
                    <div class="stat-icon" style="background: var(--gradient-info);">
                        <i class="fas fa-pen"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['draft']; ?></div>
                        <div class="stat-label">Draft Events</div>
                    </div>
                </div>
                
                <div class="stat-card" onclick="window.location.href='events.php?status=Submitted'">
                    <div class="stat-icon" style="background: var(--gradient-warning);">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['submitted']; ?></div>
                        <div class="stat-label">Pending Approval</div>
                    </div>
                </div>
                
                <div class="stat-card" onclick="window.location.href='events.php?status=Approved'">
                    <div class="stat-icon" style="background: var(--gradient-success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['approved']; ?></div>
                        <div class="stat-label">Approved Events</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters Card -->
            <div class="filters-card" data-aos="fade-up" data-aos-delay="100">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-4">
                        <div class="col-md-3">
                            <div class="filter-label">Status</div>
                            <select class="filter-select" id="statusFilter" name="status">
                                <option value="">All Statuses</option>
                                <option value="Draft" <?php echo ($statusFilter == 'Draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="Submitted" <?php echo ($statusFilter == 'Submitted') ? 'selected' : ''; ?>>Submitted</option>
                                <option value="Approved" <?php echo ($statusFilter == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                <option value="Ongoing" <?php echo ($statusFilter == 'Ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="Completed" <?php echo ($statusFilter == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Closed" <?php echo ($statusFilter == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                <option value="Rejected" <?php echo ($statusFilter == 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                                <option value="Cancelled" <?php echo ($statusFilter == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Project</div>
                            <select class="filter-select" id="projectFilter" name="project">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" <?php echo ($projectFilter == $project['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Event Type</div>
                            <select class="filter-select" id="typeFilter" name="type">
                                <option value="">All Types</option>
                                <option value="Meeting" <?php echo ($typeFilter == 'Meeting') ? 'selected' : ''; ?>>Meeting</option>
                                <option value="Presentation" <?php echo ($typeFilter == 'Presentation') ? 'selected' : ''; ?>>Presentation</option>
                                <option value="Conference" <?php echo ($typeFilter == 'Conference') ? 'selected' : ''; ?>>Conference</option>
                                <option value="Activity" <?php echo ($typeFilter == 'Activity') ? 'selected' : ''; ?>>Activity</option>
                                <option value="Training" <?php echo ($typeFilter == 'Training') ? 'selected' : ''; ?>>Training</option>
                                <option value="Workshop" <?php echo ($typeFilter == 'Workshop') ? 'selected' : ''; ?>>Workshop</option>
                                <option value="Vendor Meeting" <?php echo ($typeFilter == 'Vendor Meeting') ? 'selected' : ''; ?>>Vendor Meeting</option>
                                <option value="Other" <?php echo ($typeFilter == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="filter-label">Search</div>
                            <input type="text" class="filter-input" id="searchInput" name="search" 
                                   placeholder="Search events..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                        
                        <div class="col-12">
                            <div class="row">
                                <div class="col-md-6">
                                    <button type="submit" class="btn-filter">
                                        <i class="fas fa-search"></i> Apply Filters
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <a href="events.php" class="btn-reset">
                                        <i class="fas fa-times"></i> Clear Filters
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Events Table -->
            <div class="table-container" data-aos="fade-up" data-aos-delay="200">
                <div class="table-header">
                    <h5>
                        <i class="fas fa-list"></i>
                        Events List
                    </h5>
                    <div class="table-actions">
                        <?php if (hasAnyRole(['super_admin', 'admin'])): ?>
                        <button class="btn-table danger" id="bulkDeleteBtn" style="display: none;">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                        <?php endif; ?>
                        <div class="dropdown">
                            <button class="btn-table dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" id="exportCSV"><i class="fas fa-file-csv"></i> CSV</a></li>
                                <li><a class="dropdown-item" href="#" id="exportExcel"><i class="fas fa-file-excel"></i> Excel</a></li>
                                <li><a class="dropdown-item" href="#" id="exportPDF"><i class="fas fa-file-pdf"></i> PDF</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table" id="eventsTable">
                        <thead>
                            <tr>
                                <?php if (hasAnyRole(['super_admin', 'admin'])): ?>
                                <th width="40px">
                                    <input type="checkbox" class="form-check-input" id="selectAll">
                                </th>
                                <?php endif; ?>
                                <th>Event Name</th>
                                <th>Project</th>
                                <th>Type</th>
                                <th>Date & Time</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Stats</th>
                                <th width="150px">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($events)): ?>
                            <?php foreach ($events as $event): ?>
                            <tr data-id="<?php echo $event['id']; ?>">
                                <?php if (hasAnyRole(['super_admin', 'admin'])): ?>
                                <td>
                                    <input type="checkbox" class="form-check-input event-select" value="<?php echo $event['id']; ?>">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <strong><?php echo htmlspecialchars($event['event_name']); ?></strong>
                                    <br>
                                    <small class="text-secondary">Organizer: <?php echo htmlspecialchars($event['organizer_name']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($event['project_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge-custom" style="background: rgba(139, 30, 63, 0.05); color: var(--dashen-primary);">
                                        <i class="fas fa-<?php 
                                            $icons = ['Meeting' => 'users', 'Presentation' => 'chart-line', 
                                                     'Conference' => 'microphone', 'Activity' => 'running',
                                                     'Training' => 'graduation-cap', 'Workshop' => 'wrench',
                                                     'Vendor Meeting' => 'handshake', 'Other' => 'calendar'];
                                            echo $icons[$event['event_type']] ?? 'calendar';
                                        ?>"></i>
                                        <?php echo htmlspecialchars($event['event_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?php echo date('M j, Y', strtotime($event['start_datetime'])); ?></div>
                                    <small class="text-secondary"><?php echo date('g:i A', strtotime($event['start_datetime'])); ?></small>
                                    <?php if ($event['end_datetime']): ?>
                                    <br><small class="text-secondary">to <?php echo date('M j, Y g:i A', strtotime($event['end_datetime'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <i class="fas fa-map-marker-alt text-secondary me-1"></i>
                                    <?php echo htmlspecialchars($event['location']); ?>
                                </td>
                                <td>
                                    <span class="badge-custom badge-<?php echo strtolower($event['status']); ?>">
                                        <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                        <?php echo $event['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-custom badge-priority-<?php echo strtolower($event['priority']); ?>">
                                        <i class="fas fa-flag"></i>
                                        <?php echo $event['priority']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-custom badge-attendee" title="Attendees">
                                        <i class="fas fa-users"></i> <?php echo $event['attendee_count'] ?? 0; ?>
                                    </span>
                                    <span class="badge-custom badge-task" title="Tasks">
                                        <i class="fas fa-tasks"></i> <?php echo $event['task_count'] ?? 0; ?>
                                    </span>
                                    <span class="badge-custom badge-resource" title="Resources">
                                        <i class="fas fa-box"></i> <?php echo $event['resource_count'] ?? 0; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <a href="event_details.php?id=<?php echo $event['id']; ?>" class="btn-icon info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager']) || 
                                                  (hasRole('pm_employee') && $event['status'] == 'Draft')): ?>
                                        <button class="btn-icon primary edit-event" 
                                                data-id="<?php echo $event['id']; ?>"
                                                title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if (hasAnyRole(['super_admin', 'admin'])): ?>
                                        <button class="btn-icon danger delete-event" 
                                                data-id="<?php echo $event['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($event['event_name']); ?>"
                                                title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <div class="dropdown">
                                            <button class="btn-icon" type="button" data-bs-toggle="dropdown" title="More Actions">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="event_details.php?id=<?php echo $event['id']; ?>">
                                                    <i class="fas fa-calendar-check"></i> Manage Event
                                                </a></li>
                                                <li><a class="dropdown-item" href="event_attendees.php?event_id=<?php echo $event['id']; ?>">
                                                    <i class="fas fa-users"></i> Manage Attendees
                                                </a></li>
                                                <li><a class="dropdown-item" href="event_tasks.php?event_id=<?php echo $event['id']; ?>">
                                                    <i class="fas fa-tasks"></i> Manage Tasks
                                                </a></li>
                                                <li><a class="dropdown-item" href="event_resources.php?event_id=<?php echo $event['id']; ?>">
                                                    <i class="fas fa-box"></i> Manage Resources
                                                </a></li>
                                                <li><a class="dropdown-item" href="event_attendance.php?event_id=<?php echo $event['id']; ?>">
                                                    <i class="fas fa-clipboard-list"></i> Attendance Tracking
                                                </a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item update-status" 
                                                            data-id="<?php echo $event['id']; ?>"
                                                            data-status="<?php echo $event['status']; ?>">
                                                        <i class="fas fa-sync-alt"></i> Update Status
                                                    </button>
                                                </li>
                                                <?php if ($event['status'] == 'Draft' && hasRole('pm_employee')): ?>
                                                <li>
                                                    <button class="dropdown-item submit-approval" 
                                                            data-id="<?php echo $event['id']; ?>">
                                                        <i class="fas fa-paper-plane"></i> Submit for Approval
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <?php if ($event['status'] == 'Submitted' && (hasRole('pm_manager') || hasAnyRole(['super_admin', 'admin']))): ?>
                                                <li>
                                                    <button class="dropdown-item approve-event" 
                                                            data-id="<?php echo $event['id']; ?>">
                                                        <i class="fas fa-check-circle"></i> Approve Event
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item reject-event" 
                                                            data-id="<?php echo $event['id']; ?>">
                                                        <i class="fas fa-times-circle"></i> Reject Event
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="<?php echo (hasAnyRole(['super_admin', 'admin'])) ? '10' : '9'; ?>" class="text-center">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">
                                            <i class="fas fa-calendar-times"></i>
                                        </div>
                                        <h6>No Events Found</h6>
                                        <p>Get started by creating your first event</p>
                                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager', 'pm_employee'])): ?>
                                        <button class="btn-filter" id="emptyStateAddBtn">
                                            <i class="fas fa-plus"></i> Create New Event
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
    </main>

    <!-- Add/Edit Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventModalTitle">Create New Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="eventForm">
                    <input type="hidden" name="id" id="eventId">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Event Name *</label>
                                <input type="text" class="form-control" name="event_name" id="eventName" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Project</label>
                                <select class="form-select" name="project_id" id="projectId">
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Event Type *</label>
                                <select class="form-select" name="event_type" id="eventType" required>
                                    <option value="">Select Type</option>
                                    <option value="Meeting">Meeting</option>
                                    <option value="Presentation">Presentation</option>
                                    <option value="Conference">Conference</option>
                                    <option value="Activity">Activity</option>
                                    <option value="Training">Training</option>
                                    <option value="Workshop">Workshop</option>
                                    <option value="Vendor Meeting">Vendor Meeting</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Organizer *</label>
                                <select class="form-select" name="organizer_id" id="organizerId" required>
                                    <option value="">Select Organizer</option>
                                    <?php foreach ($organizers as $organizer): ?>
                                    <option value="<?php echo $organizer['id']; ?>" <?php echo ($organizer['id'] == $_SESSION['user_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($organizer['username']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date & Time *</label>
                                <input type="datetime-local" class="form-control" name="start_datetime" id="startDatetime" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date & Time</label>
                                <input type="datetime-local" class="form-control" name="end_datetime" id="endDatetime">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Location *</label>
                            <input type="text" class="form-control" name="location" id="eventLocation" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="eventStatus">
                                    <option value="Draft">Draft</option>
                                    <option value="Planning">Planning</option>
                                    <option value="Upcoming">Upcoming</option>
                                    <option value="Ongoing">Ongoing</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                                <small class="text-secondary">PM Employees can only create Draft events</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority" id="eventPriority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="eventDescription" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveEventBtn">Save Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Event Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="statusForm">
                    <input type="hidden" name="id" id="statusEventId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select New Status</label>
                            <select class="form-select" name="status" id="newStatus" required>
                                <option value="Draft">Draft</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Approved">Approved</option>
                                <option value="Ongoing">Ongoing</option>
                                <option value="Completed">Completed</option>
                                <option value="Closed">Closed</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Changing status will notify all confirmed attendees via email and in-app notification.
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

    <!-- View Event Details Modal -->
    <div class="modal fade" id="viewEventModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs-custom" id="eventDetailTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">
                                <i class="fas fa-info-circle"></i> Event Details
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="attendees-tab" data-bs-toggle="tab" data-bs-target="#attendees" type="button" role="tab">
                                <i class="fas fa-users"></i> Attendees
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tasks-tab" data-bs-toggle="tab" data-bs-target="#tasks" type="button" role="tab">
                                <i class="fas fa-tasks"></i> Tasks
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="resources-tab" data-bs-toggle="tab" data-bs-target="#resources" type="button" role="tab">
                                <i class="fas fa-box"></i> Resources
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab">
                                <i class="fas fa-clipboard-list"></i> Attendance
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="eventDetailTabContent">
                        <div class="tab-pane fade show active" id="details" role="tabpanel">
                            <div id="eventDetailsContent">Loading...</div>
                        </div>
                        <div class="tab-pane fade" id="attendees" role="tabpanel">
                            <div id="eventAttendeesContent">Loading...</div>
                        </div>
                        <div class="tab-pane fade" id="tasks" role="tabpanel">
                            <div id="eventTasksContent">Loading...</div>
                        </div>
                        <div class="tab-pane fade" id="resources" role="tabpanel">
                            <div id="eventResourcesContent">Loading...</div>
                        </div>
                        <div class="tab-pane fade" id="attendance" role="tabpanel">
                            <div id="eventAttendanceContent">Loading...</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Add Attendees Modal -->
    <div class="modal fade" id="bulkAttendeeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Add Attendees</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="bulkAttendeeForm">
                    <input type="hidden" name="action" value="bulk_add_attendees">
                    <input type="hidden" name="event_id" id="bulkEventId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Users *</label>
                            <select class="form-select" name="user_ids[]" id="bulkUserIds" multiple required style="height: 200px;">
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">Hold Ctrl/Cmd to select multiple users</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role in Event</label>
                            <select class="form-select" name="role">
                                <option value="Participant">Participant</option>
                                <option value="Speaker">Speaker</option>
                                <option value="Facilitator">Facilitator</option>
                                <option value="Staff">Staff</option>
                                <option value="Guest">Guest</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Selected users will receive email invitations and in-app notifications.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Attendees</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Record Attendance Modal -->
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="attendanceForm">
                    <input type="hidden" name="action" value="record_attendance">
                    <input type="hidden" name="event_id" id="attendanceEventId">
                    <input type="hidden" name="attendee_id" id="attendanceAttendeeId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Attendee</label>
                            <input type="text" class="form-control" id="attendanceAttendeeName" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Day Number *</label>
                            <input type="number" class="form-control" name="day_number" id="attendanceDay" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attendance Status *</label>
                            <select class="form-select" name="attendance_status" id="attendanceStatus" required>
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="excused">Excused</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Check In Time</label>
                            <input type="datetime-local" class="form-control" name="check_in_time" id="attendanceCheckIn">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Check Out Time</label>
                            <input type="datetime-local" class="form-control" name="check_out_time" id="attendanceCheckOut">
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

    <!-- Add Task Modal -->
    <div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="taskForm">
                    <input type="hidden" name="action" value="add_task">
                    <input type="hidden" name="event_id" id="taskEventId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Task Title *</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Assign To *</label>
                            <select class="form-select" name="assigned_to" required>
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Due Date</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                            </select>
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

    <!-- Add Resource Modal -->
    <div class="modal fade" id="resourceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Resource</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="resourceForm">
                    <input type="hidden" name="action" value="add_resource">
                    <input type="hidden" name="event_id" id="resourceEventId">
                    <div class="modal-body">
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

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
    $(document).ready(function() {
        AOS.init({ duration: 800, once: true });
        
        // Initialize DataTable
        const table = $('#eventsTable').DataTable({
            pageLength: 25,
            lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
            order: [[4, 'desc']],
            language: {
                emptyTable: "No events found",
                info: "Showing _START_ to _END_ of _TOTAL_ events",
                infoEmpty: "Showing 0 to 0 of 0 events",
                infoFiltered: "(filtered from _MAX_ total events)",
                lengthMenu: "Show _MENU_ events",
                search: "Search:",
                zeroRecords: "No matching events found"
            },
            columnDefs: [
                <?php if (hasAnyRole(['super_admin', 'admin'])): ?>
                { orderable: false, targets: [0, 8, 9] },
                { orderable: true, targets: [1, 2, 3, 4, 5, 6, 7] }
                <?php else: ?>
                { orderable: false, targets: [7, 8] },
                { orderable: true, targets: [0, 1, 2, 3, 4, 5, 6] }
                <?php endif; ?>
            ]
        });
        
        const today = new Date().toISOString().slice(0, 16);
        $('#startDatetime').attr('min', today);
        
        // Initialize Select2
        $('.filter-select').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#bulkUserIds').select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#bulkAttendeeModal') });
        
        // Add Event button click
        $('#addEventBtn, #emptyStateAddBtn').click(function() {
            $('#eventForm')[0].reset();
            $('#eventId').val('');
            $('#eventModalTitle').text('Create New Event');
            
            <?php if (hasRole('pm_employee')): ?>
            $('#eventStatus').val('Draft');
            <?php else: ?>
            $('#eventStatus').val('Planning');
            <?php endif; ?>
            
            $('#eventModal').modal('show');
        });
        
        // Edit Event button click
        $(document).on('click', '.edit-event', function() {
            const eventId = $(this).data('id');
            
            $.ajax({
                url: 'events.php?action=get&id=' + eventId,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const event = response.data;
                        
                        $('#eventId').val(event.id);
                        $('#eventName').val(event.event_name);
                        $('#projectId').val(event.project_id || '');
                        $('#eventType').val(event.event_type);
                        $('#organizerId').val(event.organizer_id);
                        
                        if (event.start_datetime) {
                            const startDate = new Date(event.start_datetime);
                            const startFormatted = startDate.toISOString().slice(0, 16);
                            $('#startDatetime').val(startFormatted);
                        }
                        
                        if (event.end_datetime) {
                            const endDate = new Date(event.end_datetime);
                            const endFormatted = endDate.toISOString().slice(0, 16);
                            $('#endDatetime').val(endFormatted);
                        } else {
                            $('#endDatetime').val('');
                        }
                        
                        $('#eventLocation').val(event.location);
                        $('#eventStatus').val(event.status);
                        $('#eventPriority').val(event.priority);
                        $('#eventDescription').val(event.description);
                        
                        $('#eventModalTitle').text('Edit Event');
                        $('#eventModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to load event details', 'error');
                }
            });
        });
        
        // Save Event form submit
        $('#eventForm').submit(function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const eventId = $('#eventId').val();
            const action = eventId ? 'edit' : 'add';
            
            formData.append('action', action);
            
            const start = new Date($('#startDatetime').val());
            const end = $('#endDatetime').val() ? new Date($('#endDatetime').val()) : null;
            
            if (end && end <= start) {
                Swal.fire('Validation Error', 'End date must be after start date', 'error');
                return;
            }
            
            $.ajax({
                url: 'events.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#eventModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
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
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Failed to save event', 'error');
                }
            });
        });
        
        // Delete Event button click
        $(document).on('click', '.delete-event', function() {
            const eventId = $(this).data('id');
            const eventName = $(this).data('name');
            
            Swal.fire({
                title: 'Delete Event?',
                html: `Are you sure you want to delete "<strong>${eventName}</strong>"?<br><br>
                       This will also delete all associated attendees, tasks, resources, and attendance records.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'events.php',
                        type: 'POST',
                        data: { action: 'delete', id: eventId },
                        dataType: 'json',
                        success: function(response) {
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
                            console.error('AJAX Error:', error);
                            Swal.fire('Error', 'Failed to delete event', 'error');
                        }
                    });
                }
            });
        });
        
        // Submit for Approval
        $(document).on('click', '.submit-approval', function() {
            const eventId = $(this).data('id');
            
            Swal.fire({
                title: 'Submit for Approval?',
                text: 'This will submit the event for PM Manager approval.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, submit'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'events.php',
                        type: 'POST',
                        data: { action: 'update_status', id: eventId, status: 'Submitted' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Success!', response.message, 'success').then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        }
                    });
                }
            });
        });
        
        // Approve Event
        $(document).on('click', '.approve-event', function() {
            const eventId = $(this).data('id');
            
            Swal.fire({
                title: 'Approve Event?',
                text: 'This will approve the event and make it visible to all attendees.',
                icon: 'success',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, approve'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'events.php',
                        type: 'POST',
                        data: { action: 'update_status', id: eventId, status: 'Approved' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Approved!', response.message, 'success').then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        }
                    });
                }
            });
        });
        
        // Reject Event
        $(document).on('click', '.reject-event', function() {
            const eventId = $(this).data('id');
            
            Swal.fire({
                title: 'Reject Event?',
                input: 'textarea',
                inputLabel: 'Rejection Reason',
                inputPlaceholder: 'Enter reason for rejection...',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Reject'
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    $.ajax({
                        url: 'events.php',
                        type: 'POST',
                        data: { action: 'update_status', id: eventId, status: 'Rejected' },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Rejected!', response.message, 'info').then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        }
                    });
                }
            });
        });
        
        // Update Status button click
        $(document).on('click', '.update-status', function() {
            const eventId = $(this).data('id');
            const currentStatus = $(this).data('status');
            
            $('#statusEventId').val(eventId);
            $('#newStatus').val(currentStatus);
            $('#statusModal').modal('show');
        });
        
        // Status form submit
        $('#statusForm').submit(function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            
            $.ajax({
                url: 'events.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#statusModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
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
                error: function() {
                    Swal.fire('Error', 'Failed to update status', 'error');
                }
            });
        });
        
        // Select All checkbox
        $('#selectAll').change(function() {
            $('.event-select').prop('checked', $(this).prop('checked'));
            toggleBulkDelete();
        });
        
        $(document).on('change', '.event-select', function() {
            const allChecked = $('.event-select:checked').length === $('.event-select').length;
            $('#selectAll').prop('checked', allChecked);
            toggleBulkDelete();
        });
        
        function toggleBulkDelete() {
            const checkedCount = $('.event-select:checked').length;
            if (checkedCount > 0) {
                $('#bulkDeleteBtn').show().text(`Delete Selected (${checkedCount})`);
            } else {
                $('#bulkDeleteBtn').hide();
            }
        }
        
        $('#bulkDeleteBtn').click(function() {
            const selectedIds = [];
            $('.event-select:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) return;
            
            Swal.fire({
                title: 'Delete Selected Events?',
                html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> event(s)?<br><br>
                       This will also delete all associated attendees, tasks, resources, and attendance records.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete them!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'events.php',
                        type: 'POST',
                        data: { action: 'bulk_delete', ids: selectedIds },
                        dataType: 'json',
                        success: function(response) {
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
                        error: function() {
                            Swal.fire('Error', 'Failed to delete events', 'error');
                        }
                    });
                }
            });
        });
        
        // Export CSV
        $('#exportCSV').click(function(e) {
            e.preventDefault();
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'events.php?action=export&format=csv&' + params.toString();
        });
        
        // Export Excel
        $('#exportExcel').click(function(e) {
            e.preventDefault();
            const params = new URLSearchParams(window.location.search);
            window.location.href = 'events.php?action=export&format=excel&' + params.toString();
        });
        
        // Export PDF
        $('#exportPDF').click(function(e) {
            e.preventDefault();
            Swal.fire({
                icon: 'info',
                title: 'PDF Export',
                text: 'PDF export requires additional library. Please use CSV or Excel format.',
                timer: 3000,
                showConfirmButton: false
            });
        });
        
        // Bulk Add Attendees
        $(document).on('click', '.bulk-add-attendees', function() {
            const eventId = $(this).data('id');
            $('#bulkEventId').val(eventId);
            $('#bulkAttendeeModal').modal('show');
        });
        
        $('#bulkAttendeeForm').submit(function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $.ajax({
                url: 'events.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#bulkAttendeeModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to add attendees', 'error');
                }
            });
        });
        
        // Add Task
        $(document).on('click', '.add-task', function() {
            const eventId = $(this).data('id');
            $('#taskEventId').val(eventId);
            $('#taskModal').modal('show');
        });
        
        $('#taskForm').submit(function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $.ajax({
                url: 'events.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#taskModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Task Added',
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
                error: function() {
                    Swal.fire('Error', 'Failed to add task', 'error');
                }
            });
        });
        
        // Add Resource
        $(document).on('click', '.add-resource', function() {
            const eventId = $(this).data('id');
            $('#resourceEventId').val(eventId);
            $('#resourceModal').modal('show');
        });
        
        $('#resourceForm').submit(function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            $.ajax({
                url: 'events.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#resourceModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Resource Added',
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
                error: function() {
                    Swal.fire('Error', 'Failed to add resource', 'error');
                }
            });
        });
        
        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                const mainContent = document.getElementById('mainContent');
                setTimeout(() => {
                    mainContent.classList.toggle('expanded');
                    AOS.refresh();
                }, 50);
            });
        }
        
        // Keyboard shortcuts
        $(document).keydown(function(e) {
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                $('#addEventBtn').click();
            }
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                $('#searchInput').focus();
            }
            if (e.key === 'Escape') {
                $('.modal').modal('hide');
            }
        });
    });
    </script>
</body>
</html>