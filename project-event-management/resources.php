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

// Get event ID from query string if specified
$eventId = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$view = isset($_GET['view']) ? $_GET['view'] : 'list';

// Handle AJAX requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    $ajaxAction = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';
    
    switch ($ajaxAction) {
        case 'add_resource':
            handleAddResource($conn);
            break;
        case 'edit_resource':
            handleEditResource($conn);
            break;
        case 'delete_resource':
            handleDeleteResource($conn);
            break;
        case 'update_status':
            handleUpdateResourceStatus($conn);
            break;
        case 'bulk_update':
            handleBulkUpdateResources($conn);
            break;
        case 'get_resource':
            getResourceDetails($conn);
            break;
        case 'export_resources':
            exportResources($conn, $eventId);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit();
}

// Initialize variables
$events = [];
$resources = [];
$resourceTypes = ['Equipment', 'Venue', 'Personnel', 'Material', 'Technology', 'Catering', 'Stationery', 'Transport', 'Other'];

// Statistics
$stats = [
    'total' => 0,
    'requested' => 0,
    'approved' => 0,
    'allocated' => 0,
    'in_use' => 0,
    'released' => 0,
    'needed' => 0
];

$error = '';
$message = '';
$messageType = '';

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#273274',
    'secondary' => '#3c4c9e',
    'accent' => '#fff'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;

// Get events for dropdown
try {
    $eventsQuery = "SELECT e.*, 
                    (SELECT COUNT(*) FROM event_resources WHERE event_id = e.id) as resource_count
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

// Get resources with all details
try {
    if ($eventId > 0) {
        $resourcesQuery = "
            SELECT er.*, e.event_name, e.start_datetime as event_date, e.location as event_location,
                   u.username as created_by_name
            FROM event_resources er
            JOIN events e ON er.event_id = e.id
            LEFT JOIN users u ON er.created_by = u.id
            WHERE er.event_id = ?
            ORDER BY 
                CASE er.status
                    WHEN 'Requested' THEN 1
                    WHEN 'Approved' THEN 2
                    WHEN 'Allocated' THEN 3
                    WHEN 'In Use' THEN 4
                    WHEN 'Released' THEN 5
                    ELSE 6
                END,
                er.resource_name ASC
        ";
        $stmt = mysqli_prepare($conn, $resourcesQuery);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $resourcesQuery = "
            SELECT er.*, e.event_name, e.start_datetime as event_date, e.location as event_location,
                   u.username as created_by_name
            FROM event_resources er
            JOIN events e ON er.event_id = e.id
            LEFT JOIN users u ON er.created_by = u.id
            ORDER BY 
                CASE er.status
                    WHEN 'Requested' THEN 1
                    WHEN 'Approved' THEN 2
                    WHEN 'Allocated' THEN 3
                    WHEN 'In Use' THEN 4
                    WHEN 'Released' THEN 5
                    ELSE 6
                END,
                e.start_datetime DESC,
                er.resource_name ASC
        ";
        $result = mysqli_query($conn, $resourcesQuery);
    }
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Set default values for missing fields
            $row['description'] = $row['description'] ?? '';
            $row['notes'] = $row['notes'] ?? '';
            $row['resource_type'] = $row['resource_type'] ?? 'Equipment';
            $row['allocation_date'] = $row['allocation_date'] ?? null;
            $row['assigned_to'] = $row['assigned_to'] ?? null;
            
            $resources[] = $row;
            
            // Update statistics
            $stats['total']++;
            switch($row['status']) {
                case 'Requested':
                    $stats['requested']++;
                    break;
                case 'Approved':
                    $stats['approved']++;
                    break;
                case 'Allocated':
                    $stats['allocated']++;
                    break;
                case 'In Use':
                    $stats['in_use']++;
                    break;
                case 'Released':
                    $stats['released']++;
                    break;
            }
            
            // Count resources that are still needed (not yet delivered/released)
            if (in_array($row['status'], ['Requested', 'Approved', 'Allocated'])) {
                $stats['needed'] += $row['quantity'];
            }
        }
    }
} catch(Exception $e) {
    error_log("Error fetching resources: " . $e->getMessage());
    $error = "Error loading resources data.";
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

function handleAddResource($conn) {
    if (!hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager') && !hasRole('pm_employee')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $eventId = intval($_POST['event_id'] ?? 0);
    $resourceName = mysqli_real_escape_string($conn, trim($_POST['resource_name'] ?? ''));
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $quantity = intval($_POST['quantity'] ?? 1);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Requested');
    $notes = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $allocationDate = !empty($_POST['allocation_date']) ? mysqli_real_escape_string($conn, $_POST['allocation_date']) : null;
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    $createdBy = $_SESSION['user_id'];
    
    // Validation
    $errors = [];
    if (!$eventId) $errors[] = 'Event is required';
    if (empty($resourceName)) $errors[] = 'Resource name is required';
    if ($quantity < 1) $errors[] = 'Quantity must be at least 1';
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        return;
    }
    
    // Insert new resource
    $sql = "INSERT INTO event_resources (event_id, resource_name, resource_type, description, quantity, status, notes, allocation_date, assigned_to, created_by, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issisissii", $eventId, $resourceName, $resourceType, $description, $quantity, $status, $notes, $allocationDate, $assignedTo, $createdBy);
    
    if (mysqli_stmt_execute($stmt)) {
        $resourceId = mysqli_insert_id($conn);
        
        // Get event info for log
        $eventSql = "SELECT event_name FROM events WHERE id = ?";
        $eventStmt = mysqli_prepare($conn, $eventSql);
        mysqli_stmt_bind_param($eventStmt, "i", $eventId);
        mysqli_stmt_execute($eventStmt);
        $eventResult = mysqli_stmt_get_result($eventStmt);
        $event = mysqli_fetch_assoc($eventResult);
        
        // Log activity
        logActivity("Resource added", "Added resource '{$resourceName}' to event '{$event['event_name']}'", $_SESSION['user_id']);
        
        // Send notification to managers for approval
        if ($status == 'Requested') {
            notifyManagersForApproval($conn, $resourceId, $resourceName, $event['event_name']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Resource added successfully', 'id' => $resourceId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error adding resource: ' . mysqli_error($conn)]);
    }
}

