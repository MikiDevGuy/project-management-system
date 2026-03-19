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

// Error reporting for debugging - remove in production
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// Create attendance_records table if it doesn't exist
$createTableSQL = "CREATE TABLE IF NOT EXISTS `attendance_records` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `attendee_id` int(11) NOT NULL,
    `day_number` int(11) NOT NULL,
    `attendance_date` date NOT NULL,
    `attendance_status` enum('Present','Absent','Late','Excused','Not Marked') DEFAULT 'Not Marked',
    `check_in_time` time DEFAULT NULL,
    `check_out_time` time DEFAULT NULL,
    `notes` text DEFAULT NULL,
    `recorded_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_daily_attendance` (`attendee_id`, `day_number`),
    KEY `attendee_id` (`attendee_id`),
    KEY `recorded_by` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

mysqli_query($conn, $createTableSQL);

// Get event ID from query string if specified
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$view = isset($_GET['view']) ? $_GET['view'] : 'list';

// Handle AJAX requests - MUST BE BEFORE ANY HTML OUTPUT
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $ajaxAction = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';
    
    // Debug log
    error_log("AJAX Action: " . $ajaxAction);
    error_log("POST Data: " . print_r($_POST, true));
    
    switch ($ajaxAction) {
        case 'add_attendee':
            handleAddAttendee($conn);
            break;
        case 'edit_attendee':
            handleEditAttendee($conn);
            break;
        case 'delete_attendee':
            handleDeleteAttendee($conn);
            break;
        case 'bulk_add':
            handleBulkAddAttendees($conn);
            break;
        case 'bulk_delete':
            handleBulkDeleteAttendees($conn);
            break;
        case 'bulk_update_status':
            handleBulkUpdateStatus($conn);
            break;
        case 'send_email':
            handleSendEmail($conn);
            break;
        case 'send_bulk_email':
            handleSendBulkEmail($conn);
            break;
        case 'mark_attendance':
            handleMarkAttendance($conn);
            break;
        case 'bulk_mark_attendance':
            handleBulkMarkAttendance($conn);
            break;
        case 'get_attendee':
            getAttendeeDetails($conn);
            break;
        case 'get_attendance_stats':
            getAttendanceStats($conn, $eventId);
            break;
        case 'export_attendees':
            exportAttendees($conn, $eventId);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Initialize variables
$events = [];
$attendees = [];
$selectedEvent = null;
$eventDays = [];
$attendanceRecords = [];
$departments = [];

// Statistics
$rsvpStats = [
    'total' => 0,
    'Invited' => 0,
    'Registered' => 0,
    'Confirmed' => 0,
    'Attended' => 0,
    'Cancelled' => 0,
    'No Show' => 0
];

$attendanceStats = [
    'full_attendance' => 0,
    'partial_attendance' => 0,
    'absent' => 0,
    'total_days' => 0,
    'average_attendance_rate' => 0
];

$error = '';
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

// Get departments
try {
    $deptQuery = "SELECT id, department_name FROM departments WHERE is_active = 1 ORDER BY department_name";
    $deptResult = mysqli_query($conn, $deptQuery);
    if ($deptResult) {
        while ($row = mysqli_fetch_assoc($deptResult)) {
            $departments[] = $row;
        }
    }
} catch(Exception $e) {
    error_log("Error fetching departments: " . $e->getMessage());
}

// Get all events for dropdown
try {
    $eventsQuery = "SELECT e.*, 
                    (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendee_count,
                    DATEDIFF(e.end_datetime, e.start_datetime) + 1 as duration_days
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

// Get selected event details if event ID is provided
if ($eventId > 0) {
    try {
        $eventQuery = "SELECT e.*, p.name as project_name, u.username as organizer_name,
                       DATEDIFF(e.end_datetime, e.start_datetime) + 1 as duration_days
                       FROM events e 
                       LEFT JOIN projects p ON e.project_id = p.id 
                       LEFT JOIN users u ON e.organizer_id = u.id 
                       WHERE e.id = ?";
        $stmt = mysqli_prepare($conn, $eventQuery);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $selectedEvent = mysqli_fetch_assoc($result);
        
        // Calculate event days for multi-day events
        if ($selectedEvent && $selectedEvent['end_datetime']) {
            $start = new DateTime($selectedEvent['start_datetime']);
            $end = new DateTime($selectedEvent['end_datetime']);
            $interval = $start->diff($end);
            $totalDays = $interval->days + 1;
            $attendanceStats['total_days'] = $totalDays;
            
            // Generate day numbers
            for ($i = 1; $i <= $totalDays; $i++) {
                $dayDate = clone $start;
                $dayDate->modify('+' . ($i - 1) . ' days');
                $eventDays[$i] = [
                    'day_number' => $i,
                    'date' => $dayDate->format('Y-m-d'),
                    'formatted_date' => $dayDate->format('M j, Y'),
                    'day_name' => $dayDate->format('l')
                ];
            }
        }
    } catch(Exception $e) {
        error_log("Error fetching event details: " . $e->getMessage());
    }
}

// Get attendees with all required fields - FIXED: Use correct column names
try {
    if ($eventId > 0) {
        $attendeesQuery = "
            SELECT ea.*, 
                   u.username, 
                   u.email as user_email,
                   (SELECT COUNT(*) FROM attendance_records ar 
                    WHERE ar.attendee_id = ea.id AND ar.attendance_status = 'Present') as days_attended,
                   (SELECT COUNT(*) FROM attendance_records ar 
                    WHERE ar.attendee_id = ea.id) as total_days_recorded,
                   DATEDIFF(e.end_datetime, e.start_datetime) + 1 as event_duration,
                   e.event_name
            FROM event_attendees ea
            LEFT JOIN users u ON ea.user_id = u.id
            JOIN events e ON ea.event_id = e.id
            WHERE ea.event_id = ?
            ORDER BY 
                CASE ea.rsvp_status
                    WHEN 'Confirmed' THEN 1
                    WHEN 'Registered' THEN 2
                    WHEN 'Invited' THEN 3
                    WHEN 'Attended' THEN 4
                    WHEN 'No Show' THEN 5
                    WHEN 'Cancelled' THEN 6
                    ELSE 7
                END,
                COALESCE(u.username, ea.name) ASC
        ";
        $stmt = mysqli_prepare($conn, $attendeesQuery);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $attendeesQuery = "
            SELECT ea.*, 
                   u.username, 
                   u.email as user_email,
                   e.event_name, 
                   e.start_datetime, 
                   e.end_datetime,
                   DATEDIFF(e.end_datetime, e.start_datetime) + 1 as event_duration,
                   (SELECT COUNT(*) FROM attendance_records ar 
                    WHERE ar.attendee_id = ea.id AND ar.attendance_status = 'Present') as days_attended
            FROM event_attendees ea
            LEFT JOIN users u ON ea.user_id = u.id
            JOIN events e ON ea.event_id = e.id
            ORDER BY e.start_datetime DESC, ea.created_at DESC
        ";
        $result = mysqli_query($conn, $attendeesQuery);
    }
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Set display name (prefer username from users table, fallback to manual name)
            $row['display_name'] = $row['username'] ?? $row['name'] ?? 'Unknown';
            // Use user email if available, otherwise use attendee email field
            $row['email'] = $row['user_email'] ?? $row['email'] ?? '';
            $row['phone'] = $row['phone'] ?? '';
            $row['department'] = $row['department'] ?? '';
            $row['status'] = $row['rsvp_status'] ?? 'Invited';
            
            // Calculate attendance rate for multi-day events
            if ($row['event_duration'] > 0) {
                $row['attendance_rate'] = round(($row['days_attended'] / $row['event_duration']) * 100);
                
                // Classify attendance based on rate
                if ($row['attendance_rate'] >= 90) {
                    $row['attendance_classification'] = 'Full Attendance';
                } elseif ($row['attendance_rate'] >= 50) {
                    $row['attendance_classification'] = 'Partial Attendance';
                } else {
                    $row['attendance_classification'] = 'Absent';
                }
            } else {
                $row['attendance_rate'] = 0;
                $row['attendance_classification'] = 'Not Started';
            }
            
            $attendees[] = $row;
            
            // Update RSVP statistics
            $rsvpStats['total']++;
            $status = $row['status'] ?? 'Invited';
            if (isset($rsvpStats[$status])) {
                $rsvpStats[$status]++;
            }
            
            // Update attendance statistics
            if ($row['attendance_classification'] == 'Full Attendance') {
                $attendanceStats['full_attendance']++;
            } elseif ($row['attendance_classification'] == 'Partial Attendance') {
                $attendanceStats['partial_attendance']++;
            } elseif ($row['attendance_classification'] == 'Absent') {
                $attendanceStats['absent']++;
            }
            
            $attendanceStats['average_attendance_rate'] += $row['attendance_rate'];
        }
        
        // Calculate average attendance rate
        if ($rsvpStats['total'] > 0) {
            $attendanceStats['average_attendance_rate'] = round($attendanceStats['average_attendance_rate'] / $rsvpStats['total']);
        }
    }
} catch(Exception $e) {
    error_log("Error fetching attendees: " . $e->getMessage());
    $error = "Error loading attendees data.";
}

// Get daily attendance records if event is selected
if ($eventId > 0) {
    try {
        $attendanceQuery = "
            SELECT ar.*, COALESCE(u.username, ea.name) as attendee_name, COALESCE(u.email, ea.email) as attendee_email
            FROM attendance_records ar
            JOIN event_attendees ea ON ar.attendee_id = ea.id
            LEFT JOIN users u ON ea.user_id = u.id
            WHERE ea.event_id = ?
            ORDER BY ar.day_number, attendee_name
        ";
        $stmt = mysqli_prepare($conn, $attendanceQuery);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $attendanceRecords[] = $row;
        }
    } catch(Exception $e) {
        error_log("Error fetching attendance records: " . $e->getMessage());
    }
}

// Get message from URL if redirected
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'success';
}

// AJAX Handlers - FIXED: All functions return proper JSON and use correct column names

