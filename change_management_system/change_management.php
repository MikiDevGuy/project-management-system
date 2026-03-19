<?php
// change_management.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

// Get user role for access control
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

// Get notification count
$notification_count = 0;
if ($user_role === 'Admin' || $user_role === 'pm_manager' || $user_role === 'super_admin') {
    $query = "SELECT COUNT(*) as count FROM change_requests WHERE status = 'Open'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $notification_count = $result['count'];
} else {
    $query = "SELECT COUNT(*) as count FROM change_requests
                  WHERE requester_id = ? AND (status = 'Approved' OR status = 'Rejected')
                  AND viewed = 0";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $notification_count = $result['count'];
}

// Get dashboard stats - role-based
if ($user_role === 'Admin' || $user_role === 'pm_manager' || $user_role === 'super_admin') {
    // Admin/Manager sees all requests
    $query = "SELECT status, COUNT(*) as count FROM change_requests GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $status_counts = $result->fetch_all(MYSQLI_ASSOC);
} else {
    // Regular users see only their requests
    $query = "SELECT status, COUNT(*) as count FROM change_requests WHERE requester_id = ? GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $status_counts = $result->fetch_all(MYSQLI_ASSOC);
}
    
$stats = [];
foreach ($status_counts as $row) {
    $stats[$row['status']] = $row['count'];
}

