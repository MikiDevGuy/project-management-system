<?php
// notifications.php - Comprehensive Notification Center
// Version 1.0 - Full notification management system
// Works with existing notification system in risks.php
// Last Updated: 2026-02-13

session_start();
require_once '../db.php';

// =============================================
// AUTHENTICATION CHECK
// =============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get current user details
$user_sql = "SELECT id, username, email, system_role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $current_user['system_role'] ?? '';
$username = $current_user['username'] ?? 'User';

// =============================================
// HELPER FUNCTIONS
// =============================================
function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;
    
    $string = [
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function get_notification_icon($type) {
    switch ($type) {
        case 'success': return 'bi-check-circle-fill';
        case 'danger': return 'bi-exclamation-triangle-fill';
        case 'warning': return 'bi-exclamation-circle-fill';
        case 'primary': return 'bi-info-circle-fill';
        default: return 'bi-bell-fill';
    }
}

function get_notification_color($type) {
    switch ($type) {
        case 'success': return 'linear-gradient(145deg, #28a745, #218838)';
        case 'danger': return 'linear-gradient(145deg, #dc3545, #c82333)';
        case 'warning': return 'linear-gradient(145deg, #ffc107, #e0a800)';
        case 'primary': return 'linear-gradient(145deg, #273274, #1a1f4a)';
        default: return 'linear-gradient(145deg, #6c757d, #5a6268)';
    }
}

function get_related_link($notification) {
    if (!$notification['related_id']) return '#';
    
    switch ($notification['related_module']) {
        case 'risk':
            return "risks.php?id=" . $notification['related_id'];
        case 'risk_mitigation':
            return "risks.php?id=" . $notification['related_id'] . "#mitigations";
        case 'project':
            return "../projects/view.php?id=" . $notification['related_id'];
        case 'change_request':
            return "../change_management/view.php?id=" . $notification['related_id'];
        default:
            return '#';
    }
}

// =============================================
// HANDLE ACTIONS
// =============================================
$action = $_GET['action'] ?? '';
$notification_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'mark_read' && $notification_id) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $notification_id, $current_user_id);
    $stmt->execute();
    $stmt->close();
    
    // Redirect back to notifications or to the related link
    if (isset($_GET['redirect']) && $_GET['redirect'] === 'true') {
        // Get notification details for redirect
        $redirect_stmt = $conn->prepare("SELECT related_module, related_id FROM notifications WHERE id = ?");
        $redirect_stmt->bind_param('i', $notification_id);
        $redirect_stmt->execute();
        $notif = $redirect_stmt->get_result()->fetch_assoc();
        $redirect_stmt->close();
        
        if ($notif && $notif['related_id']) {
            $link = get_related_link(['related_module' => $notif['related_module'], 'related_id' => $notif['related_id']]);
            header("Location: $link");
            exit;
        }
    }
    
    header('Location: notifications.php');
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success'] = 'All notifications marked as read';
    header('Location: notifications.php');
    exit;
}

if ($action === 'delete' && $notification_id) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $notification_id, $current_user_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success'] = 'Notification deleted successfully';
    header('Location: notifications.php');
    exit;
}

if ($action === 'delete_all') {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success'] = 'All notifications cleared';
    header('Location: notifications.php');
    exit;
}

