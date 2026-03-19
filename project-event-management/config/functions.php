<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection function with error handling
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        $host = "localhost";
        $user = "root";
        $password = "";
        $database = "project_manager";
        
        try {
            $conn = mysqli_connect($host, $user, $password, $database);
            
            if (!$conn) {
                throw new Exception("Database connection failed: " . mysqli_connect_error());
            }
            
            // Set charset
            mysqli_set_charset($conn, "utf8mb4");
            
        } catch (Exception $e) {
            error_log($e->getMessage());
            // For production, show user-friendly message
            die("Unable to connect to database. Please try again later.");
        }
    }
    
    return $conn;
}

// Check if user is authenticated - SIMPLIFIED VERSION (no is_active check)
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

// Check user role
function hasRole($role) {
    if (!isset($_SESSION['system_role'])) {
        return false;
    }
    
    $userRole = $_SESSION['system_role'];
    $roleHierarchy = [
        'super_admin' => ['super_admin', 'admin', 'pm_manager', 'pm_employee', 'pm_viewer', 'tester', 'test_viewer'],
        'admin' => ['admin', 'pm_manager', 'pm_employee', 'pm_viewer', 'tester', 'test_viewer'],
        'pm_manager' => ['pm_manager', 'pm_employee', 'pm_viewer'],
        'pm_employee' => ['pm_employee'],
        'pm_viewer' => ['pm_viewer'],
        'tester' => ['tester', 'test_viewer'],
        'test_viewer' => ['test_viewer']
    ];
    
    if (isset($roleHierarchy[$userRole]) && in_array($role, $roleHierarchy[$userRole])) {
        return true;
    }
    
    return $userRole === $role;
}

// Get event status badge color
// function getStatusBadge($status) {
//     $statusClasses = [
//         'Planning' => 'badge-info',
//         'Upcoming' => 'badge-primary',
//         'Ongoing' => 'badge-warning',
//         'Completed' => 'badge-success',
//         'Cancelled' => 'badge-danger'
//     ];
    
//     return isset($statusClasses[$status]) ? $statusClasses[$status] : 'badge-secondary';
// }

// Get priority badge color
// function getPriorityBadge($priority) {
//     $priorityClasses = [
//         'Low' => 'badge-success',
//         'Medium' => 'badge-warning',
//         'High' => 'badge-danger'
//     ];
    
//     return isset($priorityClasses[$priority]) ? $priorityClasses[$priority] : 'badge-warning';
// }

// // Get task status badge color
// function getTaskStatusBadge($status) {
//     $statusClasses = [
//         'Not Started' => 'badge-secondary',
//         'In Progress' => 'badge-primary',
//         'Completed' => 'badge-success',
//         'Cancelled' => 'badge-danger'
//     ];
    
//     return isset($statusClasses[$status]) ? $statusClasses[$status] : 'badge-secondary';
// }

// Get resource status badge color
// function getResourceStatusBadge($status) {
//     $statusClasses = [
//         'Requested' => 'badge-secondary',
//         'Approved' => 'badge-info',
//         'Purchased' => 'badge-primary',
//         'Delivered' => 'badge-success',
//         'Returned' => 'badge-warning'
//     ];
    
//     return isset($statusClasses[$status]) ? $statusClasses[$status] : 'badge-secondary';
// }

// // Get RSVP status badge color
// function getRSVPStatusBadge($status) {
//     $statusClasses = [
//         'Pending' => 'badge-secondary',
//         'Confirmed' => 'badge-success',
//         'Declined' => 'badge-danger',
//         'Maybe' => 'badge-warning'
//     ];
    
//     return isset($statusClasses[$status]) ? $statusClasses[$status] : 'badge-secondary';
// }

// Calculate days overdue for tasks
function getDaysOverdue($due_date) {
    if (empty($due_date) || $due_date == '0000-00-00') {
        return 0;
    }
    
    try {
        $due = new DateTime($due_date);
        $today = new DateTime();
        
        if ($today > $due) {
            return $today->diff($due)->days;
        }
    } catch (Exception $e) {
        error_log("Error calculating overdue days: " . $e->getMessage());
    }
    
    return 0;
}

// Get overdue status text
function getOverdueStatus($due_date, $task_status = '') {
    if ($task_status === 'Completed') {
        return '';
    }
    
    $days = getDaysOverdue($due_date);
    
    if ($days > 0) {
        return '<span class="text-danger small">Overdue by ' . $days . ' day' . ($days > 1 ? 's' : '') . '</span>';
    } elseif ($due_date && strtotime($due_date) < strtotime('+3 days') && strtotime($due_date) > time()) {
        return '<span class="text-warning small">Due soon</span>';
    }
    
    return '';
}