// Get projects for filter dropdown
$query = "SELECT id as project_id, name as project_name FROM projects ORDER BY name";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $projects = [];
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_change_request':
                    // Create new change request
                    $required_fields = ['project_id', 'change_title', 'change_description', 'justification', 'impact_analysis', 'priority'];
                    foreach ($required_fields as $field) {
                        if (empty($_POST[$field])) {
                            throw new Exception("Please fill in all required fields.");
                        }
                    }
                    
                    // Prepare statement
                    $query = "INSERT INTO change_requests (project_id, change_title, change_description, justification, impact_analysis, area_of_impact, resolution_expected, action, priority, escalation_required, status, requester_id, assigned_to_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', ?, ?)";
                    $stmt = $conn->prepare($query);
                    
                    if (!$stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    
                    // Bind parameters
                    $project_id = intval($_POST['project_id']);
                    $change_title = trim($_POST['change_title']);
                    $change_description = trim($_POST['change_description']);
                    $justification = trim($_POST['justification']);
                    $impact_analysis = trim($_POST['impact_analysis']);
                    $area_of_impact = isset($_POST['area_of_impact']) ? $_POST['area_of_impact'] : null;
                    $resolution_expected = isset($_POST['resolution_expected']) ? trim($_POST['resolution_expected']) : null;
                    $action = isset($_POST['action']) ? trim($_POST['action']) : null;
                    $priority = trim($_POST['priority']);
                    $escalation_required = isset($_POST['escalation_required']) ? intval($_POST['escalation_required']) : 0;
                    $assigned_to_id = isset($_POST['assigned_to_id']) && !empty($_POST['assigned_to_id']) ? intval($_POST['assigned_to_id']) : null;
                    
                    $stmt->bind_param(
                        "issssssssiii",
                        $project_id,
                        $change_title,
                        $change_description,
                        $justification,
                        $impact_analysis,
                        $area_of_impact,
                        $resolution_expected,
                        $action,
                        $priority,
                        $escalation_required,
                        $user_id,
                        $assigned_to_id
                    );
                    
                    if ($stmt->execute()) {
                        $change_request_id = $conn->insert_id;
                        
                        // Add to change logs
                        $log_query = "INSERT INTO change_logs (change_request_id, user_id, action, details) VALUES (?, ?, 'Created', 'Change request created')";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("ii", $change_request_id, $user_id);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        $response['success'] = true;
                        $response['message'] = 'Change request created successfully!';
                        $response['change_request_id'] = $change_request_id;
                    } else {
                        throw new Exception("Failed to create change request: " . $stmt->error);
                    }
                    
                    $stmt->close();
                    break;
                    
                case 'update_change_request':
                    // Update existing change request
                    if (empty($_POST['change_request_id'])) {
                        throw new Exception("Change request ID is required.");
                    }
                    
                    $change_request_id = intval($_POST['change_request_id']);
                    
                    // Check if user has permission to edit
                    $check_query = "SELECT requester_id, status FROM change_requests WHERE change_request_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("i", $change_request_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();
                    
                    if (!$check_result) {
                        throw new Exception("Change request not found.");
                    }
                    
                    // Only requester can edit if status is Open, managers can edit any
                    if ($user_role !== 'Admin' && $user_role !== 'pm_manager' && $user_role !== 'super_admin') {
                        if ($check_result['requester_id'] != $user_id || $check_result['status'] !== 'Open') {
                            throw new Exception("You don't have permission to edit this request.");
                        }
                    }
                    
                    // Update query
                    $query = "UPDATE change_requests SET 
                              project_id = ?, 
                              change_title = ?, 
                              change_description = ?, 
                              justification = ?, 
                              impact_analysis = ?, 
                              area_of_impact = ?, 
                              resolution_expected = ?, 
                              action = ?, 
                              priority = ?, 
                              escalation_required = ?,
                              assigned_to_id = ?,
                              last_updated = CURRENT_TIMESTAMP 
                              WHERE change_request_id = ?";
                    
                    $stmt = $conn->prepare($query);
                    
                    if (!$stmt) {
                        throw new Exception("Database error: " . $conn->error);
                    }
                    
                    $project_id = intval($_POST['project_id']);
                    $change_title = trim($_POST['change_title']);
                    $change_description = trim($_POST['change_description']);
                    $justification = trim($_POST['justification']);
                    $impact_analysis = trim($_POST['impact_analysis']);
                    $area_of_impact = isset($_POST['area_of_impact']) ? $_POST['area_of_impact'] : null;
                    $resolution_expected = isset($_POST['resolution_expected']) ? trim($_POST['resolution_expected']) : null;
                    $action = isset($_POST['action']) ? trim($_POST['action']) : null;
                    $priority = trim($_POST['priority']);
                    $escalation_required = isset($_POST['escalation_required']) ? intval($_POST['escalation_required']) : 0;
                    $assigned_to_id = isset($_POST['assigned_to_id']) && !empty($_POST['assigned_to_id']) ? intval($_POST['assigned_to_id']) : null;
                    
                    $stmt->bind_param(
                        "issssssssiii",
                        $project_id,
                        $change_title,
                        $change_description,
                        $justification,
                        $impact_analysis,
                        $area_of_impact,
                        $resolution_expected,
                        $action,
                        $priority,
                        $escalation_required,
                        $assigned_to_id,
                        $change_request_id
                    );
                    
                    if ($stmt->execute()) {
                        // Add to change logs
                        $log_query = "INSERT INTO change_logs (change_request_id, user_id, action, details) VALUES (?, ?, 'Updated', 'Change request details updated')";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("ii", $change_request_id, $user_id);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        $response['success'] = true;
                        $response['message'] = 'Change request updated successfully!';
                    } else {
                        throw new Exception("Failed to update change request: " . $stmt->error);
                    }
                    
                    $stmt->close();
                    break;
                    
                case 'update_status':
                    // Update status (only for managers/admins)
                    if ($user_role !== 'Admin' && $user_role !== 'pm_manager' && $user_role !== 'super_admin') {
                        throw new Exception("You don't have permission to update status.");
                    }
                    
                    if (empty($_POST['change_request_id']) || empty($_POST['status'])) {
                        throw new Exception("Change request ID and status are required.");
                    }
                    
                    $change_request_id = intval($_POST['change_request_id']);
                    $status = trim($_POST['status']);
                    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
                    
                    $valid_statuses = ['Open', 'In Progress', 'Approved', 'Rejected', 'Implemented', 'Terminated'];
                    if (!in_array($status, $valid_statuses)) {
                        throw new Exception("Invalid status.");
                    }
                    
                    $query = "UPDATE change_requests SET status = ?, last_updated = CURRENT_TIMESTAMP WHERE change_request_id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("si", $status, $change_request_id);
                    
                    if ($stmt->execute()) {
                        // Add to change logs
                        $action_text = "Status changed to: $status";
                        if (!empty($comment)) {
                            $action_text .= " - $comment";
                        }
                        
                        $log_query = "INSERT INTO change_logs (change_request_id, user_id, action, details) VALUES (?, ?, 'Status Updated', ?)";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("iis", $change_request_id, $user_id, $action_text);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        // Mark as viewed by requester if status changes
                        if ($status === 'Approved' || $status === 'Rejected') {
                            $view_query = "UPDATE change_requests SET viewed = 0 WHERE change_request_id = ?";
                            $view_stmt = $conn->prepare($view_query);
                            $view_stmt->bind_param("i", $change_request_id);
                            $view_stmt->execute();
                            $view_stmt->close();
                        }
                        
                        $response['success'] = true;
                        $response['message'] = "Status updated to $status successfully!";
                    } else {
                        throw new Exception("Failed to update status: " . $stmt->error);
                    }
                    
                    $stmt->close();
                    break;
                    
                case 'delete_change_request':
                    // Delete change request
                    if (empty($_POST['change_request_id'])) {
                        throw new Exception("Change request ID is required.");
                    }
                    
                    $change_request_id = intval($_POST['change_request_id']);
                    
                    // Check if user has permission to delete
                    $check_query = "SELECT requester_id FROM change_requests WHERE change_request_id = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("i", $change_request_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_assoc();
                    $check_stmt->close();
                    
                    if (!$check_result) {
                        throw new Exception("Change request not found.");
                    }
                    
                    // Only requester can delete if status is Open, managers can delete any
                    if ($user_role !== 'Admin' && $user_role !== 'pm_manager' && $user_role !== 'super_admin') {
                        if ($check_result['requester_id'] != $user_id) {
                            throw new Exception("You don't have permission to delete this request.");
                        }
                    }
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Delete from change_logs first
                        $log_query = "DELETE FROM change_logs WHERE change_request_id = ?";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("i", $change_request_id);
                        $log_stmt->execute();
                        $log_stmt->close();
                        
                        // Delete from change_request_comments
                        $comment_query = "DELETE FROM change_request_comments WHERE change_request_id = ?";
                        $comment_stmt = $conn->prepare($comment_query);
                        $comment_stmt->bind_param("i", $change_request_id);
                        $comment_stmt->execute();
                        $comment_stmt->close();
                        
                        // Finally delete the change request
                        $query = "DELETE FROM change_requests WHERE change_request_id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $change_request_id);
                        $stmt->execute();
                        $stmt->close();
                        
                        $conn->commit();
                        
                        $response['success'] = true;
                        $response['message'] = 'Change request deleted successfully!';
                    } catch (Exception $e) {
                        $conn->rollback();
                        throw new Exception("Failed to delete change request: " . $e->getMessage());
                    }
                    break;
                    
                case 'get_change_request':
                    // Get single change request details
                    if (empty($_POST['change_request_id'])) {
                        throw new Exception("Change request ID is required.");
                    }
                    
                    $change_request_id = intval($_POST['change_request_id']);
                    
                    $query = "SELECT cr.*, p.name as project_name, 
                             u1.username as requester_name, u2.username as assigned_to_name
                             FROM change_requests cr
                             JOIN projects p ON cr.project_id = p.id
                             JOIN users u1 ON cr.requester_id = u1.id
                             LEFT JOIN users u2 ON cr.assigned_to_id = u2.id
                             WHERE cr.change_request_id = ?";
                    
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $change_request_id);
                    $stmt->execute();
                    $result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if ($result) {
                        // Get logs for this change request
                        $log_query = "SELECT cl.*, u.username 
                                     FROM change_logs cl
                                     JOIN users u ON cl.user_id = u.id
                                     WHERE cl.change_request_id = ?
                                     ORDER BY cl.log_date DESC";
                        $log_stmt = $conn->prepare($log_query);
                        $log_stmt->bind_param("i", $change_request_id);
                        $log_stmt->execute();
                        $logs_result = $log_stmt->get_result();
                        $logs = $logs_result->fetch_all(MYSQLI_ASSOC);
                        $log_stmt->close();
                        
                        $result['logs'] = $logs;
                        
                        $response['success'] = true;
                        $response['data'] = $result;
                    } else {
                        throw new Exception("Change request not found.");
                    }
                    break;
                    
                case 'get_change_requests':
                    // Get paginated change requests with filters
                    $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
                    $per_page = 10;
                    $offset = ($page - 1) * $per_page;
                    
                    // Build WHERE clause based on user role and filters
                    $where_clauses = [];
                    $params = [];
                    $types = "";
                    
                    // Role-based filtering
                    if ($user_role !== 'Admin' && $user_role !== 'pm_manager' && $user_role !== 'super_admin') {
                        $where_clauses[] = "cr.requester_id = ?";
                        $params[] = $user_id;
                        $types .= "i";
                    }
                    
                    // Apply filters
                    if (!empty($_POST['status'])) {
                        $where_clauses[] = "cr.status = ?";
                        $params[] = $_POST['status'];
                        $types .= "s";
                    }
                    
                    if (!empty($_POST['priority'])) {
                        $where_clauses[] = "cr.priority = ?";
                        $params[] = $_POST['priority'];
                        $types .= "s";
                    }
                    
                    if (!empty($_POST['project_id'])) {
                        $where_clauses[] = "cr.project_id = ?";
                        $params[] = intval($_POST['project_id']);
                        $types .= "i";
                    }
                    
                    if (!empty($_POST['search'])) {
                        $where_clauses[] = "(cr.change_title LIKE ? OR cr.change_description LIKE ?)";
                        $search_term = "%" . $_POST['search'] . "%";
                        $params[] = $search_term;
                        $params[] = $search_term;
                        $types .= "ss";
                    }
                    
                    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
                    
                    // Get total count
                    $count_query = "SELECT COUNT(*) as total 
                                   FROM change_requests cr 
                                   $where_sql";
                    
                    $count_stmt = $conn->prepare($count_query);
                    if (!empty($params)) {
                        $count_stmt->bind_param($types, ...$params);
                    }
                    $count_stmt->execute();
                    $count_result = $count_stmt->get_result()->fetch_assoc();
                    $total_records = $count_result['total'];
                    $total_pages = ceil($total_records / $per_page);
                    $count_stmt->close();
                    
                    // Get data with pagination
                    $data_query = "SELECT cr.*, p.name as project_name, 
                                  u1.username as requester_name, u2.username as assigned_to_name
                                  FROM change_requests cr
                                  JOIN projects p ON cr.project_id = p.id
                                  JOIN users u1 ON cr.requester_id = u1.id
                                  LEFT JOIN users u2 ON cr.assigned_to_id = u2.id
                                  $where_sql
                                  ORDER BY cr.request_date DESC
                                  LIMIT ? OFFSET ?";
                    
                    $params[] = $per_page;
                    $params[] = $offset;
                    $types .= "ii";
                    
                    $data_stmt = $conn->prepare($data_query);
                    $data_stmt->bind_param($types, ...$params);
                    $data_stmt->execute();
                    $data_result = $data_stmt->get_result();
                    $change_requests = $data_result->fetch_all(MYSQLI_ASSOC);
                    $data_stmt->close();
                    
                    $response['success'] = true;
                    $response['data'] = $change_requests;
                    $response['pagination'] = [
                        'current_page' => $page,
                        'total_pages' => $total_pages,
                        'total_records' => $total_records,
                        'per_page' => $per_page
                    ];
                    break;
                    
                default:
                    throw new Exception("Invalid action.");
            }
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Change Management - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../Images/DashenLogo1.png">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f5f7fb;
            --dashen-success: #2ecc71;
            --dashen-warning: #f39c12;
            --dashen-danger: #e74c3c;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --border-color: #ecf0f1;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --transition-speed: 0.3s;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: all var(--transition-speed);
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 10px 20px rgba(39, 50, 116, 0.15);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: url('../Images/DashenLogo1.png') no-repeat center center;
            background-size: contain;
            opacity: 0.1;
            pointer-events: none;
        }
        
        .page-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: white;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        
        .welcome-text {
            color: rgba(255, 255, 255, 0.85);
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        /* Cards */
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .stat-card {
            padding: 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--dashen-primary);
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.3);
        }
        
        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            color: var(--text-light);
            font-weight: 500;
            font-size: 1rem;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem 1.5rem;
        }
        
        .card-title {
            font-weight: 600;
            color: var(--dashen-primary);
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .card-title i {
            margin-right: 0.5rem;
            color: var(--dashen-accent);
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            font-size: 0.875rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .bg-pending { background: #fff3cd; color: #856404; }
        .bg-in-progress { background: #cce7ff; color: var(--dashen-primary); }
        .bg-approved { background: #d1f7e9; color: var(--dashen-success); }
        .bg-rejected { background: #f8d7da; color: var(--dashen-danger); }
        .bg-implemented { background: #e0e7ff; color: var(--dashen-primary); }
        
        .priority-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85rem;
        }
        
        /* Table Styles */
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--text-light);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--dashen-light);
        }
        
        .table td {
            vertical-align: middle;
            padding: 1rem 0.75rem;
            border-color: var(--border-color);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            border: none;
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.3);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 50, 116, 0.4);
            background: linear-gradient(135deg, var(--dashen-secondary) 0%, var(--dashen-primary) 100%);
        }
        
        .badge.bg-accent {
            background: var(--dashen-accent) !important;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .dashboard-header::before {
                width: 150px;
                height: 150px;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            background: var(--dashen-primary);
            border: none;
            color: white;
            padding: 0.5rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.3);
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
        }
        
        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-card {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dashen-primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--dashen-secondary);
        }
        
        /* Filter Section */
        .filter-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 0.2rem rgba(39, 50, 116, 0.1);
        }
        
        /* Modal Enhancements */
        .modal-content {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-radius: 1rem 1rem 0 0;
            border-bottom: none;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        /* Action buttons */
        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .quick-action-btn {
            flex: 1;
            min-width: 150px;
            padding: 1rem;
            border-radius: 0.75rem;
            background: white;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            text-align: center;
            cursor: pointer;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--dashen-primary);
        }
        
        .quick-action-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--dashen-primary);
        }
        
        /* Priority badge colors */
        .badge-priority-low { background-color: #28a745; color: white; }
        .badge-priority-medium { background-color: #ffc107; color: #212529; }
        .badge-priority-high { background-color: #fd7e14; color: white; }
        .badge-priority-urgent { background-color: #dc3545; color: white; }
        
        /* Enhanced table styling */
        .table-hover tbody tr:hover {
            background-color: rgba(39, 50, 116, 0.05);
            transform: scale(1.002);
            transition: all 0.2s ease;
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--dashen-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            background: white;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
            animation: slideInRight 0.3s ease;
        }
        
        .toast-success { border-left-color: var(--dashen-success); }
        .toast-error { border-left-color: var(--dashen-danger); }
        .toast-warning { border-left-color: var(--dashen-warning); }
        .toast-info { border-left-color: var(--dashen-primary); }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    // Set current page for sidebar active state
    $_SESSION['current_page'] = 'change_management.php';
    include 'sidebar.php'; 
    ?>
    
    <!-- Loading Spinner -->
    <div class="spinner-overlay" id="loadingSpinner" style="display: none;">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Mobile Menu Button -->
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-file-alt me-2"></i>Project Change Requests
                </h1>
                <p class="welcome-text">Manage and track all change requests in one place</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addChangeRequestModal">
                <i class="fas fa-plus me-1"></i> New Change Request
            </button>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <div class="quick-action-btn" onclick="loadChangeRequests(1)">
                <div class="quick-action-icon">
                    <i class="fas fa-sync-alt"></i>
                </div>
                <div class="fw-medium">Refresh Data</div>
            </div>
            <div class="quick-action-btn" onclick="exportReports()">
                <div class="quick-action-icon">
                    <i class="fas fa-download"></i>
                </div>
                <div class="fw-medium">Export Reports</div>
            </div>
            <div class="quick-action-btn" onclick="toggleAdvancedFilters()">
                <div class="quick-action-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="fw-medium">Advanced Filters</div>
            </div>
            <div class="quick-action-btn">
                <div class="quick-action-icon">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="fw-medium">View Analytics</div>
            </div>
        </div>
        
        <!-- Dashboard Stats - Role Based -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="glass-card stat-card animate-card">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-number"><?php echo array_sum($stats); ?></div>
                    <div class="stat-label"><?php echo ($user_role === 'Admin' || $user_role === 'pm_manager' || $user_role === 'super_admin') ? 'Total Requests' : 'My Requests'; ?></div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="glass-card stat-card animate-card delay-1">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['Approved']) ? $stats['Approved'] : 0; ?></div>
                    <div class="stat-label">Approved</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="glass-card stat-card animate-card delay-2">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%);">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['In Progress']) ? $stats['In Progress'] : 0; ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="glass-card stat-card animate-card delay-3">
                    <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-danger) 0%, #c0392b 100%);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-danger) 0%, #c0392b 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['Open']) ? $stats['Open'] : 0; ?></div>
                    <div class="stat-label">Pending Review</div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card mb-4 animate-card" id="filterSection">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label for="statusFilter" class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Statuses</option>
                            <option value="Open">Open</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Approved">Approved</option>
                            <option value="Implemented">Implemented</option>
                            <option value="Terminated">Terminated</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="priorityFilter" class="form-label">Priority</label>
                        <select class="form-select" id="priorityFilter">
                            <option value="">All Priorities</option>
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="projectFilter" class="form-label">Project</label>
                        <select class="form-select" id="projectFilter">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['project_id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="searchInput" class="form-label">Search</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Search requests...">
                    </div>
                </div>
                <div class="row mt-3" id="advancedFilters" style="display: none;">
                    <div class="col-md-3">
                        <label for="dateFromFilter" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="dateFromFilter">
                    </div>
                    <div class="col-md-3">
                        <label for="dateToFilter" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="dateToFilter">
                    </div>
                    <div class="col-md-3">
                        <label for="requesterFilter" class="form-label">Requester</label>
                        <select class="form-select" id="requesterFilter">
                            <option value="">All Requesters</option>
                            <!-- Will be populated dynamically -->
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="assignedToFilter" class="form-label">Assigned To</label>
                        <select class="form-select" id="assignedToFilter">
                            <option value="">All Assignees</option>
                            <!-- Will be populated dynamically -->
                        </select>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12 text-end">
                        <button class="btn btn-outline-secondary me-2" onclick="clearFilters()">Clear Filters</button>
                        <button class="btn btn-primary" onclick="applyFilters()">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Requests Table -->
        <div class="glass-card animate-card delay-1">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Project</th>
                                <th>Title</th>
                                <th>Requester</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="changeRequestsTableBody">
                            <!-- Data will be loaded via JavaScript -->
                            <tr id="loadingRow">
                                <td colspan="8" class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2">Loading change requests...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mt-4" id="pagination-controls">
                        <!-- Pagination will be loaded via JavaScript -->
                    </ul>
                </nav>
            </div>
        </div>
    </div>

    <!-- Add Change Request Modal -->
    <div class="modal fade" id="addChangeRequestModal" tabindex="-1" aria-labelledby="addChangeRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addChangeRequestModalLabel">New Change Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="changeRequestForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="project_id" class="form-label">Project *</label>
                                <select class="form-select" id="project_id" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['project_id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="change_title" class="form-label">Change Title *</label>
                                <input type="text" class="form-control" id="change_title" name="change_title" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="priority" class="form-label">Priority *</label>
                                <select class="form-select" id="priority" name="priority" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="escalation_required" class="form-label">Escalation Required</label>
                                <select class="form-select" id="escalation_required" name="escalation_required">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="change_description" class="form-label">Description *</label>
                            <textarea class="form-control" id="change_description" name="change_description" rows="3" required placeholder="Describe the change in detail..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="justification" class="form-label">Justification *</label>
                            <textarea class="form-control" id="justification" name="justification" rows="3" required placeholder="Why is this change necessary?"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="impact_analysis" class="form-label">Impact Analysis *</label>
                            <textarea class="form-control" id="impact_analysis" name="impact_analysis" rows="3" required placeholder="What impact will this change have?"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="area_of_impact" class="form-label">Area of Impact</label>
                                <select class="form-select" id="area_of_impact" name="area_of_impact">
                                    <option value="">Select Area</option>
                                    <option value="Budget">Budget</option>
                                    <option value="Schedule">Schedule</option>
                                    <option value="Scope">Scope</option>
                                    <option value="Resources">Resources</option>
                                    <option value="Quality">Quality</option>
                                    <option value="Risk">Risk</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="resolution_expected" class="form-label">Resolution Expected</label>
                                <input type="text" class="form-control" id="resolution_expected" name="resolution_expected" placeholder="e.g., 2 weeks">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="assigned_to_id" class="form-label">Assigned To</label>
                                <select class="form-select" id="assigned_to_id" name="assigned_to_id">
                                    <option value="">Unassigned</option>
                                    <?php
                                    // Fetch users who can be assigned tasks
                                    $users_query = "SELECT id as user_id, username FROM users WHERE system_role IN ('pm_employee', 'pm_manager', 'super_admin') ORDER BY username";
                                    $users_stmt = $conn->prepare($users_query);
                                    if ($users_stmt) {
                                        $users_stmt->execute();
                                        $result = $users_stmt->get_result();
                                        $users = $result->fetch_all(MYSQLI_ASSOC);
                                        $users_stmt->close();
                                    } else {
                                        $users = [];
                                    }
                                    
                                    foreach ($users as $user) {
                                        echo "<option value=\"{$user['user_id']}\">" . htmlspecialchars($user['username']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="action" class="form-label">Action</label>
                            <textarea class="form-control" id="action" name="action" rows="2" placeholder="Recommended actions..."></textarea>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Change Request Modal -->
    <div class="modal fade" id="viewChangeRequestModal" tabindex="-1" aria-labelledby="viewChangeRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewChangeRequestModalLabel">Change Request Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewChangeRequestBody">
                    <!-- Content will be loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Change Request Modal -->
    <div class="modal fade" id="editChangeRequestModal" tabindex="-1" aria-labelledby="editChangeRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editChangeRequestModalLabel">Edit Change Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editChangeRequestForm">
                        <input type="hidden" id="edit_change_request_id" name="change_request_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_project_id" class="form-label">Project *</label>
                                <select class="form-select" id="edit_project_id" name="project_id" required>
                                    <option value="">Select Project</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['project_id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_change_title" class="form-label">Change Title *</label>
                                <input type="text" class="form-control" id="edit_change_title" name="change_title" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_priority" class="form-label">Priority *</label>
                                <select class="form-select" id="edit_priority" name="priority" required>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_escalation_required" class="form-label">Escalation Required</label>
                                <select class="form-select" id="edit_escalation_required" name="escalation_required">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_change_description" class="form-label">Description *</label>
                            <textarea class="form-control" id="edit_change_description" name="change_description" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_justification" class="form-label">Justification *</label>
                            <textarea class="form-control" id="edit_justification" name="justification" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_impact_analysis" class="form-label">Impact Analysis *</label>
                            <textarea class="form-control" id="edit_impact_analysis" name="impact_analysis" rows="3" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_area_of_impact" class="form-label">Area of Impact</label>
                                <select class="form-select" id="edit_area_of_impact" name="area_of_impact">
                                    <option value="">Select Area</option>
                                    <option value="Budget">Budget</option>
                                    <option value="Schedule">Schedule</option>
                                    <option value="Scope">Scope</option>
                                    <option value="Resources">Resources</option>
                                    <option value="Quality">Quality</option>
                                    <option value="Risk">Risk</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_resolution_expected" class="form-label">Resolution Expected</label>
                                <input type="text" class="form-control" id="edit_resolution_expected" name="resolution_expected">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_assigned_to_id" class="form-label">Assigned To</label>
                                <select class="form-select" id="edit_assigned_to_id" name="assigned_to_id">
                                    <option value="">Unassigned</option>
                                    <?php
                                    // Fetch users who can be assigned tasks
                                    $users_query = "SELECT id as user_id, username FROM users WHERE system_role IN ('pm_employee', 'pm_manager', 'super_admin') ORDER BY username";
                                    $users_stmt = $conn->prepare($users_query);
                                    if ($users_stmt) {
                                        $users_stmt->execute();
                                        $result = $users_stmt->get_result();
                                        $users = $result->fetch_all(MYSQLI_ASSOC);
                                        $users_stmt->close();
                                    } else {
                                        $users = [];
                                    }
                                    
                                    foreach ($users as $user) {
                                        echo "<option value=\"{$user['user_id']}\">" . htmlspecialchars($user['username']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_action" class="form-label">Action</label>
                            <textarea class="form-control" id="edit_action" name="action" rows="2"></textarea>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div class="modal fade" id="statusUpdateModal" tabindex="-1" aria-labelledby="statusUpdateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="statusUpdateModalLabel">Update Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="statusUpdateForm">
                        <input type="hidden" id="status_change_request_id" name="change_request_id">
                        <div class="mb-3">
                            <label for="status" class="form-label">New Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="">Select Status</option>
                                <option value="Open">Open</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Implemented">Implemented</option>
                                <option value="Terminated">Terminated</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status_comment" class="form-label">Comment (Optional)</label>
                            <textarea class="form-control" id="status_comment" name="comment" rows="3" placeholder="Add any comments about this status change..."></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update Status</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteConfirmationModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this change request? This action cannot be undone.</p>
                    <input type="hidden" id="delete_change_request_id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store user role for JavaScript use
        const userRole = "<?php echo $user_role; ?>";
        const userId = "<?php echo $user_id; ?>";
        
        // Current page for pagination
        let currentPage = 1;
        let totalPages = 1;
        
        // Mobile menu functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && 
                    !sidebar.contains(e.target) && 
                    !mobileMenuBtn.contains(e.target)) {
                    sidebar.classList.remove('mobile-open');
                }
            });
            
            // Load change requests on page load
            loadChangeRequests(1);
            
            // Set up filter listeners
            document.getElementById('statusFilter').addEventListener('change', function() {
                loadChangeRequests(1);
            });
            
            document.getElementById('priorityFilter').addEventListener('change', function() {
                loadChangeRequests(1);
            });
            
            document.getElementById('projectFilter').addEventListener('change', function() {
                loadChangeRequests(1);
            });
            
            document.getElementById('searchInput').addEventListener('keyup', function(e) {
                if (e.key === 'Enter') {
                    loadChangeRequests(1);
                }
            });
        });
        
        // Show loading spinner
        function showLoading() {
            document.getElementById('loadingSpinner').style.display = 'flex';
        }
        
        // Hide loading spinner
        function hideLoading() {
            document.getElementById('loadingSpinner').style.display = 'none';
        }
        
        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                        <p class="mb-0">${message}</p>
                    </div>
                    <button type="button" class="btn-close btn-close-sm" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Load change requests with pagination
        function loadChangeRequests(page) {
            showLoading();
            currentPage = page;
            
            const formData = new FormData();
            formData.append('action', 'get_change_requests');
            formData.append('page', page);
            
            // Add filters
            const statusFilter = document.getElementById('statusFilter').value;
            const priorityFilter = document.getElementById('priorityFilter').value;
            const projectFilter = document.getElementById('projectFilter').value;
            const searchInput = document.getElementById('searchInput').value;
            
            if (statusFilter) formData.append('status', statusFilter);
            if (priorityFilter) formData.append('priority', priorityFilter);
            if (projectFilter) formData.append('project_id', projectFilter);
            if (searchInput) formData.append('search', searchInput);
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    renderChangeRequests(data.data);
                    renderPagination(data.pagination);
                } else {
                    showToast(data.message || 'Error loading change requests', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
        
        // Render change requests table
        function renderChangeRequests(requests) {
            const tbody = document.getElementById('changeRequestsTableBody');
            
            if (requests.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No change requests found.</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            let html = '';
            
            requests.forEach(request => {
                // Format date
                const requestDate = new Date(request.request_date);
                const formattedDate = requestDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                // Get status badge class
                let statusBadgeClass = 'bg-pending';
                switch(request.status) {
                    case 'In Progress':
                        statusBadgeClass = 'bg-in-progress';
                        break;
                    case 'Approved':
                        statusBadgeClass = 'bg-approved';
                        break;
                    case 'Rejected':
                        statusBadgeClass = 'bg-rejected';
                        break;
                    case 'Implemented':
                        statusBadgeClass = 'bg-implemented';
                        break;
                }
                
                // Get priority badge class
                let priorityBadgeClass = 'badge-priority-medium';
                switch(request.priority) {
                    case 'Low':
                        priorityBadgeClass = 'badge-priority-low';
                        break;
                    case 'High':
                        priorityBadgeClass = 'badge-priority-high';
                        break;
                    case 'Urgent':
                        priorityBadgeClass = 'badge-priority-urgent';
                        break;
                }
                
                html += `
                    <tr>
                        <td><strong>#${request.change_request_id}</strong></td>
                        <td>${escapeHtml(request.project_name)}</td>
                        <td>
                            <strong>${escapeHtml(request.change_title)}</strong>
                            <br><small class="text-muted">${escapeHtml(request.change_description.substring(0, 50))}${request.change_description.length > 50 ? '...' : ''}</small>
                        </td>
                        <td>${escapeHtml(request.requester_name)}</td>
                        <td><span class="badge ${statusBadgeClass}">${request.status}</span></td>
                        <td><span class="badge ${priorityBadgeClass}">${request.priority}</span></td>
                        <td>${formattedDate}</td>
                        <td>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="viewChangeRequest(${request.change_request_id})" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="editChangeRequest(${request.change_request_id})" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                ${userRole === 'Admin' || userRole === 'pm_manager' || userRole === 'super_admin' ? `
                                <button type="button" class="btn btn-outline-warning" onclick="updateStatus(${request.change_request_id})" title="Update Status">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                ` : ''}
                                <button type="button" class="btn btn-outline-danger" onclick="deleteChangeRequest(${request.change_request_id})" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }
        
        // Render pagination controls
        function renderPagination(pagination) {
            const paginationControls = document.getElementById('pagination-controls');
            totalPages = pagination.total_pages;
            
            if (totalPages <= 1) {
                paginationControls.innerHTML = '';
                return;
            }
            
            let html = '';
            
            // Previous button
            html += `
                <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                    <button class="page-link" onclick="loadChangeRequests(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </li>
            `;
            
            // Page numbers
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `
                    <li class="page-item ${currentPage === i ? 'active' : ''}">
                        <button class="page-link" onclick="loadChangeRequests(${i})">${i}</button>
                    </li>
                `;
            }
            
            // Next button
            html += `
                <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                    <button class="page-link" onclick="loadChangeRequests(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </li>
            `;
            
            paginationControls.innerHTML = html;
        }
        
        // View change request details
        function viewChangeRequest(changeRequestId) {
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'get_change_request');
            formData.append('change_request_id', changeRequestId);
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    renderChangeRequestDetails(data.data);
                } else {
                    showToast(data.message || 'Error loading change request details', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
        
        // Render change request details in modal
        function renderChangeRequestDetails(request) {
            // Format dates
            const requestDate = new Date(request.request_date);
            const lastUpdated = new Date(request.last_updated);
            
            const formattedRequestDate = requestDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const formattedLastUpdated = lastUpdated.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Get status badge
            let statusBadgeClass = 'bg-pending';
            let statusIcon = 'fa-clock';
            switch(request.status) {
                case 'In Progress':
                    statusBadgeClass = 'bg-in-progress';
                    statusIcon = 'fa-spinner';
                    break;
                case 'Approved':
                    statusBadgeClass = 'bg-approved';
                    statusIcon = 'fa-check-circle';
                    break;
                case 'Rejected':
                    statusBadgeClass = 'bg-rejected';
                    statusIcon = 'fa-times-circle';
                    break;
                case 'Implemented':
                    statusBadgeClass = 'bg-implemented';
                    statusIcon = 'fa-check-double';
                    break;
            }
            
            // Get priority badge
            let priorityBadgeClass = 'badge-priority-medium';
            switch(request.priority) {
                case 'Low':
                    priorityBadgeClass = 'badge-priority-low';
                    break;
                case 'High':
                    priorityBadgeClass = 'badge-priority-high';
                    break;
                case 'Urgent':
                    priorityBadgeClass = 'badge-priority-urgent';
                    break;
            }
            
            // Build logs HTML
            let logsHtml = '';
            if (request.logs && request.logs.length > 0) {
                logsHtml = `
                    <h6 class="mt-4 mb-3"><i class="fas fa-history me-2"></i>Activity Log</h6>
                    <div class="timeline">
                `;
                
                request.logs.forEach(log => {
                    const logDate = new Date(log.log_date);
                    const formattedLogDate = logDate.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    logsHtml += `
                        <div class="timeline-item mb-3">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="d-flex justify-content-between">
                                    <strong>${escapeHtml(log.username)}</strong>
                                    <small class="text-muted">${formattedLogDate}</small>
                                </div>
                                <div class="mb-1">
                                    <span class="badge bg-light text-dark">${log.action}</span>
                                </div>
                                <p class="mb-0 text-muted">${escapeHtml(log.details || 'No details provided')}</p>
                            </div>
                        </div>
                    `;
                });
                
                logsHtml += `</div>`;
            }
            
            const modalBody = document.getElementById('viewChangeRequestBody');
            modalBody.innerHTML = `
                <div class="row">
                    <div class="col-md-8">
                        <h5>${escapeHtml(request.change_title)}</h5>
                        <p class="text-muted">${escapeHtml(request.project_name)}</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge ${statusBadgeClass} fs-6 p-2">
                            <i class="fas ${statusIcon} me-1"></i> ${request.status}
                        </span>
                        <br>
                        <span class="badge ${priorityBadgeClass} mt-2">${request.priority} Priority</span>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-2"></i>Details</h6>
                        <table class="table table-sm">
                            <tr>
                                <th width="120">Requester:</th>
                                <td>${escapeHtml(request.requester_name)}</td>
                            </tr>
                            <tr>
                                <th>Assigned To:</th>
                                <td>${request.assigned_to_name ? escapeHtml(request.assigned_to_name) : '<span class="text-muted">Unassigned</span>'}</td>
                            </tr>
                            <tr>
                                <th>Request Date:</th>
                                <td>${formattedRequestDate}</td>
                            </tr>
                            <tr>
                                <th>Last Updated:</th>
                                <td>${formattedLastUpdated}</td>
                            </tr>
                            ${request.area_of_impact ? `<tr><th>Area of Impact:</th><td>${escapeHtml(request.area_of_impact)}</td></tr>` : ''}
                            ${request.resolution_expected ? `<tr><th>Resolution Expected:</th><td>${escapeHtml(request.resolution_expected)}</td></tr>` : ''}
                            ${request.escalation_required == 1 ? `<tr><th>Escalation:</th><td><span class="badge bg-warning">Required</span></td></tr>` : ''}
                        </table>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-align-left me-2"></i>Description</h6>
                        <div class="card card-body bg-light">
                            ${escapeHtml(request.change_description).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle me-2"></i>Justification</h6>
                        <div class="card card-body bg-light">
                            ${escapeHtml(request.justification).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-line me-2"></i>Impact Analysis</h6>
                        <div class="card card-body bg-light">
                            ${escapeHtml(request.impact_analysis).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                </div>
                
                ${request.action ? `
                <div class="row mt-3">
                    <div class="col-12">
                        <h6><i class="fas fa-tasks me-2"></i>Action</h6>
                        <div class="card card-body bg-light">
                            ${escapeHtml(request.action).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                </div>
                ` : ''}
                
                ${logsHtml}
            `;
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('viewChangeRequestModal'));
            modal.show();
        }
        
        // Edit change request
        function editChangeRequest(changeRequestId) {
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'get_change_request');
            formData.append('change_request_id', changeRequestId);
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    populateEditForm(data.data);
                } else {
                    showToast(data.message || 'Error loading change request for editing', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
        
        // Populate edit form with data
        function populateEditForm(request) {
            document.getElementById('edit_change_request_id').value = request.change_request_id;
            document.getElementById('edit_project_id').value = request.project_id;
            document.getElementById('edit_change_title').value = request.change_title;
            document.getElementById('edit_change_description').value = request.change_description;
            document.getElementById('edit_justification').value = request.justification;
            document.getElementById('edit_impact_analysis').value = request.impact_analysis;
            document.getElementById('edit_area_of_impact').value = request.area_of_impact || '';
            document.getElementById('edit_resolution_expected').value = request.resolution_expected || '';
            document.getElementById('edit_action').value = request.action || '';
            document.getElementById('edit_priority').value = request.priority;
            document.getElementById('edit_escalation_required').value = request.escalation_required;
            document.getElementById('edit_assigned_to_id').value = request.assigned_to_id || '';
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('editChangeRequestModal'));
            modal.show();
        }
        
        // Update status
        function updateStatus(changeRequestId) {
            document.getElementById('status_change_request_id').value = changeRequestId;
            document.getElementById('status').value = '';
            document.getElementById('status_comment').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('statusUpdateModal'));
            modal.show();
        }
        
        // Delete change request
        function deleteChangeRequest(changeRequestId) {
            document.getElementById('delete_change_request_id').value = changeRequestId;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
            modal.show();
        }
        
        // Confirm deletion
        function confirmDelete() {
            const changeRequestId = document.getElementById('delete_change_request_id').value;
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'delete_change_request');
            formData.append('change_request_id', changeRequestId);
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
                    modal.hide();
                    
                    // Reload the table
                    loadChangeRequests(currentPage);
                } else {
                    showToast(data.message || 'Error deleting change request', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
        
        // Apply filters
        function applyFilters() {
            loadChangeRequests(1);
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('statusFilter').value = '';
            document.getElementById('priorityFilter').value = '';
            document.getElementById('projectFilter').value = '';
            document.getElementById('searchInput').value = '';
            document.getElementById('dateFromFilter').value = '';
            document.getElementById('dateToFilter').value = '';
            document.getElementById('requesterFilter').value = '';
            document.getElementById('assignedToFilter').value = '';
            
            loadChangeRequests(1);
        }
        
        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const advancedFilters = document.getElementById('advancedFilters');
            if (advancedFilters.style.display === 'none') {
                advancedFilters.style.display = 'flex';
            } else {
                advancedFilters.style.display = 'none';
            }
        }
        
        // Export reports
        function exportReports() {
            showToast('Export feature coming soon!', 'info');
        }
        
        // Handle form submissions
        document.getElementById('changeRequestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            showLoading();
            
            const formData = new FormData(this);
            formData.append('action', 'create_change_request');
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addChangeRequestModal'));
                    modal.hide();
                    
                    // Reset the form
                    this.reset();
                    
                    // Reload the table
                    loadChangeRequests(1);
                } else {
                    showToast(data.message || 'Error creating change request', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        });
        
        document.getElementById('editChangeRequestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            showLoading();
            
            const formData = new FormData(this);
            formData.append('action', 'update_change_request');
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editChangeRequestModal'));
                    modal.hide();
                    
                    // Reload the table
                    loadChangeRequests(currentPage);
                } else {
                    showToast(data.message || 'Error updating change request', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        });
        
        document.getElementById('statusUpdateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            showLoading();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            
            fetch('change_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Close the modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('statusUpdateModal'));
                    modal.hide();
                    
                    // Reload the table
                    loadChangeRequests(currentPage);
                } else {
                    showToast(data.message || 'Error updating status', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        });
        
        // Utility function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>

</body>
</html>