// =============================================
// GET FILTERS
// =============================================
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_read = isset($_GET['read']) ? $_GET['read'] : 'all';
$filter_module = isset($_GET['module']) ? $_GET['module'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause
$where_conditions = ["user_id = ?"];
$params = [$current_user_id];
$types = "i";

if ($filter_type !== 'all') {
    $where_conditions[] = "type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if ($filter_read !== 'all') {
    if ($filter_read === 'read') {
        $where_conditions[] = "is_read = 1";
    } else {
        $where_conditions[] = "is_read = 0";
    }
}

if ($filter_module !== 'all') {
    $where_conditions[] = "related_module = ?";
    $params[] = $filter_module;
    $types .= "s";
}

if (!empty($search)) {
    $search_term = "%$search%";
    $where_conditions[] = "(title LIKE ? OR message LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where_conditions);

// =============================================
// GET PAGINATION
// =============================================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total notifications
$count_sql = "SELECT COUNT(*) as total FROM notifications $where_sql";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_notifications = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_notifications / $limit);
$count_stmt->close();

// Get notifications
$sql = "SELECT n.*, 
               u.username as related_username,
               p.name as project_name
        FROM notifications n
        LEFT JOIN users u ON n.related_user_id = u.id
        LEFT JOIN projects p ON n.related_id = p.id AND n.related_module = 'project'
        $where_sql
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$types_with_pagination = $types . "ii";
$params_with_pagination = array_merge($params, [$limit, $offset]);
$stmt->bind_param($types_with_pagination, ...$params_with_pagination);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN type = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN type = 'danger' THEN 1 ELSE 0 END) as danger,
                SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) as warning,
                SUM(CASE WHEN type = 'info' THEN 1 ELSE 0 END) as info,
                SUM(CASE WHEN related_module = 'risk' THEN 1 ELSE 0 END) as risk,
                SUM(CASE WHEN related_module = 'risk_mitigation' THEN 1 ELSE 0 END) as mitigation,
                SUM(CASE WHEN related_module = 'project' THEN 1 ELSE 0 END) as project,
                SUM(CASE WHEN related_module = 'change_request' THEN 1 ELSE 0 END) as change_request
              FROM notifications 
              WHERE user_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param('i', $current_user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Get unique modules for filter dropdown
$modules_sql = "SELECT DISTINCT related_module FROM notifications WHERE user_id = ? AND related_module IS NOT NULL ORDER BY related_module";
$modules_stmt = $conn->prepare($modules_sql);
$modules_stmt->bind_param('i', $current_user_id);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center - Dashen Bank</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-primary-light: #3a47a3;
            --dashen-primary-dark: #1a1f4a;
            --dashen-secondary: #f8a01c;
            --dashen-secondary-light: #ffb44a;
            --dashen-secondary-dark: #d47e0a;
            --dashen-success: #28a745;
            --dashen-danger: #dc3545;
            --dashen-warning: #ffc107;
            --dashen-info: #17a2b8;
            --sidebar-width: 280px;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(145deg, #f4f7fc 0%, #eef2f8 100%);
            color: #343a40;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        /* Cards */
        .dashen-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.03), 0 8px 16px rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .dashen-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 30px 60px rgba(39, 50, 116, 0.12);
        }
        
        /* Stat Cards */
        .stat-card {
            padding: 1.5rem;
            border-radius: 20px;
            background: white;
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
            border: 1px solid rgba(39, 50, 116, 0.05);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            border-color: var(--dashen-primary);
            transform: translateY(-4px);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
        }
        
        /* Notification Items */
        .notification-item {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            position: relative;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: rgba(39, 50, 116, 0.02);
            transform: translateX(6px);
        }
        
        .notification-item.unread {
            background: rgba(39, 50, 116, 0.05);
            border-left: 4px solid var(--dashen-primary);
        }
        
        .notification-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .notification-badge {
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        .badge-danger { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
        .badge-warning { background: rgba(255, 193, 7, 0.1); color: #856404; }
        .badge-info { background: rgba(23, 162, 184, 0.1); color: #17a2b8; }
        .badge-primary { background: rgba(39, 50, 116, 0.1); color: var(--dashen-primary); }
        
        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.02);
        }
        
        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }
        
        /* Action Buttons */
        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: none;
            background: transparent;
        }
        
        .action-btn:hover {
            background: rgba(39, 50, 116, 0.1);
            transform: scale(1.1);
        }
        
        .action-btn.delete:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        /* Empty State */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        
        .empty-icon {
            font-size: 5rem;
            color: #adb5bd;
            margin-bottom: 1.5rem;
        }
        
        /* Pagination */
        .pagination-custom .page-link {
            border: none;
            margin: 0 5px;
            border-radius: 12px !important;
            color: var(--dashen-primary);
            font-weight: 500;
            padding: 0.75rem 1.2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .pagination-custom .page-item.active .page-link {
            background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));
            color: white;
        }
        
        .pagination-custom .page-item.disabled .page-link {
            color: #6c757d;
            background: white;
        }
        
        /* Animations */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-item {
            animation: slideIn 0.5s ease forwards;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .notification-item {
                padding: 1rem;
            }
            
            .notification-icon {
                width: 48px;
                height: 48px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="navbar navbar-expand-lg bg-white shadow-sm px-4 py-3 sticky-top" style="backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important;">
            <div class="container-fluid p-0">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link d-lg-none me-3" type="button" onclick="toggleSidebar()">
                        <i class="bi bi-list fs-2" style="color: var(--dashen-primary);"></i>
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                                <i class="bi bi-bell-fill me-1"></i>Notification Center
                            </span>
                            <?php if ($stats['unread'] > 0): ?>
                                <span class="badge bg-danger px-3 py-2 rounded-pill">
                                    <?= $stats['unread'] ?> Unread
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            All Notifications
                        </h4>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-3">
                    <!-- Quick Actions -->
                    <?php if ($stats['unread'] > 0): ?>
                        <a href="?action=mark_all_read" class="btn dashen-btn-outline rounded-pill px-4">
                            <i class="bi bi-check2-all me-2"></i>Mark All Read
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($total_notifications > 0): ?>
                        <button class="btn btn-outline-danger rounded-pill px-4" onclick="clearAllNotifications()">
                            <i class="bi bi-trash3 me-2"></i>Clear All
                        </button>
                    <?php endif; ?>
                    
                    <!-- User Profile -->
                    <div class="d-none d-md-flex align-items-center gap-2">
                        <div class="bg-light rounded-pill px-4 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-dashen-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                     style="width: 36px; height: 36px;">
                                    <span class="fw-bold"><?= strtoupper(substr($username, 0, 1)) ?></span>
                                </div>
                                <div>
                                    <span class="fw-medium small"><?= e($username) ?></span>
                                    <span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary ms-2 px-3 py-1 rounded-pill small">
                                        <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <a href="risks.php" class="btn dashen-btn-outline rounded-pill px-4">
                        <i class="bi bi-arrow-left me-2"></i>Back to Risks
                    </a>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">
            <!-- Status Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown rounded-4 shadow-sm border-0" role="alert" style="background: linear-gradient(145deg, #d4edda, #c3e6cb); color: #155724;">
                    <div class="d-flex align-items-center">
                        <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="bi bi-check-lg fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <strong class="fw-bold">Success!</strong> <?= e($_SESSION['success']) ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="0">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small text-uppercase fw-semibold">Total</span>
                                <h2 class="stat-number mb-0" style="color: var(--dashen-primary);"><?= number_format($stats['total']) ?></h2>
                                <small class="text-muted">All notifications</small>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(145deg, var(--dashen-primary), var(--dashen-primary-dark));">
                                <i class="bi bi-bell"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small text-uppercase fw-semibold">Unread</span>
                                <h2 class="stat-number mb-0" style="color: #dc3545;"><?= number_format($stats['unread']) ?></h2>
                                <small class="text-muted">Require attention</small>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(145deg, #dc3545, #c82333);">
                                <i class="bi bi-envelope"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small text-uppercase fw-semibold">Risk Alerts</span>
                                <h2 class="stat-number mb-0" style="color: #fd7e14;"><?= number_format($stats['risk'] ?? 0) ?></h2>
                                <small class="text-muted">Risk related</small>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(145deg, #fd7e14, #e96b02);">
                                <i class="bi bi-shield"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small text-uppercase fw-semibold">Mitigations</span>
                                <h2 class="stat-number mb-0" style="color: #6f42c1;"><?= number_format($stats['mitigation'] ?? 0) ?></h2>
                                <small class="text-muted">Action updates</small>
                            </div>
                            <div class="stat-icon" style="background: linear-gradient(145deg, #6f42c1, #6610f2);">
                                <i class="bi bi-shield-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="dashen-card mb-4" data-aos="fade-up" data-aos-delay="400">
                <div class="card-body p-4">
                    <form method="get" action="notifications.php" class="row g-3">
                        <div class="col-lg-2 col-md-6">
                            <label class="filter-label">Type</label>
                            <select name="type" class="form-select form-select-lg rounded-pill">
                                <option value="all" <?= $filter_type == 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="success" <?= $filter_type == 'success' ? 'selected' : '' ?>>Success</option>
                                <option value="danger" <?= $filter_type == 'danger' ? 'selected' : '' ?>>Danger</option>
                                <option value="warning" <?= $filter_type == 'warning' ? 'selected' : '' ?>>Warning</option>
                                <option value="info" <?= $filter_type == 'info' ? 'selected' : '' ?>>Info</option>
                                <option value="primary" <?= $filter_type == 'primary' ? 'selected' : '' ?>>Primary</option>
                            </select>
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="filter-label">Status</label>
                            <select name="read" class="form-select form-select-lg rounded-pill">
                                <option value="all" <?= $filter_read == 'all' ? 'selected' : '' ?>>All</option>
                                <option value="unread" <?= $filter_read == 'unread' ? 'selected' : '' ?>>Unread</option>
                                <option value="read" <?= $filter_read == 'read' ? 'selected' : '' ?>>Read</option>
                            </select>
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="filter-label">Module</label>
                            <select name="module" class="form-select form-select-lg rounded-pill">
                                <option value="all" <?= $filter_module == 'all' ? 'selected' : '' ?>>All Modules</option>
                                <option value="risk" <?= $filter_module == 'risk' ? 'selected' : '' ?>>Risk</option>
                                <option value="risk_mitigation" <?= $filter_module == 'risk_mitigation' ? 'selected' : '' ?>>Mitigation</option>
                                <option value="project" <?= $filter_module == 'project' ? 'selected' : '' ?>>Project</option>
                                <option value="change_request" <?= $filter_module == 'change_request' ? 'selected' : '' ?>>Change Request</option>
                                <?php foreach ($modules as $m): ?>
                                    <?php if (!in_array($m['related_module'], ['risk', 'risk_mitigation', 'project', 'change_request'])): ?>
                                        <option value="<?= e($m['related_module']) ?>" <?= $filter_module == $m['related_module'] ? 'selected' : '' ?>>
                                            <?= ucwords(str_replace('_', ' ', $m['related_module'])) ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date_from" class="form-control form-control-lg rounded-pill" value="<?= e($date_from) ?>">
                        </div>
                        
                        <div class="col-lg-2 col-md-6">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="form-control form-control-lg rounded-pill" value="<?= e($date_to) ?>">
                        </div>
                        
                        <div class="col-lg-2 col-md-12 d-flex align-items-end gap-2">
                            <button type="submit" class="btn dashen-btn-primary rounded-pill px-4 py-3 flex-grow-1">
                                <i class="bi bi-funnel me-2"></i>Filter
                            </button>
                            <a href="notifications.php" class="btn btn-outline-secondary rounded-pill px-4 py-3" data-bs-toggle="tooltip" title="Clear Filters">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        </div>
                    </form>
                    
                    <!-- Search Bar -->
                    <form method="get" action="notifications.php" class="mt-4">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control form-control-lg rounded-pill-start" 
                                   placeholder="Search notifications by title or message..." value="<?= e($search) ?>">
                            <button class="btn dashen-btn-primary rounded-pill-end px-5" type="submit">
                                <i class="bi bi-search me-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Notifications List -->
            <div class="dashen-card" data-aos="fade-up" data-aos-delay="500">
                <div class="card-header bg-transparent border-0 p-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-1" style="color: var(--dashen-primary);">
                            <i class="bi bi-list-ul me-2"></i>Notifications
                        </h5>
                        <p class="text-muted small mb-0">Showing <?= count($notifications) ?> of <?= number_format($total_notifications) ?> notifications</p>
                    </div>
                    <?php if ($total_notifications > 0): ?>
                        <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                            Page <?= $page ?> of <?= $total_pages ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="notifications-list">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $index => $notif): 
                            $icon = get_notification_icon($notif['type']);
                            $color = get_notification_color($notif['type']);
                            $is_unread = !$notif['is_read'];
                            $time_ago = time_elapsed_string($notif['created_at']);
                            $related_link = get_related_link($notif);
                        ?>
                            <div class="notification-item <?= $is_unread ? 'unread' : '' ?> animate-item" 
                                 style="animation-delay: <?= $index * 0.05 ?>s;"
                                 onclick="window.location.href='?action=mark_read&id=<?= $notif['id'] ?>&redirect=true'">
                                <div class="d-flex gap-4">
                                    <!-- Icon -->
                                    <div class="notification-icon" style="background: <?= $color ?>;">
                                        <i class="bi <?= $icon ?>"></i>
                                    </div>
                                    
                                    <!-- Content -->
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="fw-bold mb-1" style="<?= $is_unread ? 'color: var(--dashen-primary);' : '' ?>">
                                                    <?= e($notif['title']) ?>
                                                </h6>
                                                <p class="mb-2 text-muted" style="white-space: pre-line;">
                                                    <?= e($notif['message']) ?>
                                                </p>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <?php if ($notif['type']): ?>
                                                    <span class="notification-badge badge-<?= $notif['type'] ?>">
                                                        <?= ucfirst($notif['type']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="notification-time">
                                                    <i class="bi bi-clock me-1"></i><?= $time_ago ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Metadata -->
                                        <div class="d-flex flex-wrap gap-3 mt-2">
                                            <?php if ($notif['related_module']): ?>
                                                <span class="badge bg-light text-dark rounded-pill px-3 py-1">
                                                    <i class="bi bi-folder me-1"></i>
                                                    <?= ucwords(str_replace('_', ' ', $notif['related_module'])) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($notif['project_name']): ?>
                                                <span class="badge bg-light text-dark rounded-pill px-3 py-1">
                                                    <i class="bi bi-building me-1"></i>
                                                    <?= e($notif['project_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($notif['related_username']): ?>
                                                <span class="badge bg-light text-dark rounded-pill px-3 py-1">
                                                    <i class="bi bi-person me-1"></i>
                                                    <?= e($notif['related_username']) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <span class="badge bg-light text-dark rounded-pill px-3 py-1">
                                                <i class="bi bi-calendar me-1"></i>
                                                <?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="d-flex gap-1" onclick="event.stopPropagation();">
                                        <?php if ($is_unread): ?>
                                            <a href="?action=mark_read&id=<?= $notif['id'] ?>" class="action-btn" data-bs-toggle="tooltip" title="Mark as Read">
                                                <i class="bi bi-check2"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="?action=delete&id=<?= $notif['id'] ?>" class="action-btn delete" 
                                           onclick="return confirmDelete(event, '<?= e(addslashes($notif['title'])) ?>')"
                                           data-bs-toggle="tooltip" title="Delete">
                                            <i class="bi bi-trash3"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="bi bi-bell-slash"></i>
                            </div>
                            <h5 class="fw-bold mb-2" style="color: var(--dashen-primary);">No Notifications Found</h5>
                            <p class="text-muted mb-4">No notifications match your current filters</p>
                            <a href="notifications.php" class="btn dashen-btn-primary rounded-pill px-5 py-3">
                                <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-transparent border-0 p-4">
                        <nav aria-label="Notifications pagination">
                            <ul class="pagination pagination-custom justify-content-center mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php 
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            once: true,
            offset: 20
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('expanded');
            mainContent.classList.toggle('expanded');
        }

        // Confirm delete
        function confirmDelete(event, title) {
            event.preventDefault();
            const url = event.currentTarget.href;
            
            Swal.fire({
                title: 'Delete Notification',
                html: `Are you sure you want to delete <strong>"${title}"</strong>?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
            
            return false;
        }

        // Clear all notifications
        function clearAllNotifications() {
            Swal.fire({
                title: 'Clear All Notifications',
                text: 'Are you sure you want to delete all notifications? This action cannot be undone!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Yes, clear all',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?action=delete_all';
                }
            });
        }

        // Mark all as read confirmation
        document.querySelector('a[href*="mark_all_read"]')?.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.href;
            
            Swal.fire({
                title: 'Mark All as Read',
                text: 'Are you sure you want to mark all notifications as read?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#273274',
                confirmButtonText: 'Yes, mark all',
                cancelButtonText: 'Cancel',
                background: 'white',
                borderRadius: '24px'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        });

        // Auto-refresh unread count in title
        <?php if ($stats['unread'] > 0): ?>
        document.title = '(<?= $stats['unread'] ?>) ' + document.title;
        <?php endif; ?>
    </script>
</body>
</html>
<?php
$conn->close();
?>