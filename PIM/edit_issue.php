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
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: issues.php");
    exit();
}

$issue_id = $_GET['id'];

// Get issue details
$stmt = $conn->prepare("
    SELECT i.*, 
           p.name as project_name,
           u.username as assigned_username,
           creator.username as creator_username
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

// Check if user has permission to edit this issue
if (!hasRole('admin') && $issue['created_by'] != $user_id) {
    header("Location: issue_detail.php?id=" . $issue_id);
    exit();
}

// Get projects for dropdown
$projects = [];
$project_query = "SELECT id, name FROM projects ORDER BY name";
$project_result = $conn->query($project_query);
if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        $projects[$row['id']] = $row['name'];
    }
}

// Get users for assignment dropdown
$users = [];
$user_query = "SELECT id, username FROM users ORDER BY username";
$user_result = $conn->query($user_query);
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[$row['id']] = $row['username'];
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $summary = trim($_POST['summary']);
    $status = $_POST['status'];
    $priority = $_POST['priority'];
    $type = $_POST['type'];
    $project_id = $_POST['project_id'];
    $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
    $team = trim($_POST['team']);
    $sprint = trim($_POST['sprint']);
    $story_points = !empty($_POST['story_points']) ? (int)$_POST['story_points'] : null;
    $labels = trim($_POST['labels']);
    
    // Validate required fields
    if (empty($title) || empty($description)) {
        $error = "Title and description are required.";
    } else {
        // Update the issue
        $stmt = $conn->prepare("UPDATE issues SET title = ?, description = ?, summary = ?, status = ?, priority = ?, type = ?, project_id = ?, assigned_to = ?, team = ?, sprint = ?, story_points = ?, labels = ?, updated_at = NOW() WHERE id = ?");
        
        $stmt->bind_param("ssssssiissisi", $title, $description, $summary, $status, $priority, $type, $project_id, $assigned_to, $team, $sprint, $story_points, $labels, $issue_id);
        
        if ($stmt->execute()) {
            $success = "Issue updated successfully!";
            
            // Refresh issue data
            $stmt = $conn->prepare("
                SELECT i.*, 
                       p.name as project_name,
                       u.username as assigned_username,
                       creator.username as creator_username
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
            $error = "Error updating issue: " . $conn->error;
        }
        $stmt->close();
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
    <title>Edit Issue #<?php echo $issue['id']; ?> - Dashen Bank Issue Tracker</title>
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
            padding: 40px;
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
        
        /* Form Styles */
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark-color);
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.15);
        }
        
        .btn-modern {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-primary-modern {
            background: var(--dashen-primary);
            background: linear-gradient(45deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: white;
            background: var(--dashen-secondary);
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
        
        .status-badge, .priority-badge {
            font-size: 0.9em;
            padding: 0.5em 0.75em;
        }
        
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
                padding: 30px 20px;
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
            
            <h1 class="welcome-title">Edit Issue #<?php echo $issue['id']; ?></h1>
            <p class="welcome-subtitle">Update the details of this issue to keep your team informed and on track.</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Issue Header -->
            <div class="mb-4 p-3 bg-light rounded">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="status-badge badge 
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
                                <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                            </span>
                            <span class="priority-badge badge 
                                <?php 
                                switch($issue['priority']) {
                                    case 'low': echo 'bg-secondary'; break;
                                    case 'medium': echo 'bg-primary'; break;
                                    case 'high': echo 'bg-warning'; break;
                                    case 'critical': echo 'bg-danger'; break;
                                    default: echo 'bg-primary';
                                }
                                ?>
                            ">
                                <?php echo ucfirst($issue['priority']); ?> Priority
                            </span>
                            <span class="badge bg-light text-dark">
                                <?php echo ucfirst($issue['type']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted">Created: <?php echo date('M j, Y g:i A', strtotime($issue['created_at'])); ?></div>
                        <div class="text-muted">by <?php echo htmlspecialchars($issue['creator_username']); ?></div>
                        <?php if ($issue['updated_at'] != $issue['created_at']): ?>
                            <div class="text-muted">Updated: <?php echo date('M j, Y g:i A', strtotime($issue['updated_at'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <form method="POST" action="edit_issue.php?id=<?php echo $issue_id; ?>">
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-4">
                            <label for="title" class="form-label">Issue Title *</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : htmlspecialchars($issue['title']); ?>" 
                                   required placeholder="Enter a clear and descriptive title">
                        </div>
                        
                        <div class="mb-4">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="5" 
                                      required placeholder="Provide detailed information about the issue"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : htmlspecialchars($issue['description']); ?></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label for="summary" class="form-label">Summary</label>
                            <textarea class="form-control" id="summary" name="summary" rows="3" 
                                      placeholder="Brief summary of the issue (optional)"><?php echo isset($_POST['summary']) ? htmlspecialchars($_POST['summary']) : htmlspecialchars($issue['summary']); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="mb-4">
                            <label for="project_id" class="form-label">Project</label>
                            <select class="form-select" id="project_id" name="project_id">
                                <option value="">Select a project</option>
                                <?php foreach ($projects as $id => $name): ?>
                                    <option value="<?php echo $id; ?>" <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $id) || $issue['project_id'] == $id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="open" <?php echo (isset($_POST['status']) && $_POST['status'] == 'open') || $issue['status'] == 'open' ? 'selected' : ''; ?>>Open</option>
                                <option value="in_progress" <?php echo (isset($_POST['status']) && $_POST['status'] == 'in_progress') || $issue['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="resolved" <?php echo (isset($_POST['status']) && $_POST['status'] == 'resolved') || $issue['status'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="closed" <?php echo (isset($_POST['status']) && $_POST['status'] == 'closed') || $issue['status'] == 'closed' ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority">
                                <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') || $issue['priority'] == 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') || $issue['priority'] == 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') || $issue['priority'] == 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="critical" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'critical') || $issue['priority'] == 'critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="bug" <?php echo (isset($_POST['type']) && $_POST['type'] == 'bug') || $issue['type'] == 'bug' ? 'selected' : ''; ?>>Bug</option>
                                <option value="feature" <?php echo (isset($_POST['type']) && $_POST['type'] == 'feature') || $issue['type'] == 'feature' ? 'selected' : ''; ?>>Feature</option>
                                <option value="task" <?php echo (isset($_POST['type']) && $_POST['type'] == 'task') || $issue['type'] == 'task' ? 'selected' : ''; ?>>Task</option>
                                <option value="improvement" <?php echo (isset($_POST['type']) && $_POST['type'] == 'improvement') || $issue['type'] == 'improvement' ? 'selected' : ''; ?>>Improvement</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="assigned_to" class="form-label">Assign To</label>
                            <select class="form-select" id="assigned_to" name="assigned_to">
                                <option value="">Unassigned</option>
                                <?php foreach ($users as $id => $username): ?>
                                    <option value="<?php echo $id; ?>" <?php echo (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $id) || $issue['assigned_to'] == $id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($username); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Additional Fields -->
                        <div class="mb-4">
                            <label for="team" class="form-label">Team</label>
                            <input type="text" class="form-control" id="team" name="team" 
                                   value="<?php echo isset($_POST['team']) ? htmlspecialchars($_POST['team']) : htmlspecialchars($issue['team']); ?>" 
                                   placeholder="e.g., Development, QA">
                        </div>
                        
                        <div class="mb-4">
                            <label for="sprint" class="form-label">Sprint</label>
                            <input type="text" class="form-control" id="sprint" name="sprint" 
                                   value="<?php echo isset($_POST['sprint']) ? htmlspecialchars($_POST['sprint']) : htmlspecialchars($issue['sprint']); ?>" 
                                   placeholder="e.g., Sprint 1, Sprint 2">
                        </div>
                        
                        <div class="mb-4">
                            <label for="story_points" class="form-label">Story Points</label>
                            <input type="number" class="form-control" id="story_points" name="story_points" 
                                   value="<?php echo isset($_POST['story_points']) ? htmlspecialchars($_POST['story_points']) : htmlspecialchars($issue['story_points']); ?>" 
                                   min="1" placeholder="Estimate complexity">
                        </div>
                        
                        <div class="mb-4">
                            <label for="labels" class="form-label">Labels</label>
                            <input type="text" class="form-control" id="labels" name="labels" 
                                   value="<?php echo isset($_POST['labels']) ? htmlspecialchars($_POST['labels']) : htmlspecialchars($issue['labels']); ?>" 
                                   placeholder="Comma-separated tags">
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <button type="submit" class="btn btn-primary-modern btn-modern">
                        <i class="bi bi-check-circle"></i> Update Issue
                    </button>
                    <a href="issue_detail.php?id=<?php echo $issue_id; ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirm before leaving if form has changes
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            let formChanged = false;
            
            // Track form changes
            const formInputs = form.querySelectorAll('input, select, textarea');
            formInputs.forEach(input => {
                const originalValue = input.value;
                
                input.addEventListener('change', function() {
                    formChanged = input.value !== originalValue;
                });
                
                input.addEventListener('input', function() {
                    formChanged = input.value !== originalValue;
                });
            });
            
            // Warn before leaving
            window.addEventListener('beforeunload', function(e) {
                if (formChanged) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
            
            // Reset form changed flag on submit
            form.addEventListener('submit', function() {
                formChanged = false;
            });
        });
    </script>
</body>
</html>