function handleEditResource($conn) {
    if (!hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['resource_id'] ?? 0);
    $eventId = intval($_POST['event_id'] ?? 0);
    $resourceName = mysqli_real_escape_string($conn, trim($_POST['resource_name'] ?? ''));
    $resourceType = mysqli_real_escape_string($conn, $_POST['resource_type'] ?? 'Equipment');
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $quantity = intval($_POST['quantity'] ?? 1);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Requested');
    $notes = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    $allocationDate = !empty($_POST['allocation_date']) ? mysqli_real_escape_string($conn, $_POST['allocation_date']) : null;
    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;
    
    // Validation
    $errors = [];
    if (!$resourceId) $errors[] = 'Invalid resource ID';
    if (!$eventId) $errors[] = 'Event is required';
    if (empty($resourceName)) $errors[] = 'Resource name is required';
    if ($quantity < 1) $errors[] = 'Quantity must be at least 1';
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        return;
    }
    
    // Get old status for notification
    $oldSql = "SELECT status, resource_name FROM event_resources WHERE id = ?";
    $oldStmt = mysqli_prepare($conn, $oldSql);
    mysqli_stmt_bind_param($oldStmt, "i", $resourceId);
    mysqli_stmt_execute($oldStmt);
    $oldResult = mysqli_stmt_get_result($oldStmt);
    $oldResource = mysqli_fetch_assoc($oldResult);
    
    // Update resource
    $sql = "UPDATE event_resources SET 
                event_id = ?, 
                resource_name = ?, 
                resource_type = ?, 
                description = ?, 
                quantity = ?, 
                status = ?, 
                notes = ?, 
                allocation_date = ?, 
                assigned_to = ?,
                updated_at = NOW()
            WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "issisissii", $eventId, $resourceName, $resourceType, $description, $quantity, $status, $notes, $allocationDate, $assignedTo, $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        logActivity("Resource updated", "Updated resource: {$resourceName}", $_SESSION['user_id']);
        
        // Send notification for status change
        if ($oldResource['status'] != $status) {
            // Notify requester if approved/allocated
            if (in_array($status, ['Approved', 'Allocated'])) {
                $requesterSql = "SELECT created_by FROM event_resources WHERE id = ?";
                $requesterStmt = mysqli_prepare($conn, $requesterSql);
                mysqli_stmt_bind_param($requesterStmt, "i", $resourceId);
                mysqli_stmt_execute($requesterStmt);
                $requesterResult = mysqli_stmt_get_result($requesterStmt);
                $requester = mysqli_fetch_assoc($requesterResult);
                
                if ($requester && $requester['created_by'] != $_SESSION['user_id']) {
                    sendNotification($requester['created_by'], "Resource {$status}", 
                        "Your resource request '{$resourceName}' has been {$status}", 
                        'success', "resources.php?event_id={$eventId}");
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Resource updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating resource: ' . mysqli_error($conn)]);
    }
}

function handleDeleteResource($conn) {
    if (!hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['resource_id'] ?? $_GET['id'] ?? 0);
    
    if (!$resourceId) {
        echo json_encode(['success' => false, 'message' => 'Invalid resource ID']);
        return;
    }
    
    // Get resource info for logging
    $infoSql = "SELECT resource_name FROM event_resources WHERE id = ?";
    $infoStmt = mysqli_prepare($conn, $infoSql);
    mysqli_stmt_bind_param($infoStmt, "i", $resourceId);
    mysqli_stmt_execute($infoStmt);
    $infoResult = mysqli_stmt_get_result($infoStmt);
    $resource = mysqli_fetch_assoc($infoResult);
    
    // Delete resource
    $sql = "DELETE FROM event_resources WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        logActivity("Resource deleted", "Deleted resource: " . ($resource['resource_name'] ?? 'Unknown'), $_SESSION['user_id']);
        echo json_encode(['success' => true, 'message' => 'Resource deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting resource: ' . mysqli_error($conn)]);
    }
}