// Sanitize input — supports both `sanitizeInput($input)` and legacy
// `sanitizeInput($conn, $input)` calls.
function sanitizeInput($maybeConnOrInput, $maybeInput = null) {
    // Handle legacy call signature: sanitizeInput($conn, $input)
    if ($maybeConnOrInput instanceof mysqli && $maybeInput !== null) {
        $conn = $maybeConnOrInput;
        $input = $maybeInput;
    } else {
        $input = $maybeConnOrInput;
        $conn = $maybeInput ?? getDBConnection();
    }

    // Handle null or non-string values
    if ($input === null) {
        return '';
    }

    // Convert to string if it's not already
    if (!is_string($input)) {
        $input = (string)$input;
    }

    // Trim whitespace
    $input = trim($input);

    // Apply mysqli_real_escape_string if connection is available
    if ($conn) {
        // make sure $conn is a mysqli object
        if ($conn instanceof mysqli) {
            $input = mysqli_real_escape_string($conn, $input);
        }
    }

    // Also apply htmlspecialchars for additional safety
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

    return $input;
}

// Alternative sanitize function that accepts connection as parameter (for backward compatibility)
function sanitizeInputWithConn($conn, $input) {
    // Handle null or non-string values
    if ($input === null) {
        return '';
    }
    
    // Convert to string if it's not already
    if (!is_string($input)) {
        $input = (string)$input;
    }
    
    // Trim whitespace
    $input = trim($input);
    
    // Apply mysqli_real_escape_string if connection is available
    if ($conn) {
        $input = mysqli_real_escape_string($conn, $input);
    }
    
    // Also apply htmlspecialchars for additional safety
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    
    return $input;
}

// Format date for display
function formatDate($date, $format = 'F j, Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return 'Not set';
    }
    
    try {
        return date($format, strtotime($date));
    } catch (Exception $e) {
        error_log("Error formatting date: " . $e->getMessage());
        return 'Invalid date';
    }
}

// Format datetime for display
function formatDateTime($datetime, $format = 'F j, Y g:i A') {
    return formatDate($datetime, $format);
}