function handleAddAttendee($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $department = mysqli_real_escape_string($conn, trim($_POST['department'] ?? ''));
    $role = mysqli_real_escape_string($conn, $_POST['role_in_event'] ?? 'Participant');
    $rsvp = mysqli_real_escape_string($conn, $_POST['rsvp_status'] ?? 'Invited');
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Event is required']);
        exit();
    }
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name is required']);
        exit();
    }
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }
    
    // Check if email already exists for this event - FIXED: Use correct column names
    $checkSql = "SELECT ea.id FROM event_attendees ea 
                 LEFT JOIN users u ON ea.user_id = u.id
                 WHERE ea.event_id = ? AND (u.email = ? OR ea.email = ?)";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "iss", $eventId, $email, $email);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'An attendee with this email already exists for this event']);
        exit();
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Check if user exists with this email
        $userSql = "SELECT id FROM users WHERE email = ?";
        $userStmt = mysqli_prepare($conn, $userSql);
        mysqli_stmt_bind_param($userStmt, "s", $email);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        $user = mysqli_fetch_assoc($userResult);
        
        $userId = $user ? $user['id'] : null;
        
        // Insert new attendee
        $sql = "INSERT INTO event_attendees (event_id, user_id, name, email, phone, department, role_in_event, rsvp_status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iissssss", $eventId, $userId, $name, $email, $phone, $department, $role, $rsvp);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        $attendeeId = mysqli_insert_id($conn);
        
        // Create initial attendance records if event is multi-day
        $eventSql = "SELECT start_datetime, end_datetime FROM events WHERE id = ?";
        $eventStmt = mysqli_prepare($conn, $eventSql);
        mysqli_stmt_bind_param($eventStmt, "i", $eventId);
        mysqli_stmt_execute($eventStmt);
        $eventResult = mysqli_stmt_get_result($eventStmt);
        $event = mysqli_fetch_assoc($eventResult);
        
        if ($event && $event['end_datetime']) {
            $start = new DateTime($event['start_datetime']);
            $end = new DateTime($event['end_datetime']);
            $days = $start->diff($end)->days + 1;
            
            for ($day = 1; $day <= $days; $day++) {
                $dayDate = clone $start;
                $dayDate->modify('+' . ($day - 1) . ' days');
                
                $attendanceSql = "INSERT INTO attendance_records (attendee_id, day_number, attendance_date, attendance_status) 
                                  VALUES (?, ?, ?, 'Not Marked')";
                $attendanceStmt = mysqli_prepare($conn, $attendanceSql);
                $dateStr = $dayDate->format('Y-m-d');
                mysqli_stmt_bind_param($attendanceStmt, "iis", $attendeeId, $day, $dateStr);
                
                if (!mysqli_stmt_execute($attendanceStmt)) {
                    throw new Exception(mysqli_error($conn));
                }
            }
        }
        
        mysqli_commit($conn);
        
        // Log activity
        logActivity($conn, $_SESSION['user_id'], 'added', "Added attendee: $name to event ID: $eventId", 'attendee', $attendeeId);
        
        echo json_encode(['success' => true, 'message' => 'Attendee added successfully', 'id' => $attendeeId]);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error adding attendee: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

function handleEditAttendee($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
    $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
    $department = mysqli_real_escape_string($conn, trim($_POST['department'] ?? ''));
    $role = mysqli_real_escape_string($conn, $_POST['role_in_event'] ?? 'Participant');
    $rsvp = mysqli_real_escape_string($conn, $_POST['rsvp_status'] ?? 'Invited');
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendee ID']);
        exit();
    }
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name is required']);
        exit();
    }
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }
    
    // Check if email already exists for another attendee in same event - FIXED: Use correct column names
    $checkSql = "SELECT ea.id FROM event_attendees ea 
                 LEFT JOIN users u ON ea.user_id = u.id
                 WHERE ea.event_id = (SELECT event_id FROM event_attendees WHERE id = ?) 
                 AND (u.email = ? OR ea.email = ?) AND ea.id != ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "issi", $attendeeId, $email, $email, $attendeeId);
    mysqli_stmt_execute($checkStmt);
    mysqli_stmt_store_result($checkStmt);
    
    if (mysqli_stmt_num_rows($checkStmt) > 0) {
        echo json_encode(['success' => false, 'message' => 'Another attendee with this email already exists for this event']);
        exit();
    }
    
    // Check if user exists with this email
    $userSql = "SELECT id FROM users WHERE email = ?";
    $userStmt = mysqli_prepare($conn, $userSql);
    mysqli_stmt_bind_param($userStmt, "s", $email);
    mysqli_stmt_execute($userStmt);
    $userResult = mysqli_stmt_get_result($userStmt);
    $user = mysqli_fetch_assoc($userResult);
    
    $userId = $user ? $user['id'] : null;
    
    // Update attendee - FIXED: Use correct column names
    $sql = "UPDATE event_attendees SET 
            user_id = ?, 
            name = ?, 
            email = ?, 
            phone = ?, 
            department = ?, 
            role_in_event = ?, 
            rsvp_status = ?, 
            updated_at = NOW() 
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issssssi", $userId, $name, $email, $phone, $department, $role, $rsvp, $attendeeId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity($conn, $_SESSION['user_id'], 'updated', "Updated attendee: $name ID: $attendeeId", 'attendee', $attendeeId);
        echo json_encode(['success' => true, 'message' => 'Attendee updated successfully']);
        exit();
    } else {
        error_log("Error updating attendee: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Error updating attendee: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleDeleteAttendee($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendee ID']);
        exit();
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get attendee info for logging
        $infoSql = "SELECT name FROM event_attendees WHERE id = ?";
        $infoStmt = mysqli_prepare($conn, $infoSql);
        mysqli_stmt_bind_param($infoStmt, "i", $attendeeId);
        mysqli_stmt_execute($infoStmt);
        $infoResult = mysqli_stmt_get_result($infoStmt);
        $attendee = mysqli_fetch_assoc($infoResult);
        $attendeeName = $attendee['name'] ?? 'Unknown';
        
        // Delete attendance records first
        $deleteAttendanceSql = "DELETE FROM attendance_records WHERE attendee_id = ?";
        $deleteAttendanceStmt = mysqli_prepare($conn, $deleteAttendanceSql);
        mysqli_stmt_bind_param($deleteAttendanceStmt, "i", $attendeeId);
        
        if (!mysqli_stmt_execute($deleteAttendanceStmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        // Delete attendee
        $sql = "DELETE FROM event_attendees WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $attendeeId);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        
        logActivity($conn, $_SESSION['user_id'], 'deleted', "Deleted attendee: $attendeeName ID: $attendeeId", 'attendee', $attendeeId);
        
        echo json_encode(['success' => true, 'message' => 'Attendee deleted successfully']);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error deleting attendee: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error deleting attendee: ' . $e->getMessage()]);
        exit();
    }
}

function handleBulkAddAttendees($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $attendeesText = trim($_POST['attendees_text'] ?? '');
    $defaultStatus = mysqli_real_escape_string($conn, $_POST['default_status'] ?? 'Invited');
    $defaultRole = mysqli_real_escape_string($conn, $_POST['default_role'] ?? 'Participant');
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Please select an event']);
        exit();
    }
    
    if (empty($attendeesText)) {
        echo json_encode(['success' => false, 'message' => 'Please enter attendee information']);
        exit();
    }
    
    // Parse the text input - each line should contain: Name, Email, Phone(optional), Department(optional)
    $lines = explode("\n", $attendeesText);
    $attendees = [];
    $errors = [];
    
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Split by comma
        $parts = explode(',', $line);
        $parts = array_map('trim', $parts);
        
        $name = $parts[0] ?? '';
        $email = $parts[1] ?? '';
        $phone = $parts[2] ?? '';
        $department = $parts[3] ?? '';
        
        if (empty($name)) {
            $errors[] = "Line " . ($lineNum + 1) . ": Name is required";
            continue;
        }
        
        if (empty($email)) {
            $errors[] = "Line " . ($lineNum + 1) . ": Email is required";
            continue;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Line " . ($lineNum + 1) . ": Invalid email format for '$email'";
            continue;
        }
        
        $attendees[] = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'department' => $department,
            'role' => $defaultRole,
            'status' => $defaultStatus
        ];
    }
    
    if (empty($attendees)) {
        echo json_encode(['success' => false, 'message' => 'No valid attendees found to add. ' . implode(' ', $errors)]);
        exit();
    }
    
    $successCount = 0;
    $failedCount = 0;
    $duplicateCount = 0;
    
    mysqli_begin_transaction($conn);
    
    try {
        foreach ($attendees as $attendeeData) {
            $name = mysqli_real_escape_string($conn, $attendeeData['name']);
            $email = mysqli_real_escape_string($conn, $attendeeData['email']);
            $phone = mysqli_real_escape_string($conn, $attendeeData['phone']);
            $department = mysqli_real_escape_string($conn, $attendeeData['department']);
            $role = mysqli_real_escape_string($conn, $attendeeData['role']);
            $status = mysqli_real_escape_string($conn, $attendeeData['status']);
            
            // Check if already exists - FIXED: Use correct column names
            $checkSql = "SELECT ea.id FROM event_attendees ea 
                         LEFT JOIN users u ON ea.user_id = u.id
                         WHERE ea.event_id = ? AND (u.email = ? OR ea.email = ?)";
            $checkStmt = mysqli_prepare($conn, $checkSql);
            mysqli_stmt_bind_param($checkStmt, "iss", $eventId, $email, $email);
            mysqli_stmt_execute($checkStmt);
            mysqli_stmt_store_result($checkStmt);
            
            if (mysqli_stmt_num_rows($checkStmt) > 0) {
                $duplicateCount++;
                continue;
            }
            
            // Check if user exists with this email
            $userSql = "SELECT id FROM users WHERE email = ?";
            $userStmt = mysqli_prepare($conn, $userSql);
            mysqli_stmt_bind_param($userStmt, "s", $email);
            mysqli_stmt_execute($userStmt);
            $userResult = mysqli_stmt_get_result($userStmt);
            $user = mysqli_fetch_assoc($userResult);
            
            $userId = $user ? $user['id'] : null;
            
            // Insert
            $sql = "INSERT INTO event_attendees (event_id, user_id, name, email, phone, department, role_in_event, rsvp_status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iissssss", $eventId, $userId, $name, $email, $phone, $department, $role, $status);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception(mysqli_error($conn));
            }
            
            $attendeeId = mysqli_insert_id($conn);
            
            // Create attendance records
            $eventSql = "SELECT start_datetime, end_datetime FROM events WHERE id = ?";
            $eventStmt = mysqli_prepare($conn, $eventSql);
            mysqli_stmt_bind_param($eventStmt, "i", $eventId);
            mysqli_stmt_execute($eventStmt);
            $eventResult = mysqli_stmt_get_result($eventStmt);
            $event = mysqli_fetch_assoc($eventResult);
            
            if ($event && $event['end_datetime']) {
                $start = new DateTime($event['start_datetime']);
                $end = new DateTime($event['end_datetime']);
                $days = $start->diff($end)->days + 1;
                
                for ($day = 1; $day <= $days; $day++) {
                    $dayDate = clone $start;
                    $dayDate->modify('+' . ($day - 1) . ' days');
                    
                    $attendanceSql = "INSERT INTO attendance_records (attendee_id, day_number, attendance_date, attendance_status) 
                                      VALUES (?, ?, ?, 'Not Marked')";
                    $attendanceStmt = mysqli_prepare($conn, $attendanceSql);
                    $dateStr = $dayDate->format('Y-m-d');
                    mysqli_stmt_bind_param($attendanceStmt, "iis", $attendeeId, $day, $dateStr);
                    
                    if (!mysqli_stmt_execute($attendanceStmt)) {
                        throw new Exception(mysqli_error($conn));
                    }
                }
            }
            
            $successCount++;
        }
        
        mysqli_commit($conn);
        
        logActivity($conn, $_SESSION['user_id'], 'bulk_added', "Added $successCount attendees to event ID: $eventId", 'attendee', $eventId);
        
        $message = "$successCount attendees added successfully.";
        if ($duplicateCount > 0) {
            $message .= " $duplicateCount duplicates skipped.";
        }
        if (!empty($errors)) {
            $message .= " Errors: " . implode('; ', array_slice($errors, 0, 3));
        }
        
        echo json_encode(['success' => true, 'message' => $message]);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error in bulk add: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

