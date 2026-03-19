<?php
// includes/header.php
session_start();
require_once __DIR__ . '../../../db.php';
require_once __DIR__ . '/auth_check.php';

// Get current user info
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Guest';
$user_role = $_SESSION['system_role'] ?? '';
$user_email = $_SESSION['email'] ?? '';

// Get notifications for current user
$notifications = [];
$unread_count = 0;
if ($user_id) {
    $notif_query = "SELECT * FROM notifications 
                   WHERE user_id = ? 
                   ORDER BY created_at DESC 
                   LIMIT 5";
    $stmt = mysqli_prepare($conn, $notif_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
        if (!$row['is_read']) $unread_count++;
    }
    mysqli_stmt_close($stmt);
}

// Define navigation items based on user role - REMOVED ALL SUBMENUS
$nav_items = [
    'dashboard' => [
        'name' => 'Dashboard',
        'icon' => 'fas fa-tachometer-alt',
        'url' => 'dashboard.php'
    ],
    'unified_dashboard' => [
        'name' => 'Unified Dashboard',
        'icon' => 'fas fa-chart-line',
        'url' => '../dashboard.php'
    ]
];

// Add modules based on user permissions - NO SUBMENUS
if (can_access_module('project_intake')) {

    $nav_items['new_intake'] = [
        'name' => 'New Intake',
        'icon' => 'fas fa-plus-circle',
        'url' => 'project_intake_form.php'
    ];

    $nav_items['project_intake'] = [
        'name' => 'Project Intake',
        'icon' => 'fas fa-file-alt',
        'url' => 'project_intake_list.php'
    ];
   
}

if (can_access_module('checkpoint')) {
    $nav_items['checkpoint'] = [
        'name' => 'Checkpoint Evaluations',
        'icon' => 'fas fa-clipboard-check',
        'url' => 'checkpoint_evaluations.php'
    ];
}

if (can_access_module('gate_review')) {
    $nav_items['gate_review'] = [
        'name' => 'Gate Reviews',
        'icon' => 'fas fa-door-open',
        'url' => 'gate_reviews.php'
    ];
}

if (can_access_module('reports')) {
    $nav_items['reports'] = [
        'name' => 'Reports',
        'icon' => 'fas fa-chart-bar',
        'url' => 'reports.php'
    ];
}

// Admin settings
if (has_role(['super_admin', 'pm_manager', 'admin'])) {
    $nav_items['settings'] = [
        'name' => 'Settings',
        'icon' => 'fas fa-cog',
        'url' => 'admin_settings.php'
    ];
}

// Add other systems as individual items
$nav_items['budget_management'] = [
    'name' => 'Budget Management',
    'icon' => 'fas fa-money-bill-wave',
    'url' => '../Budget/dashboard.php'
];

$nav_items['project_management'] = [
    'name' => 'Project Management',
    'icon' => 'fas fa-project-diagram',
    'url' => '../dashboard_project_manager.php'
];

$nav_items['test_management'] = [
    'name' => 'Test Management',
    'icon' => 'fas fa-vial',
    'url' => '../dashboard_testcase.php'
];

$nav_items['change_management'] = [
    'name' => 'Change Management',
    'icon' => 'fas fa-exchange-alt',
    'url' => '../change_management_system/dashboard.php'
];

$nav_items['risk_management'] = [
    'name' => 'Risk Management',
    'icon' => 'fas fa-exclamation-triangle',
    'url' => '../Risk/risks.php'
];

$nav_items['issue_management'] = [
    'name' => 'Issue Management',
    'icon' => 'fas fa-bug',
    'url' => '../PIM/index.php'
];

