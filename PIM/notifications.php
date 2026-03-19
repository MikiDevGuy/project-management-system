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

// Mark all activity logs as read if requested
if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Mark a specific activity log as read if requested
if (isset($_GET['mark_read']) && !empty($_GET['mark_read'])) {
    $log_id = intval($_GET['mark_read']);
    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $log_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Delete an activity log if requested
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $log_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM activity_logs WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $log_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Delete all read activity logs if requested
if (isset($_GET['delete_all_read'])) {
    $stmt = $conn->prepare("DELETE FROM activity_logs WHERE user_id = ? AND is_read = 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Get activity logs for the current user with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total activity logs count
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$total_logs = $result->fetch_assoc()['total'];
$stmt->close();

$total_pages = ceil($total_logs / $limit);

// Get activity logs for the current page
$activity_logs = [];
$stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $activity_logs[] = $row;
}
$stmt->close();

// Count unread activity logs
$unread_count = 0;
foreach ($activity_logs as $log) {
    if (!$log['is_read']) {
        $unread_count++;
    }
}

// Get unread activity logs count for sidebar
$sidebar_unread_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$sidebar_unread_count = $result->fetch_assoc()['count'];
$stmt->close();

// Include the sidebar
include 'sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Dashen Bank Issue Tracker</title>
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
        
        /* Enhanced Activity Log Styles */
        .log-unread { 
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-left: 4px solid var(--dashen-primary);
            position: relative;
        }
        .log-read { 
            background: rgba(255, 255, 255, 0.8);
        }
        .log-item { 
            padding: 25px; 
            border-bottom: 1px solid rgba(226, 232, 240, 0.8); 
            transition: var(--transition);
            border-radius: var(--border-radius-sm);
            margin-bottom: 10px;
            position: relative;
        }
        .log-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow-md);
        }
        .log-item:last-child { 
            border-bottom: none; 
            margin-bottom: 0;
        }
        .log-time { 
            font-size: 0.85rem; 
            color: #6c757d;
            font-weight: 500;
        }
        .log-action {
            font-weight: 700;
            color: var(--dashen-primary);
            font-size: 1rem;
        }
        .log-description {
            color: var(--dark-color);
            line-height: 1.5;
        }
        
        .unread-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 8px;
            height: 8px;
            background: var(--dashen-accent);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(0.95); opacity: 1; }
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
        
        .btn-outline-danger {
            border: 2px solid #dc3545;
            color: #dc3545;
            background: transparent;
            font-weight: 600;
        }
        
        .btn-outline-danger:hover {
            background-color: #dc3545;
            border-color: #dc3545;
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
        
        /* Enhanced Pagination Styles */
        .pagination {
            margin-top: 30px;
        }
        
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            border-color: var(--dashen-primary);
        }
        
        .pagination .page-link {
            color: var(--dashen-primary);
            border-radius: var(--border-radius-sm);
            margin: 0 3px;
            border: 1px solid #e9ecef;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .pagination .page-link:hover {
            background-color: #e9ecef;
            transform: translateY(-1px);
        }
        
        /* Action Buttons */
        .action-buttons .btn {
            border-radius: var(--border-radius-sm);
            padding: 8px 16px;
            font-weight: 600;
            transition: var(--transition);
        }
        
        /* Empty State */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        /* Stats Cards */
        .stats-card {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            
            .log-item {
                padding: 20px 15px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons .btn {
                width: 100%;
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
        
        <!-- Notifications Content -->
        <div class="dashboard-card slide-up">
            <span class="user-role-badge">
                <i class="bi bi-shield-check me-2"></i><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access Level
            </span>
            
            <h1 class="welcome-title">Activity Logs & Notifications</h1>
            <p class="welcome-subtitle">Stay updated with your recent system activities, notifications, and important updates.</p>
            
            <!-- Statistics Cards -->
            <div class="row mb-5">
                <div class="col-md-4 mb-4">
                    <div class="stats-card">
                        <div class="stats-number"><?php echo $total_logs; ?></div>
                        <div class="stats-label">Total Activities</div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, var(--warning-color), #fd7e5a);">
                        <div class="stats-number"><?php echo $sidebar_unread_count; ?></div>
                        <div class="stats-label">Unread Notifications</div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="stats-card" style="background: linear-gradient(135deg, var(--success-color), #3ddc97);">
                        <div class="stats-number"><?php echo $total_logs - $sidebar_unread_count; ?></div>
                        <div class="stats-label">Read Notifications</div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="mb-0">
                    <i class="bi bi-activity me-2"></i>Recent Activities
                </h3>
                <div class="action-buttons d-flex gap-2">
                    <?php if ($sidebar_unread_count > 0): ?>
                        <a href="?mark_all_read=1" class="btn btn-primary-modern btn-modern">
                            <i class="bi bi-check-all me-2"></i> Mark All as Read
                        </a>
                    <?php endif; ?>
                    <?php if ($total_logs > 0): ?>
                        <a href="?delete_all_read=1" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete all read activity logs?')">
                            <i class="bi bi-trash me-2"></i> Delete Read
                        </a>
                    <?php endif; ?>
                    <a href="notifications.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise me-2"></i> Refresh
                    </a>
                </div>
            </div>

            <!-- Activity Logs -->
            <div class="card border-0 shadow-lg">
                <div class="card-body p-0">
                    <?php if (empty($activity_logs)): ?>
                        <div class="text-center py-5 empty-state">
                            <i class="bi bi-bell-slash-fill text-muted" style="font-size: 4rem;"></i>
                            <h5 class="text-muted mt-3 mb-2">No activity logs yet</h5>
                            <p class="text-muted">Your activity history will appear here as you use the system.</p>
                            <a href="issues.php" class="btn btn-primary-modern mt-3">
                                <i class="bi bi-list-task me-2"></i> Browse Issues
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activity_logs as $log): ?>
                            <div class="log-item <?php echo $log['is_read'] ? 'log-read' : 'log-unread'; ?>">
                                <?php if (!$log['is_read']): ?>
                                    <div class="unread-badge"></div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1 me-3">
                                        <p class="mb-2">
                                            <span class="log-action"><?php echo htmlspecialchars($log['action']); ?>:</span>
                                            <span class="log-description"><?php echo htmlspecialchars($log['description']); ?></span>
                                        </p>
                                        <small class="log-time">
                                            <i class="bi bi-clock me-1"></i> 
                                            <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                            <?php if (!$log['is_read']): ?>
                                                <span class="badge bg-warning text-dark ms-2">New</span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <?php if (!$log['is_read']): ?>
                                            <a href="?mark_read=<?php echo $log['id']; ?>" class="btn btn-sm btn-outline-success" title="Mark as read">
                                                <i class="bi bi-check-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?delete=<?php echo $log['id']; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this activity log?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Enhanced Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Activity logs pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        
                        if ($end_page - $start_page < 4) {
                            $start_page = max(1, $end_page - 4);
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                
                <div class="text-center text-muted mt-2">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> • 
                    Showing <?php echo count($activity_logs); ?> of <?php echo $total_logs; ?> activities
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle functionality
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Auto-refresh activity logs every 60 seconds if there are unread notifications
            const unreadCount = <?php echo $sidebar_unread_count; ?>;
            if (unreadCount > 0) {
                setInterval(function() {
                    window.location.reload();
                }, 60000);
            }
            
            // Add smooth animations to log items
            const logItems = document.querySelectorAll('.log-item');
            logItems.forEach((item, index) => {
                item.style.animationDelay = `${index * 0.1}s`;
                item.classList.add('slide-up');
            });
        });
    </script>
</body>
</html>