function handleBulkDeleteAttendees($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $attendeeIds = $_POST['attendee_ids'] ?? [];
    
    if (empty($attendeeIds)) {
        echo json_encode(['success' => false, 'message' => 'No attendees selected']);
        exit();
    }
    
    $ids = implode(',', array_map('intval', $attendeeIds));
    
    mysqli_begin_transaction($conn);
    
    try {
        // Delete attendance records first
        $deleteAttendanceSql = "DELETE FROM attendance_records WHERE attendee_id IN ($ids)";
        if (!mysqli_query($conn, $deleteAttendanceSql)) {
            throw new Exception(mysqli_error($conn));
        }
        
        // Delete attendees
        $sql = "DELETE FROM event_attendees WHERE id IN ($ids)";
        if (!mysqli_query($conn, $sql)) {
            throw new Exception(mysqli_error($conn));
        }
        
        $count = mysqli_affected_rows($conn);
        
        mysqli_commit($conn);
        
        logActivity($conn, $_SESSION['user_id'], 'bulk_deleted', "Deleted $count attendees", 'attendee', 0);
        
        echo json_encode(['success' => true, 'message' => "$count attendees deleted successfully"]);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error in bulk delete: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error deleting attendees: ' . $e->getMessage()]);
        exit();
    }
}

function handleBulkUpdateStatus($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $attendeeIds = $_POST['attendee_ids'] ?? [];
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    if (empty($attendeeIds)) {
        echo json_encode(['success' => false, 'message' => 'No attendees selected']);
        exit();
    }
    
    if (empty($status)) {
        echo json_encode(['success' => false, 'message' => 'Status is required']);
        exit();
    }
    
    $validStatuses = ['Invited', 'Registered', 'Confirmed', 'Attended', 'Cancelled', 'No Show'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }
    
    $ids = implode(',', array_map('intval', $attendeeIds));
    
    $sql = "UPDATE event_attendees SET rsvp_status = ?, updated_at = NOW() WHERE id IN ($ids)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $status);
    
    if (mysqli_stmt_execute($stmt)) {
        $count = mysqli_affected_rows($conn);
        logActivity($conn, $_SESSION['user_id'], 'bulk_status_update', "Updated $count attendees to status: $status", 'attendee', 0);
        
        echo json_encode(['success' => true, 'message' => "$count attendees updated to $status"]);
        exit();
    } else {
        error_log("Error updating status: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => 'Error updating attendees: ' . mysqli_error($conn)]);
        exit();
    }
}

function handleSendEmail($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $emailType = mysqli_real_escape_string($conn, $_POST['email_type'] ?? 'custom');
    $subject = mysqli_real_escape_string($conn, $_POST['subject'] ?? '');
    $message = mysqli_real_escape_string($conn, $_POST['message'] ?? '');
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Attendee ID required']);
        exit();
    }
    
    if (empty($subject)) {
        echo json_encode(['success' => false, 'message' => 'Subject is required']);
        exit();
    }
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit();
    }
    
    // Get attendee details - FIXED: Use correct column names
    $sql = "SELECT ea.*, COALESCE(u.username, ea.name) as attendee_name, COALESCE(u.email, ea.email) as attendee_email, 
                   e.event_name, e.start_datetime, e.end_datetime, e.location 
            FROM event_attendees ea
            LEFT JOIN users u ON ea.user_id = u.id
            JOIN events e ON ea.event_id = e.id
            WHERE ea.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $attendeeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $attendee = mysqli_fetch_assoc($result);
    
    if (!$attendee) {
        echo json_encode(['success' => false, 'message' => 'Attendee not found']);
        exit();
    }
    
    // Send email using PHPMailer
    try {
        $emailSent = sendEmailNotification(
            $attendee['attendee_email'],
            $attendee['attendee_name'],
            $subject,
            nl2br($message)
        );
        
        if ($emailSent) {
            // Log email in database
            $logSql = "INSERT INTO email_logs (attendee_id, email_type, subject, recipient_email, sent_at) 
                       VALUES (?, ?, ?, ?, NOW())";
            $logStmt = mysqli_prepare($conn, $logSql);
            mysqli_stmt_bind_param($logStmt, "isss", $attendeeId, $emailType, $subject, $attendee['attendee_email']);
            mysqli_stmt_execute($logStmt);
            
            logActivity($conn, $_SESSION['user_id'], 'email_sent', "Sent $emailType email to: " . $attendee['attendee_email'], 'attendee', $attendeeId);
            
            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check your email configuration.']);
            exit();
        }
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Email error: ' . $e->getMessage()]);
        exit();
    }
}

function handleSendBulkEmail($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $attendeeIds = $_POST['attendee_ids'] ?? [];
    $subject = mysqli_real_escape_string($conn, $_POST['subject'] ?? '');
    $message = mysqli_real_escape_string($conn, $_POST['message'] ?? '');
    
    if (empty($attendeeIds)) {
        echo json_encode(['success' => false, 'message' => 'No attendees selected']);
        exit();
    }
    
    if (empty($subject)) {
        echo json_encode(['success' => false, 'message' => 'Subject is required']);
        exit();
    }
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit();
    }
    
    // Convert to array if it's a string
    if (is_string($attendeeIds)) {
        $attendeeIds = explode(',', $attendeeIds);
    }
    
    $attendeeIds = array_map('intval', $attendeeIds);
    $ids = implode(',', $attendeeIds);
    
    // Get attendee emails - FIXED: Use correct column names
    $sql = "SELECT ea.id, COALESCE(u.username, ea.name) as attendee_name, COALESCE(u.email, ea.email) as attendee_email 
            FROM event_attendees ea
            LEFT JOIN users u ON ea.user_id = u.id
            WHERE ea.id IN ($ids)";
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Error fetching attendees: ' . mysqli_error($conn)]);
        exit();
    }
    
    $sentCount = 0;
    $failedCount = 0;
    $failedEmails = [];
    
    while ($attendee = mysqli_fetch_assoc($result)) {
        try {
            // Send email using PHPMailer
            $emailSent = sendEmailNotification(
                $attendee['attendee_email'],
                $attendee['attendee_name'],
                $subject,
                nl2br($message)
            );
            
            if ($emailSent) {
                // Log email
                $logSql = "INSERT INTO email_logs (attendee_id, email_type, subject, recipient_email, sent_at) 
                           VALUES (?, 'bulk', ?, ?, NOW())";
                $logStmt = mysqli_prepare($conn, $logSql);
                mysqli_stmt_bind_param($logStmt, "iss", $attendee['id'], $subject, $attendee['attendee_email']);
                mysqli_stmt_execute($logStmt);
                
                $sentCount++;
            } else {
                $failedCount++;
                $failedEmails[] = $attendee['attendee_email'];
            }
        } catch (Exception $e) {
            $failedCount++;
            $failedEmails[] = $attendee['attendee_email'] . ' (' . $e->getMessage() . ')';
            error_log("Bulk email error for {$attendee['attendee_email']}: " . $e->getMessage());
        }
    }
    
    logActivity($conn, $_SESSION['user_id'], 'bulk_email', "Sent bulk email to $sentCount attendees, $failedCount failed", 'attendee', 0);
    
    if ($failedCount > 0) {
        echo json_encode(['success' => true, 'message' => "$sentCount emails sent successfully, $failedCount failed: " . implode(', ', array_slice($failedEmails, 0, 3))]);
        exit();
    } else {
        echo json_encode(['success' => true, 'message' => "$sentCount emails sent successfully"]);
        exit();
    }
}