// Get relative time (e.g., "2 hours ago")
function getRelativeTime($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return 'Never';
    }
    
    try {
        $time = strtotime($datetime);
        $timeDiff = time() - $time;
        
        if ($timeDiff < 0) {
            return 'in the future';
        } elseif ($timeDiff < 60) {
            return 'just now';
        } elseif ($timeDiff < 3600) {
            $minutes = floor($timeDiff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($timeDiff < 86400) {
            $hours = floor($timeDiff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($timeDiff < 604800) {
            $days = floor($timeDiff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    } catch (Exception $e) {
        error_log("Error calculating relative time: " . $e->getMessage());
        return 'Unknown time';
    }
}

// Generate random color for avatars
function getRandomColor() {
    $colors = [
        '#273274', // Dashen Blue
        '#1a237e', // Dark Navy
        '#3949ab', // Accent Blue
        '#2e7d32', // Green
        '#f57c00', // Orange
        '#c62828', // Red
        '#0288d1', // Light Blue
        '#6a1b9a', // Purple
    ];
    
    return $colors[array_rand($colors)];
}

// Get user initials for avatar
function getUserInitials($name) {
    if (empty($name)) {
        return 'U';
    }
    
    $words = explode(' ', $name);
    $initials = '';
    
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }
    
    return $initials ?: 'U';
}

// Check if string is JSON
function isJson($string) {
    if (!is_string($string) || empty($string)) {
        return false;
    }
    
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

// Truncate text with ellipsis
function truncateText($text, $length = 100) {
    if (empty($text)) {
        return '';
    }
    
    if (strlen($text) <= $length) {
        return $text;
    }
    
    $truncated = substr($text, 0, $length);
    $lastSpace = strrpos($truncated, ' ');
    
    if ($lastSpace !== false) {
        $truncated = substr($truncated, 0, $lastSpace);
    }
    
    return $truncated . '...';
}

// Generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Password strength validator
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

// Get page title based on filename
function getPageTitle($filename) {
    $titles = [
        'dashboard.php' => 'Dashboard',
        'events.php' => 'Event Management',
        'attendees.php' => 'Attendee Management',
        'tasks.php' => 'Task Management',
        'resources.php' => 'Resource Management',
        'reports.php' => 'Reports & Analytics',
        'users.php' => 'User Management',
        'profile.php' => 'My Profile',
        'settings.php' => 'System Settings',
        'login.php' => 'Login - Dashen Bank PEMS',
        'register.php' => 'Register',
        'forgot-password.php' => 'Forgot Password'
    ];
    
    return $titles[$filename] ?? 'Dashen Bank PEMS';
}

// Get user-friendly role name
function getRoleName($role_code) {
    $roleNames = [
        'super_admin' => 'Super Administrator',
        'admin' => 'Administrator',
        'pm_manager' => 'Project Manager',
        'pm_employee' => 'Project Staff',
        'pm_viewer' => 'Project Viewer',
        'tester' => 'Tester',
        'test_viewer' => 'Test Viewer'
    ];
    
    return $roleNames[$role_code] ?? 'User';
}

// Redirect with message
function redirect($url, $message = '', $type = 'success') {
    if (!empty($message)) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    
    if (headers_sent()) {
        echo "<script>window.location.href='$url';</script>";
    } else {
        header("Location: $url");
    }
    exit();
}

// Get flash message
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Display flash message
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        $type = $flash['type'];
        $message = htmlspecialchars($flash['message']);
        $icon = [
            'success' => 'check-circle',
            'error' => 'exclamation-circle',
            'warning' => 'exclamation-triangle',
            'info' => 'info-circle'
        ][$type] ?? 'info-circle';
        
        return <<<HTML
        <div class="alert alert-{$type} alert-dismissible fade show" role="alert">
            <i class="fas fa-{$icon} me-2"></i>
            {$message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
HTML;
    }
    return '';
}

// Set flash message (helper used across pages)
function setFlashMessage($message, $type = 'success') {
    // Ensure session started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

// Get event type icon
function getEventTypeIcon($event_type) {
    $icons = [
        'Meeting' => 'fa-users',
        'Presentation' => 'fa-chart-line',
        'Conference' => 'fa-microphone',
        'Activity' => 'fa-running',
        'Training' => 'fa-graduation-cap',
        'Other' => 'fa-calendar'
    ];
    
    return $icons[$event_type] ?? 'fa-calendar';
}

// Get task priority icon
function getPriorityIcon($priority) {
    $icons = [
        'Low' => 'fa-arrow-down',
        'Medium' => 'fa-equals',
        'High' => 'fa-arrow-up'
    ];
    
    return $icons[$priority] ?? 'fa-equals';
}

// Get status icon
function getStatusIcon($status) {
    $icons = [
        'Planning' => 'fa-clipboard-list',
        'Upcoming' => 'fa-clock',
        'Ongoing' => 'fa-spinner',
        'Completed' => 'fa-check-circle',
        'Cancelled' => 'fa-times-circle',
        'Not Started' => 'fa-circle',
        'In Progress' => 'fa-spinner',
        'Requested' => 'fa-paper-plane',
        'Approved' => 'fa-thumbs-up',
        'Purchased' => 'fa-shopping-cart',
        'Delivered' => 'fa-truck',
        'Returned' => 'fa-undo',
        'Pending' => 'fa-clock',
        'Confirmed' => 'fa-check',
        'Declined' => 'fa-times',
        'Maybe' => 'fa-question'
    ];
    
    return $icons[$status] ?? 'fa-circle';
}

// Check if date is in the past
function isPastDate($date) {
    if (empty($date)) return false;
    
    try {
        $dateObj = new DateTime($date);
        $today = new DateTime();
        return $dateObj < $today;
    } catch (Exception $e) {
        return false;
    }
}

// Check if date is today
function isToday($date) {
    if (empty($date)) return false;
    
    try {
        $dateObj = new DateTime($date);
        $today = new DateTime();
        return $dateObj->format('Y-m-d') === $today->format('Y-m-d');
    } catch (Exception $e) {
        return false;
    }
}

// Check if date is tomorrow
function isTomorrow($date) {
    if (empty($date)) return false;
    
    try {
        $dateObj = new DateTime($date);
        $tomorrow = new DateTime('tomorrow');
        return $dateObj->format('Y-m-d') === $tomorrow->format('Y-m-d');
    } catch (Exception $e) {
        return false;
    }
}

// Get days until date
function getDaysUntil($date) {
    if (empty($date)) return null;
    
    try {
        $dateObj = new DateTime($date);
        $today = new DateTime();
        
        if ($dateObj < $today) {
            return -$today->diff($dateObj)->days;
        } else {
            return $today->diff($dateObj)->days;
        }
    } catch (Exception $e) {
        return null;
    }
}

// Generate random string
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $randomString;
}

// Validate email address
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Get file extension
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

// Check if file is image
function isImageFile($filename) {
    $ext = getFileExtension($filename);
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    return in_array($ext, $imageExtensions);
}

// Check if file is document
function isDocumentFile($filename) {
    $ext = getFileExtension($filename);
    $docExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
    return in_array($ext, $docExtensions);
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// Log activity
function logActivity($action, $details = '', $user_id = null) {
    try {
        $conn = getDBConnection();
        $user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Check if activity_logs table exists
        $checkTable = "SHOW TABLES LIKE 'activity_logs'";
        $tableExists = mysqli_query($conn, $checkTable) && mysqli_num_rows(mysqli_query($conn, $checkTable)) > 0;
        
        if ($tableExists) {
            $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "issss", $user_id, $action, $details, $ip_address, $user_agent);
            mysqli_stmt_execute($stmt);
        }
    } catch (Exception $e) {
        error_log("Activity logging failed: " . $e->getMessage());
    }
}
// Add this function to config/functions.php
function checkAuthSimple() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

// Send notification
function sendNotification($user_id, $title, $message, $type = 'info', $link = null) {
    try {
        $conn = getDBConnection();
        
        // Check if notifications table exists
        $checkTable = "SHOW TABLES LIKE 'notifications'";
        $tableExists = mysqli_query($conn, $checkTable) && mysqli_num_rows(mysqli_query($conn, $checkTable)) > 0;
        
        if ($tableExists) {
            $sql = "INSERT INTO notifications (user_id, title, message, type, link) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "issss", $user_id, $title, $message, $type, $link);
            mysqli_stmt_execute($stmt);
            return true;
        }
    } catch (Exception $e) {
        error_log("Notification sending failed: " . $e->getMessage());
    }
    return false;
}
?>