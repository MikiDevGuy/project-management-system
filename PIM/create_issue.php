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

// Get projects for dropdown
$projects = [];
$project_query = "SELECT id, name FROM projects";
$project_result = $conn->query($project_query);
if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        $projects[$row['id']] = $row['name'];
    }
}

// Get users for assignee dropdown
$users = [];
$user_query = "SELECT id, username FROM users";
$user_result = $conn->query($user_query);
if ($user_result) {
    while ($row = $user_result->fetch_assoc()) {
        $users[$row['id']] = $row['username'];
    }
}

// Process form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $summary = trim($_POST['summary']);
    $priority = $_POST['priority'];
    $type = $_POST['type'];
    // Handle the empty project_id value correctly
    $project_id = !empty($_POST['project_id']) ? $_POST['project_id'] : null;
    $assigned_to = !empty($_POST['assigned_to']) ? $_POST['assigned_to'] : null;
    $team = trim($_POST['team']);
    $sprint = trim($_POST['sprint']);
    $story_points = !empty($_POST['story_points']) ? (int)$_POST['story_points'] : null;
    $labels = trim($_POST['labels']);
    
    // Validate required fields, including project_id
    if (empty($title) || empty($description)) {
        $error = "Title and description are required.";
    } elseif (empty($project_id)) {
        $error = "A project must be selected.";
    } else {
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO issues (title, description, summary, status, priority, type, project_id, assigned_to, created_by, team, sprint, story_points, labels) 
                                 VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // 's' for string, 'i' for integer, 's' for string...
        $stmt->bind_param("sssssiisssis", $title, $description, $summary, $priority, $type, $project_id, $assigned_to, $user_id, $team, $sprint, $story_points, $labels);
        
        if ($stmt->execute()) {
            $success = "Issue created successfully!";
            // Clear form fields
            $_POST = [];
        } else {
            $error = "Error creating issue: " . $conn->error;
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
    <title>Create Issue - Dashen Bank Issue Tracker</title>
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
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --border-radius: 16px;
            --border-radius-sm: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4e8f0 100%);
            color: var(--dark-color);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Main Content Styles - Perfectly Aligned with Sidebar */
        .main-content {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 30px;
            min-height: 100vh;
            transition: var(--transition);
        }
        
        .main-content.expanded {
            margin-left: 80px;
            width: calc(100% - 80px);
        }
        
        /* Premium Header */
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
        }
        
        .header-brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-logo {
            height: 40px;
            width: auto;
        }
        
        .brand-text {
            font-weight: 700;
            color: var(--dashen-primary);
            font-size: 1.4rem;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
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
            padding: 10px 20px;
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .profile-btn {
            background: var(--light-color);
            color: var(--dark-color);
            border: 1px solid #e9ecef;
        }
        
        .profile-btn:hover {
            background: white;
            color: var(--dashen-primary);
        }
        
        .logout-btn {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border: none;
        }
        
        .logout-btn:hover {
            background: linear-gradient(135deg, var(--dashen-secondary), var(--dashen-primary));
            color: white;
        }
        
        /* Enhanced Dashboard Card */
        .dashboard-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-xl);
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
            transition: var(--transition);
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
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
            font-weight: 800;
            font-size: 3rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
        }
        
        .welcome-subtitle {
            color: #6c757d;
            font-weight: 400;
            margin-bottom: 2.5rem;
            font-size: 1.2rem;
            max-width: 600px;
            line-height: 1.6;
        }
        
        /* Enhanced Form Styles */
        .form-label {
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--dashen-primary);
            font-size: 0.95rem;
        }
        
        .required-field::after {
            content: " *";
            color: #e53e3e;
        }
        
        .form-control, .form-select {
            border-radius: var(--border-radius-sm);
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            transition: var(--transition);
            font-size: 0.95rem;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.15);
            background: white;
            transform: translateY(-2px);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }
        
        /* Enhanced Button Styles */
        .btn-modern {
            padding: 14px 28px;
            border-radius: var(--border-radius-sm);
            font-weight: 700;
            font-size: 1rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            box-shadow: var(--shadow-md);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary-modern {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--dashen-secondary), var(--dashen-primary));
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
            background: transparent;
            font-weight: 600;
        }
        
        .btn-outline-secondary:hover {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
            transform: translateY(-2px);
        }
        
        .user-role-badge {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
        }
        
        /* Enhanced Alert Styles */
        .alert {
            border-radius: var(--border-radius-sm);
            border: none;
            padding: 20px;
            font-weight: 500;
            box-shadow: var(--shadow-md);
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left: 4px solid var(--success-color);
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 4px solid var(--warning-color);
        }
        
        /* Form Section Styling */
        .form-section {
            background: rgba(248, 249, 250, 0.7);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .form-section-title {
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 20px;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-section-title i {
            color: var(--dashen-accent);
        }
        
        /* Character Counter */
        .char-counter {
            font-size: 0.8rem;
            color: #6c757d;
            text-align: right;
            margin-top: 5px;
        }
        
        .char-counter.warning {
            color: var(--warning-color);
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
            
            .main-content.expanded {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px 15px;
            }
            
            .main-content.expanded {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
            }
            
            .dashboard-card {
                padding: 25px 20px;
            }
            
            .welcome-title {
                font-size: 2.2rem;
            }
            
            .header-brand .brand-text {
                font-size: 1.1rem;
            }
            
            .user-info {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .form-section {
                padding: 20px 15px;
            }
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }
        
        .slide-up {
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Form Field Groups */
        .form-field-group {
            margin-bottom: 25px;
        }
        
        /* Help Text */
        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Premium Header with Dashen Logo -->
        <header class="dashboard-header fade-in">
            <div class="header-brand">
                <img src="../Images/DashenLogo1.png" alt="Dashen Bank" class="header-logo">
                <div class="brand-text">Issue Tracker Pro</div>
            </div>
            <div class="user-info">
                <p class="user-greeting">Welcome back, <span class="user-name"><?php echo $_SESSION['username']; ?></span></p>
                <a href="../profile.php" class="header-btn profile-btn">
                    <i class="bi bi-person-circle"></i> My Profile
                </a>
                <a href="../logout.php" class="header-btn logout-btn">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </header>
        
        <!-- Create Issue Form -->
        <div class="dashboard-card slide-up">
            <span class="user-role-badge">
                <i class="bi bi-shield-check me-2"></i><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access Level
            </span>
            
            <h1 class="welcome-title">Create New Issue</h1>
            <p class="welcome-subtitle">Report a new issue or task to be tracked and resolved by your team. Fill in the details below to get started.</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger fade-in">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success fade-in">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                    <div class="mt-2">
                        <a href="issues.php" class="btn btn-sm btn-outline-primary me-2">View All Issues</a>
                        <a href="create_issue.php" class="btn btn-sm btn-primary-modern">Create Another Issue</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="create_issue.php" id="issueForm">
                <div class="row">
                    <!-- Left Column - Main Content -->
                    <div class="col-lg-8">
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="bi bi-card-text"></i>Issue Details
                            </h3>
                            
                            <div class="form-field-group">
                                <label for="title" class="form-label required-field">Issue Title</label>
                                <input type="text" class="form-control" id="title" name="title" 
                                       value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" 
                                       required placeholder="Enter a clear and descriptive title for the issue">
                                <div class="form-text">Be specific and concise. This will help team members understand the issue quickly.</div>
                                <div class="char-counter" id="titleCounter">0/100</div>
                            </div>
                            
                            <div class="form-field-group">
                                <label for="description" class="form-label required-field">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="6" 
                                          required placeholder="Provide detailed information about the issue, including steps to reproduce if applicable"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                <div class="form-text">Include all relevant details, error messages, and context to help resolve the issue efficiently.</div>
                                <div class="char-counter" id="descCounter">0/2000</div>
                            </div>
                            
                            <div class="form-field-group">
                                <label for="summary" class="form-label">Executive Summary</label>
                                <textarea class="form-control" id="summary" name="summary" rows="3" 
                                          placeholder="Brief summary of the issue for quick overview (optional)"><?php echo isset($_POST['summary']) ? htmlspecialchars($_POST['summary']) : ''; ?></textarea>
                                <div class="form-text">A concise summary that appears in issue lists and reports.</div>
                                <div class="char-counter" id="summaryCounter">0/255</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column - Metadata -->
                    <div class="col-lg-4">
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="bi bi-gear"></i>Issue Properties
                            </h3>
                            
                            <div class="form-field-group">
                                <label for="project_id" class="form-label required-field">Project</label>
                                <select class="form-select" id="project_id" name="project_id" required>
                                    <option value="">Select a project</option>
                                    <?php foreach ($projects as $id => $name): ?>
                                        <option value="<?php echo $id; ?>" <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-sm-6">
                                    <div class="form-field-group">
                                        <label for="priority" class="form-label">Priority</label>
                                        <select class="form-select" id="priority" name="priority">
                                            <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>Medium</option>
                                            <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>Low</option>
                                            <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>High</option>
                                            <option value="critical" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'critical') ? 'selected' : ''; ?>>Critical</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="form-field-group">
                                        <label for="type" class="form-label">Type</label>
                                        <select class="form-select" id="type" name="type">
                                            <option value="bug" <?php echo (isset($_POST['type']) && $_POST['type'] == 'bug') ? 'selected' : ''; ?>>Bug</option>
                                            <option value="feature" <?php echo (isset($_POST['type']) && $_POST['type'] == 'feature') ? 'selected' : ''; ?>>Feature</option>
                                            <option value="task" <?php echo (isset($_POST['type']) && $_POST['type'] == 'task') ? 'selected' : ''; ?>>Task</option>
                                            <option value="improvement" <?php echo (isset($_POST['type']) && $_POST['type'] == 'improvement') ? 'selected' : ''; ?>>Improvement</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-field-group">
                                <label for="assigned_to" class="form-label">Assign To</label>
                                <select class="form-select" id="assigned_to" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $id => $username): ?>
                                        <option value="<?php echo $id; ?>" <?php echo (isset($_POST['assigned_to']) && $_POST['assigned_to'] == $id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($username); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="bi bi-diagram-3"></i>Team & Planning
                            </h3>
                            
                            <div class="form-field-group">
                                <label for="team" class="form-label">Team</label>
                                <input type="text" class="form-control" id="team" name="team" 
                                       value="<?php echo isset($_POST['team']) ? htmlspecialchars($_POST['team']) : ''; ?>" 
                                       placeholder="e.g., Development, QA, Design">
                            </div>
                            
                            <div class="form-field-group">
                                <label for="sprint" class="form-label">Sprint</label>
                                <input type="text" class="form-control" id="sprint" name="sprint" 
                                       value="<?php echo isset($_POST['sprint']) ? htmlspecialchars($_POST['sprint']) : ''; ?>" 
                                       placeholder="e.g., Sprint 1, Sprint 2">
                            </div>
                            
                            <div class="form-field-group">
                                <label for="story_points" class="form-label">Story Points</label>
                                <input type="number" class="form-control" id="story_points" name="story_points" 
                                       value="<?php echo isset($_POST['story_points']) ? htmlspecialchars($_POST['story_points']) : ''; ?>" 
                                       min="1" max="100" placeholder="Estimate complexity (1-100)">
                            </div>
                            
                            <div class="form-field-group">
                                <label for="labels" class="form-label">Labels</label>
                                <input type="text" class="form-control" id="labels" name="labels" 
                                       value="<?php echo isset($_POST['labels']) ? htmlspecialchars($_POST['labels']) : ''; ?>" 
                                       placeholder="Comma-separated tags (e.g., frontend, backend, urgent)">
                                <div class="form-text">Use labels to categorize and filter issues.</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Actions -->
                <div class="form-section mt-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <button type="submit" class="btn btn-primary-modern btn-modern">
                                <i class="bi bi-plus-circle me-2"></i> Create Issue
                            </button>
                            <a href="issues.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-x-circle me-2"></i> Cancel
                            </a>
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-info-circle me-1"></i>Fields marked with * are required
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Character counters
        document.addEventListener('DOMContentLoaded', function() {
            const titleInput = document.getElementById('title');
            const descInput = document.getElementById('description');
            const summaryInput = document.getElementById('summary');
            const titleCounter = document.getElementById('titleCounter');
            const descCounter = document.getElementById('descCounter');
            const summaryCounter = document.getElementById('summaryCounter');
            
            function updateCounter(input, counter, maxLength) {
                const length = input.value.length;
                counter.textContent = `${length}/${maxLength}`;
                if (length > maxLength * 0.9) {
                    counter.classList.add('warning');
                } else {
                    counter.classList.remove('warning');
                }
            }
            
            if (titleInput) {
                titleInput.addEventListener('input', () => updateCounter(titleInput, titleCounter, 100));
                updateCounter(titleInput, titleCounter, 100);
            }
            
            if (descInput) {
                descInput.addEventListener('input', () => updateCounter(descInput, descCounter, 2000));
                updateCounter(descInput, descCounter, 2000);
            }
            
            if (summaryInput) {
                summaryInput.addEventListener('input', () => updateCounter(summaryInput, summaryCounter, 255));
                updateCounter(summaryInput, summaryCounter, 255);
            }
            
            // Sidebar toggle functionality
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggleBtn && mainContent) {
                sidebarToggleBtn.addEventListener('click', function() {
                    mainContent.classList.toggle('expanded');
                });
            }

            // Auto-collapse on medium screens
            function checkScreenSize() {
                if (window.innerWidth <= 1200 && window.innerWidth > 992) {
                    mainContent.classList.add('expanded');
                } else if (window.innerWidth > 1200) {
                    mainContent.classList.remove('expanded');
                }
            }
            
            window.addEventListener('load', checkScreenSize);
            window.addEventListener('resize', checkScreenSize);
            
            // Form validation enhancement
            const form = document.getElementById('issueForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const title = document.getElementById('title').value.trim();
                    const description = document.getElementById('description').value.trim();
                    const project = document.getElementById('project_id').value;
                    
                    if (!title || !description || !project) {
                        e.preventDefault();
                        // Bootstrap will handle the required fields
                    }
                });
            }
        });
    </script>
</body>
</html>