function handleMarkAttendance($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    $attendeeId = intval($_POST['attendee_id'] ?? 0);
    $dayNumber = intval($_POST['day_number'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['attendance_status'] ?? 'Present');
    $checkIn = $_POST['check_in_time'] ?? null;
    $checkOut = $_POST['check_out_time'] ?? null;
    $notes = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');
    
    if (!$attendeeId || !$dayNumber) {
        echo json_encode(['success' => false, 'message' => 'Attendee ID and day number are required']);
        exit();
    }
    
    $validStatuses = ['Present', 'Absent', 'Late', 'Excused', 'Not Marked'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance status']);
        exit();
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Check if record exists
        $checkSql = "SELECT id FROM attendance_records WHERE attendee_id = ? AND day_number = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "ii", $attendeeId, $dayNumber);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        
        if (mysqli_num_rows($checkResult) > 0) {
            // Update existing
            $row = mysqli_fetch_assoc($checkResult);
            $sql = "UPDATE attendance_records SET 
                        attendance_status = ?,
                        check_in_time = ?,
                        check_out_time = ?,
                        notes = ?,
                        recorded_by = ?,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssii", $status, $checkIn, $checkOut, $notes, $_SESSION['user_id'], $row['id']);
        } else {
            // Insert new
            $sql = "INSERT INTO attendance_records (attendee_id, day_number, attendance_status, check_in_time, check_out_time, notes, recorded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iissssi", $attendeeId, $dayNumber, $status, $checkIn, $checkOut, $notes, $_SESSION['user_id']);
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception(mysqli_error($conn));
        }
        
        mysqli_commit($conn);
        
        echo json_encode(['success' => true, 'message' => 'Attendance marked successfully']);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error marking attendance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error marking attendance: ' . $e->getMessage()]);
        exit();
    }
}

