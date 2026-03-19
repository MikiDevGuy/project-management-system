<?php
session_start();
require_once 'db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check if user has super_admin role
$current_user_role = $_SESSION['system_role'] ?? '';
$current_username = $_SESSION['username'] ?? 'User';

// Only super_admin can access this page
if ($current_user_role !== 'super_admin') {
    header('Location: dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

// Initialize variables
$error = '';
$selected_user = null;
$user_systems = [];
$systems = [];

// Fetch available systems
$systems_result = $conn->query("SELECT system_id, system_name FROM systems ORDER BY system_name");
while ($row = $systems_result->fetch_assoc()) {
    $systems[$row['system_id']] = $row['system_name'];
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_id'])) {
    $selected_user_id = intval($_POST['user_id']);
    $selected_systems = isset($_POST['systems']) ? $_POST['systems'] : [];
    
    // Validate that selected_systems is an array
    if (!is_array($selected_systems)) {
        $selected_systems = [];
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // First, remove all existing system assignments for this user
        $delete_stmt = $conn->prepare("DELETE FROM user_systems WHERE user_id = ?");
        $delete_stmt->bind_param("i", $selected_user_id);
        $delete_stmt->execute();
        
        // Then insert new system assignments
        if (!empty($selected_systems)) {
            $insert_stmt = $conn->prepare("INSERT INTO user_systems (user_id, system_id) VALUES (?, ?)");
            foreach ($selected_systems as $system_id) {
                $system_id = intval($system_id);
                $insert_stmt->bind_param("ii", $selected_user_id, $system_id);
                $insert_stmt->execute();
            }
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "System access updated successfully!";
        
        // Redirect to clear POST data
        header('Location: module_assignment.php?user_id=' . $selected_user_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Failed to update system access: " . $e->getMessage();
    }
}

// Get user details if user_id is provided
if (isset($_GET['user_id'])) {
    $selected_user_id = intval($_GET['user_id']);
    
    // Fetch user details
    $user_stmt = $conn->prepare("SELECT id, username, email, system_role FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $selected_user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result->num_rows > 0) {
        $selected_user = $user_result->fetch_assoc();
        
        // Fetch user's current system access
        $system_stmt = $conn->prepare("SELECT system_id FROM user_systems WHERE user_id = ?");
        $system_stmt->bind_param("i", $selected_user_id);
        $system_stmt->execute();
        $system_result = $system_stmt->get_result();
        
        while ($row = $system_result->fetch_assoc()) {
            $user_systems[] = $row['system_id'];
        }
    }
}

// Fetch all existing users for the sidebar user list
$users_result = $conn->query("SELECT id, username, email, system_role FROM users ORDER BY username");
$total_users = $users_result->num_rows;

// Reset pointer for later use
$users_result->data_seek(0);

// Fetch current user's profile data for the profile dropdown
$profile_stmt = $conn->prepare("SELECT id, username, email, system_role, created_at FROM users WHERE id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$profile_result = $profile_stmt->get_result();
$current_user_profile = $profile_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashen Bank - Module Assignment & Access Control</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
            --dashen-success: #2dce89;
            --dashen-warning: #fb6340;
            --dashen-danger: #f5365c;
            --dashen-info: #11cdef;
            --dashen-dark: #32325d;
            --dashen-light: #f8f9fe;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --border-radius: 20px;
            --box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
            --box-shadow-hover: 0 30px 60px rgba(39, 50, 116, 0.12);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8f9fe 0%, #eef2f9 100%);
            color: var(--dashen-dark);
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Static Header - Same as consolidated reports */
        .static-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: 80px;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, #1e275a 100%);
            color: white;
            padding: 0 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            transition: left 0.3s ease;
        }
        
        .static-header.sidebar-collapsed {
            left: var(--sidebar-collapsed-width);
        }
        
        .static-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .static-header h1 i {
            font-size: 2rem;
            color: var(--dashen-accent);
        }
        
        /* Profile Dropdown Styles */
        .profile-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .profile-trigger {
            background: rgba(255,255,255,0.15);
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 600;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .profile-trigger:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-accent), #ffb347);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--dashen-primary);
        }
        
        .profile-info {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        
        .profile-name {
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.2;
        }
        
        .profile-role {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 60px;
            right: 0;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            min-width: 280px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1000;
            border: 1px solid rgba(39, 50, 116, 0.1);
        }
        
        .dropdown-menu-custom.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-radius: 16px 16px 0 0;
        }
        
        .dropdown-user {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .dropdown-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
        }
        
        .dropdown-user-info h6 {
            margin: 0;
            font-weight: 600;
            color: var(--dashen-dark);
        }
        
        .dropdown-user-info small {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .dropdown-items {
            padding: 10px;
        }
        
        .dropdown-item-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: var(--dashen-dark);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .dropdown-item-custom:hover {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            transform: translateX(5px);
        }
        
        .dropdown-item-custom i {
            width: 20px;
            color: var(--dashen-primary);
        }
        
        .dropdown-divider {
            height: 1px;
            background: #e9ecef;
            margin: 8px 0;
        }
        
        .logout-item {
            color: var(--dashen-danger);
        }
        
        .logout-item i {
            color: var(--dashen-danger);
        }
        
        /* Main Content - Adjusted for static header */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: 80px;
            padding: 30px;
            min-height: calc(100vh - 80px);
            transition: margin-left 0.3s ease;
            width: calc(100% - var(--sidebar-width));
        }
        
        .main-content.sidebar-collapsed {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }
        
        @media (max-width: 992px) {
            .static-header {
                left: 0;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            .static-header.sidebar-collapsed,
            .main-content.sidebar-collapsed {
                left: 0;
                margin-left: 0;
            }
        }
        
        /* Custom Card Styles */
        .card-custom {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            background: white;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card-custom:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }
        
        .card-header-custom {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }
        
        .card-header-custom h3 {
            margin: 0;
            font-weight: 600;
        }
        
        /* User Card Styles */
        .user-card {
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: pointer;
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            background: white;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        
        .user-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-accent));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .user-card:hover {
            transform: translateY(-8px);
            border-color: var(--dashen-secondary);
            box-shadow: 0 15px 40px rgba(39, 50, 116, 0.15);
        }
        
        .user-card:hover::before {
            opacity: 1;
        }
        
        .user-card.active {
            border-color: var(--dashen-primary);
            background-color: rgba(39, 50, 116, 0.05);
            transform: translateY(-5px);
        }
        
        .user-card.active::before {
            opacity: 1;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
            box-shadow: 0 8px 20px rgba(39, 50, 116, 0.3);
            transition: all 0.3s ease;
        }
        
        .user-card:hover .user-avatar {
            transform: scale(1.1) rotate(5deg);
        }
        
        /* Role Badges */
        .role-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        
        .role-super_admin { 
            background-color: #ffebee; 
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        
        .role-admin { 
            background-color: #e3f2fd; 
            color: #1565c0;
            border: 1px solid #bbdefb;
        }
        
        .role-tester { 
            background-color: #e8f5e8; 
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        
        .role-test_viewer { 
            background-color: #fff3e0; 
            color: #ef6c00;
            border: 1px solid #ffe0b2;
        }
        
        .role-pm_manager { 
            background-color: #f3e5f5; 
            color: #7b1fa2;
            border: 1px solid #e1bee7;
        }
        
        .role-pm_employee { 
            background-color: #e0f2f1; 
            color: #00695c;
            border: 1px solid #b2dfdb;
        }
        
        /* System Item Styles */
        .system-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border: 2px solid #e3e6f0;
            border-radius: 12px;
            margin-bottom: 12px;
            transition: all 0.3s ease;
            background-color: white;
            position: relative;
            overflow: hidden;
        }
        
        .system-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--dashen-primary);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .system-item:hover {
            background-color: #f8faff;
            border-color: var(--dashen-secondary);
            transform: translateX(8px);
        }
        
        .system-item:hover::before {
            opacity: 1;
        }
        
        .system-item.checked {
            border-color: var(--dashen-success);
            background-color: rgba(40, 167, 69, 0.05);
        }
        
        .system-item.checked::before {
            background: var(--dashen-success);
            opacity: 1;
        }
        
        .system-item input[type="checkbox"] {
            margin-right: 15px;
            transform: scale(1.3);
            cursor: pointer;
            accent-color: var(--dashen-primary);
        }
        
        .system-item label {
            margin-bottom: 0;
            cursor: pointer;
            flex-grow: 1;
            font-weight: 500;
            color: #333;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dashen-primary);
            margin: 10px 0;
        }
        
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Progress Bar */
        .progress-container {
            background: #f0f2ff;
            border-radius: 10px;
            height: 12px;
            overflow: hidden;
            margin: 20px 0;
        }
        
        .progress-bar-custom {
            background: linear-gradient(90deg, var(--dashen-primary), var(--dashen-secondary));
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        /* Search Input */
        .search-container {
            position: relative;
            margin-bottom: 25px;
        }
        
        .search-input {
            padding: 12px 20px 12px 45px;
            border: 2px solid #e3e6f0;
            border-radius: 12px;
            width: 100%;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 1.2rem;
        }
        
        /* Filter Buttons */
        .filter-btn {
            background: white;
            border: 2px solid #e3e6f0;
            color: #6c757d;
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-btn.active {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border-color: transparent;
        }
        
        .filter-btn:hover {
            background: #f8f9ff;
            border-color: var(--dashen-primary);
        }
        
        /* Selected Count Badge */
        .selected-count {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(39, 50, 116, 0.2);
        }
        
        /* Back Link */
        .back-link {
            color: var(--dashen-primary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(39, 50, 116, 0.1);
            padding: 10px 20px;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        
        .back-link:hover {
            background: rgba(39, 50, 116, 0.2);
            transform: translateX(-5px);
            color: var(--dashen-primary);
        }
        
        /* Access Info Section */
        .access-info {
            background: linear-gradient(135deg, #f8faff 0%, #eef2ff 100%);
            border-left: 6px solid var(--dashen-primary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in-up {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 5rem;
            color: #e3e6f0;
            margin-bottom: 20px;
        }
        
        /* Badges */
        .badge-access {
            background: rgba(40, 167, 69, 0.1);
            color: var(--dashen-success);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .badge-no-access {
            background: rgba(220, 53, 69, 0.1);
            color: var(--dashen-danger);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .static-header h1 {
                font-size: 1.2rem;
            }
            .static-header h1 i {
                font-size: 1.5rem;
            }
            .profile-trigger {
                padding: 5px 10px;
            }
            .profile-name {
                display: none;
            }
            .profile-role {
                display: none;
            }
            .stats-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Static Header with Profile Dropdown -->
    <header class="static-header" id="staticHeader">
        <h1>
            <i class="fas fa-puzzle-piece"></i>
            Module Assignment & Access Control
        </h1>
        <div class="user-info">
            <!-- Profile Dropdown -->
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-trigger" onclick="toggleProfileDropdown()">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <div class="profile-info">
                        <span class="profile-name"><?= htmlspecialchars($username) ?></span>
                        <span class="profile-role"><?= ucfirst(str_replace('_', ' ', $current_user_role)) ?></span>
                    </div>
                    <i class="fas fa-chevron-down" style="font-size: 0.8rem; opacity: 0.8;"></i>
                </div>
                
                <!-- Dropdown Menu -->
                <div class="dropdown-menu-custom" id="profileDropdownMenu">
                    <div class="dropdown-header">
                        <div class="dropdown-user">
                            <div class="dropdown-avatar">
                                <?= strtoupper(substr($username, 0, 1)) ?>
                            </div>
                            <div class="dropdown-user-info">
                                <h6><?= htmlspecialchars($username) ?></h6>
                                <small><?= htmlspecialchars($current_user_profile['email'] ?? '') ?></small>
                                <div class="mt-2">
                                    <span class="role-badge <?= 'role-' . $current_user_role ?>">
                                        <?= ucwords(str_replace('_', ' ', $current_user_role)) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dropdown-items">
                        <a href="profile.php" class="dropdown-item-custom">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="settings.php" class="dropdown-item-custom">
                            <i class="fas fa-cog"></i>
                            <span>Account Settings</span>
                        </a>
                        <a href="activity_log.php" class="dropdown-item-custom">
                            <i class="fas fa-history"></i>
                            <span>Activity Log</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item-custom logout-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Back Link -->
        <a href="display_user.php" class="back-link fade-in-up">
            <i class="fas fa-arrow-left"></i>Back to User Management
        </a>

        <!-- Success Message -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4 fade-in-up" role="alert" style="border-radius: 12px; border: none; box-shadow: 0 5px 15px rgba(40, 167, 69, 0.2);">
                <div class="d-flex align-items-center">
                    <div class="bg-success rounded-circle p-2 me-3">
                        <i class="fas fa-check text-white"></i>
                    </div>
                    <div>
                        <h5 class="mb-1">Success!</h5>
                        <p class="mb-0"><?= htmlspecialchars($_SESSION['success_message']) ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4 fade-in-up" role="alert" style="border-radius: 12px; border: none; box-shadow: 0 5px 15px rgba(220, 53, 69, 0.2);">
                <div class="d-flex align-items-center">
                    <div class="bg-danger rounded-circle p-2 me-3">
                        <i class="fas fa-exclamation-triangle text-white"></i>
                    </div>
                    <div>
                        <h5 class="mb-1">Attention Required!</h5>
                        <p class="mb-0"><?= htmlspecialchars($error) ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="row mb-4 fade-in-up">
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-users fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number"><?= $total_users ?></div>
                    <div class="stats-label">Total Users</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-server fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number"><?= count($systems) ?></div>
                    <div class="stats-label">Available Systems</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-check-circle fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number">
                        <?php 
                        $total_assignments = $conn->query("SELECT COUNT(*) as count FROM user_systems")->fetch_assoc()['count'];
                        echo $total_assignments;
                        ?>
                    </div>
                    <div class="stats-label">Total Assignments</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Users List - Left Column -->
            <div class="col-lg-5 mb-4">
                <div class="card-custom h-100">
                    <div class="card-header-custom">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-0">
                                    <i class="fas fa-users me-2"></i>Select User
                                </h3>
                                <small class="opacity-75">Click on a user to manage their access</small>
                            </div>
                            <span class="selected-count">
                                <i class="fas fa-user-check"></i>
                                <?= $selected_user ? '1 Selected' : '0 Selected' ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Search Bar -->
                        <div class="search-container">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="userSearch" class="search-input" placeholder="Search users by name, email, or role...">
                        </div>
                        
                        <!-- Role Filters -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <button class="filter-btn active" data-filter="all">All Users</button>
                            <button class="filter-btn" data-filter="super_admin">Super Admin</button>
                            <button class="filter-btn" data-filter="admin">Admin</button>
                            <button class="filter-btn" data-filter="tester">Tester</button>
                            <button class="filter-btn" data-filter="test_viewer">Test Viewer</button>
                            <button class="filter-btn" data-filter="pm_manager">PM Manager</button>
                            <button class="filter-btn" data-filter="pm_employee">PM Employee</button>
                        </div>
                        
                        <!-- Users Grid -->
                        <div class="row g-3" id="usersGrid">
                            <?php 
                            while ($user = $users_result->fetch_assoc()): 
                                $user_system_count = $conn->query("SELECT COUNT(*) as count FROM user_systems WHERE user_id = " . $user['id'])->fetch_assoc()['count'];
                            ?>
                                <div class="col-md-6 col-lg-12 user-item" data-role="<?= $user['system_role'] ?>" data-name="<?= strtolower($user['username']) ?>" data-email="<?= strtolower($user['email']) ?>">
                                    <div class="user-card <?= (isset($_GET['user_id']) && $_GET['user_id'] == $user['id']) ? 'active' : '' ?>" onclick="window.location.href='?user_id=<?= $user['id'] ?>'">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="user-avatar">
                                                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                                        <h5 class="mb-0"><?= htmlspecialchars($user['username']) ?></h5>
                                                        <span class="badge <?= $user_system_count > 0 ? 'badge-access' : 'badge-no-access' ?>">
                                                            <?= $user_system_count ?> sys
                                                        </span>
                                                    </div>
                                                    <small class="text-muted d-block mb-2">
                                                        <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($user['email']) ?>
                                                    </small>
                                                    <span class="role-badge <?= 'role-' . $user['system_role'] ?>">
                                                        <i class="fas fa-user-tag me-1"></i><?= ucwords(str_replace('_', ' ', $user['system_role'])) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            
                            <?php if ($total_users == 0): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <h5 class="mb-3">No Users Found</h5>
                                    <p class="text-muted mb-4">Please add users first.</p>
                                    <a href="display_user.php" class="btn btn-primary" style="background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); border: none;">
                                        <i class="fas fa-plus me-2"></i>Add New User
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer text-center py-3" style="background: #f8faff; border-top: 1px solid #e3e6f0;">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Showing <?= $total_users ?> user<?= $total_users != 1 ? 's' : '' ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- System Access Management - Right Column -->
            <div class="col-lg-7">
                <?php if ($selected_user): 
                    $selected_user_system_count = count($user_systems);
                    $percentage = count($systems) > 0 ? round(($selected_user_system_count / count($systems)) * 100) : 0;
                ?>
                    <div class="card-custom fade-in-up">
                        <div class="card-header-custom">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3 class="mb-0 d-flex align-items-center">
                                        <div class="bg-white rounded-circle p-2 me-3">
                                            <i class="fas fa-user-cog" style="color: var(--dashen-primary);"></i>
                                        </div>
                                        <div>
                                            Access Control Panel
                                            <small class="d-block opacity-75">Managing permissions for <?= htmlspecialchars($selected_user['username']) ?></small>
                                        </div>
                                    </h3>
                                </div>
                                <span class="selected-count">
                                    <i class="fas fa-chart-pie me-2"></i>
                                    <?= $percentage ?>% Coverage
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- User Info Header -->
                            <div class="access-info mb-4">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="user-avatar me-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                                                <?= strtoupper(substr($selected_user['username'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h5 class="mb-0"><?= htmlspecialchars($selected_user['username']) ?></h5>
                                                <small class="text-muted">ID: <?= $selected_user['id'] ?></small>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2">
                                            <span class="role-badge <?= 'role-' . $selected_user['system_role'] ?>">
                                                <i class="fas fa-user-tag me-1"></i>
                                                <?= ucwords(str_replace('_', ' ', $selected_user['system_role'])) ?>
                                            </span>
                                            <span class="badge bg-light text-dark">
                                                <i class="fas fa-envelope me-1"></i>
                                                <?= htmlspecialchars($selected_user['email']) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="stats-card">
                                                    <div class="stats-number"><?= $selected_user_system_count ?></div>
                                                    <div class="stats-label">Assigned</div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="stats-card">
                                                    <div class="stats-number"><?= count($systems) - $selected_user_system_count ?></div>
                                                    <div class="stats-label">Available</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Progress Bar -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="fw-bold">Access Coverage</span>
                                    <span><?= $percentage ?>%</span>
                                </div>
                                <div class="progress-container">
                                    <div class="progress-bar-custom" style="width: <?= $percentage ?>%" id="accessProgress"></div>
                                </div>
                            </div>
                            
                            <form method="post" id="accessForm">
                                <input type="hidden" name="user_id" value="<?= $selected_user['id'] ?>">
                                
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <label class="form-label fw-bold d-flex align-items-center">
                                            <i class="fas fa-server me-2"></i>Available Systems
                                            <span class="badge bg-primary ms-2"><?= count($systems) ?></span>
                                        </label>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="selectAll" style="width: 3em; height: 1.5em; cursor: pointer;">
                                            <label class="form-check-label fw-medium" for="selectAll">
                                                Toggle All
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <?php if (empty($systems)): ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No systems available. Please add systems first.
                                        </div>
                                    <?php else: ?>
                                        <div class="search-container mb-3">
                                            <i class="fas fa-search search-icon"></i>
                                            <input type="text" id="systemSearch" class="search-input" placeholder="Filter systems by name...">
                                        </div>
                                        
                                        <div class="row" id="systemsContainer">
                                            <?php foreach ($systems as $system_id => $system_name): ?>
                                                <div class="col-lg-6 system-item-container">
                                                    <div class="system-item <?= in_array($system_id, $user_systems) ? 'checked' : '' ?>">
                                                        <input type="checkbox" 
                                                               class="system-checkbox"
                                                               id="system_<?= $system_id ?>" 
                                                               name="systems[]" 
                                                               value="<?= $system_id ?>"
                                                               <?= in_array($system_id, $user_systems) ? 'checked' : '' ?>>
                                                        <label for="system_<?= $system_id ?>" class="form-check-label">
                                                            <div class="fw-bold"><?= htmlspecialchars($system_name) ?></div>
                                                            <div class="text-muted small">
                                                                <i class="fas fa-hashtag me-1"></i>ID: <?= $system_id ?>
                                                                <?php if (in_array($system_id, $user_systems)): ?>
                                                                    <span class="badge bg-success ms-2">✓ Assigned</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary ms-2">Not Assigned</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </label>
                                                        <i class="fas fa-<?= in_array($system_id, $user_systems) ? 'lock-open text-success' : 'lock text-muted' ?>"></i>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-flex gap-3 mt-5">
                                    <button type="submit" class="btn btn-primary flex-grow-1 py-3" style="background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); border: none;">
                                        <i class="fas fa-save me-2"></i>Save & Update Permissions
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary py-3" onclick="window.location.href='?user_id=<?= $selected_user['id'] ?>'">
                                        <i class="fas fa-redo me-2"></i>Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div class="card-footer text-center py-3" style="background: #f8faff; border-top: 1px solid #e3e6f0;">
                            <small class="text-muted">
                                <i class="fas fa-lightbulb me-1"></i>
                                Changes take effect immediately. User may need to logout and login again.
                            </small>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-custom h-100">
                        <div class="card-body d-flex flex-column justify-content-center align-items-center py-5">
                            <div class="empty-state-icon">
                                <i class="fas fa-hand-pointer"></i>
                            </div>
                            <h3 class="mb-3" style="color: var(--dashen-primary);">Select a User</h3>
                            <p class="text-muted text-center mb-4" style="max-width: 400px;">
                                Choose a user from the left panel to view and manage their<br>
                                system access permissions and module assignments
                            </p>
                            <div class="d-flex flex-column align-items-center text-muted">
                                <i class="fas fa-arrow-left fa-2x mb-3" style="opacity: 0.5;"></i>
                                <span>Click on any user card to begin</span>
                            </div>
                        </div>
                        <div class="card-footer text-center py-3" style="background: #f8faff; border-top: 1px solid #e3e6f0;">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                System access can be assigned to multiple users simultaneously
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true
        });

        // Profile Dropdown Toggle
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdownMenu');
            dropdown.classList.toggle('show');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('profileDropdown');
            const dropdownMenu = document.getElementById('profileDropdownMenu');
            
            if (!dropdown.contains(event.target)) {
                dropdownMenu.classList.remove('show');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Handle sidebar toggle synchronization
            const sidebarContainer = document.getElementById('sidebarContainer');
            const staticHeader = document.getElementById('staticHeader');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarContainer && staticHeader && mainContent) {
                // Check initial state
                if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                    staticHeader.classList.add('sidebar-collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                }
                
                // Observe changes to sidebar container
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'class') {
                            if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                                staticHeader.classList.add('sidebar-collapsed');
                                mainContent.classList.add('sidebar-collapsed');
                            } else {
                                staticHeader.classList.remove('sidebar-collapsed');
                                mainContent.classList.remove('sidebar-collapsed');
                            }
                        }
                    });
                });
                
                observer.observe(sidebarContainer, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            }

            // Function to update selected count and progress
            function updateSelectedCount() {
                const selectedCount = document.querySelectorAll('.system-checkbox:checked').length;
                const totalCount = document.querySelectorAll('.system-checkbox').length;
                const percentage = totalCount > 0 ? Math.round((selectedCount / totalCount) * 100) : 0;
                
                // Update progress bar
                const progressBar = document.getElementById('accessProgress');
                if (progressBar) {
                    progressBar.style.width = `${percentage}%`;
                }
                
                // Update stats numbers
                const statsNumberAssigned = document.querySelector('.stats-number:first-child');
                const statsNumberAvailable = document.querySelector('.stats-number:last-child');
                if (statsNumberAssigned) {
                    statsNumberAssigned.textContent = selectedCount;
                }
                if (statsNumberAvailable) {
                    statsNumberAvailable.textContent = totalCount - selectedCount;
                }
                
                // Update system item styles
                document.querySelectorAll('.system-item').forEach(item => {
                    const checkbox = item.querySelector('.system-checkbox');
                    const lockIcon = item.querySelector('.fa-lock, .fa-lock-open');
                    const badge = item.querySelector('.badge');
                    
                    if (checkbox && checkbox.checked) {
                        item.classList.add('checked');
                        if (lockIcon) {
                            lockIcon.className = 'fas fa-lock-open text-success';
                        }
                        if (badge) {
                            badge.className = 'badge bg-success ms-2';
                            badge.textContent = '✓ Assigned';
                        }
                    } else {
                        item.classList.remove('checked');
                        if (lockIcon) {
                            lockIcon.className = 'fas fa-lock text-muted';
                        }
                        if (badge) {
                            badge.className = 'badge bg-secondary ms-2';
                            badge.textContent = 'Not Assigned';
                        }
                    }
                });
                
                // Update selected count badge
                const selectedCountBadge = document.querySelector('.selected-count');
                if (selectedCountBadge) {
                    selectedCountBadge.innerHTML = `<i class="fas fa-chart-pie me-2"></i>${percentage}% Coverage`;
                }
            }
            
            // Select All functionality
            const selectAll = document.getElementById('selectAll');
            const systemCheckboxes = document.querySelectorAll('.system-checkbox');
            
            if (selectAll && systemCheckboxes.length > 0) {
                selectAll.addEventListener('change', function() {
                    const isChecked = this.checked;
                    systemCheckboxes.forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                    updateSelectedCount();
                });
                
                // Update select all checkbox based on individual selections
                systemCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(systemCheckboxes).every(cb => cb.checked);
                        const anyChecked = Array.from(systemCheckboxes).some(cb => cb.checked);
                        
                        if (allChecked) {
                            selectAll.checked = true;
                            selectAll.indeterminate = false;
                        } else if (anyChecked) {
                            selectAll.indeterminate = true;
                        } else {
                            selectAll.checked = false;
                            selectAll.indeterminate = false;
                        }
                        
                        updateSelectedCount();
                    });
                });
                
                // Initialize select all state
                const allChecked = Array.from(systemCheckboxes).every(cb => cb.checked);
                const anyChecked = Array.from(systemCheckboxes).some(cb => cb.checked);
                
                if (allChecked) {
                    selectAll.checked = true;
                } else if (anyChecked) {
                    selectAll.indeterminate = true;
                }
                
                // Initialize selected count
                updateSelectedCount();
            }
            
            // User search functionality
            const userSearch = document.getElementById('userSearch');
            if (userSearch) {
                userSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const userItems = document.querySelectorAll('.user-item');
                    
                    userItems.forEach(item => {
                        const name = item.getAttribute('data-name');
                        const email = item.getAttribute('data-email');
                        const role = item.getAttribute('data-role');
                        
                        if (name.includes(searchTerm) || email.includes(searchTerm) || role.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
            
            // System search functionality
            const systemSearch = document.getElementById('systemSearch');
            if (systemSearch) {
                systemSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const systemItems = document.querySelectorAll('.system-item-container');
                    
                    systemItems.forEach(item => {
                        const systemName = item.textContent.toLowerCase();
                        if (systemName.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
            
            // Role filter functionality
            const filterButtons = document.querySelectorAll('.filter-btn');
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Remove active class from all buttons
                    filterButtons.forEach(btn => btn.classList.remove('active'));
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    const filter = this.getAttribute('data-filter');
                    const userItems = document.querySelectorAll('.user-item');
                    
                    userItems.forEach(item => {
                        const role = item.getAttribute('data-role');
                        
                        if (filter === 'all' || role === filter) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            });
            
            // Form validation for removing all access
            const accessForm = document.getElementById('accessForm');
            if (accessForm) {
                accessForm.addEventListener('submit', function(e) {
                    const selectedCount = Array.from(systemCheckboxes).filter(cb => cb.checked).length;
                    
                    if (selectedCount === 0) {
                        e.preventDefault();
                        if (confirm('⚠️ You are about to remove ALL system access from this user.\n\nThis means the user will not be able to access any systems.\n\nAre you sure you want to continue?')) {
                            this.submit();
                        }
                    }
                });
            }
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
                alerts.forEach(alert => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>