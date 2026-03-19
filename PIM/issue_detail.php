<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'] ?? 'viewer';

// Function to check user roles
function hasRole($role) {
    global $user_role;
    
    // Check for specific role permissions
    if ($role === 'admin') {
        return in_array($user_role, ['admin', 'super_admin']);
    } elseif ($role === 'super_admin') {
        return $user_role === 'super_admin';
    }
    return false;
}

// Get issue ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: issues.php");
    exit();
}

$issue_id = intval($_GET['id']);

// Get issue details
$stmt = $conn->prepare("
    SELECT i.*, p.name as project_name, 
           u.username as assigned_username, u.email as assigned_email,
           creator.username as creator_username, creator.email as creator_email
    FROM issues i 
    LEFT JOIN projects p ON i.project_id = p.id 
    LEFT JOIN users u ON i.assigned_to = u.id 
    LEFT JOIN users creator ON i.created_by = creator.id 
    WHERE i.id = ?
");
$stmt->bind_param("i", $issue_id);
$stmt->execute();
$result = $stmt->get_result();
$issue = $result->fetch_assoc();

if (!$issue) {
    header("Location: issues.php");
    exit();
}

// Check if user has access to this issue
if (!hasRole('admin') && 
    $issue['assigned_to'] != $user_id && 
    $issue['created_by'] != $user_id) {
    header("Location: issues.php");
    exit();
}

// Get comments
$comments = [];
$stmt = $conn->prepare("
    SELECT c.*, u.username, u.email
    FROM comments c 
    LEFT JOIN users u ON c.user_id = u.id 
    WHERE c.issue_id = ? 
    ORDER BY c.created_at ASC
");
$stmt->bind_param("i", $issue_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
}
$stmt->close();

// Get attachments
$attachments = [];
$stmt = $conn->prepare("
    SELECT a.*, u.username
    FROM attachments a 
    LEFT JOIN users u ON a.uploaded_by = u.id 
    WHERE a.issue_id = ? 
    ORDER BY a.uploaded_at DESC
");
$stmt->bind_param("i", $issue_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $attachments[] = $row;
}
$stmt->close();

// Get activity logs
$activity_logs = [];
$stmt = $conn->prepare("
    SELECT al.*, u.username 
    FROM activity_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    WHERE al.entity_type = 'issue' AND al.entity_id = ? 
    ORDER BY al.created_at DESC
");
$stmt->bind_param("i", $issue_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activity_logs[] = $row;
}
$stmt->close();

// Handle comment submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle comment submission
    if (isset($_POST['add_comment'])) {
        $comment = trim($_POST['comment']);
        
        if (!empty($comment)) {
            $stmt = $conn->prepare("INSERT INTO comments (issue_id, user_id, comment) VALUES (?, ?, ?)");
            $stmt->bind_param("iis", $issue_id, $user_id, $comment);
            
            if ($stmt->execute()) {
                $success = "Comment added successfully!";
                
                // Log the activity
                $action = "Comment Added";
                $description = "User added a comment to issue #" . $issue_id;
                $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'issue', ?, NOW())");
                $stmt_log->bind_param("issi", $user_id, $action, $description, $issue_id);
                $stmt_log->execute();
                $stmt_log->close();
                
                // Refresh comments
                $stmt = $conn->prepare("
                    SELECT c.*, u.username
                    FROM comments c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    WHERE c.issue_id = ? 
                    ORDER BY c.created_at ASC
                ");
                $stmt->bind_param("i", $issue_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $comments = [];
                while ($row = $result->fetch_assoc()) {
                    $comments[] = $row;
                }
                $stmt->close();
            } else {
                $error = "Error adding comment: " . $conn->error;
            }
        } else {
            $error = "Comment cannot be empty";
        }
    }
    
    // Handle status update
    if (isset($_POST['update_status'])) {
        $new_status = $_POST['status'];
        $status_comment = trim($_POST['status_comment']);
        
        $stmt = $conn->prepare("UPDATE issues SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $new_status, $issue_id);
        
        if ($stmt->execute()) {
            $success = "Status updated successfully!";
            
            // Add status change as a comment if comment is provided
            if (!empty($status_comment)) {
                $comment_text = "Status changed to " . ucfirst(str_replace('_', ' ', $new_status)) . ": " . $status_comment;
                $stmt_comment = $conn->prepare("INSERT INTO comments (issue_id, user_id, comment) VALUES (?, ?, ?)");
                $stmt_comment->bind_param("iis", $issue_id, $user_id, $comment_text);
                $stmt_comment->execute();
                $stmt_comment->close();
            }
            
            // Log the activity
            $action = "Status Updated";
            $description = "Issue status changed from " . $issue['status'] . " to " . $new_status;
            $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'issue', ?, NOW())");
            $stmt_log->bind_param("issi", $user_id, $action, $description, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Refresh issue data
            $stmt = $conn->prepare("
                SELECT i.*, p.name as project_name, 
                       u.username as assigned_username, u.email as assigned_email,
                       creator.username as creator_username, creator.email as creator_email
                FROM issues i 
                LEFT JOIN projects p ON i.project_id = p.id 
                LEFT JOIN users u ON i.assigned_to = u.id 
                LEFT JOIN users creator ON i.created_by = creator.id 
                WHERE i.id = ?
            ");
            $stmt->bind_param("i", $issue_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $issue = $result->fetch_assoc();
            
        } else {
            $error = "Error updating status: " . $conn->error;
        }
    }
    
    // Handle assignment update
    if (isset($_POST['update_assignment'])) {
        $new_assignee = $_POST['assigned_to'];
        
        $stmt = $conn->prepare("UPDATE issues SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $new_assignee, $issue_id);
        
        if ($stmt->execute()) {
            $success = "Assignment updated successfully!";
            
            // Get assignee username
            $assignee_name = "Unassigned";
            if (!empty($new_assignee)) {
                $stmt_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $stmt_user->bind_param("i", $new_assignee);
                $stmt_user->execute();
                $result_user = $stmt_user->get_result();
                if ($row_user = $result_user->fetch_assoc()) {
                    $assignee_name = $row_user['username'];
                }
                $stmt_user->close();
            }
            
            // Log the activity
            $action = "Assignment Updated";
            $description = "Issue assigned to " . $assignee_name;
            $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'issue', ?, NOW())");
            $stmt_log->bind_param("issi", $user_id, $action, $description, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Refresh issue data
            $stmt = $conn->prepare("
                SELECT i.*, p.name as project_name, 
                       u.username as assigned_username, u.email as assigned_email,
                       creator.username as creator_username, creator.email as creator_email
                FROM issues i 
                LEFT JOIN projects p ON i.project_id = p.id 
                LEFT JOIN users u ON i.assigned_to = u.id 
                LEFT JOIN users creator ON i.created_by = creator.id 
                WHERE i.id = ?
            ");
            $stmt->bind_param("i", $issue_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $issue = $result->fetch_assoc();
            
        } else {
            $error = "Error updating assignment: " . $conn->error;
        }
    }
    
    // Handle priority update
    if (isset($_POST['update_priority'])) {
        $new_priority = $_POST['priority'];
        
        $stmt = $conn->prepare("UPDATE issues SET priority = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $new_priority, $issue_id);
        
        if ($stmt->execute()) {
            $success = "Priority updated successfully!";
            
            // Log the activity
            $action = "Priority Updated";
            $description = "Issue priority changed to " . $new_priority;
            $stmt_log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, created_at) VALUES (?, ?, ?, 'issue', ?, NOW())");
            $stmt_log->bind_param("issi", $user_id, $action, $description, $issue_id);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Refresh issue data
            $stmt = $conn->prepare("
                SELECT i.*, p.name as project_name, 
                       u.username as assigned_username, u.email as assigned_email,
                       creator.username as creator_username, creator.email as creator_email
                FROM issues i 
                LEFT JOIN projects p ON i.project_id = p.id 
                LEFT JOIN users u ON i.assigned_to = u.id 
                LEFT JOIN users creator ON i.created_by = creator.id 
                WHERE i.id = ?
            ");
            $stmt->bind_param("i", $issue_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $issue = $result->fetch_assoc();
            
        } else {
            $error = "Error updating priority: " . $conn->error;
        }
    }
}

// Get users for assignee dropdown
$users = [];
$user_query = "SELECT id, username FROM users ORDER BY username";
$user_result = $conn->query($user_query);
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[$row['id']] = $row['username'];
    }
}

// Get unread notification count
$unread_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$unread_count = $result->fetch_assoc()['count'];
$stmt->close();

// Include the sidebar
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue #<?php echo $issue_id; ?> - Dashen Bank Issue Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f5f7fb;
            --success-color: #2dce89;
            --info-color: #11cdef;
            --warning-color: #fb6340;
            --dark-color: #32325d;
            --light-color: #f8f9fa;
            --card-bg: #ffffff;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--dashen-light);
            color: var(--dark-color);
            min-height: 100vh;
            display: flex;
            margin: 0;
            padding: 0;
        }
        
        /* Header Styles */
        .dashboard-header {
            position: fixed;
            top: 0;
            right: 0;
            width: calc(100% - 280px);
            background: white;
            padding: 15px 30px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            box-shadow: var(--shadow-sm);
            z-index: 999;
            transition: var(--transition);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-greeting {
            margin: 0;
            font-size: 0.9rem;
            color: var(--dark-color);
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dashen-primary);
        }
        
        .header-btn {
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
        }
        
        .header-btn:hover {
            transform: translateY(-2px);
        }
        
        .profile-btn {
            background: var(--light-color);
            color: var(--dark-color);
        }
        
        .profile-btn:hover {
            background: #e9ecef;
            color: var(--dark-color);
        }
        
        .logout-btn {
            background: var(--dashen-primary);
            color: white;
        }
        
        .logout-btn:hover {
            background: var(--dashen-secondary);
            color: white;
        }
        
        /* Main Content Styles */
        .main-content {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 80px 30px 30px;
            min-height: 100vh;
        }
        
        .dashboard-card {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 30px;
            border: none;
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
        }
        
        .welcome-title {
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--dark-color);
            position: relative;
        }
        
        .welcome-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--dashen-primary);
            border-radius: 2px;
        }
        
        .welcome-subtitle {
            color: #6c757d;
            font-weight: 400;
            margin-bottom: 2.5rem;
            font-size: 1.1rem;
            max-width: 600px;
        }
        
        /* Badge Styles */
        .badge-status {
            font-size: 0.8rem;
            padding: 0.5em 0.75em;
            border-radius: 6px;
        }
        
        .user-role-badge {
            background: var(--dashen-primary);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 20px;
        }
        
        /* Button Styles */
        .btn-primary {
            background-color: var(--dashen-primary);
            border-color: var(--dashen-primary);
        }
        
        .btn-primary:hover {
            background-color: var(--dashen-secondary);
            border-color: var(--dashen-secondary);
        }
        
        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .btn-outline-secondary:hover {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        
        /* Comment Styles */
        .comment {
            border-left: 3px solid #e9ecef;
            padding-left: 15px;
            margin-bottom: 20px;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .comment-author {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .comment-date {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        /* Attachment Styles */
        .attachment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        
        .attachment-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Activity Log Styles */
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #e8f0fe;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        
        /* Quick Action Styles */
        .quick-action {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .quick-action h6 {
            margin-bottom: 10px;
            color: var(--dark-color);
        }
        
        /* Status indicator */
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-open { background-color: var(--warning-color); }
        .status-in_progress { background-color: var(--info-color); }
        .status-resolved { background-color: var(--success-color); }
        .status-closed { background-color: #6c757d; }
        
        /* Alert Styles */
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        /* Form Styles */
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.15);
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .dashboard-header {
                width: calc(100% - 80px);
            }
            
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                width: 100%;
                padding: 15px 20px;
            }
            
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 70px 15px 15px;
            }
            
            .dashboard-card {
                padding: 20px 15px;
            }
            
            .welcome-title {
                font-size: 2rem;
            }
            
            .user-info {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            
            .user-greeting {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header with User Info and Actions -->
    <header class="dashboard-header">
        <div class="user-info">
            <p class="user-greeting">Hello, <span class="user-name"><?php echo $_SESSION['username']; ?></span></p>
            <a href="../profile.php" class="header-btn profile-btn">
                <i class="bi bi-person-circle"></i> Profile
            </a>
            <a href="../logout.php" class="header-btn logout-btn">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </header>
    
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="dashboard-card">
            <span class="user-role-badge">
                <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access
            </span>
            
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h1 class="welcome-title">Issue #<?php echo $issue_id; ?></h1>
                    <p class="welcome-subtitle">View and manage this issue details, comments, and attachments.</p>
                </div>
                <div>
                    <?php if (hasRole('admin') || $issue['created_by'] == $user_id): ?>
                    <a href="edit_issue.php?id=<?php echo $issue_id; ?>" class="btn btn-primary">
                        <i class="bi bi-pencil me-1"></i> Edit Issue
                    </a>
                    <?php endif; ?>
                    <a href="issues.php" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-arrow-left me-1"></i> Back to Issues
                    </a>
                </div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Left Column - Issue Details and Comments -->
                <div class="col-md-8">
                    <!-- Issue Details -->
                    <div class="dashboard-card mb-4">
                        <h4 class="mb-3"><?php echo htmlspecialchars($issue['title']); ?></h4>
                        
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="badge badge-status 
                                <?php 
                                switch($issue['status']) {
                                    case 'open': echo 'bg-warning'; break;
                                    case 'in_progress': echo 'bg-info'; break;
                                    case 'resolved': echo 'bg-success'; break;
                                    case 'closed': echo 'bg-secondary'; break;
                                    default: echo 'bg-secondary';
                                }
                                ?>
                            ">
                                <span class="status-indicator status-<?php echo $issue['status']; ?>"></span>
                                <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                            </span>
                            <span class="badge badge-status 
                                <?php 
                                switch($issue['priority']) {
                                    case 'low': echo 'bg-secondary'; break;
                                    case 'medium': echo 'bg-primary'; break;
                                    case 'high': echo 'bg-warning text-dark'; break;
                                    case 'critical': echo 'bg-danger'; break;
                                    default: echo 'bg-primary';
                                }
                                ?>
                            ">
                                <?php echo ucfirst($issue['priority']); ?> Priority
                            </span>
                            <span class="badge badge-status bg-light text-dark">
                                <?php echo ucfirst($issue['type']); ?>
                            </span>
                            <?php if (!empty($issue['project_name'])): ?>
                                <span class="badge badge-status bg-primary">
                                    <?php echo htmlspecialchars($issue['project_name']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($issue['team'])): ?>
                                <span class="badge badge-status bg-secondary">
                                    <?php echo htmlspecialchars($issue['team']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($issue['sprint'])): ?>
                                <span class="badge badge-status bg-warning text-dark">
                                    <?php echo htmlspecialchars($issue['sprint']); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($issue['story_points'])): ?>
                                <span class="badge badge-status bg-light text-dark">
                                    <i class="bi bi-star-fill"></i> <?php echo $issue['story_points']; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($issue['summary'])): ?>
                            <div class="mb-4">
                                <h5>Summary</h5>
                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($issue['summary'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($issue['description'])): ?>
                            <div class="mb-4">
                                <h5>Description</h5>
                                <p><?php echo nl2br(htmlspecialchars($issue['description'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <strong>Created by:</strong> 
                                    <?php echo htmlspecialchars($issue['creator_username']); ?>
                                    <?php if (!empty($issue['creator_email'])): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($issue['creator_email']); ?>)</small>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-2">
                                    <strong>Created at:</strong> 
                                    <?php echo date('M j, Y g:i A', strtotime($issue['created_at'])); ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <strong>Assigned to:</strong> 
                                    <?php if (!empty($issue['assigned_username'])): ?>
                                        <?php echo htmlspecialchars($issue['assigned_username']); ?>
                                        <?php if (!empty($issue['assigned_email'])): ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($issue['assigned_email']); ?>)</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Unassigned</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mb-2">
                                    <strong>Last updated:</strong> 
                                    <?php echo date('M j, Y g:i A', strtotime($issue['updated_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="dashboard-card mb-4">
                        <h4 class="mb-4">Quick Actions</h4>
                        
                        <div class="row">
                            <!-- Status Update -->
                            <div class="col-md-6">
                                <div class="quick-action">
                                    <h6>Update Status</h6>
                                    <form method="POST">
                                        <div class="mb-2">
                                            <select class="form-select form-select-sm" name="status">
                                                <option value="open" <?php echo $issue['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                                <option value="in_progress" <?php echo $issue['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="resolved" <?php echo $issue['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                 <!--<option value="closed" <php echo $issue['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>-->
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <textarea class="form-control form-control-sm" name="status_comment" rows="2" placeholder="Optional comment"></textarea>
                                        </div>
                                        <button type="submit" name="update_status" class="btn btn-sm btn-primary w-100">Update Status</button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Assignment Update -->
                            <div class="col-md-6">
                                <div class="quick-action">
                                    <h6>Assign To</h6>
                                    <form method="POST">
                                        <div class="mb-2">
                                            <select class="form-select form-select-sm" name="assigned_to">
                                                <option value="">Unassigned</option>
                                                <?php foreach ($users as $id => $username): ?>
                                                    <option value="<?php echo $id; ?>" <?php echo $issue['assigned_to'] == $id ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($username); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" name="update_assignment" class="btn btn-sm btn-primary w-100">Update Assignment</button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Priority Update -->
                            <div class="col-md-6">
                                <div class="quick-action">
                                    <h6>Update Priority</h6>
                                    <form method="POST">
                                        <div class="mb-2">
                                            <select class="form-select form-select-sm" name="priority">
                                                <option value="low" <?php echo $issue['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                                <option value="medium" <?php echo $issue['priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                                <option value="high" <?php echo $issue['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                                <option value="critical" <?php echo $issue['priority'] == 'critical' ? 'selected' : ''; ?>>Critical</option>
                                            </select>
                                        </div>
                                        <button type="submit" name="update_priority" class="btn btn-sm btn-primary w-100">Update Priority</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comments Section -->
                    <div class="dashboard-card">
                        <h4 class="mb-4">Comments</h4>
                        
                        <?php if (empty($comments)): ?>
                            <p class="text-muted">No comments yet.</p>
                        <?php else: ?>
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment">
                                    <div class="comment-header">
                                        <span class="comment-author"><?php echo htmlspecialchars($comment['username']); ?>
                                            <?php if (!empty($comment['email'])): ?>
                                                <small class="text-muted">(<?php echo htmlspecialchars($comment['email']); ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                        <span class="comment-date"><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></span>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($comment['comment'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <h5 class="mb-3">Add Comment</h5>
                        <form method="POST">
                            <div class="mb-3">
                                <textarea class="form-control" name="comment" rows="3" placeholder="Add your comment here..." required></textarea>
                            </div>
                            <button type="submit" name="add_comment" class="btn btn-primary">
                                <i class="bi bi-chat me-1"></i> Post Comment
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Right Column - Attachments and Details -->
                <div class="col-md-4">
                    <!-- Activity Log -->
                    <div class="dashboard-card mb-4">
                        <h4 class="mb-4">Activity Log</h4>
                        
                        <?php if (empty($activity_logs)): ?>
                            <p class="text-muted">No activity recorded yet.</p>
                        <?php else: ?>
                            <?php foreach ($activity_logs as $log): ?>
                                <div class="activity-item d-flex align-items-start">
                                    <div class="activity-icon">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></strong>
                                            <small class="text-muted"><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></small>
                                        </div>
                                        <div class="text-muted"><?php echo htmlspecialchars($log['action']); ?></div>
                                        <?php if (!empty($log['description'])): ?>
                                            <div class="small mt-1"><?php echo htmlspecialchars($log['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Attachments Section -->
                    <div class="dashboard-card mb-4">
                        <h4 class="mb-4">Attachments</h4>
                        
                        <?php if (empty($attachments)): ?>
                            <p class="text-muted">No attachments yet.</p>
                        <?php else: ?>
                            <?php foreach ($attachments as $attachment): ?>
                                <div class="attachment-item">
                                    <div class="attachment-info">
                                        <i class="bi bi-file-earmark"></i>
                                        <div>
                                            <div><?php echo htmlspecialchars($attachment['filename']); ?></div>
                                            <small class="text-muted">
                                                Uploaded by <?php echo htmlspecialchars($attachment['username']); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <a href="#" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <hr>
                        
                        <h5 class="mb-3">Upload File</h5>
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <input type="file" class="form-control" name="attachment">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i> Upload
                            </button>
                        </form>
                    </div>
                    
                    <!-- Issue Details Sidebar -->
                    <div class="dashboard-card">
                        <h4 class="mb-4">Issue Details</h4>
                        
                        <div class="mb-3">
                            <strong>ID:</strong> <?php echo $issue_id; ?>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Status:</strong> 
                            <span class="badge badge-status 
                                <?php 
                                switch($issue['status']) {
                                    case 'open': echo 'bg-warning'; break;
                                    case 'in_progress': echo 'bg-info'; break;
                                    case 'resolved': echo 'bg-success'; break;
                                    case 'closed': echo 'bg-secondary'; break;
                                    default: echo 'bg-secondary';
                                }
                                ?>
                            ">
                                <span class="status-indicator status-<?php echo $issue['status']; ?>"></span>
                                <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Priority:</strong> 
                            <span class="badge badge-status 
                                <?php 
                                switch($issue['priority']) {
                                    case 'low': echo 'bg-secondary'; break;
                                    case 'medium': echo 'bg-primary'; break;
                                    case 'high': echo 'bg-warning text-dark'; break;
                                    case 'critical': echo 'bg-danger'; break;
                                    default: echo 'bg-primary';
                                }
                                ?>
                            ">
                                <?php echo ucfirst($issue['priority']); ?>
                            </span>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Type:</strong> 
                            <span class="badge badge-status bg-light text-dark">
                                <?php echo ucfirst($issue['type']); ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($issue['project_name'])): ?>
                            <div class="mb-3">
                                <strong>Project:</strong> <?php echo htmlspecialchars($issue['project_name']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($issue['team'])): ?>
                            <div class="mb-3">
                                <strong>Team:</strong> <?php echo htmlspecialchars($issue['team']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($issue['sprint'])): ?>
                            <div class="mb-3">
                                <strong>Sprint:</strong> <?php echo htmlspecialchars($issue['sprint']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($issue['story_points'])): ?>
                            <div class="mb-3">
                                <strong>Story Points:</strong> <?php echo $issue['story_points']; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($issue['labels'])): ?>
                            <div class="mb-3">
                                <strong>Labels:</strong>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    <?php 
                                    $labels = explode(',', $issue['labels']);
                                    foreach ($labels as $label): 
                                        $label = trim($label);
                                        if (!empty($label)): ?>
                                            <span class="badge badge-status bg-light text-dark"><?php echo htmlspecialchars($label); ?></span>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($issue['created_at'])); ?>
                        </div>
                        
                        <div class="mb-3">
                            <strong>Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($issue['updated_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-resize textareas
        document.querySelectorAll('textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
            
            // Trigger initial resize
            textarea.dispatchEvent(new Event('input'));
        });
    </script>
</body>
</html>