$nav_items['event_management'] = [
    'name' => 'Event Management',
    'icon' => 'fas fa-calendar-day',
    'url' => '../project-event-management/dashboard.php'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'BSPMD - Dashen Bank'; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    
    <!-- ApexCharts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --sidebar-bg: linear-gradient(180deg, #1a1f3a 0%, #273274 100%);
            --sidebar-accent: #3c4c9e;
            --sidebar-hover: rgba(255, 255, 255, 0.15);
            --sidebar-active: linear-gradient(90deg, rgba(60, 76, 158, 0.3) 0%, rgba(39, 50, 116, 0.1) 100%);
            --sidebar-text: rgba(255, 255, 255, 0.85);
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 70px;
            
            --dashen-primary: #273274;
            --dashen-secondary: #1a245a;
            --dashen-accent: #3c4c9e;
            --dashen-light: #f0f2ff;
            --dashen-success: #28a745;
            --dashen-warning: #ffc107;
            --dashen-danger: #dc3545;
            --dashen-info: #17a2b8;
            
            --transition-speed: 0.3s;
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            color: #333;
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        /* Main Container */
        .app-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            transition: margin-left var(--transition-speed);
        }
        
        /* ==================== ENHANCED SIDEBAR ==================== */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            transition: all var(--transition-speed) cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            box-shadow: 8px 0 25px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }
        
        /* Sidebar Header */
        .sidebar-header {
            padding: 25px 20px;
            background: rgba(26, 31, 58, 0.8);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            backdrop-filter: blur(5px);
        }
        
        .sidebar-logo {
            transition: all var(--transition-speed);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar.collapsed .sidebar-logo {
            padding: 0;
        }
        
        .sidebar-logo img {
            max-width: 160px;
            height: auto;
            transition: all var(--transition-speed);
            filter: brightness(1.1);
        }
        
        .sidebar.collapsed .sidebar-logo img {
            max-width: 40px;
        }
        
        .sidebar-title {
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 10px;
            opacity: 1;
            transition: opacity var(--transition-speed);
            white-space: nowrap;
            overflow: hidden;
            background: linear-gradient(45deg, #fff, rgba(255, 255, 255, 0.8));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .sidebar.collapsed .sidebar-title {
            opacity: 0;
            width: 0;
        }
        
        /* Enhanced Toggle Button */
        .sidebar-toggle {
            position: absolute;
            top: 50%;
            right: -15px;
            transform: translateY(-50%);
            background: var(--dashen-primary);
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            transition: all var(--transition-speed);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        
        .sidebar-toggle:hover {
            background: var(--dashen-accent);
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }
        
        .sidebar.collapsed .sidebar-toggle i {
            transform: rotate(180deg);
        }
        
        /* Enhanced Sidebar Menu */
        .sidebar-menu {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 25px 0;
            scrollbar-width: thin;
            scrollbar-color: var(--sidebar-accent) rgba(0, 0, 0, 0.2);
        }
        
        .sidebar-menu::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-menu::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }
        
        .sidebar-menu::-webkit-scrollbar-thumb {
            background: var(--sidebar-accent);
            border-radius: 10px;
        }
        
        /* Navigation Items - No Dropdowns */
        .nav-item {
            position: relative;
            margin: 5px 15px;
        }
        
        .nav-link {
            color: var(--sidebar-text);
            padding: 16px 20px;
            border-radius: var(--border-radius);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            text-decoration: none;
            white-space: nowrap;
            position: relative;
            overflow: hidden;
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            backdrop-filter: blur(5px);
        }
        
        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(180deg, #4a9eff, #0066ff);
            border-radius: 0 3px 3px 0;
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }
        
        .nav-link:hover {
            background: var(--sidebar-hover);
            color: white;
            padding-left: 25px;
            transform: translateX(5px);
        }
        
        .nav-link:hover::before {
            transform: scaleY(1);
        }
        
        .nav-link.active {
            background: var(--sidebar-active);
            color: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .nav-link.active::before {
            transform: scaleY(1);
        }
        
        .nav-icon {
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all var(--transition-speed);
            flex-shrink: 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 5px;
        }
        
        .nav-link:hover .nav-icon {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }
        
        .nav-link.active .nav-icon {
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .nav-text {
            margin-left: 15px;
            opacity: 1;
            transition: all var(--transition-speed);
            font-weight: 500;
            font-size: 0.95rem;
            letter-spacing: 0.3px;
        }
        
        .sidebar.collapsed .nav-text {
            opacity: 0;
            width: 0;
            margin-left: 0;
        }
        
        .nav-badge {
            margin-left: auto;
            background: linear-gradient(45deg, #ff416c, #ff4b2b);
            color: white;
            font-size: 0.7rem;
            padding: 4px 9px;
            border-radius: 20px;
            animation: pulse 2s infinite;
            opacity: 1;
            transition: opacity var(--transition-speed);
            box-shadow: 0 2px 8px rgba(255, 65, 108, 0.3);
        }
        
        .sidebar.collapsed .nav-badge {
            opacity: 0;
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 0.6rem;
            padding: 2px 6px;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 65, 108, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 8px rgba(255, 65, 108, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 65, 108, 0); }
        }
        
        /* Divider */
        .nav-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            margin: 20px 25px;
            transition: margin var(--transition-speed);
        }
        
        .sidebar.collapsed .nav-divider {
            margin: 20px 15px;
        }
        
        /* Section Headers for Collapsed State */
        .section-header {
            padding: 10px 20px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 15px;
            opacity: 1;
            transition: opacity var(--transition-speed);
            white-space: nowrap;
        }
        
        .sidebar.collapsed .section-header {
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
        }
        
        /* Logout Button Styling */
        .logout-item {
            margin-top: auto;
            margin-bottom: 20px;
        }
        
        .logout-link {
            background: rgba(220, 53, 69, 0.1);
            color: rgba(255, 255, 255, 0.9);
        }
        
        .logout-link:hover {
            background: rgba(220, 53, 69, 0.2);
        }
        
        /* Enhanced Tooltips for collapsed sidebar */
        .sidebar.collapsed .nav-link::after {
            content: attr(data-title);
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(26, 31, 58, 0.95);
            color: white;
            padding: 10px 15px;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            min-width: 180px;
        }
        
        .sidebar.collapsed .nav-link:hover::after {
            opacity: 1;
            margin-left: 15px;
            transform: translateY(-50%) scale(1.05);
        }
        
        /* ==================== MAIN CONTENT ==================== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-speed);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, #f5f7ff 0%, #f0f2ff 100%);
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        /* Enhanced Top Navigation */
        .top-nav {
            background: white;
            padding: 0 30px;
            height: 80px;
            border-bottom: 1px solid rgba(39, 50, 116, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .top-nav-left {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        .mobile-toggle {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-speed);
            display: none;
        }
        
        .mobile-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(39, 50, 116, 0.3);
        }
        
        .page-header h1 {
            color: var(--dashen-primary);
            margin: 0;
            font-size: 1.9rem;
            font-weight: 700;
            background: linear-gradient(45deg, var(--dashen-primary), var(--dashen-accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-header p {
            color: #666;
            margin: 8px 0 0;
            font-size: 0.95rem;
        }
        
        /* Enhanced Top Navigation Right */
        .top-nav-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }
        
        /* Enhanced Notifications */
        .notification-wrapper {
            position: relative;
        }
        
        .notification-btn {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            border: none;
            color: white;
            font-size: 1.1rem;
            cursor: pointer;
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-speed);
            position: relative;
        }
        
        .notification-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(39, 50, 116, 0.3);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(45deg, #ff416c, #ff4b2b);
            color: white;
            font-size: 0.75rem;
            min-width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 3px 10px rgba(255, 65, 108, 0.3);
        }
        
        /* Enhanced User Profile */
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 15px;
            transition: all var(--transition-speed);
            background: rgba(39, 50, 116, 0.05);
            border: 1px solid rgba(39, 50, 116, 0.1);
        }
        
        .user-profile:hover {
            background: rgba(39, 50, 116, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            box-shadow: 0 4px 15px rgba(39, 50, 116, 0.3);
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 700;
            color: var(--dashen-primary);
            font-size: 0.95rem;
        }
        
        .user-role {
            font-size: 0.85rem;
            color: #666;
            background: rgba(39, 50, 116, 0.1);
            padding: 2px 8px;
            border-radius: 10px;
            display: inline-block;
        }
        
        /* Enhanced Content Area */
        .content-wrapper {
            flex: 1;
            padding: 35px;
            background: transparent;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
                z-index: 1002;
                box-shadow: 15px 0 40px rgba(0, 0, 0, 0.3);
            }
            
            .sidebar.show {
                margin-left: 0;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .mobile-toggle {
                display: flex;
            }
            
            .top-nav {
                padding: 0 20px;
            }
            
            .content-wrapper {
                padding: 25px;
            }
            
            .user-info {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar-header {
                padding: 20px 15px;
            }
            
            .sidebar-logo img {
                max-width: 120px;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .content-wrapper {
                padding: 20px;
            }
            
            .top-nav {
                height: 70px;
                padding: 0 15px;
            }
            
            .nav-link {
                padding: 14px 16px;
            }
        }
        
        /* Enhanced Dark overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1001;
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @media (max-width: 992px) {
            .sidebar-overlay.show {
                display: block;
            }
        }
        
        /* Loading animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(39, 50, 116, 0.3);
            border-radius: 50%;
            border-top-color: var(--dashen-primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Additional Enhancements */
        .nav-link {
            position: relative;
            overflow: hidden;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .nav-link:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            20% {
                transform: scale(25, 25);
                opacity: 0.3;
            }
            100% {
                opacity: 0;
                transform: scale(40, 40);
            }
        }
    </style>
    
    <!-- Page-specific CSS -->
    <?php if (isset($page_css)): ?>
    <style><?php echo $page_css; ?></style>
    <?php endif; ?>
</head>
<body>
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Main App Container -->
    <div class="app-container">
        <!-- Enhanced Sidebar -->
        <aside class="sidebar" id="sidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <!-- Dashen Bank Logo -->
                <div class="sidebar-logo">
                    <img src="../Images/DashenLogo12.png" alt="Dashen Bank Logo" class="img-fluid">
                </div>
                <h3 class="sidebar-title">BSPMD</h3>
                
                <!-- Enhanced Collapse Toggle -->
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            
            <!-- Enhanced Sidebar Menu - No Dropdowns -->
            <div class="sidebar-menu">
                <nav>
                    <?php 
                    $current_page = basename($_SERVER['PHP_SELF']);
                    
                    // Main Navigation Section
                    echo '<div class="section-header">Main Navigation</div>';
                    
                    foreach ($nav_items as $key => $item): 
                        $is_active = ($item['url'] == $current_page);
                        
                        // Add section headers at logical points
                        if ($key == 'reports' || $key == 'settings') {
                            echo '<div class="nav-divider"></div>';
                            echo '<div class="section-header">Management Tools</div>';
                        }
                        
                        if ($key == 'budget_management') {
                            echo '<div class="nav-divider"></div>';
                            echo '<div class="section-header">Other Systems</div>';
                        }
                    ?>
                    
                    <!-- Single Level Menu Item - No Dropdown -->
                    <div class="nav-item">
                        <a class="nav-link <?php echo $is_active ? 'active' : ''; ?>" 
                           href="<?php echo $item['url']; ?>"
                           data-title="<?php echo $item['name']; ?>">
                            <div class="nav-icon">
                                <i class="<?php echo $item['icon']; ?>"></i>
                            </div>
                            <span class="nav-text"><?php echo $item['name']; ?></span>
                            <?php if ($key == 'dashboard' && $unread_count > 0): ?>
                            <span class="nav-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <?php endforeach; ?>
                    
                    <!-- Logout -->
                    <div class="nav-divider"></div>
                    <div class="nav-item logout-item">
                        <a class="nav-link logout-link" href="../logout.php" data-title="Logout">
                            <div class="nav-icon">
                                <i class="fas fa-sign-out-alt"></i>
                            </div>
                            <span class="nav-text">Logout</span>
                        </a>
                    </div>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <!-- Enhanced Top Navigation -->
            <nav class="top-nav">
                <div class="top-nav-left">
                    <button class="mobile-toggle" id="mobileToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="page-header">
                        <h1><?php echo $page_title ?? 'Dashboard'; ?></h1>
                        <?php if (isset($page_subtitle)): ?>
                        <p><?php echo $page_subtitle; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="top-nav-right">
                    <!-- Enhanced Notifications -->
                    <div class="notification-wrapper">
                        <button class="notification-btn" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end" style="min-width: 350px; border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); border-radius: 15px; overflow: hidden;">
                            <div class="dropdown-header" style="background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); color: white; padding: 20px;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0" style="font-weight: 600;">Notifications</h6>
                                    <small><?php echo date('M d, Y'); ?></small>
                                </div>
                            </div>
                            <div class="notification-list" style="max-height: 350px; overflow-y: auto;">
                                <?php if (empty($notifications)): ?>
                                <div class="text-center py-5">
                                    <div class="mb-3" style="font-size: 3rem; color: #e9ecef;">
                                        <i class="fas fa-bell-slash"></i>
                                    </div>
                                    <p class="text-muted mb-0" style="font-size: 0.9rem;">No notifications</p>
                                    <small class="text-muted">You're all caught up!</small>
                                </div>
                                <?php else: ?>
                                <?php foreach ($notifications as $notification): ?>
                                <a href="#" class="dropdown-item border-bottom <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?> p-4" style="transition: all 0.3s;">
                                    <div class="d-flex">
                                        <div class="me-3">
                                            <div class="rounded-circle" style="width: 40px; height: 40px; background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-accent)); display: flex; align-items: center; justify-content: center; color: white;">
                                                <i class="fas fa-info"></i>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start mb-1">
                                                <h6 class="mb-0" style="font-size: 0.95rem; font-weight: 600;"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                                <?php if (!$notification['is_read']): ?>
                                                <span class="badge rounded-pill" style="background: linear-gradient(45deg, #ff416c, #ff4b2b);">New</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="mb-1 small text-muted" style="line-height: 1.4;"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            <small class="text-muted">
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo date('M d, H:i', strtotime($notification['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="dropdown-footer p-3 border-top" style="background: #f8f9fa;">
                                <div class="d-grid gap-2">
                                    <a href="notifications.php" class="btn btn-dashen btn-sm" style="border-radius: 10px; padding: 8px;">View All Notifications</a>
                                    <a href="#" class="btn btn-outline-dashen btn-sm" onclick="markAllAsRead()" style="border-radius: 10px; padding: 8px;">Mark All as Read</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Enhanced User Profile -->
                    <div class="dropdown">
                        <button class="user-profile" type="button" data-bs-toggle="dropdown">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($username, 0, 1)); ?>
                            </div>
                            <div class="user-info">
                                <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                                <span class="user-role"><?php echo htmlspecialchars($user_role); ?></span>
                            </div>
                            <i class="fas fa-chevron-down ms-2"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="border: none; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15); border-radius: 15px; overflow: hidden; min-width: 250px;">
                            <li class="dropdown-header" style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 20px;">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <div class="user-avatar" style="width: 50px; height: 50px; font-size: 1.3rem;">
                                            <?php echo strtoupper(substr($username, 0, 1)); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <h6 class="mb-1" style="font-weight: 700;"><?php echo htmlspecialchars($username); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($user_email); ?></small>
                                    </div>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="profile.php" style="padding: 12px 20px;">
                                    <i class="fas fa-user-cog me-3 text-primary"></i>
                                    Profile Settings
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="change_password.php" style="padding: 12px 20px;">
                                    <i class="fas fa-key me-3 text-warning"></i>
                                    Change Password
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="help.php" style="padding: 12px 20px;">
                                    <i class="fas fa-question-circle me-3 text-info"></i>
                                    Help & Support
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="../logout.php" style="padding: 12px 20px;">
                                    <i class="fas fa-sign-out-alt me-3"></i>
                                    Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>

            <!-- Content Wrapper -->
            <div class="content-wrapper">