function handleUpdateResourceStatus($conn) {
    if (!hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceId = intval($_POST['resource_id'] ?? 0);
    $status = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    
    if (!$resourceId || !$status) {
        echo json_encode(['success' => false, 'message' => 'Resource ID and status are required']);
        return;
    }
    
    // Get current resource info
    $checkSql = "SELECT er.*, e.event_name FROM event_resources er
                 JOIN events e ON er.event_id = e.id
                 WHERE er.id = ?";
    $checkStmt = mysqli_prepare($conn, $checkSql);
    mysqli_stmt_bind_param($checkStmt, "i", $resourceId);
    mysqli_stmt_execute($checkStmt);
    $result = mysqli_stmt_get_result($checkStmt);
    $resource = mysqli_fetch_assoc($result);
    
    if (!$resource) {
        echo json_encode(['success' => false, 'message' => 'Resource not found']);
        return;
    }
    
    $oldStatus = $resource['status'];
    
    $sql = "UPDATE event_resources SET status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "si", $status, $resourceId);
    
    if (mysqli_stmt_execute($stmt)) {
        // Log activity
        logActivity("Resource status updated", 
            "Resource '{$resource['resource_name']}' status changed from {$oldStatus} to {$status}", 
            $_SESSION['user_id']);
        
        // Notify requester
        if ($resource['created_by'] != $_SESSION['user_id']) {
            sendNotification($resource['created_by'], "Resource Status Updated", 
                "Your resource '{$resource['resource_name']}' status has been changed from {$oldStatus} to {$status}", 
                'info', "resources.php?event_id={$resource['event_id']}");
        }
        
        echo json_encode(['success' => true, 'message' => 'Resource status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating status: ' . mysqli_error($conn)]);
    }
}

function handleBulkUpdateResources($conn) {
    if (!hasRole('super_admin') && !hasRole('admin') && !hasRole('pm_manager')) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        return;
    }
    
    $resourceIds = $_POST['resource_ids'] ?? [];
    $action = $_POST['bulk_action'] ?? '';
    
    if (empty($resourceIds) || empty($action)) {
        echo json_encode(['success' => false, 'message' => 'Resource IDs and action are required']);
        return;
    }
    
    $ids = implode(',', array_map('intval', $resourceIds));
    $message = '';
    
    switch($action) {
        case 'approve':
            $sql = "UPDATE event_resources SET status = 'Approved', updated_at = NOW() WHERE id IN ($ids)";
            $message = 'Resources approved';
            break;
        case 'allocate':
            $sql = "UPDATE event_resources SET status = 'Allocated', updated_at = NOW() WHERE id IN ($ids)";
            $message = 'Resources allocated';
            break;
        case 'release':
            $sql = "UPDATE event_resources SET status = 'Released', updated_at = NOW() WHERE id IN ($ids)";
            $message = 'Resources released';
            break;
        case 'delete':
            $sql = "DELETE FROM event_resources WHERE id IN ($ids)";
            $message = 'Resources deleted';
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            return;
    }
    
    if (mysqli_query($conn, $sql)) {
        $count = mysqli_affected_rows($conn);
        logActivity("Bulk resource update", "Performed '{$action}' on {$count} resources", $_SESSION['user_id']);
        echo json_encode(['success' => true, 'message' => "{$count} {$message}"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error performing bulk action: ' . mysqli_error($conn)]);
    }
}

function getResourceDetails($conn) {
    $resourceId = intval($_GET['id'] ?? 0);
    
    if (!$resourceId) {
        echo json_encode(['success' => false, 'message' => 'Resource ID is required']);
        return;
    }
    
    $sql = "SELECT er.*, e.event_name, e.start_datetime as event_date, e.location as event_location,
                   u.username as created_by_name, creator.email as created_by_email
            FROM event_resources er
            JOIN events e ON er.event_id = e.id
            LEFT JOIN users u ON er.created_by = u.id
            LEFT JOIN users creator ON er.created_by = creator.id
            WHERE er.id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $resourceId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        return;
    }
    
    $resource = mysqli_fetch_assoc($result);
    
    if ($resource) {
        // Set default values for missing fields
        $resource['description'] = $resource['description'] ?? '';
        $resource['notes'] = $resource['notes'] ?? '';
        $resource['allocation_date'] = $resource['allocation_date'] ?? null;
        $resource['assigned_to'] = $resource['assigned_to'] ?? null;
        
        echo json_encode(['success' => true, 'data' => $resource]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resource not found with ID: ' . $resourceId]);
    }
}

function exportResources($conn, $eventId) {
    $format = $_GET['format'] ?? 'csv';
    
    if ($eventId > 0) {
        $sql = "SELECT 
                    er.resource_name as 'Resource Name',
                    er.resource_type as 'Type',
                    er.description as 'Description',
                    er.quantity as 'Quantity',
                    er.status as 'Status',
                    er.notes as 'Notes',
                    er.allocation_date as 'Allocation Date',
                    e.event_name as 'Event',
                    e.location as 'Event Location',
                    e.start_datetime as 'Event Date',
                    u.username as 'Created By',
                    er.created_at as 'Created Date'
                FROM event_resources er
                JOIN events e ON er.event_id = e.id
                LEFT JOIN users u ON er.created_by = u.id
                WHERE er.event_id = ?
                ORDER BY er.created_at DESC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $eventId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $sql = "SELECT 
                    er.resource_name as 'Resource Name',
                    er.resource_type as 'Type',
                    er.description as 'Description',
                    er.quantity as 'Quantity',
                    er.status as 'Status',
                    er.notes as 'Notes',
                    er.allocation_date as 'Allocation Date',
                    e.event_name as 'Event',
                    e.location as 'Event Location',
                    e.start_datetime as 'Event Date',
                    u.username as 'Created By',
                    er.created_at as 'Created Date'
                FROM event_resources er
                JOIN events e ON er.event_id = e.id
                LEFT JOIN users u ON er.created_by = u.id
                ORDER BY er.created_at DESC";
        $result = mysqli_query($conn, $sql);
    }
    
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="resources_export_' . date('Y-m-d') . '.csv"');
        
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
    }
}

function notifyManagersForApproval($conn, $resourceId, $resourceName, $eventName) {
    // Get all managers
    $sql = "SELECT id FROM users WHERE system_role IN ('super_admin', 'admin', 'pm_manager') AND is_active = 1";
    $result = mysqli_query($conn, $sql);
    
    while ($manager = mysqli_fetch_assoc($result)) {
        sendNotification($manager['id'], "Resource Approval Required", 
            "A new resource '{$resourceName}' has been requested for event '{$eventName}'. Please review and approve.", 
            'warning', "resources.php?action=review&id={$resourceId}");
    }
}

// Helper Functions for UI
function getResourceStatusBadge($status) {
    switch ($status) {
        case 'Requested': return 'badge-warning';
        case 'Approved': return 'badge-info';
        case 'Allocated': return 'badge-primary';
        case 'In Use': return 'badge-success';
        case 'Released': return 'badge-secondary';
        default: return 'badge-secondary';
    }
}

function getResourceStatusIcon($status) {
    switch ($status) {
        case 'Requested': return 'fa-clock';
        case 'Approved': return 'fa-check-circle';
        case 'Allocated': return 'fa-tasks';
        case 'In Use': return 'fa-play-circle';
        case 'Released': return 'fa-undo';
        default: return 'fa-circle';
    }
}

function getResourceTypeIcon($type) {
    $icons = [
        'Equipment' => 'fa-tools',
        'Venue' => 'fa-building',
        'Personnel' => 'fa-users',
        'Material' => 'fa-cube',
        'Technology' => 'fa-laptop',
        'Catering' => 'fa-utensils',
        'Stationery' => 'fa-pen',
        'Transport' => 'fa-truck',
        'Other' => 'fa-box'
    ];
    return $icons[$type] ?? 'fa-box';
}

// Get message from session if any
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resource Management - Dashen Bank PEMS</title>
    
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
            background: linear-gradient(135deg, rgba(39, 50, 116, 0.05) 0%, rgba(60, 76, 158, 0.05) 100%);
            border-left: 4px solid var(--dashen-primary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        /* Stats Grid */
        .stats-grid {
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
        .btn-action.approve:hover { background: var(--dashen-success); }
        .btn-action.release:hover { background: var(--dashen-info); }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-primary { background: rgba(39, 50, 116, 0.1); color: var(--dashen-primary); }
        .badge-success { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        .badge-warning { background: rgba(255, 193, 7, 0.1); color: #ffc107; }
        .badge-danger { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .badge-info { background: rgba(23, 162, 184, 0.1); color: #17a2b8; }
        .badge-secondary { background: rgba(108, 117, 125, 0.1); color: #6c757d; }

        body.dark-mode .badge-primary { background: rgba(39, 50, 116, 0.3); }

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

        /* Resource status indicators */
        .resource-status-requested { border-left: 4px solid #ffc107 !important; }
        .resource-status-approved { border-left: 4px solid #17a2b8 !important; }
        .resource-status-allocated { border-left: 4px solid #0d6efd !important; }
        .resource-status-inuse { border-left: 4px solid #28a745 !important; }
        .resource-status-released { border-left: 4px solid #6c757d !important; }

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
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
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
                    <h1>Resource Management</h1>
                    <p>Manage event resources with full lifecycle tracking</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($eventId > 0): ?>
                    <a href="event_details.php?id=<?php echo $eventId; ?>" class="btn-outline-dashen">
                        <i class="fas fa-arrow-left"></i> Back to Event
                    </a>
                    <?php endif; ?>
                    <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager') || hasRole('pm_employee')): ?>
                    <button class="btn-dashen" onclick="openAddResourceModal()">
                        <i class="fas fa-plus"></i> Add Resource
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
                            <?php if ($selectedEvent['location']): ?>
                            | <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($selectedEvent['location']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge badge-info">
                            <i class="fas fa-boxes me-1"></i> <?php echo $selectedEvent['resource_count'] ?? 0; ?> Resources
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card" onclick="filterByStatus('all')">
                    <div class="stat-icon" style="background: rgba(39, 50, 116, 0.1); color: var(--dashen-primary);">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Resources</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #ffc107;" onclick="filterByStatus('Requested')">
                    <div class="stat-icon" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['requested']; ?></div>
                    <div class="stat-label">Requested</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #17a2b8;" onclick="filterByStatus('Approved')">
                    <div class="stat-icon" style="background: rgba(23, 162, 184, 0.1); color: #17a2b8;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['approved']; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #0d6efd;" onclick="filterByStatus('Allocated')">
                    <div class="stat-icon" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['allocated']; ?></div>
                    <div class="stat-label">Allocated</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #28a745;" onclick="filterByStatus('In Use')">
                    <div class="stat-icon" style="background: rgba(40, 167, 69, 0.1); color: #28a745;">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['in_use']; ?></div>
                    <div class="stat-label">In Use</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #6c757d;" onclick="filterByStatus('Released')">
                    <div class="stat-icon" style="background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                        <i class="fas fa-undo"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['released']; ?></div>
                    <div class="stat-label">Released</div>
                </div>
                
                <div class="stat-card" style="border-left-color: #dc3545;">
                    <div class="stat-icon" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value"><?php echo $stats['needed']; ?></div>
                    <div class="stat-label">Units Needed</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(39, 50, 116, 0.1); color: var(--dashen-primary);">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-value"><?php echo count($events); ?></div>
                    <div class="stat-label">Events</div>
                </div>
            </div>
            
            <!-- Filters Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Resources</h5>
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
                                        (<?php echo $event['resource_count'] ?? 0; ?> resources)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="statusFilter" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="Requested">Requested</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Allocated">Allocated</option>
                                    <option value="In Use">In Use</option>
                                    <option value="Released">Released</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Type</label>
                                <select class="form-select" id="typeFilter" name="type">
                                    <option value="">All Types</option>
                                    <?php foreach ($resourceTypes as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-flex gap-2 w-100">
                                    <button type="submit" class="btn-dashen flex-grow-1">
                                        <i class="fas fa-search"></i> Filter
                                    </button>
                                    <a href="resources.php<?php echo $eventId ? '?event_id=' . $eventId : ''; ?>" class="btn-outline-dashen">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Resources Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        <?php echo $eventId ? 'Event Resources' : 'All Resources'; ?>
                        <span class="badge bg-primary ms-2"><?php echo count($resources); ?> resources</span>
                    </h5>
                    <div class="d-flex gap-2">
                        <?php if (!empty($resources)): ?>
                        <button class="btn-outline-dashen btn-sm" onclick="exportResources()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                        <button class="btn-outline-dashen btn-sm" id="bulkActionsBtn" style="display: none;">
                            <i class="fas fa-tasks"></i> Bulk Actions
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card-body">
                    <?php if (!empty($resources)): ?>
                    <div class="table-responsive">
                        <table class="table" id="resourcesTable">
                            <thead>
                                <tr>
                                    <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                                    <th width="40px">
                                        <input type="checkbox" class="form-check-input" id="selectAll">
                                    </th>
                                    <?php endif; ?>
                                    <th>Resource</th>
                                    <th>Type</th>
                                    <?php if (!$eventId): ?>
                                    <th>Event</th>
                                    <?php endif; ?>
                                    <th>Qty</th>
                                    <th>Status</th>
                                    <th>Allocation Date</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resources as $resource): 
                                    $statusClass = 'resource-status-' . strtolower(str_replace(' ', '', $resource['status']));
                                ?>
                                <tr class="<?php echo $statusClass; ?>" data-status="<?php echo $resource['status']; ?>" data-type="<?php echo $resource['resource_type']; ?>">
                                    <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                                    <td>
                                        <input type="checkbox" class="form-check-input resource-select" value="<?php echo $resource['id']; ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle-small me-2">
                                                <i class="fas <?php echo getResourceTypeIcon($resource['resource_type']); ?>"></i>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($resource['resource_name']); ?></strong>
                                                <?php if (!empty($resource['description'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($resource['description'], 0, 50)); ?><?php echo strlen($resource['description']) > 50 ? '...' : ''; ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo htmlspecialchars($resource['resource_type']); ?>
                                        </span>
                                    </td>
                                    <?php if (!$eventId): ?>
                                    <td>
                                        <span class="badge badge-primary">
                                            <?php echo htmlspecialchars($resource['event_name']); ?>
                                        </span>
                                        <br><small class="text-muted"><?php echo date('M j', strtotime($resource['event_date'])); ?></small>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $resource['quantity']; ?></span>
                                    </td>
                                    <td>
                                        <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                                        <select class="form-select form-select-sm status-select" 
                                                data-id="<?php echo $resource['id']; ?>"
                                                onchange="updateResourceStatus(this)">
                                            <option value="Requested" <?php echo ($resource['status'] == 'Requested') ? 'selected' : ''; ?>>Requested</option>
                                            <option value="Approved" <?php echo ($resource['status'] == 'Approved') ? 'selected' : ''; ?>>Approved</option>
                                            <option value="Allocated" <?php echo ($resource['status'] == 'Allocated') ? 'selected' : ''; ?>>Allocated</option>
                                            <option value="In Use" <?php echo ($resource['status'] == 'In Use') ? 'selected' : ''; ?>>In Use</option>
                                            <option value="Released" <?php echo ($resource['status'] == 'Released') ? 'selected' : ''; ?>>Released</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge <?php echo getResourceStatusBadge($resource['status']); ?>">
                                            <i class="fas <?php echo getResourceStatusIcon($resource['status']); ?> me-1"></i>
                                            <?php echo $resource['status']; ?>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($resource['allocation_date']): ?>
                                        <?php echo date('M j, Y', strtotime($resource['allocation_date'])); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($resource['notes'])): ?>
                                        <span title="<?php echo htmlspecialchars($resource['notes']); ?>">
                                            <?php echo htmlspecialchars(substr($resource['notes'], 0, 20)); ?>
                                            <?php if (strlen($resource['notes']) > 20): ?>...<?php endif; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action" onclick="viewResource(<?php echo $resource['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                                            <button class="btn-action" onclick="editResource(<?php echo $resource['id']; ?>)" title="Edit Resource">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-action delete" onclick="deleteResource(<?php echo $resource['id']; ?>, '<?php echo htmlspecialchars(addslashes($resource['resource_name'])); ?>')" title="Delete Resource">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($resource['status'] == 'Requested' && (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager'))): ?>
                                            <button class="btn-action approve" onclick="quickApprove(<?php echo $resource['id']; ?>)" title="Quick Approve">
                                                <i class="fas fa-check"></i>
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
                    <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager')): ?>
                    <div class="bulk-actions" id="bulkActions" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="fw-bold" id="selectedCount">0</span>
                                    <span>selected</span>
                                    <select class="form-select form-select-sm" style="width: 150px;" id="bulkAction">
                                        <option value="">Select Action</option>
                                        <option value="approve">Approve</option>
                                        <option value="allocate">Allocate</option>
                                        <option value="release">Release</option>
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
                        <i class="fas fa-boxes"></i>
                        <h4>No resources found</h4>
                        <p class="text-muted">
                            <?php echo $eventId ? 'No resources added for this event yet.' : 'No resources found in the system.'; ?>
                        </p>
                        <?php if (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager') || hasRole('pm_employee')): ?>
                        <button class="btn-dashen" onclick="openAddResourceModal()">
                            <i class="fas fa-plus"></i> Add First Resource
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Add/Edit Resource Modal -->
    <div class="modal fade" id="resourceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resourceModalTitle">Add New Resource</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="resourceForm">
                    <input type="hidden" name="ajax_action" id="ajaxAction" value="add_resource">
                    <input type="hidden" name="resource_id" id="resourceId" value="">
                    
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
                                        (<?php echo date('M j, Y', strtotime($event['start_datetime'])); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Resource Name *</label>
                                <input type="text" class="form-control" name="resource_name" id="resourceName" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Resource Type</label>
                                <select class="form-select" name="resource_type" id="resourceType">
                                    <?php foreach ($resourceTypes as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Quantity *</label>
                                <input type="number" class="form-control" name="quantity" id="resourceQuantity" value="1" min="1" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="resourceStatus">
                                    <option value="Requested">Requested</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Allocated">Allocated</option>
                                    <option value="In Use">In Use</option>
                                    <option value="Released">Released</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="resourceDescription" rows="2" 
                                      placeholder="Detailed description of the resource"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Allocation Date</label>
                                <input type="date" class="form-control" name="allocation_date" id="resourceAllocationDate" 
                                       min="<?php echo date('Y-m-d'); ?>">
                                <div class="form-text">When the resource will be allocated</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Assigned To (User ID)</label>
                                <input type="number" class="form-control" name="assigned_to" id="resourceAssignedTo" 
                                       placeholder="Optional user ID">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" id="resourceNotes" rows="2" 
                                      placeholder="Any additional information, vendor details, costs, etc."></textarea>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-dashen" id="saveResourceBtn">
                            <i class="fas fa-save me-2"></i> Save Resource
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Resource Modal -->
    <div class="modal fade" id="viewResourceModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Resource Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewResourceContent">
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
        if ($('#resourcesTable').length) {
            $('#resourcesTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    emptyTable: "No resources found",
                    info: "Showing _START_ to _END_ of _TOTAL_ resources",
                    infoEmpty: "Showing 0 to 0 of 0 resources",
                    infoFiltered: "(filtered from _MAX_ total resources)",
                    lengthMenu: "Show _MENU_ resources",
                    search: "Search:"
                }
            });
        }
        
        // Select All checkbox
        $('#selectAll').change(function() {
            $('.resource-select').prop('checked', $(this).prop('checked'));
            updateBulkActions();
        });
        
        // Individual checkbox change
        $(document).on('change', '.resource-select', function() {
            updateBulkActions();
            const allChecked = $('.resource-select:checked').length === $('.resource-select').length;
            $('#selectAll').prop('checked', allChecked);
        });
        
        // Update bulk actions visibility
        function updateBulkActions() {
            const selectedCount = $('.resource-select:checked').length;
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
            $('.resource-select').prop('checked', false);
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
            $('.resource-select:checked').each(function() {
                selectedIds.push($(this).val());
            });
            
            if (action === 'delete') {
                Swal.fire({
                    title: 'Delete Resources?',
                    html: `Are you sure you want to delete <strong>${selectedIds.length}</strong> resources?`,
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
                                resource_ids: selectedIds,
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
                let status = '';
                switch(action) {
                    case 'approve': status = 'Approved'; break;
                    case 'allocate': status = 'Allocated'; break;
                    case 'release': status = 'Released'; break;
                }
                
                Swal.fire({
                    title: 'Confirm Bulk Action',
                    text: `Mark ${selectedIds.length} resources as ${status}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#273274',
                    confirmButtonText: 'Yes, proceed!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: {
                                ajax_action: 'bulk_update',
                                resource_ids: selectedIds,
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
                });
            }
        };
        
        // Open Add Resource Modal
        window.openAddResourceModal = function() {
            $('#ajaxAction').val('add_resource');
            $('#resourceId').val('');
            $('#resourceModalTitle').text('Add New Resource');
            $('#resourceForm')[0].reset();
            $('#modalEventId').val('<?php echo $eventId; ?>');
            $('#resourceStatus').val('Requested');
            $('#resourceType').val('Equipment');
            $('#resourceModal').modal('show');
        };
        
        // Resource Form Submit
        $('#resourceForm').submit(function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = $('#saveResourceBtn');
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
                        $('#resourceModal').modal('hide');
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
                    Swal.fire('Error', 'Failed to save resource: ' + error, 'error');
                }
            });
        });
        
        // Edit Resource
        window.editResource = function(resourceId) {
            // Show loading
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: window.location.href + '&ajax_action=get_resource&id=' + resourceId,
                type: 'GET',
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#ajaxAction').val('edit_resource');
                        $('#resourceId').val(response.data.id);
                        $('#modalEventId').val(response.data.event_id);
                        $('#resourceName').val(response.data.resource_name);
                        $('#resourceType').val(response.data.resource_type || 'Equipment');
                        $('#resourceQuantity').val(response.data.quantity);
                        $('#resourceStatus').val(response.data.status);
                        $('#resourceDescription').val(response.data.description || '');
                        $('#resourceAllocationDate').val(response.data.allocation_date || '');
                        $('#resourceAssignedTo').val(response.data.assigned_to || '');
                        $('#resourceNotes').val(response.data.notes || '');
                        
                        $('#resourceModalTitle').text('Edit Resource');
                        $('#resourceModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load resource details: ' + error, 'error');
                }
            });
        };
        
        // View Resource
        window.viewResource = function(resourceId) {
            // Show loading
            Swal.fire({
                title: 'Loading...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: window.location.href + '&ajax_action=get_resource&id=' + resourceId,
                type: 'GET',
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        const resource = response.data;
                        
                        const html = `
                            <h5>${escapeHtml(resource.resource_name)}</h5>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Event:</strong> ${escapeHtml(resource.event_name)}</p>
                                    <p><strong>Event Date:</strong> ${resource.event_date ? new Date(resource.event_date).toLocaleDateString() : 'N/A'}</p>
                                    <p><strong>Event Location:</strong> ${escapeHtml(resource.event_location || 'N/A')}</p>
                                    <p><strong>Resource Type:</strong> ${escapeHtml(resource.resource_type || 'Not specified')}</p>
                                    <p><strong>Quantity:</strong> ${resource.quantity}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Status:</strong> <span class="badge ${getStatusClass(resource.status)}">${resource.status}</span></p>
                                    <p><strong>Allocation Date:</strong> ${resource.allocation_date ? new Date(resource.allocation_date).toLocaleDateString() : 'Not set'}</p>
                                    <p><strong>Assigned To:</strong> ${resource.assigned_to || 'Not assigned'}</p>
                                    <p><strong>Created By:</strong> ${escapeHtml(resource.created_by_name || 'System')}</p>
                                    <p><strong>Created Date:</strong> ${new Date(resource.created_at).toLocaleDateString()}</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <h6>Description</h6>
                                <p>${resource.description ? escapeHtml(resource.description) : 'No description provided'}</p>
                            </div>
                            <div class="mt-3">
                                <h6>Additional Notes</h6>
                                <p>${resource.notes ? escapeHtml(resource.notes) : 'No notes'}</p>
                            </div>
                        `;
                        
                        $('#viewResourceContent').html(html);
                        $('#viewResourceModal').modal('show');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire('Error', 'Failed to load resource details: ' + error, 'error');
                }
            });
        };
        
        // Delete Resource
        window.deleteResource = function(resourceId, resourceName) {
            Swal.fire({
                title: 'Delete Resource?',
                html: `Are you sure you want to delete "<strong>${escapeHtml(resourceName)}</strong>"?`,
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
                            ajax_action: 'delete_resource',
                            resource_id: resourceId
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
                            Swal.fire('Error', 'Failed to delete resource: ' + error, 'error');
                        }
                    });
                }
            });
        };
        
        // Update Resource Status
        window.updateResourceStatus = function(select) {
            const resourceId = $(select).data('id');
            const status = $(select).val();
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    ajax_action: 'update_status',
                    resource_id: resourceId,
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
                        location.reload();
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'Failed to update status: ' + error, 'error');
                    location.reload();
                }
            });
        };
        
        // Quick Approve
        window.quickApprove = function(resourceId) {
            Swal.fire({
                title: 'Approve Resource?',
                text: 'Mark this resource as Approved?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                confirmButtonText: 'Yes, approve!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: window.location.href,
                        type: 'POST',
                        data: {
                            ajax_action: 'update_status',
                            resource_id: resourceId,
                            status: 'Approved'
                        },
                        dataType: 'json',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Approved',
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
        };
        
        // Filter by status
        window.filterByStatus = function(status) {
            if (status === 'all') {
                $('#statusFilter').val('');
                $('#filterForm').submit();
            } else {
                $('#statusFilter').val(status);
                $('#filterForm').submit();
            }
        };
        
        // Export resources
        window.exportResources = function() {
            const eventId = '<?php echo $eventId; ?>';
            window.location.href = `resources.php?ajax_action=export_resources&event_id=${eventId}&format=csv`;
        };
        
        // Reset modal on close
        $('#resourceModal').on('hidden.bs.modal', function() {
            if ($('#ajaxAction').val() !== 'edit_resource') {
                $('#resourceForm')[0].reset();
            }
        });
        
        // Helper functions
        function getStatusClass(status) {
            const classes = {
                'Requested': 'badge-warning',
                'Approved': 'badge-info',
                'Allocated': 'badge-primary',
                'In Use': 'badge-success',
                'Released': 'badge-secondary'
            };
            return classes[status] || 'badge-secondary';
        }
        
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
    });
    </script>
</body>
</html>