function handleBulkMarkAttendance($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!hasAnyRole(['super_admin', 'admin', 'pm_manager'])) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $dayNumber = intval($_POST['day_number'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['attendance_status'] ?? 'Present');
    
    if (!$eventId || !$dayNumber) {
        echo json_encode(['success' => false, 'message' => 'Event ID and day number are required']);
        exit();
    }
    
    $validStatuses = ['Present', 'Absent', 'Late', 'Excused', 'Not Marked'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance status']);
        exit();
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // Get all attendees for this event
        $sql = "SELECT id FROM event_attendees WHERE event_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $updatedCount = 0;
        while ($attendee = mysqli_fetch_assoc($result)) {
            // Check if record exists
            $checkSql = "SELECT id FROM attendance_records WHERE attendee_id = ? AND day_number = ?";
            $checkStmt = mysqli_prepare($conn, $checkSql);
            mysqli_stmt_bind_param($checkStmt, "ii", $attendee['id'], $dayNumber);
            mysqli_stmt_execute($checkStmt);
            $checkResult = mysqli_stmt_get_result($checkStmt);
            
            if (mysqli_num_rows($checkResult) > 0) {
                // Update existing
                $row = mysqli_fetch_assoc($checkResult);
                $updateSql = "UPDATE attendance_records SET attendance_status = ?, recorded_by = ?, updated_at = NOW() 
                             WHERE id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($updateStmt, "sii", $status, $_SESSION['user_id'], $row['id']);
                
                if (!mysqli_stmt_execute($updateStmt)) {
                    throw new Exception(mysqli_error($conn));
                }
            } else {
                // Insert new
                $insertSql = "INSERT INTO attendance_records (attendee_id, day_number, attendance_status, recorded_by, created_at) 
                             VALUES (?, ?, ?, ?, NOW())";
                $insertStmt = mysqli_prepare($conn, $insertSql);
                mysqli_stmt_bind_param($insertStmt, "iisi", $attendee['id'], $dayNumber, $status, $_SESSION['user_id']);
                
                if (!mysqli_stmt_execute($insertStmt)) {
                    throw new Exception(mysqli_error($conn));
                }
            }
            
            $updatedCount++;
        }
        
        mysqli_commit($conn);
        
        echo json_encode(['success' => true, 'message' => "$updatedCount attendance records updated"]);
        exit();
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error in bulk mark attendance: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

function getAttendeeDetails($conn) {
    // Ensure no output before JSON
    ob_clean();
    
    $attendeeId = intval($_GET['id'] ?? 0);
    
    if (!$attendeeId) {
        echo json_encode(['success' => false, 'message' => 'Attendee ID is required']);
        exit();
    }
    
    // FIXED: Use correct column names
    $sql = "SELECT ea.*, COALESCE(u.username, ea.name) as display_name, COALESCE(u.email, ea.email) as display_email,
                   e.event_name, e.start_datetime, e.end_datetime,
                   DATEDIFF(e.end_datetime, e.start_datetime) + 1 as event_duration
            FROM event_attendees ea
            LEFT JOIN users u ON ea.user_id = u.id
            JOIN events e ON ea.event_id = e.id
            WHERE ea.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $attendeeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit();
    }
    
    $attendee = mysqli_fetch_assoc($result);
    
    if ($attendee) {
        // Get attendance records
        $attSql = "SELECT * FROM attendance_records WHERE attendee_id = ? ORDER BY day_number";
        $attStmt = mysqli_prepare($conn, $attSql);
        mysqli_stmt_bind_param($attStmt, "i", $attendeeId);
        mysqli_stmt_execute($attStmt);
        $attResult = mysqli_stmt_get_result($attStmt);
        
        $attendance = [];
        while ($row = mysqli_fetch_assoc($attResult)) {
            $attendance[] = $row;
        }
        
        $attendee['attendance_records'] = $attendance;
        
        echo json_encode(['success' => true, 'data' => $attendee]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Attendee not found with ID: ' . $attendeeId]);
        exit();
    }
}

function getAttendanceStats($conn, $eventId) {
    // Ensure no output before JSON
    ob_clean();
    
    if (!$eventId) {
        echo json_encode(['success' => false, 'message' => 'Event ID required']);
        exit();
    }
    
    $stats = [
        'daily' => [],
        'summary' => []
    ];
    
    // Get daily attendance counts
    $sql = "SELECT 
                ar.day_number,
                COUNT(DISTINCT ar.attendee_id) as total,
                SUM(CASE WHEN ar.attendance_status = 'Present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN ar.attendance_status = 'Absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN ar.attendance_status = 'Late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN ar.attendance_status = 'Excused' THEN 1 ELSE 0 END) as excused,
                SUM(CASE WHEN ar.attendance_status = 'Not Marked' THEN 1 ELSE 0 END) as not_marked,
                MIN(ar.check_in_time) as earliest_checkin,
                MAX(ar.check_out_time) as latest_checkout
            FROM attendance_records ar
            JOIN event_attendees ea ON ar.attendee_id = ea.id
            WHERE ea.event_id = ?
            GROUP BY ar.day_number
            ORDER BY ar.day_number";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $eventId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['daily'][] = $row;
    }
    
    // Get summary statistics
    $sql = "SELECT 
                COUNT(DISTINCT ea.id) as total_attendees,
                SUM(CASE WHEN ea.rsvp_status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN ea.rsvp_status = 'Registered' THEN 1 ELSE 0 END) as registered,
                SUM(CASE WHEN ea.rsvp_status = 'Invited' THEN 1 ELSE 0 END) as invited,
                SUM(CASE WHEN ea.rsvp_status = 'Attended' THEN 1 ELSE 0 END) as attended,
                SUM(CASE WHEN ea.rsvp_status = 'No Show' THEN 1 ELSE 0 END) as no_show,
                SUM(CASE WHEN ea.rsvp_status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
                AVG(CASE 
                    WHEN e.end_datetime IS NOT NULL 
                    THEN (SELECT COUNT(*) FROM attendance_records ar 
                          WHERE ar.attendee_id = ea.id AND ar.attendance_status = 'Present') * 100.0 /
                          (DATEDIFF(e.end_datetime, e.start_datetime) + 1)
                    ELSE 0 
                END) as avg_attendance_rate
            FROM event_attendees ea
            JOIN events e ON ea.event_id = e.id
            WHERE ea.event_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $eventId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats['summary'] = mysqli_fetch_assoc($result);
    
    echo json_encode(['success' => true, 'data' => $stats]);
    exit();
}

function exportAttendees($conn, $eventId) {
    $format = $_GET['format'] ?? 'csv';
    
    if ($eventId > 0) {
        $sql = "SELECT 
                    COALESCE(u.username, ea.name) as 'Name',
                    COALESCE(u.email, ea.email) as 'Email',
                    ea.phone as 'Phone',
                    ea.department as 'Department',
                    ea.rsvp_status as 'Status',
                    DATE(ea.created_at) as 'Registration Date',
                    (SELECT COUNT(*) FROM attendance_records ar 
                     WHERE ar.attendee_id = ea.id AND ar.attendance_status = 'Present') as 'Days Attended',
                    DATEDIFF(e.end_datetime, e.start_datetime) + 1 as 'Total Days',
                    ROUND((SELECT COUNT(*) FROM attendance_records ar 
                          WHERE ar.attendee_id = ea.id AND ar.attendance_status = 'Present') * 100.0 /
                          (DATEDIFF(e.end_datetime, e.start_datetime) + 1), 2) as 'Attendance Rate %'
                FROM event_attendees ea
                LEFT JOIN users u ON ea.user_id = u.id
                JOIN events e ON ea.event_id = e.id
                WHERE ea.event_id = ?
                ORDER BY COALESCE(u.username, ea.name)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $sql = "SELECT 
                    e.event_name as 'Event',
                    COALESCE(u.username, ea.name) as 'Name',
                    COALESCE(u.email, ea.email) as 'Email',
                    ea.phone as 'Phone',
                    ea.department as 'Department',
                    ea.rsvp_status as 'Status',
                    DATE(ea.created_at) as 'Registration Date'
                FROM event_attendees ea
                LEFT JOIN users u ON ea.user_id = u.id
                JOIN events e ON ea.event_id = e.id
                ORDER BY e.start_datetime DESC, COALESCE(u.username, ea.name)";
        $result = mysqli_query($conn, $sql);
    }
    
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="attendees_export_' . date('Y-m-d') . '.csv"');
        
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
        header('Content-Disposition: attachment; filename="attendees_export_' . date('Y-m-d') . '.xls"');
        
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

function getAttendanceStatusBadge($status) {
    switch ($status) {
        case 'Present': return 'bg-success';
        case 'Absent': return 'bg-danger';
        case 'Late': return 'bg-warning';
        case 'Excused': return 'bg-info';
        case 'Not Marked': return 'bg-secondary';
        default: return 'bg-secondary';
    }
}

function getRSVPBadge($status) {
    switch ($status) {
        case 'Confirmed': return 'bg-success';
        case 'Registered': return 'bg-info';
        case 'Invited': return 'bg-primary';
        case 'Attended': return 'bg-success';
        case 'No Show': return 'bg-danger';
        case 'Cancelled': return 'bg-danger';
        default: return 'bg-secondary';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendee Management - Dashen Bank BSPM</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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

        /* Stats Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 16px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.95rem;
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

        /* Avatar */
        .avatar-circle {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
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
            text-decoration: none;
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
        .btn-action.email:hover { background: var(--dashen-info); }

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

        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #e8eaed;
            margin-bottom: 20px;
        }

        .nav-tabs .nav-link {
            color: #5f6368;
            font-weight: 500;
            border: none;
            padding: 12px 20px;
            transition: var(--transition);
        }

        .nav-tabs .nav-link:hover {
            color: var(--dashen-primary);
        }

        .nav-tabs .nav-link.active {
            color: var(--dashen-primary);
            background: transparent;
            border-bottom: 3px solid var(--dashen-primary);
        }

        /* Progress Bar */
        .progress {
            height: 8px;
            border-radius: 4px;
            background: #e8eaed;
        }

        .progress-bar {
            background: var(--dashen-primary);
            border-radius: 4px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state i {
            opacity: 0.5;
            color: var(--dashen-primary);
        }

        .empty-state h4 {
            margin: 15px 0 10px;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-row {
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
            .stats-row {
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
                    <h1>Attendee Management</h1>
                    <p>Manage event attendees, RSVPs, and multi-day attendance tracking</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($eventId > 0): ?>
                    <a href="event_details.php?id=<?php echo $eventId; ?>" class="btn-outline-dashen">
                        <i class="fas fa-arrow-left"></i> Back to Event
                    </a>
                    <?php endif; ?>
                    <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                    <button class="btn-dashen" onclick="openAddAttendeeModal()">
                        <i class="fas fa-user-plus"></i> Add Attendee
                    </button>
                    <button class="btn-outline-dashen" data-bs-toggle="modal" data-bs-target="#bulkAddModal">
                        <i class="fas fa-users"></i> Bulk Add
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Display Messages -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
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
                        <p class="mb-1">
                            <i class="fas fa-calendar me-2"></i>
                            <?php echo date('F j, Y g:i A', strtotime($selectedEvent['start_datetime'])); ?>
                            <?php if ($selectedEvent['end_datetime']): ?>
                            - <?php echo date('F j, Y g:i A', strtotime($selectedEvent['end_datetime'])); ?>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <?php echo htmlspecialchars($selectedEvent['location']); ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge <?php echo getStatusBadge($selectedEvent['status']); ?> fs-6">
                            <?php echo $selectedEvent['status']; ?>
                        </span>
                        <?php if ($attendanceStats['total_days'] > 1): ?>
                        <div class="mt-2">
                            <span class="badge badge-info">
                                <i class="fas fa-calendar-week"></i> <?php echo $attendanceStats['total_days']; ?> Days Event
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(139, 30, 63, 0.1); color: var(--dashen-primary);">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-value"><?php echo $rsvpStats['total']; ?></div>
                    <div class="stat-label">Total Attendees</div>
                </div>
                
                <div class="stat-card" style="border-left-color: var(--dashen-success);">
                    <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $rsvpStats['Confirmed']; ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="stat-card" style="border-left-color: var(--dashen-warning);">
                    <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $rsvpStats['Registered'] + $rsvpStats['Invited']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                
                <div class="stat-card" style="border-left-color: var(--dashen-info);">
                    <div class="stat-icon" style="background: rgba(23, 162, 184, 0.1); color: #17a2b8;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-value"><?php echo $attendanceStats['average_attendance_rate']; ?>%</div>
                    <div class="stat-label">Avg. Attendance Rate</div>
                </div>
            </div>
            
            <!-- Filters Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Attendees</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Select Event</label>
                                <select class="form-select" id="eventFilter" name="event_id">
                                    <option value="">All Events</option>
                                    <?php foreach ($events as $event): ?>
                                    <option value="<?php echo $event['id']; ?>" 
                                            <?php echo ($eventId == $event['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($event['event_name']); ?>
                                        (<?php echo date('M j, Y', strtotime($event['start_datetime'])); ?>)
                                        - <?php echo $event['attendee_count'] ?? 0; ?> attendees
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="statusFilter" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="Invited">Invited</option>
                                    <option value="Registered">Registered</option>
                                    <option value="Confirmed">Confirmed</option>
                                    <option value="Attended">Attended</option>
                                    <option value="Cancelled">Cancelled</option>
                                    <option value="No Show">No Show</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-flex gap-2 w-100">
                                    <button type="submit" class="btn-dashen flex-grow-1">
                                        <i class="fas fa-search"></i> Apply
                                    </button>
                                    <a href="attendees.php<?php echo $eventId ? '?event_id=' . $eventId : ''; ?>" class="btn-outline-dashen">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabs for different views -->
            <?php if ($eventId > 0 && $attendanceStats['total_days'] > 1): ?>
            <ul class="nav nav-tabs" id="attendeeTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $view == 'list' ? 'active' : ''; ?>" 
                            onclick="window.location.href='attendees.php?event_id=<?php echo $eventId; ?>&view=list'">
                        <i class="fas fa-list me-1"></i> List View
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $view == 'daily' ? 'active' : ''; ?>" 
                            onclick="window.location.href='attendees.php?event_id=<?php echo $eventId; ?>&view=daily'">
                        <i class="fas fa-calendar-day me-1"></i> Daily Attendance
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $view == 'stats' ? 'active' : ''; ?>" 
                            onclick="window.location.href='attendees.php?event_id=<?php echo $eventId; ?>&view=stats'">
                        <i class="fas fa-chart-bar me-1"></i> Statistics
                    </button>
                </li>
            </ul>
            <?php endif; ?>
            
            <!-- Main Content Area -->
            <?php if ($view == 'daily' && $eventId > 0 && $attendanceStats['total_days'] > 1): ?>
                <!-- Daily Attendance View -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-calendar-day me-2"></i>Daily Attendance Tracking</h5>
                    </div>
                    <div class="card-body">
                        <!-- Day Selector -->
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label class="form-label">Select Day</label>
                                <select class="form-select" id="daySelector">
                                    <?php foreach ($eventDays as $day): ?>
                                    <option value="<?php echo $day['day_number']; ?>">
                                        Day <?php echo $day['day_number']; ?> - <?php echo $day['formatted_date']; ?> (<?php echo $day['day_name']; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quick Actions</label>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-dashen btn-sm" id="markAllPresent">
                                        <i class="fas fa-check-circle"></i> All Present
                                    </button>
                                    <button class="btn-outline-dashen btn-sm" id="markAllAbsent">
                                        <i class="fas fa-times-circle"></i> All Absent
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <button class="btn-dashen btn-sm" id="saveAllAttendance">
                                    <i class="fas fa-save"></i> Save All Changes
                                </button>
                            </div>
                        </div>
                        
                        <!-- Attendance Table -->
                        <div class="table-responsive">
                            <table class="table" id="attendanceTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Attendee</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Check In</th>
                                        <th>Check Out</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="attendanceTableBody">
                                    <?php foreach ($attendees as $attendee): 
                                        // Get attendance for current day
                                        $currentDayAttendance = null;
                                        foreach ($attendanceRecords as $record) {
                                            if ($record['attendee_id'] == $attendee['id'] && $record['day_number'] == 1) {
                                                $currentDayAttendance = $record;
                                                break;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo $attendee['id']; ?></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-3">
                                                    <?php echo strtoupper(substr($attendee['display_name'] ?? 'U', 0, 2)); ?>
                                                </div>
                                                <strong><?php echo htmlspecialchars($attendee['display_name'] ?? 'Unknown'); ?></strong>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($attendee['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($attendee['department'] ?? 'N/A'); ?></td>
                                        <td>
                                            <select class="form-select form-select-sm attendance-status" 
                                                    data-attendee-id="<?php echo $attendee['id']; ?>"
                                                    data-day="1">
                                                <option value="Not Marked" <?php echo ($currentDayAttendance && $currentDayAttendance['attendance_status'] == 'Not Marked') ? 'selected' : ''; ?>>Not Marked</option>
                                                <option value="Present" <?php echo ($currentDayAttendance && $currentDayAttendance['attendance_status'] == 'Present') ? 'selected' : ''; ?>>Present</option>
                                                <option value="Absent" <?php echo ($currentDayAttendance && $currentDayAttendance['attendance_status'] == 'Absent') ? 'selected' : ''; ?>>Absent</option>
                                                <option value="Late" <?php echo ($currentDayAttendance && $currentDayAttendance['attendance_status'] == 'Late') ? 'selected' : ''; ?>>Late</option>
                                                <option value="Excused" <?php echo ($currentDayAttendance && $currentDayAttendance['attendance_status'] == 'Excused') ? 'selected' : ''; ?>>Excused</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="time" class="form-control form-control-sm check-in-time" 
                                                   data-attendee-id="<?php echo $attendee['id']; ?>"
                                                   data-day="1"
                                                   value="<?php echo $currentDayAttendance && $currentDayAttendance['check_in_time'] ? $currentDayAttendance['check_in_time'] : ''; ?>">
                                        </td>
                                        <td>
                                            <input type="time" class="form-control form-control-sm check-out-time" 
                                                   data-attendee-id="<?php echo $attendee['id']; ?>"
                                                   data-day="1"
                                                   value="<?php echo $currentDayAttendance && $currentDayAttendance['check_out_time'] ? $currentDayAttendance['check_out_time'] : ''; ?>">
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm attendance-notes" 
                                                   data-attendee-id="<?php echo $attendee['id']; ?>"
                                                   data-day="1"
                                                   value="<?php echo $currentDayAttendance && $currentDayAttendance['notes'] ? htmlspecialchars($currentDayAttendance['notes']) : ''; ?>"
                                                   placeholder="Notes">
                                        </td>
                                        <td>
                                            <button class="btn-action save-attendance" 
                                                    data-attendee-id="<?php echo $attendee['id']; ?>"
                                                    data-day="1"
                                                    title="Save Attendance">
                                                <i class="fas fa-save"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php elseif ($view == 'stats' && $eventId > 0): ?>
                <!-- Statistics View -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>RSVP Distribution</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="rsvpChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Attendance Classification</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-calendar-week me-2"></i>Daily Attendance Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Present</th>
                                                <th>Absent</th>
                                                <th>Late</th>
                                                <th>Excused</th>
                                                <th>Not Marked</th>
                                                <th>Total</th>
                                                <th>Attendance Rate</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dailyStatsBody">
                                            <!-- Filled by AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- List View (Default) -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>
                            <?php echo $eventId ? 'Event Attendees' : 'All Attendees'; ?>
                            <span class="badge bg-primary ms-2"><?php echo count($attendees); ?> total</span>
                        </h5>
                        <div class="d-flex gap-2">
                            <?php if (!empty($attendees)): ?>
                            <button class="btn-outline-dashen btn-sm" onclick="exportAttendees('csv')">
                                <i class="fas fa-file-csv"></i> CSV
                            </button>
                            <button class="btn-outline-dashen btn-sm" onclick="exportAttendees('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                            <?php if ($eventId > 0): ?>
                            <button class="btn-outline-dashen btn-sm" id="bulkEmailBtn">
                                <i class="fas fa-envelope"></i> Email Selected
                            </button>
                            <?php endif; ?>
                            <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                            <button class="btn-outline-dashen btn-sm" id="bulkDeleteBtn" style="display: none;">
                                <i class="fas fa-trash"></i> Delete Selected
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (!empty($attendees)): ?>
                        <div class="table-responsive">
                            <table class="table" id="attendeesTable">
                                <thead>
                                    <tr>
                                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                        <th width="40px">
                                            <input type="checkbox" class="form-check-input" id="selectAll">
                                        </th>
                                        <?php endif; ?>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Department</th>
                                        <?php if (!$eventId): ?>
                                        <th>Event</th>
                                        <?php endif; ?>
                                        <th>Registration Date</th>
                                        <th>Status</th>
                                        <?php if ($eventId && $attendanceStats['total_days'] > 1): ?>
                                        <th>Attendance Rate</th>
                                        <th>Classification</th>
                                        <?php endif; ?>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendees as $attendee): ?>
                                    <tr>
                                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                        <td>
                                            <input type="checkbox" class="form-check-input attendee-select" 
                                                   value="<?php echo $attendee['id']; ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td><code>#<?php echo $attendee['id']; ?></code></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle me-3">
                                                    <?php echo strtoupper(substr($attendee['display_name'] ?? 'U', 0, 2)); ?>
                                                </div>
                                                <strong><?php echo htmlspecialchars($attendee['display_name'] ?? 'Unknown'); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="mailto:<?php echo htmlspecialchars($attendee['email'] ?? ''); ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($attendee['email'] ?? ''); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($attendee['phone'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($attendee['department'] ?? 'N/A'); ?></td>
                                        
                                        <?php if (!$eventId): ?>
                                        <td><?php echo htmlspecialchars($attendee['event_name'] ?? 'N/A'); ?></td>
                                        <?php endif; ?>
                                        
                                        <td><?php echo date('M j, Y', strtotime($attendee['created_at'] ?? date('Y-m-d'))); ?></td>
                                        
                                        <td>
                                            <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                            <select class="form-select form-select-sm status-select" 
                                                    data-id="<?php echo $attendee['id']; ?>"
                                                    onchange="updateStatus(this)">
                                                <option value="Invited" <?php echo ($attendee['rsvp_status'] == 'Invited') ? 'selected' : ''; ?>>Invited</option>
                                                <option value="Registered" <?php echo ($attendee['rsvp_status'] == 'Registered') ? 'selected' : ''; ?>>Registered</option>
                                                <option value="Confirmed" <?php echo ($attendee['rsvp_status'] == 'Confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                                                <option value="Attended" <?php echo ($attendee['rsvp_status'] == 'Attended') ? 'selected' : ''; ?>>Attended</option>
                                                <option value="Cancelled" <?php echo ($attendee['rsvp_status'] == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                                                <option value="No Show" <?php echo ($attendee['rsvp_status'] == 'No Show') ? 'selected' : ''; ?>>No Show</option>
                                            </select>
                                            <?php else: ?>
                                            <span class="badge <?php echo getRSVPBadge($attendee['rsvp_status'] ?? 'Invited'); ?>">
                                                <?php echo $attendee['rsvp_status'] ?? 'Invited'; ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <?php if ($eventId && $attendanceStats['total_days'] > 1): ?>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1 me-2" style="height: 6px; width: 80px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo $attendee['attendance_rate'] ?? 0; ?>%"></div>
                                                </div>
                                                <small><?php echo $attendee['attendance_rate'] ?? 0; ?>%</small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php 
                                                echo ($attendee['attendance_classification'] ?? '') == 'Full Attendance' ? 'bg-success' : 
                                                    (($attendee['attendance_classification'] ?? '') == 'Partial Attendance' ? 'bg-warning' : 'bg-danger'); 
                                            ?>">
                                                <?php echo $attendee['attendance_classification'] ?? 'Not Started'; ?>
                                            </span>
                                        </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <div class="action-buttons">
                                                <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                                                <button class="btn-action edit-attendee" 
                                                        data-id="<?php echo $attendee['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($attendee['display_name'] ?? ''); ?>"
                                                        data-email="<?php echo htmlspecialchars($attendee['email'] ?? ''); ?>"
                                                        data-phone="<?php echo htmlspecialchars($attendee['phone'] ?? ''); ?>"
                                                        data-department="<?php echo htmlspecialchars($attendee['department'] ?? ''); ?>"
                                                        data-role="<?php echo htmlspecialchars($attendee['role_in_event'] ?? 'Participant'); ?>"
                                                        data-rsvp="<?php echo $attendee['rsvp_status'] ?? 'Invited'; ?>"
                                                        title="Edit Attendee">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-action delete delete-attendee" 
                                                        data-id="<?php echo $attendee['id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($attendee['display_name'] ?? 'Unknown'); ?>"
                                                        title="Delete Attendee">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn-action email send-email" 
                                                        data-id="<?php echo $attendee['id']; ?>"
                                                        data-email="<?php echo htmlspecialchars($attendee['email'] ?? ''); ?>"
                                                        data-name="<?php echo htmlspecialchars($attendee['display_name'] ?? 'Unknown'); ?>"
                                                        title="Send Email">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Bulk Actions -->
                        <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                        <div class="row mt-4" id="bulkActions" style="display: none;">
                            <div class="col-12">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center gap-3 flex-wrap">
                                            <span class="fw-bold" id="selectedCount">0</span>
                                            <span>selected</span>
                                            <select class="form-select form-select-sm" style="width: 150px;" id="bulkAction">
                                                <option value="">Select Action</option>
                                                <option value="confirm">Mark as Confirmed</option>
                                                <option value="register">Mark as Registered</option>
                                                <option value="invite">Mark as Invited</option>
                                                <option value="cancel">Mark as Cancelled</option>
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
                        </div>
                        <?php endif; ?>
                        
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-users fa-4x"></i>
                            <h4>No attendees found</h4>
                            <p class="text-muted">
                                <?php echo $eventId ? 'No attendees registered for this event yet.' : 'No attendees found in the system.'; ?>
                            </p>
                            <?php if (hasAnyRole(['super_admin', 'admin', 'pm_manager'])): ?>
                            <button class="btn-dashen" onclick="openAddAttendeeModal()">
                                <i class="fas fa-user-plus"></i> Add First Attendee
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add/Edit Attendee Modal -->
    <div class="modal fade" id="addAttendeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Attendee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addAttendeeForm">
                    <input type="hidden" name="ajax_action" id="ajaxAction" value="add_attendee">
                    <input type="hidden" name="attendee_id" id="attendeeId" value="">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Event *</label>
                            <select class="form-select" name="event_id" id="modalEventId" required>
                                <option value="">Select Event</option>
                                <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['id']; ?>" 
                                        <?php echo ($eventId == $event['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($event['event_name']); ?>
                                    (<?php echo date('M j, Y', strtotime($event['start_datetime'])); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="name" id="attendeeName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control" name="email" id="attendeeEmail" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone" id="attendeePhone">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department" id="attendeeDepartment">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['department_name']); ?>">
                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                </option>
                                <?php endforeach; ?>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role in Event</label>
                            <select class="form-select" name="role_in_event" id="attendeeRole">
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
                            <select class="form-select" name="rsvp_status" id="attendeeRSVP">
                                <option value="Invited">Invited</option>
                                <option value="Registered">Registered</option>
                                <option value="Confirmed">Confirmed</option>
                                <option value="Attended">Attended</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="No Show">No Show</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-dashen" id="saveAttendeeBtn">
                            <i class="fas fa-save me-2"></i> Save Attendee
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div class="modal fade" id="bulkAddModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bulk Add Attendees</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="bulkAddForm">
                        <input type="hidden" name="ajax_action" value="bulk_add">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Event *</label>
                            <select class="form-select" name="event_id" id="bulkEventId" required>
                                <option value="">Select Event</option>
                                <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['id']; ?>" 
                                        <?php echo ($eventId == $event['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($event['event_name']); ?>
                                    (<?php echo date('M j, Y', strtotime($event['start_datetime'])); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Default Status</label>
                                <select class="form-select" name="default_status">
                                    <option value="Invited">Invited</option>
                                    <option value="Registered">Registered</option>
                                    <option value="Confirmed">Confirmed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Default Role</label>
                                <select class="form-select" name="default_role">
                                    <option value="Participant">Participant</option>
                                    <option value="Speaker">Speaker</option>
                                    <option value="VIP">VIP</option>
                                    <option value="Volunteer">Volunteer</option>
                                    <option value="Staff">Staff</option>
                                    <option value="Guest">Guest</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Add Attendees (One per line)</label>
                            <textarea class="form-control" name="attendees_text" rows="8" 
                                      placeholder="Name, Email, Phone (optional), Department (optional)&#10;Example:&#10;John Doe, john@example.com, 0911223344, IT&#10;Jane Smith, jane@example.com, , HR&#10;Bob Johnson, bob@example.com"></textarea>
                            <small class="text-muted">Format: Name, Email, Phone (optional), Department (optional) - separated by commas</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Instructions:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Enter one attendee per line</li>
                                <li>Required fields: Name, Email</li>
                                <li>Optional fields: Phone, Department</li>
                                <li>Separate fields with commas</li>
                            </ul>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-dashen" id="processBulkAdd">
                        <i class="fas fa-user-plus"></i> Add Attendees
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Email Modal -->
    <div class="modal fade" id="bulkEmailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Bulk Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bulkEmailForm">
                    <input type="hidden" name="ajax_action" value="send_bulk_email">
                    <input type="hidden" name="attendee_ids" id="bulkEmailAttendeeIds" value="">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Recipients</label>
                            <div class="form-control bg-light" id="bulkEmailRecipients" style="min-height: 60px; overflow-y: auto;">
                                Loading...
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Subject *</label>
                            <input type="text" class="form-control" name="subject" id="bulkEmailSubject" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Message *</label>
                            <textarea class="form-control" name="message" id="bulkEmailMessage" rows="6" required></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Email will be sent to all selected attendees.
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-dashen">
                            <i class="fas fa-paper-plane"></i> Send Emails
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Send Email Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="emailForm">
                    <input type="hidden" name="ajax_action" value="send_email">
                    <input type="hidden" name="attendee_id" id="emailAttendeeId">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">To</label>
                            <input type="text" class="form-control" id="emailTo" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Type</label>
                            <select class="form-select" name="email_type" id="emailType">
                                <option value="invitation">Invitation</option>
                                <option value="reminder">Reminder</option>
                                <option value="thankyou">Thank You</option>
                                <option value="custom">Custom</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Subject *</label>
                            <input type="text" class="form-control" name="subject" id="emailSubject" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Message *</label>
                            <textarea class="form-control" name="message" id="emailMessage" rows="6" required></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-dashen">
                            <i class="fas fa-paper-plane"></i> Send Email
                        </button>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        if ($('#attendeesTable').length) {
            $('#attendeesTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    emptyTable: "No attendees found",
                    info: "Showing _START_ to _END_ of _TOTAL_ attendees",
                    infoEmpty: "Showing 0 to 0 of 0 attendees",
                    infoFiltered: "(filtered from _MAX_ total attendees)",
                    lengthMenu: "Show _MENU_ attendees",
                    search: "Search:"
                }
            });
        }
        
        // Select All checkbox
        $('#selectAll').change(function() {
            $('.attendee-select').prop('checked', $(this).prop('checked'));
            updateBulkActions();
        });
        
        // Individual checkbox change
        $(document).on('change', '.attendee-select', function() {
            updateBulkActions();
            const allChecked = $('.attendee-select:checked').length === $('.attendee-select').length;
            $('#selectAll').prop('checked', allChecked);
        });
        
        // Update bulk actions visibility
        function updateBulkActions() {
            const selectedCount = $('.attendee-select:checked').length;
            if (selectedCount > 0) {
                $('#bulkActions').show();
                $('#selectedCount').text(selectedCount);
                $('#bulkDeleteBtn').show().text(`Delete Selected (${selectedCount})`);
            } else {
                $('#bulkActions').hide();
                $('#bulkDeleteBtn').hide();
            }
        }
        
        // Clear selection
        window.clearSelection = function() {
            $('.attendee-select').prop('checked', false);
            $('#selectAll').prop('checked', false);
            $('#bulkActions').hide();
            $('#bulkDeleteBtn').hide();
        };
        
        // Execute bulk action
        window.executeBulkAction = function() {
            const action = $('#bulkAction').val();
            if (!action) {
                Swal.fire('Warning', 'Please select an action', 'warning');
                return;
            }
            
            const selectedIds = [];
            $('.attendee-select:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (selectedIds.length === 0) {
                Swal.fire('Warning', 'No attendees selected', 'warning');
                return;
            }
            
            if (action === 'delete') {
                Swal.fire({
                    title: 'Delete Attendees?',
                    html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> attendees?`,
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
                                ajax_action: 'bulk_delete',
                                attendee_ids: selectedIds
                            },
                            dataType: 'json',
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
                                Swal.fire('Error', 'Failed to delete attendees: ' + error, 'error');
                            }
                        });
                    }
                });
            } else {
                // Status update
                let status = '';
                switch(action) {
                    case 'confirm': status = 'Confirmed'; break;
                    case 'register': status = 'Registered'; break;
                    case 'invite': status = 'Invited'; break;
                    case 'cancel': status = 'Cancelled'; break;
                }
                
                Swal.fire({
                    title: 'Update Status',
                    text: `Mark ${selectedIds.length} attendees as ${status}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Yes, update!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                ajax_action: 'bulk_update_status',
                                attendee_ids: selectedIds,
                                status: status
                            },
                            dataType: 'json',
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
                                Swal.fire('Error', 'Failed to update attendees: ' + error, 'error');
                            }
                        });
                    }
                });
            }
        };
        
        // Open Add Attendee Modal
        window.openAddAttendeeModal = function() {
            $('#ajaxAction').val('add_attendee');
            $('#attendeeId').val('');
            $('#modalTitle').text('Add Attendee');
            $('#addAttendeeForm')[0].reset();
            $('#modalEventId').val('<?php echo $eventId; ?>');
            $('#attendeeRSVP').val('Invited');
            $('#attendeeRole').val('Participant');
            $('#addAttendeeModal').modal('show');
        };
        
        // Add Attendee Form Submit
        $('#addAttendeeForm').submit(function(e) {
            e.preventDefault();
            
            const submitBtn = $('#saveAttendeeBtn');
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
                        submitBtn.html(originalText).prop('disabled', false);
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    submitBtn.html(originalText).prop('disabled', false);
                    Swal.fire('Error', 'Failed to add attendee: ' + error, 'error');
                }
            });
        });
        
        // Edit Attendee
        $('.edit-attendee').click(function() {
            const attendeeId = $(this).data('id');
            const name = $(this).data('name');
            const email = $(this).data('email');
            const phone = $(this).data('phone');
            const department = $(this).data('department');
            const role = $(this).data('role');
            const rsvp = $(this).data('rsvp');
            
            $('#ajaxAction').val('edit_attendee');
            $('#attendeeId').val(attendeeId);
            $('#modalEventId').val('<?php echo $eventId; ?>');
            $('#attendeeName').val(name);
            $('#attendeeEmail').val(email);
            $('#attendeePhone').val(phone);
            $('#attendeeDepartment').val(department);
            $('#attendeeRole').val(role);
            $('#attendeeRSVP').val(rsvp);
            $('#modalTitle').text('Edit Attendee');
            
            $('#addAttendeeModal').modal('show');
        });
        
        // Reset modal when closed
        $('#addAttendeeModal').on('hidden.bs.modal', function() {
            if ($('#ajaxAction').val() !== 'edit_attendee') {
                $('#addAttendeeForm')[0].reset();
            }
        });
        
        // Delete Attendee
        $('.delete-attendee').click(function() {
            const attendeeId = $(this).data('id');
            const attendeeName = $(this).data('name');
            
            Swal.fire({
                title: 'Delete Attendee?',
                html: `Are you sure you want to delete <strong>${attendeeName}</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'delete_attendee',
                            attendee_id: attendeeId
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
                            Swal.fire('Error', 'Failed to delete attendee: ' + error, 'error');
                        }
                    });
                }
            });
        });
        
        // Send Email
        $('.send-email').click(function() {
            const attendeeId = $(this).data('id');
            const attendeeName = $(this).data('name');
            const attendeeEmail = $(this).data('email');
            
            $('#emailAttendeeId').val(attendeeId);
            $('#emailTo').val(attendeeName + ' <' + attendeeEmail + '>');
            
            // Set default template
            const eventName = '<?php echo addslashes($selectedEvent['event_name'] ?? 'Event'); ?>';
            const eventDate = '<?php echo addslashes(date('F j, Y', strtotime($selectedEvent['start_datetime'] ?? ''))); ?>';
            const eventTime = '<?php echo addslashes(date('g:i A', strtotime($selectedEvent['start_datetime'] ?? ''))); ?>';
            const eventLocation = '<?php echo addslashes($selectedEvent['location'] ?? 'TBD'); ?>';
            
            const subject = `Invitation: ${eventName}`;
            const message = `Dear ${attendeeName},\n\nYou are invited to attend: ${eventName}\nDate: ${eventDate}\nTime: ${eventTime}\nLocation: ${eventLocation}\n\nPlease confirm your attendance.\n\nBest regards,\nDashen Bank Events Team`;
            
            $('#emailSubject').val(subject);
            $('#emailMessage').val(message);
            $('#emailType').val('invitation');
            
            $('#emailModal').modal('show');
        });
        
        // Email type change
        $('#emailType').change(function() {
            const type = $(this).val();
            const emailTo = $('#emailTo').val();
            let attendeeName = 'Attendee';
            if (emailTo) {
                attendeeName = emailTo.split('<')[0].trim();
            }
            
            const eventName = '<?php echo addslashes($selectedEvent['event_name'] ?? 'Event'); ?>';
            const eventDate = '<?php echo addslashes(date('F j, Y', strtotime($selectedEvent['start_datetime'] ?? ''))); ?>';
            const eventTime = '<?php echo addslashes(date('g:i A', strtotime($selectedEvent['start_datetime'] ?? ''))); ?>';
            const eventLocation = '<?php echo addslashes($selectedEvent['location'] ?? 'TBD'); ?>';
            
            let subject = '';
            let message = '';
            
            switch(type) {
                case 'invitation':
                    subject = `Invitation: ${eventName}`;
                    message = `Dear ${attendeeName},\n\nYou are invited to attend: ${eventName}\nDate: ${eventDate}\nTime: ${eventTime}\nLocation: ${eventLocation}\n\nPlease confirm your attendance.\n\nBest regards,\nDashen Bank Events Team`;
                    break;
                    
                case 'reminder':
                    subject = `Reminder: ${eventName}`;
                    message = `Dear ${attendeeName},\n\nThis is a reminder for the upcoming event:\n${eventName}\nDate: ${eventDate}\nTime: ${eventTime}\nLocation: ${eventLocation}\n\nWe look forward to seeing you there.\n\nBest regards,\nDashen Bank Events Team`;
                    break;
                    
                case 'thankyou':
                    subject = `Thank You - ${eventName}`;
                    message = `Dear ${attendeeName},\n\nThank you for attending ${eventName}.\nWe hope you found the event valuable.\n\nBest regards,\nDashen Bank Events Team`;
                    break;
                    
                case 'custom':
                    subject = '';
                    message = '';
                    break;
            }
            
            $('#emailSubject').val(subject);
            $('#emailMessage').val(message);
        });
        
        // Send Email Form
        $('#emailForm').submit(function(e) {
            e.preventDefault();
            
            const submitBtn = $(this).find('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Sending...').prop('disabled', true);
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        $('#emailModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Email Sent',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        submitBtn.html(originalText).prop('disabled', false);
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    submitBtn.html(originalText).prop('disabled', false);
                    Swal.fire('Error', 'Failed to send email: ' + error, 'error');
                }
            });
        });
        
        // Bulk Email button
        $('#bulkEmailBtn').click(function() {
            const selectedIds = [];
            const recipientNames = [];
            
            $('.attendee-select:checked').each(function() {
                selectedIds.push($(this).val());
                const row = $(this).closest('tr');
                const name = row.find('td:eq(2)').text().trim(); // Name column
                recipientNames.push(name);
            });
            
            if (selectedIds.length === 0) {
                Swal.fire('Warning', 'Please select attendees first', 'warning');
                return;
            }
            
            $('#bulkEmailAttendeeIds').val(selectedIds.join(','));
            $('#bulkEmailRecipients').html(recipientNames.join('<br>'));
            $('#bulkEmailSubject').val('Event Communication');
            $('#bulkEmailMessage').val('');
            
            $('#bulkEmailModal').modal('show');
        });
        
        // Bulk Email Form Submit
        $('#bulkEmailForm').submit(function(e) {
            e.preventDefault();
            
            const attendeeIds = $('#bulkEmailAttendeeIds').val().split(',').map(id => parseInt(id));
            
            if (attendeeIds.length === 0) {
                Swal.fire('Warning', 'No attendees selected', 'warning');
                return;
            }
            
            const submitBtn = $(this).find('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Sending...').prop('disabled', true);
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'send_bulk_email',
                    attendee_ids: attendeeIds,
                    subject: $('#bulkEmailSubject').val(),
                    message: $('#bulkEmailMessage').val()
                },
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        $('#bulkEmailModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Emails Sent',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        submitBtn.html(originalText).prop('disabled', false);
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    submitBtn.html(originalText).prop('disabled', false);
                    Swal.fire('Error', 'Failed to send emails: ' + error, 'error');
                }
            });
        });
        
        // Export attendees
        window.exportAttendees = function(format) {
            const eventId = '<?php echo $eventId; ?>';
            const status = $('#statusFilter').val();
            window.location.href = `attendees.php?ajax_action=export_attendees&event_id=${eventId}&format=${format}&status=${status}`;
        };
        
        // Update status
        window.updateStatus = function(select) {
            const attendeeId = $(select).data('id');
            const status = $(select).val();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'bulk_update_status',
                    attendee_ids: [attendeeId],
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
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'Failed to update status: ' + error, 'error');
                }
            });
        };
        
        // Process Bulk Add
        $('#processBulkAdd').click(function() {
            const eventId = $('#bulkEventId').val();
            const attendeesText = $('#bulkAddForm textarea').val();
            
            if (!eventId) {
                Swal.fire('Error', 'Please select an event', 'error');
                return;
            }
            
            if (!attendeesText.trim()) {
                Swal.fire('Error', 'Please enter attendee information', 'error');
                return;
            }
            
            const submitBtn = $(this);
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Adding...').prop('disabled', true);
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'bulk_add',
                    event_id: eventId,
                    attendees_text: attendeesText,
                    default_status: $('#bulkAddForm select[name="default_status"]').val(),
                    default_role: $('#bulkAddForm select[name="default_role"]').val()
                },
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (response.success) {
                        $('#bulkAddModal').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: response.message,
                            timer: 2000,
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
                    Swal.fire('Error', 'Failed to add attendees: ' + error, 'error');
                }
            });
        });

        // Day selector change
        $('#daySelector').change(function() {
            const day = $(this).val();
            // Update table data for selected day
            $('.attendance-status, .check-in-time, .check-out-time, .attendance-notes').each(function() {
                $(this).attr('data-day', day);
            });
            $('.save-attendance').each(function() {
                $(this).attr('data-day', day);
            });
        });

        // Mark all present
        $('#markAllPresent').click(function() {
            const day = $('#daySelector').val();
            
            Swal.fire({
                title: 'Mark All Present?',
                text: 'This will mark all attendees as Present for the selected day.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, mark all'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'bulk_mark_attendance',
                            event_id: '<?php echo $eventId; ?>',
                            day_number: day,
                            attendance_status: 'Present'
                        },
                        dataType: 'json',
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
                        }
                    });
                }
            });
        });

        // Mark all absent
        $('#markAllAbsent').click(function() {
            const day = $('#daySelector').val();
            
            Swal.fire({
                title: 'Mark All Absent?',
                text: 'This will mark all attendees as Absent for the selected day.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, mark all'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'bulk_mark_attendance',
                            event_id: '<?php echo $eventId; ?>',
                            day_number: day,
                            attendance_status: 'Absent'
                        },
                        dataType: 'json',
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
                        }
                    });
                }
            });
        });

        // Save individual attendance
        $(document).on('click', '.save-attendance', function() {
            const attendeeId = $(this).data('attendee-id');
            const day = $(this).data('day');
            const status = $(`.attendance-status[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
            const checkIn = $(`.check-in-time[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
            const checkOut = $(`.check-out-time[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
            const notes = $(`.attendance-notes[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'mark_attendance',
                    attendee_id: attendeeId,
                    day_number: day,
                    attendance_status: status,
                    check_in_time: checkIn,
                    check_out_time: checkOut,
                    notes: notes
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Saved',
                            text: response.message,
                            timer: 1000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                }
            });
        });

        // Save all attendance
        $('#saveAllAttendance').click(function() {
            const day = $('#daySelector').val();
            const promises = [];
            
            $('.save-attendance').each(function() {
                const attendeeId = $(this).data('attendee-id');
                const status = $(`.attendance-status[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
                const checkIn = $(`.check-in-time[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
                const checkOut = $(`.check-out-time[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
                const notes = $(`.attendance-notes[data-attendee-id="${attendeeId}"][data-day="${day}"]`).val();
                
                promises.push(
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'mark_attendance',
                            attendee_id: attendeeId,
                            day_number: day,
                            attendance_status: status,
                            check_in_time: checkIn,
                            check_out_time: checkOut,
                            notes: notes
                        },
                        dataType: 'json'
                    })
                );
            });
            
            Swal.fire({
                title: 'Saving...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            Promise.all(promises).then((results) => {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: 'All attendance records saved successfully',
                    timer: 1500,
                    showConfirmButton: false
                });
            }).catch((error) => {
                Swal.close();
                Swal.fire('Error', 'Failed to save some records', 'error');
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

        // Load charts if on stats view
        <?php if ($view == 'stats' && $eventId > 0): ?>
        $.ajax({
            url: window.location.href + '&ajax_action=get_attendance_stats&event_id=<?php echo $eventId; ?>',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // RSVP Chart
                    const rsvpCtx = document.getElementById('rsvpChart').getContext('2d');
                    new Chart(rsvpCtx, {
                        type: 'pie',
                        data: {
                            labels: ['Confirmed', 'Registered', 'Invited', 'Attended', 'No Show', 'Cancelled'],
                            datasets: [{
                                data: [
                                    response.data.summary.confirmed || 0,
                                    response.data.summary.registered || 0,
                                    response.data.summary.invited || 0,
                                    response.data.summary.attended || 0,
                                    response.data.summary.no_show || 0,
                                    response.data.summary.cancelled || 0
                                ],
                                backgroundColor: [
                                    '#28a745',
                                    '#17a2b8',
                                    '#007bff',
                                    '#20c997',
                                    '#dc3545',
                                    '#6c757d'
                                ]
                            }]
                        }
                    });
                    
                    // Attendance Classification Chart
                    const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
                    new Chart(attendanceCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Full Attendance', 'Partial Attendance', 'Absent'],
                            datasets: [{
                                data: [
                                    <?php echo $attendanceStats['full_attendance']; ?>,
                                    <?php echo $attendanceStats['partial_attendance']; ?>,
                                    <?php echo $attendanceStats['absent']; ?>
                                ],
                                backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                            }]
                        }
                    });
                    
                    // Daily Stats Table
                    let dailyHtml = '';
                    response.data.daily.forEach(day => {
                        const total = (day.present || 0) + (day.absent || 0) + (day.late || 0) + (day.excused || 0);
                        const rate = total > 0 ? Math.round(((day.present || 0) / total) * 100) : 0;
                        dailyHtml += `
                            <tr>
                                <td>Day ${day.day_number}</td>
                                <td>${day.present || 0}</td>
                                <td>${day.absent || 0}</td>
                                <td>${day.late || 0}</td>
                                <td>${day.excused || 0}</td>
                                <td>${day.not_marked || 0}</td>
                                <td>${total}</td>
                                <td>${rate}%</td>
                            </tr>
                        `;
                    });
                    $('#dailyStatsBody').html(dailyHtml);
                }
            }
        });
        <?php endif; ?>

        // Keyboard shortcuts
        $(document).keydown(function(e) {
            // Ctrl+A to open add attendee modal
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                $('#addAttendeeModal').modal('show');
            }
            // Ctrl+E to export
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportAttendees('csv');
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