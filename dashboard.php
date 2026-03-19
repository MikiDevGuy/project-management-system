<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'] ?? 'viewer';
$username = $_SESSION['username'] ?? 'User';
$user_email = $_SESSION['email'] ?? '';

// Fetch user details from database
$stmt = $conn->prepare("SELECT id, username, email, system_role, is_active, profile_picture FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$user_data = $user_result->fetch_assoc();
$stmt->close();

// Set profile picture
$profile_picture = $user_data['profile_picture'] ?? null;
$profile_image = $profile_picture ? "uploads/profile/" . $profile_picture : null;

// Fetch user systems
$systems = [];
$sql = "SELECT s.system_id, s.system_name, s.system_url
        FROM user_systems us
        JOIN systems s ON us.system_id = s.system_id
        WHERE us.user_id = ?";
$stmt2 = $conn->prepare($sql);
if ($stmt2) {
    $stmt2->bind_param("i", $user_id);
    $stmt2->execute();
    $systems_result = $stmt2->get_result();
    $systems = $systems_result->fetch_all(MYSQLI_ASSOC);
    $_SESSION['systems'] = $systems;
    $stmt2->close();
}

// Handle color customization
if (!isset($_SESSION['custom_colors'])) {
    $_SESSION['custom_colors'] = [
        'primary' => '#1a237e', // Deep Indigo
        'secondary' => '#283593', // Indigo
        'accent' => '#fff',
        'success' => '#1a237e',
        'warning' => '#1a237e',
        'info' => '#1a237e'
    ];
}

// Handle Dark Mode
if (!isset($_SESSION['dark_mode'])) {
    $_SESSION['dark_mode'] = false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Handle Dark Mode Toggle
    if ($_POST['action'] == 'toggle_dark_mode') {
        $_SESSION['dark_mode'] = !$_SESSION['dark_mode'];
        echo json_encode(['success' => true, 'dark_mode' => $_SESSION['dark_mode']]);
        exit();
    }
    
    // Handle profile picture upload
    if ($_POST['action'] == 'upload_profile') {
        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
            exit();
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        $file_type = $_FILES['profile_image']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG and GIF files are allowed']);
            exit();
        }
        
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($_FILES['profile_image']['size'] > $max_size) {
            echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
            exit();
        }
        
        $upload_dir = 'uploads/profile/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $file_name = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
            // Delete old profile picture if exists
            if ($profile_picture && file_exists($upload_dir . $profile_picture)) {
                @unlink($upload_dir . $profile_picture);
            }
            
            // Update database
            $update_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $update_stmt->bind_param("si", $file_name, $user_id);
            
            if ($update_stmt->execute()) {
                $_SESSION['profile_picture'] = $file_name;
                echo json_encode(['success' => true, 'message' => 'Profile picture updated successfully', 'image_path' => $target_path]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database update failed']);
            }
            $update_stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        }
        exit();
    }
    
    // Handle profile picture removal
    if ($_POST['action'] == 'remove_profile') {
        $upload_dir = 'uploads/profile/';
        $response = ['success' => false, 'message' => ''];
        
        // Delete the file if it exists
        if ($profile_picture && file_exists($upload_dir . $profile_picture)) {
            @unlink($upload_dir . $profile_picture);
        }
        
        // Update database to set profile_picture to NULL
        $update_stmt = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?");
        $update_stmt->bind_param("i", $user_id);
        
        if ($update_stmt->execute()) {
            unset($_SESSION['profile_picture']);
            $response['success'] = true;
            $response['message'] = 'Profile picture removed successfully';
        } else {
            $response['message'] = 'Failed to update database';
        }
        $update_stmt->close();
        
        echo json_encode($response);
        exit();
    }
    
    // Handle password change
    if ($_POST['action'] == 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $response = ['success' => false, 'message' => ''];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $response['message'] = 'All fields are required';
            echo json_encode($response);
            exit();
        }
        
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!password_verify($current_password, $user['password'])) {
            $response['message'] = '❌ Your current password is incorrect. Please enter your correct password.';
            echo json_encode($response);
            exit();
        }
        
        // Check if new password is same as current password
        if (password_verify($new_password, $user['password'])) {
            $response['message'] = '⚠️ The new password cannot be the same as your current password. Please enter a different password.';
            echo json_encode($response);
            exit();
        }
        
        // Validate new password
        if (strlen($new_password) < 8) {
            $response['message'] = 'Password must be at least 8 characters long';
            echo json_encode($response);
            exit();
        }
        
        if (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/", $new_password)) {
            $response['message'] = 'Password must contain uppercase, lowercase and number';
            echo json_encode($response);
            exit();
        }
        
        if ($new_password !== $confirm_password) {
            $response['message'] = 'New passwords do not match';
            echo json_encode($response);
            exit();
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = '✅ Your password has been updated successfully.';
        } else {
            $response['message'] = 'Failed to change password';
        }
        $update_stmt->close();
        
        echo json_encode($response);
        exit();
    }
    
    // Handle color customization
    if ($_POST['action'] == 'update_colors') {
        $_SESSION['custom_colors'] = [
            'primary' => $_POST['primary_color'] ?? '#1a237e',
            'secondary' => $_POST['secondary_color'] ?? '#283593',
            'accent' => $_POST['accent_color'] ?? '#fff',
            'success' => $_POST['success_color'] ?? '#1a237e',
            'warning' => $_POST['warning_color'] ?? '#1a237e',
            'info' => $_POST['info_color'] ?? '#1a237e'
        ];
        echo json_encode(['success' => true, 'colors' => $_SESSION['custom_colors']]);
        exit();
    }
}

$colors = $_SESSION['custom_colors'];
$dark_mode = $_SESSION['dark_mode'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashen Bank - Enterprise Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --dashen-primary: <?php echo $colors['primary']; ?>;
            --dashen-secondary: <?php echo $colors['secondary']; ?>;
            --dashen-accent: <?php echo $colors['accent']; ?>;
            --dashen-success: <?php echo $colors['success']; ?>;
            --dashen-warning: <?php echo $colors['warning']; ?>;
            --dashen-info: <?php echo $colors['info']; ?>;
            --dashen-light: #f8f9fa;
            --dashen-white: #ffffff;
            --dashen-dark: #202124;
            --dashen-gray: #5f6368;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1);
            --transition: all 0.2s ease;
            --border-radius: 16px;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --header-height: 80px;
            
            /* Consistent card color - Light Indigo Tint */
            --card-bg: #ffffff;
            --card-border: #e8eaf6;
            --card-shadow: rgba(26, 35, 126, 0.08);
            --icon-bg: #e8eaf6;
            --icon-color: #1a237e;
            --body-bg: #f5f5f7;
            --text-primary: #202124;
            --text-secondary: #5f6368;
        }

        /* Dark Mode Variables */
        body.dark-mode {
            --dashen-primary: #5c6bc0;
            --dashen-secondary: #3949ab;
            --dashen-light: #1e1e1e;
            --dashen-white: #2d2d2d;
            --dashen-dark: #e0e0e0;
            --dashen-gray: #b0b0b0;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.4);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.4);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.5);
            
            /* Card colors for dark mode */
            --card-bg: #2d2d2d;
            --card-border: #404040;
            --card-shadow: rgba(0,0,0,0.3);
            --icon-bg: #404040;
            --icon-color: #5c6bc0;
            --body-bg: #1a1a1a;
            --text-primary: #e0e0e0;
            --text-secondary: #b0b0b0;
        }

        body.dark-mode .dashboard-card,
        body.dark-mode .system-card,
        body.dark-mode .stat-card,
        body.dark-mode .action-card,
        body.dark-mode .chart-container,
        body.dark-mode .profile-info-card,
        body.dark-mode .modal-content {
            background: var(--card-bg);
            border-color: var(--card-border);
            color: var(--text-primary);
        }

        body.dark-mode .welcome-title,
        body.dark-mode .section-header,
        body.dark-mode .stat-value,
        body.dark-mode .action-title,
        body.dark-mode .system-name,
        body.dark-mode .profile-info-value {
            color: var(--text-primary);
        }

        body.dark-mode .welcome-subtitle,
        body.dark-mode .stat-label,
        body.dark-mode .action-desc,
        body.dark-mode .profile-info-label,
        body.dark-mode .text-muted {
            color: var(--text-secondary);
        }

        body.dark-mode .system-card-header {
            background: linear-gradient(135deg, #333333, #2a2a2a);
            border-bottom-color: var(--card-border);
        }

        body.dark-mode .btn-system {
            background: #333333;
            color: var(--text-primary);
            border-color: var(--card-border);
        }

        body.dark-mode .btn-system:hover {
            background: var(--dashen-primary);
            color: white;
            border-color: var(--dashen-primary);
        }

        body.dark-mode .modal-header {
            background: linear-gradient(135deg, #333333, #2a2a2a);
        }

        body.dark-mode .modal-body {
            background: var(--card-bg);
            color: var(--text-primary);
        }

        body.dark-mode .form-control {
            background: #333333;
            border-color: #404040;
            color: var(--text-primary);
        }

        body.dark-mode .form-control:focus {
            background: #3a3a3a;
            border-color: var(--dashen-primary);
            color: var(--text-primary);
        }

        body.dark-mode .password-toggle-btn {
            color: var(--text-secondary);
        }

        body.dark-mode .password-toggle-btn:hover {
            color: var(--dashen-primary);
        }

        body.dark-mode .profile-dropdown-menu {
            background: #2d2d2d;
            border-color: #404040;
        }

        body.dark-mode .profile-menu-item {
            color: var(--text-primary);
        }

        body.dark-mode .profile-menu-item:hover {
            background: #3a3a3a;
        }

        body.dark-mode .profile-menu-footer {
            background: #333333;
            border-top-color: #404040;
        }

        body.dark-mode .toast {
            background: #2d2d2d;
            border-color: #404040;
            color: var(--text-primary);
        }

        body.dark-mode .toast-content h5,
        body.dark-mode .toast-content p {
            color: var(--text-primary);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--body-bg);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: var(--header-height);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Layout Container */
        .layout-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Modern Glass Sidebar - Consistent Dashen Colors */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            padding: 0;
            box-shadow: var(--shadow-xl);
            transition: var(--transition);
            position: fixed;
            z-index: 1000;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            top: 0;
            left: 0;
        }

        .sidebar.collapsed {
            width: var(--sidebar-collapsed-width);
        }

        .sidebar-header {
            padding: 30px 20px;
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
            transition: var(--transition);
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .sidebar-logo img {
            width: 140px;
            height: auto;
            transition: var(--transition);
        }

        .sidebar.collapsed .sidebar-logo img {
            width: 45px;
        }

        .sidebar-title {
            color: white;
            font-weight: 700;
            margin: 0;
            font-size: 1.4rem;
            transition: var(--transition);
        }

        .sidebar.collapsed .sidebar-title {
            opacity: 0;
            height: 0;
            overflow: hidden;
        }

        .sidebar-menu {
            padding: 25px 0;
            flex-grow: 1;
        }

        .sidebar-menu ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            padding: 18px 25px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            position: relative;
            margin: 8px 15px;
            border-radius: 12px;
        }

        .sidebar-menu a:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.2);
            border-left-color: var(--dashen-accent);
            transform: translateX(8px);
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            border-left-color: var(--dashen-accent);
            font-weight: 600;
        }

        .sidebar-menu a i {
            margin-right: 15px;
            width: 24px;
            text-align: center;
            font-size: 1.3rem;
            transition: var(--transition);
        }

        .sidebar.collapsed .sidebar-menu a span {
            opacity: 0;
            width: 0;
            height: 0;
            overflow: hidden;
        }

        .sidebar.collapsed .sidebar-menu a i {
            margin-right: 0;
            font-size: 1.5rem;
        }

        .sidebar-footer {
            padding: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
        }

        .logout-btn {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
        }

        .sidebar-toggle-btn {
            position: absolute;
            top: 30px;
            right: -15px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--dashen-primary);
            color: white;
            border: 2px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
        }

        .sidebar-toggle-btn:hover {
            background: var(--dashen-accent);
            transform: scale(1.15);
        }

        .sidebar.collapsed .sidebar-toggle-btn i {
            transform: rotate(180deg);
        }

        /* Mobile responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-width);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .sidebar-toggle-btn {
                display: none;
            }
        }

        .mobile-sidebar-toggle {
            display: none;
            position: fixed;
            top: 25px;
            left: 25px;
            z-index: 1100;
            background: var(--dashen-primary);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            font-size: 1.4rem;
            box-shadow: var(--shadow-xl);
            transition: var(--transition);
            z-index: 1002;
        }

        .mobile-sidebar-toggle:hover {
            background: var(--dashen-accent);
            transform: scale(1.05);
        }

        @media (max-width: 992px) {
            .mobile-sidebar-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .sidebar {
                width: var(--sidebar-width) !important;
            }

            .sidebar.collapsed {
                width: var(--sidebar-width) !important;
            }
        }

        /* Main Content Styles */
        .main-content {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            padding: 30px;
            min-height: calc(100vh - var(--header-height));
            transition: var(--transition);
            background: transparent;
            margin-top: 0;
        }

        .main-content.expanded {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }

        /* Fixed Header - Consistent Dashen Colors */
        .main-header {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: fixed;
            top: 0;
            right: 0;
            left: var(--sidebar-width);
            height: var(--header-height);
            z-index: 999;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .main-header.expanded {
            left: var(--sidebar-collapsed-width);
        }

        .header-title h2 {
            color: white;
            font-weight: 600;
            font-size: 1.5rem;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .header-title h2 i {
            color: rgba(255,255,255,0.9);
        }

        /* Chrome-style Profile Dropdown */
        .profile-dropdown {
            position: relative;
        }

        .profile-trigger {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid rgba(255,255,255,0.5);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .profile-trigger:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-lg);
            border-color: white;
            background: rgba(255,255,255,0.3);
        }

        .profile-trigger i {
            font-size: 26px;
            color: white;
        }

        .profile-trigger img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-dropdown-menu {
            position: absolute;
            top: 55px;
            right: 0;
            width: 320px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            padding: 0;
            margin-top: 8px;
            display: none;
            z-index: 1000;
            border: 1px solid #e8eaed;
            overflow: hidden;
        }

        .profile-dropdown-menu.show {
            display: block;
            animation: chromeSlideDown 0.2s ease;
        }

        @keyframes chromeSlideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .profile-menu-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .profile-menu-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: var(--shadow-md);
        }

        .profile-menu-avatar i {
            font-size: 36px;
            color: white;
        }

        .profile-menu-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-menu-info {
            flex: 1;
        }

        .profile-menu-info h4 {
            font-weight: 600;
            margin: 0 0 4px 0;
            color: white;
            font-size: 16px;
        }

        .profile-menu-info p {
            margin: 0;
            color: rgba(255,255,255,0.9);
            font-size: 13px;
        }

        .profile-menu-body {
            padding: 8px 0;
        }

        .profile-menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            color: #202124;
            text-decoration: none;
            transition: background 0.1s ease;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-size: 14px;
            font-weight: 400;
        }

        .profile-menu-item:hover {
            background: #f1f3f4;
        }

        .profile-menu-item i {
            width: 20px;
            color: var(--dashen-primary);
            font-size: 16px;
        }

        .profile-menu-divider {
            height: 1px;
            background: #e8eaed;
            margin: 8px 0;
        }

        .profile-menu-footer {
            padding: 8px 0;
            border-top: 1px solid #e8eaed;
            background: #f8f9fa;
        }

        /* Dark Mode Toggle Button */
        .dark-mode-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 8px;
            transition: var(--transition);
        }

        .dark-mode-toggle:hover {
            background: #f1f3f4;
        }

        .dark-mode-toggle .toggle-switch {
            width: 44px;
            height: 22px;
            background: #e0e0e0;
            border-radius: 22px;
            position: relative;
            transition: var(--transition);
        }

        .dark-mode-toggle .toggle-switch::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: var(--transition);
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        body.dark-mode .dark-mode-toggle .toggle-switch {
            background: var(--dashen-primary);
        }

        body.dark-mode .dark-mode-toggle .toggle-switch::after {
            left: 24px;
        }

        /* ===== LIGHTWEIGHT CARDS WITH CONSISTENT COLORS ===== */
        
        /* Main Dashboard Card */
        .dashboard-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px var(--card-shadow);
            padding: 32px;
            border: 1px solid var(--card-border);
            position: relative;
            overflow: hidden;
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .dashboard-card:hover {
            box-shadow: 0 8px 24px var(--card-shadow);
            transform: translateY(-2px);
        }

        .welcome-title {
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--dashen-primary);
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .welcome-subtitle {
            color: var(--text-secondary);
            font-weight: 400;
            margin-bottom: 2rem;
            font-size: 1.1rem;
            max-width: 800px;
            line-height: 1.6;
        }

        .user-role-badge {
            background: var(--dashen-primary);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-block;
            margin-bottom: 20px;
        }

        /* System Cards - Lightweight with Icons */
        .system-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 30px;
        }

        .system-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
            box-shadow: 0 4px 12px var(--card-shadow);
            border: 1px solid var(--card-border);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .system-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px var(--card-shadow);
        }

        .system-card-header {
            padding: 24px;
            background: linear-gradient(135deg, #ffffff, #f8f9fa);
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .system-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: var(--icon-bg);
            color: var(--icon-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            transition: var(--transition);
        }

        .system-card:hover .system-icon {
            background: var(--dashen-primary);
            color: white;
        }

        .system-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .system-card-footer {
            padding: 20px 24px 24px;
        }

        .btn-system {
            width: 100%;
            padding: 12px;
            background: white;
            color: var(--dashen-primary);
            border: 2px solid var(--card-border);
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.95rem;
            text-decoration: none;
        }

        .btn-system:hover {
            background: var(--dashen-primary);
            color: white;
            border-color: var(--dashen-primary);
            transform: translateY(-2px);
        }

        /* Stats Cards - Lightweight */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: 0 4px 12px var(--card-shadow);
            border: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--card-shadow);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: var(--icon-bg);
            color: var(--icon-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .stat-content {
            flex-grow: 1;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 4px;
            line-height: 1;
            color: var(--dashen-primary);
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        .section-header {
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 20px;
            color: var(--dashen-primary);
            letter-spacing: -0.5px;
        }

        /* Quick Action Cards */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }

        .action-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px var(--card-shadow);
            border: 1px solid var(--card-border);
            text-align: center;
            transition: var(--transition);
        }

        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--card-shadow);
        }

        .action-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background: var(--icon-bg);
            color: var(--icon-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 12px;
        }

        .action-card:hover .action-icon {
            background: var(--dashen-primary);
            color: white;
        }

        .action-title {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .action-desc {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .action-card .btn-system {
            background: white;
            color: var(--dashen-primary);
            border: 2px solid var(--card-border);
            padding: 8px;
            font-size: 0.85rem;
        }

        .action-card .btn-system:hover {
            background: var(--dashen-primary);
            color: white;
            border-color: var(--dashen-primary);
        }

        /* Chart Container */
        .chart-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: 0 4px 12px var(--card-shadow);
            border: 1px solid var(--card-border);
            height: 350px;
        }

        /* Chrome-style Modals */
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }

        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 20px 24px;
            border-bottom: none;
        }

        .modal-header .modal-title {
            font-weight: 600;
            font-size: 1.2rem;
            color: white;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
            transition: opacity 0.1s ease;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 30px;
        }

        /* Profile Modal */
        .profile-avatar-large {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            margin: 0 auto 16px;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: var(--shadow-lg);
            position: relative;
            cursor: pointer;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-avatar-large i {
            font-size: 70px;
            color: white;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 8px;
            font-size: 0.85rem;
            text-align: center;
            transform: translateY(100%);
            transition: transform 0.2s ease;
            cursor: pointer;
            backdrop-filter: blur(4px);
        }

        .profile-avatar-large:hover .avatar-upload-overlay {
            transform: translateY(0);
        }

        .profile-info-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid var(--card-border);
        }

        .profile-info-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--card-border);
        }

        .profile-info-row:last-child {
            border-bottom: none;
        }

        .profile-info-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.1rem;
        }

        .profile-info-content {
            flex: 1;
        }

        .profile-info-label {
            font-size: 0.8rem;
            color: var(--dashen-gray);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .profile-info-value {
            font-weight: 600;
            color: #202124;
            font-size: 1rem;
        }

        .role-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .status-active {
            background: #e6f4ea;
            color: #137333;
        }

        .status-inactive {
            background: #fce8e8;
            color: #c5221f;
        }

        /* Button Styles */
        .btn-custom {
            padding: 12px 20px;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary-custom {
            background: var(--dashen-primary);
            color: white;
        }

        .btn-primary-custom:hover {
            background: var(--dashen-secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(26, 35, 126, 0.2);
            color: white;
        }

        .btn-outline-custom {
            background: transparent;
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
        }

        .btn-outline-custom:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
        }

        .btn-danger-custom {
            background: #d93025;
            color: white;
        }

        .btn-danger-custom:hover {
            background: #b3261e;
            transform: translateY(-2px);
        }

        /* Password input group */
        .password-input-group {
            position: relative;
            margin-bottom: 20px;
        }

        .password-input-group .form-label {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 6px;
            font-size: 0.9rem;
        }

        .password-input-group .form-control {
            padding-right: 45px;
            border-radius: 12px;
            border: 1px solid #dadce0;
            padding: 12px 15px;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .password-input-group .form-control:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
            outline: none;
        }

        .password-toggle-btn {
            position: absolute;
            right: 15px;
            bottom: 12px;
            background: none;
            border: none;
            color: #5f6368;
            cursor: pointer;
            padding: 4px;
            z-index: 10;
            transition: var(--transition);
        }

        .password-toggle-btn:hover {
            color: var(--dashen-primary);
        }

        .form-text {
            color: #5f6368;
            font-size: 0.8rem;
            margin-top: 4px;
        }

        /* Color Picker Styles */
        .color-picker-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }

        .color-picker-item {
            text-align: center;
            cursor: pointer;
        }

        .color-picker-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 8px;
            border: 3px solid white;
            box-shadow: var(--shadow-md);
            transition: transform 0.2s ease;
        }

        .color-picker-circle:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-lg);
        }

        .color-picker-name {
            font-size: 0.85rem;
            color: #5f6368;
            font-weight: 500;
        }

        .form-control-color {
            width: 50px;
            height: 42px;
            padding: 4px;
            border-radius: 8px;
            border: 1px solid #dadce0;
            cursor: pointer;
        }

        /* Message Container for Password Modal */
        .password-message-container {
            margin-bottom: 20px;
            padding: 12px 16px;
            border-radius: 8px;
            display: none;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            animation: slideDown 0.3s ease;
        }

        .password-message-container.show {
            display: flex;
        }

        .password-message-container.success {
            background: #e6f4ea;
            border: 1px solid #137333;
            color: #137333;
        }

        .password-message-container.error {
            background: #fce8e8;
            border: 1px solid #c5221f;
            color: #c5221f;
        }

        .password-message-container.warning {
            background: #fff4e5;
            border: 1px solid #f9a825;
            color: #b45f06;
        }

        .password-message-container i {
            font-size: 1.2rem;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Toast notification */
        .toast-container {
            position: fixed;
            top: 100px;
            right: 30px;
            z-index: 99999;
        }

        .toast {
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 380px;
            max-width: 420px;
            animation: slideInRight 0.3s ease;
            border: 1px solid #e8eaed;
            position: relative;
            z-index: 100000;
            pointer-events: auto;
        }

        .toast-success {
            border-left: 6px solid #137333;
        }

        .toast-success i {
            color: #137333;
            font-size: 24px;
        }

        .toast-error {
            border-left: 6px solid #c5221f;
        }

        .toast-error i {
            color: #c5221f;
            font-size: 24px;
        }

        .toast-warning {
            border-left: 6px solid #f9a825;
        }

        .toast-warning i {
            color: #f9a825;
            font-size: 24px;
        }

        .toast-info {
            border-left: 6px solid #1a73e8;
        }

        .toast-info i {
            color: #1a73e8;
            font-size: 24px;
        }

        .toast-content {
            flex: 1;
        }

        .toast-content h5 {
            margin: 0 0 5px 0;
            font-weight: 600;
            font-size: 1rem;
            color: #202124;
        }

        .toast-content p {
            margin: 0;
            color: #5f6368;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .system-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            }
            
            .welcome-title {
                font-size: 2.2rem;
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .main-content.expanded {
                margin-left: 0;
            }

            .main-header {
                left: 0;
            }

            .main-header.expanded {
                left: 0;
            }

            .welcome-title {
                font-size: 2rem;
            }
        }

        @media (max-width: 768px) {
            .dashboard-card {
                padding: 20px;
            }

            .welcome-title {
                font-size: 1.8rem;
            }

            .system-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .main-header {
                padding: 0 20px;
            }

            .header-title h2 {
                font-size: 1.2rem;
            }

            .profile-avatar-large {
                width: 100px;
                height: 100px;
            }

            .profile-avatar-large i {
                font-size: 50px;
            }

            .toast {
                min-width: 300px;
                max-width: 320px;
                right: 10px;
            }
            
            .section-header {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body class="<?php echo $dark_mode ? 'dark-mode' : ''; ?>">
    <!-- Mobile Toggle Button -->
    <button class="mobile-sidebar-toggle" id="mobileSidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Navigation - Consistent Dashen Colors -->
    <nav id="sidebar" class="sidebar">
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn">
            <i class="fas fa-chevron-left"></i>
        </button>

        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="Images/DashenLogo12.png" alt="Dashen Bank Logo" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTQwIiBoZWlnaHQ9IjQwIiB2aWV3Qm94PSIwIDAgMTQwIDQwIiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxwYXRoIGQ9Ik0yMCAxMEg0MFYzMEgyMFYxMFoiIGZpbGw9IndoaXRlIi8+PHNwYW4geD0iNTAiIHk9IjIwIiBmaWxsPSJ3aGl0ZSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0Ij5EYXNoZW4gQmFuazwvc3Bhbj48L3N2Zz4='">
            </div>
            <h3 class="sidebar-title">Dashen Bank</h3>
        </div>

        <div class="sidebar-menu">
            <ul class="list-unstyled">
                <li>
                    <a href="#" class="active">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <!-- Super Admin Menu Items -->
                <?php if($user_role === 'super_admin'): ?>
                <li>
                    <a href="display_user.php">
                        <i class="fas fa-user-plus"></i>
                        <span>Manage Users</span>
                    </a>
                </li>
                <li>
                    <a href="users.php">
                        <i class="fas fa-users-cog"></i>
                        <span>Add New Users</span>
                    </a>
                </li>
                <li>
                    <a href="pm_admin_projects.php">
                        <i class="fas fa-project-diagram"></i>
                        <span>Project Profile</span>
                    </a>
                </li>
                <li>
                    <a href="user_assignment.php">
                        <i class="fas fa-user-check"></i>
                        <span>User Assignment</span>
                    </a>
                </li>
                <li>
                    <a href="module_assignment.php">
                        <i class="fas fa-cubes"></i>
                        <span>Module Assignment</span>
                    </a>
                </li>
                <li>
                    <a href="consolidated_reports.php">
                        <i class="fas fa-cubes"></i>
                        <span>consolidated_reports</span>
                    </a>
                </li>
                <?php endif; ?>
 
                <!-- PM Manager Menu Items -->
                <?php if($user_role === 'pm_manager'): ?>
                <li>
                    <a href="pm_admin_projects.php">
                        <i class="fas fa-project-diagram"></i>
                        <span>Project Profile</span>
                    </a>
                </li>
                <li>
                    <a href="user_assignment.php">
                        <i class="fas fa-user-check"></i>
                        <span>User Assignment</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- PM Employee Menu Items -->
                <?php if($user_role === 'pm_employee'): ?>
                <li>
                    <a href="pm_admin_projects.php">
                        <i class="fas fa-project-diagram"></i>
                        <span>Project Profile</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- System Modules with Professional Icons -->
                <?php 
                $system_icons = [
                    'Test Case' => 'fa-solid fa-clipboard-check',
                    'Change Control' => 'fa-solid fa-arrows-rotate',
                    'Budget Management' => 'fa-solid fa-chart-column',
                    'Project Scheduler' => 'fa-solid fa-calendar-days',
                    'Risk Management' => 'fa-solid fa-shield', // Fixed risk icon
                    'Issue Management' => 'fa-solid fa-bug',
                    'Event Managment' => 'fa-regular fa-calendar-check', // Fixed event icon
                    'Project Intake Form' => 'fa-solid fa-file-circle-plus'
                ];
                
                foreach ($systems as $system): 
                    $icon = 'fa-solid fa-cube'; // default icon
                    foreach ($system_icons as $key => $value) {
                        if (strpos($system['system_name'], $key) !== false) {
                            $icon = $value;
                            break;
                        }
                    }
                ?>
                <li>
                    <a href="<?php echo htmlspecialchars($system['system_url']); ?>">
                        <i class="<?php echo $icon; ?>"></i>
                        <span><?php echo htmlspecialchars($system['system_name']); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
                
                <li>
                    <a href="#">
                        <i class="fas fa-chart-line"></i>
                        <span>Analytics</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="sidebar-footer">
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Fixed Header - Consistent Dashen Colors -->
        <header class="main-header" id="mainHeader">
            <div class="header-title">
                <h2><i class="fas fa-tachometer-alt me-2"></i>PM PULSE PORTAL</h2>
            </div>

            <!-- Chrome-style Profile Dropdown -->
            <div class="profile-dropdown">
                <div class="profile-trigger" onclick="toggleProfileDropdown()">
                    <?php if ($profile_image && file_exists($profile_image)): ?>
                        <img src="<?php echo $profile_image; ?>" alt="Profile" id="headerProfileImage">
                    <?php else: ?>
                        <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </div>

                <div class="profile-dropdown-menu" id="profileDropdown">
                    <div class="profile-menu-header">
                        <div class="profile-menu-avatar">
                            <?php if ($profile_image && file_exists($profile_image)): ?>
                                <img src="<?php echo $profile_image; ?>" alt="Profile" id="dropdownProfileImage">
                            <?php else: ?>
                                <i class="fas fa-user-circle"></i>
                            <?php endif; ?>
                        </div>
                        <div class="profile-menu-info">
                            <h4><?php echo htmlspecialchars($username); ?></h4>
                            <p><?php echo htmlspecialchars($user_email); ?></p>
                        </div>
                    </div>

                    <div class="profile-menu-body">
                        <button class="profile-menu-item" onclick="openProfileModal()">
                            <i class="fas fa-user"></i>
                            <span>Your profile</span>
                        </button>

                        <button class="profile-menu-item" onclick="openChangePasswordModal()">
                            <i class="fas fa-key"></i>
                            <span>Change password</span>
                        </button>

                        <button class="profile-menu-item" onclick="openThemeModal()">
                            <i class="fas fa-palette"></i>
                            <span>Theme settings</span>
                        </button>

                        <!-- Dark Mode Toggle -->
                        <div class="dark-mode-toggle profile-menu-item" onclick="toggleDarkMode()">
                            <div style="display: flex; align-items: center; gap: 16px;">
                                <i class="fas <?php echo $dark_mode ? 'fa-sun' : 'fa-moon'; ?>"></i>
                                <span>Dark Mode</span>
                            </div>
                            <div class="toggle-switch"></div>
                        </div>
                    </div>

                    <div class="profile-menu-footer">
                        <a href="logout.php" class="profile-menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Sign out</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Welcome Section - Lightweight Card -->
        <div class="dashboard-card">
            <span class="user-role-badge">
                <i class="fas fa-user-shield me-2"></i><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Access
            </span>

            <h1 class="welcome-title">Welcome back, <?php echo htmlspecialchars($username); ?>!</h1>
            <p class="welcome-subtitle">Access and manage all your banking systems from one central dashboard. Monitor performance, track projects, and streamline your workflow.</p>

            <?php if($user_role === 'super_admin' || $user_role === 'pm_manager' || $user_role === 'pm_employee'): ?>
            <div class="quick-actions">
                <?php if($user_role === 'super_admin'): ?>
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="action-title">User Management</div>
                    <div class="action-desc">Add and manage system users</div>
                    <a href="display_user.php" class="btn-system" style="padding: 8px; font-size: 0.85rem;">
                        <i class="fas fa-users"></i> Manage
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <div class="action-title">Project Profile</div>
                    <div class="action-desc">Manage systems and projects</div>
                    <a href="pm_admin_projects.php" class="btn-system" style="padding: 8px; font-size: 0.85rem;">
                        <i class="fas fa-tasks"></i> Manage
                    </a>
                </div>
                
                <?php if($user_role !== 'pm_employee'): ?>
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="action-title">User Assignment</div>
                    <div class="action-desc">Assign users to systems</div>
                    <a href="user_assignment.php" class="btn-system" style="padding: 8px; font-size: 0.85rem;">
                        <i class="fas fa-tasks"></i> Assign
                    </a>
                </div>
                <?php endif; ?>

                <?php if($user_role === 'super_admin'): ?>
                <div class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <div class="action-title">Module Assignment</div>
                    <div class="action-desc">Assign modules to users</div>
                    <a href="module_assignment.php" class="btn-system" style="padding: 8px; font-size: 0.85rem;">
                        <i class="fas fa-tasks"></i> Manage
                    </a>
                </div>
                
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <h3 class="section-header" style="margin-top: 40px;">Available Modules</h3>

            <div class="system-grid">
                <?php 
                $module_icons = [
                    'Test Case' => 'fa-solid fa-clipboard-check',
                    'Change Control' => 'fa-solid fa-arrows-rotate',
                    'Budget Management' => 'fa-solid fa-chart-column',
                    'Project Scheduler' => 'fa-solid fa-calendar-days',
                    'Risk Management' => 'fa-solid fa-shield', // Fixed risk icon
                    'Issue Management' => 'fa-solid fa-bug',
                    'Event Managment' => 'fa-regular fa-calendar-check', // Fixed event icon
                    'Project Intake Form' => 'fa-solid fa-file-circle-plus'
                ];
                
                foreach ($systems as $system): 
                    $icon = 'fa-solid fa-cube'; // default icon
                    foreach ($module_icons as $key => $value) {
                        if (strpos($system['system_name'], $key) !== false) {
                            $icon = $value;
                            break;
                        }
                    }
                ?>
                <div class="system-card">
                    <div class="system-card-header">
                        <div class="system-icon">
                            <i class="<?php echo $icon; ?>"></i>
                        </div>
                        <h4 class="system-name"><?php echo htmlspecialchars($system['system_name']); ?></h4>
                    </div>
                    <div class="system-card-footer">
                        <a href="<?php echo htmlspecialchars($system['system_url']); ?>" class="btn-system">
                            <i class="fas fa-external-link-alt"></i> Launch System
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Statistics Section - Lightweight Cards -->
        <div class="dashboard-card">
            <h3 class="section-header">Performance Overview</h3>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo count($systems); ?></div>
                        <div class="stat-label">Active Systems</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">1</div>
                        <div class="stat-label">Active Users</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value">98%</div>
                        <div class="stat-label">Uptime</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Activity Chart - Lightweight Card -->
        <div class="dashboard-card">
            <h3 class="section-header">System Performance Analytics</h3>
            <div class="chart-container">
                <canvas id="activityChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>Your profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <div class="profile-avatar-large" onclick="document.getElementById('profileUpload').click()">
                            <?php if ($profile_image && file_exists($profile_image)): ?>
                                <img src="<?php echo $profile_image; ?>" alt="Profile" id="modalProfileImage">
                            <?php else: ?>
                                <i class="fas fa-user-circle" id="modalProfileIcon"></i>
                            <?php endif; ?>
                            <div class="avatar-upload-overlay">
                                <i class="fas fa-camera me-1"></i> Upload
                            </div>
                        </div>
                        <input type="file" id="profileUpload" accept="image/*" style="display: none;" onchange="uploadProfilePicture(this)">
                        
                        <div class="mt-3 d-flex justify-content-center gap-2">
                            <button class="btn btn-primary-custom btn-sm" onclick="document.getElementById('profileUpload').click()">
                                <i class="fas fa-upload me-1"></i> Upload Photo
                            </button>
                            <button class="btn btn-outline-custom btn-sm" onclick="removeProfilePicture()" id="removePhotoBtn" <?php echo !$profile_image ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-alt me-1"></i> Remove
                            </button>
                        </div>
                    </div>

                    <div class="profile-info-card">
                        <div class="profile-info-row">
                            <div class="profile-info-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Full name</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($username); ?></div>
                            </div>
                        </div>

                        <div class="profile-info-row">
                            <div class="profile-info-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Email address</div>
                                <div class="profile-info-value"><?php echo htmlspecialchars($user_email); ?></div>
                            </div>
                        </div>

                        <div class="profile-info-row">
                            <div class="profile-info-icon">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Role</div>
                                <div class="profile-info-value">
                                    <span class="role-badge"><?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="profile-info-row">
                            <div class="profile-info-icon">
                                <i class="fas fa-circle"></i>
                            </div>
                            <div class="profile-info-content">
                                <div class="profile-info-label">Account status</div>
                                <div class="profile-info-value">
                                    <span class="status-badge <?php echo $user_data['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                        <i class="fas fa-<?php echo $user_data['is_active'] ? 'check-circle' : 'exclamation-circle'; ?> me-1"></i>
                                        <?php echo $user_data['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button class="btn btn-primary-custom btn-custom" onclick="closeProfileModalAndOpenChangePassword()">
                            <i class="fas fa-key me-2"></i>Change password
                        </button>
                        <button class="btn btn-outline-custom btn-custom" onclick="closeProfileModalAndOpenTheme()">
                            <i class="fas fa-palette me-2"></i>Theme settings
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-key me-2"></i>Change password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Message Container for Feedback -->
                    <div id="passwordMessage" class="password-message-container">
                        <i class="fas"></i>
                        <span id="passwordMessageText"></span>
                    </div>

                    <form id="changePasswordForm">
                        <div class="password-input-group">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" class="form-control" id="current_password" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('current_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <div class="password-input-group">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" class="form-control" id="new_password" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('new_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                            <div class="form-text">Minimum 8 characters with uppercase, lowercase and number</div>
                        </div>

                        <div class="password-input-group">
                            <label for="confirm_password" class="form-label">Confirm new password</label>
                            <input type="password" class="form-control" id="confirm_password" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword('confirm_password', this)">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>

                        <button type="submit" class="btn btn-primary-custom btn-custom w-100" id="changePasswordBtn">
                            <span class="btn-text">Update password</span>
                            <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Theme Settings Modal -->
    <div class="modal fade" id="themeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-palette me-2"></i>Theme settings
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="themeForm">
                        <?php 
                        $colorOptions = [
                            'primary' => 'Primary',
                            'secondary' => 'Secondary', 
                            'accent' => 'Accent',
                            'success' => 'Success',
                            'warning' => 'Warning',
                            'info' => 'Info'
                        ];
                        
                        foreach ($colorOptions as $key => $label): ?>
                        <div class="mb-3">
                            <label class="form-label"><?php echo $label; ?> color</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control-color" name="<?php echo $key; ?>_color" value="<?php echo $colors[$key]; ?>" onchange="this.nextElementSibling.value = this.value">
                                <input type="text" class="form-control" value="<?php echo $colors[$key]; ?>" readonly style="width: 100px;">
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary-custom btn-custom w-100" id="themeBtn">
                            <span class="btn-text">Apply theme</span>
                            <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        </button>
                    </form>

                    <div class="color-picker-grid">
                        <h6 class="mb-2 col-12">Quick presets</h6>
                        <div class="color-picker-item" onclick="applyColorPreset('primary', '#1a237e')">
                            <div class="color-picker-circle" style="background: #1a237e;"></div>
                            <div class="color-picker-name">Default</div>
                        </div>
                        <div class="color-picker-item" onclick="applyColorPreset('primary', '#283593')">
                            <div class="color-picker-circle" style="background: #283593;"></div>
                            <div class="color-picker-name">Indigo</div>
                        </div>
                        <div class="color-picker-item" onclick="applyColorPreset('primary', '#3949ab')">
                            <div class="color-picker-circle" style="background: #3949ab;"></div>
                            <div class="color-picker-name">Light</div>
                        </div>
                        <div class="color-picker-item" onclick="applyColorPreset('primary', '#1e3a8a')">
                            <div class="color-picker-circle" style="background: #1e3a8a;"></div>
                            <div class="color-picker-name">Deep</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal instances
        let profileModal, changePasswordModal, themeModal;

        document.addEventListener('DOMContentLoaded', function() {
            profileModal = new bootstrap.Modal(document.getElementById('profileModal'));
            changePasswordModal = new bootstrap.Modal(document.getElementById('changePasswordModal'));
            themeModal = new bootstrap.Modal(document.getElementById('themeModal'));

            // Initialize chart
            initChart();

            // Check screen size for sidebar
            checkScreenSize();

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const dropdown = document.getElementById('profileDropdown');
                const trigger = document.querySelector('.profile-trigger');
                
                if (dropdown && trigger && !trigger.contains(event.target) && !dropdown.contains(event.target)) {
                    dropdown.classList.remove('show');
                }
            });
        });

        // Toggle Profile Dropdown
        function toggleProfileDropdown() {
            document.getElementById('profileDropdown').classList.toggle('show');
        }

        // Open Profile Modal
        function openProfileModal() {
            document.getElementById('profileDropdown').classList.remove('show');
            profileModal.show();
        }

        // Open Change Password Modal
        function openChangePasswordModal() {
            document.getElementById('profileDropdown').classList.remove('show');
            // Clear any previous messages
            const messageContainer = document.getElementById('passwordMessage');
            messageContainer.classList.remove('show', 'success', 'error', 'warning');
            changePasswordModal.show();
        }

        // Open Theme Modal
        function openThemeModal() {
            document.getElementById('profileDropdown').classList.remove('show');
            themeModal.show();
        }

        // Close Profile Modal and Open Change Password
        function closeProfileModalAndOpenChangePassword() {
            profileModal.hide();
            setTimeout(() => {
                // Clear any previous messages
                const messageContainer = document.getElementById('passwordMessage');
                messageContainer.classList.remove('show', 'success', 'error', 'warning');
                changePasswordModal.show();
            }, 300);
        }

        // Close Profile Modal and Open Theme
        function closeProfileModalAndOpenTheme() {
            profileModal.hide();
            setTimeout(() => {
                themeModal.show();
            }, 300);
        }

        // Toggle Password Visibility
        function togglePassword(fieldId, button) {
            const field = document.getElementById(fieldId);
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Upload Profile Picture
        function uploadProfilePicture(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const formData = new FormData();
                formData.append('profile_image', file);
                formData.append('action', 'upload_profile');

                // Show preview immediately
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Update all profile images
                    const headerTrigger = document.querySelector('.profile-trigger');
                    if (headerTrigger) {
                        headerTrigger.innerHTML = `<img src="${e.target.result}" alt="Profile">`;
                    }
                    
                    const dropdownAvatar = document.querySelector('.profile-menu-avatar');
                    if (dropdownAvatar) {
                        dropdownAvatar.innerHTML = `<img src="${e.target.result}" alt="Profile">`;
                    }
                    
                    const modalAvatar = document.querySelector('.profile-avatar-large');
                    if (modalAvatar) {
                        modalAvatar.innerHTML = `<img src="${e.target.result}" alt="Profile" id="modalProfileImage"><div class="avatar-upload-overlay"><i class="fas fa-camera me-1"></i> Upload</div>`;
                    }
                    
                    // Enable remove button
                    document.getElementById('removePhotoBtn').disabled = false;
                };
                reader.readAsDataURL(file);

                // Upload to server (background)
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Optional: Show success toast
                        // showToast('Success', data.message, 'success');
                    } else {
                        showToast('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    showToast('Error', 'Failed to upload image', 'error');
                });
            }
        }

        // Remove Profile Picture - INSTANT
        function removeProfilePicture() {
            const removeBtn = document.getElementById('removePhotoBtn');
            
            // Update UI instantly - NO PROCESSING DELAY
            const headerTrigger = document.querySelector('.profile-trigger');
            if (headerTrigger) {
                headerTrigger.innerHTML = '<i class="fas fa-user-circle"></i>';
            }
            
            const dropdownAvatar = document.querySelector('.profile-menu-avatar');
            if (dropdownAvatar) {
                dropdownAvatar.innerHTML = '<i class="fas fa-user-circle"></i>';
            }
            
            const modalAvatar = document.querySelector('.profile-avatar-large');
            if (modalAvatar) {
                modalAvatar.innerHTML = '<i class="fas fa-user-circle" id="modalProfileIcon"></i><div class="avatar-upload-overlay"><i class="fas fa-camera me-1"></i> Upload</div>';
            }
            
            // Disable remove button immediately
            removeBtn.disabled = true;
            
            // Show success toast instantly
            showToast('Success', 'Profile picture removed successfully', 'success');

            // Send request to server in the background (no waiting)
            const formData = new FormData();
            formData.append('action', 'remove_profile');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    showToast('Error', data.message || 'Failed to remove profile picture', 'error');
                }
            })
            .catch(error => {
                console.error('Background removal error:', error);
            });
        }

        // Toggle Dark Mode
        function toggleDarkMode() {
            const formData = new FormData();
            formData.append('action', 'toggle_dark_mode');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.body.classList.toggle('dark-mode');
                    const icon = document.querySelector('.dark-mode-toggle i');
                    const toggleText = document.querySelector('.dark-mode-toggle span');
                    
                    if (document.body.classList.contains('dark-mode')) {
                        icon.className = 'fas fa-sun';
                        showToast('Success', 'Dark mode enabled', 'success');
                    } else {
                        icon.className = 'fas fa-moon';
                        showToast('Success', 'Light mode enabled', 'success');
                    }
                }
            })
            .catch(error => {
                showToast('Error', 'Failed to toggle dark mode', 'error');
            });
        }

        // Change Password Form
        document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            const messageContainer = document.getElementById('passwordMessage');
            const messageText = document.getElementById('passwordMessageText');
            const messageIcon = messageContainer.querySelector('i');

            // Clear previous messages
            messageContainer.classList.remove('show', 'success', 'error', 'warning');

            if (!currentPassword || !newPassword || !confirmPassword) {
                messageIcon.className = 'fas fa-exclamation-circle';
                messageText.textContent = 'All fields are required';
                messageContainer.classList.add('show', 'error');
                return;
            }

            const btn = document.getElementById('changePasswordBtn');
            btn.disabled = true;
            btn.querySelector('.btn-text').classList.add('d-none');
            btn.querySelector('.spinner-border').classList.remove('d-none');

            const formData = new FormData();
            formData.append('action', 'change_password');
            formData.append('current_password', currentPassword);
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    messageIcon.className = 'fas fa-check-circle';
                    messageText.textContent = data.message;
                    messageContainer.classList.add('show', 'success');
                    
                    // Clear form
                    document.getElementById('changePasswordForm').reset();
                    
                    // Close modal after 2 seconds
                    setTimeout(() => {
                        changePasswordModal.hide();
                        // Clear message after modal closes
                        setTimeout(() => {
                            messageContainer.classList.remove('show', 'success', 'error', 'warning');
                        }, 300);
                    }, 2000);
                } else {
                    // Show error message
                    let type = 'error';
                    if (data.message.includes('same')) {
                        type = 'warning';
                        messageIcon.className = 'fas fa-exclamation-triangle';
                    } else if (data.message.includes('wrong') || data.message.includes('incorrect')) {
                        type = 'error';
                        messageIcon.className = 'fas fa-exclamation-circle';
                    } else {
                        messageIcon.className = 'fas fa-exclamation-circle';
                    }
                    
                    messageText.textContent = data.message;
                    messageContainer.classList.add('show', type);
                }
            })
            .catch(error => {
                messageIcon.className = 'fas fa-exclamation-circle';
                messageText.textContent = 'An error occurred';
                messageContainer.classList.add('show', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.querySelector('.btn-text').classList.remove('d-none');
                btn.querySelector('.spinner-border').classList.add('d-none');
            });
        });

        // Theme Form
        document.getElementById('themeForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = document.getElementById('themeBtn');
            btn.disabled = true;
            btn.querySelector('.btn-text').classList.add('d-none');
            btn.querySelector('.spinner-border').classList.remove('d-none');

            const formData = new FormData(this);
            formData.append('action', 'update_colors');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Success', 'Theme updated successfully!', 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast('Error', 'Failed to update theme', 'error');
                }
            })
            .catch(error => {
                showToast('Error', 'Failed to update theme', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.querySelector('.btn-text').classList.remove('d-none');
                btn.querySelector('.spinner-border').classList.add('d-none');
            });
        });

        // Apply Color Preset
        function applyColorPreset(color, value) {
            const input = document.querySelector(`[name="${color}_color"]`);
            if (input) {
                input.value = value;
                const preview = input.nextElementSibling;
                if (preview) preview.value = value;
            }
        }

        // Show Toast
        function showToast(title, message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            let icon = 'fa-info-circle';
            if (type === 'success') icon = 'fa-check-circle';
            if (type === 'error') icon = 'fa-exclamation-circle';
            if (type === 'warning') icon = 'fa-exclamation-triangle';
            
            toast.innerHTML = `
                <i class="fas ${icon}"></i>
                <div class="toast-content">
                    <h5>${title}</h5>
                    <p>${message}</p>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (toast && toast.parentNode) {
                    toast.style.animation = 'fadeOut 0.3s ease';
                    setTimeout(() => {
                        if (toast && toast.parentNode) {
                            toast.remove();
                        }
                    }, 300);
                }
            }, 3000);
        }

        // Sidebar Toggle
        document.getElementById('sidebarToggleBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mainHeader = document.getElementById('mainHeader');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            mainHeader.classList.toggle('expanded');

            const icon = this.querySelector('i');
            if (sidebar.classList.contains('collapsed')) {
                icon.className = 'fas fa-chevron-right';
            } else {
                icon.className = 'fas fa-chevron-left';
            }
        });

        // Mobile Sidebar Toggle
        document.getElementById('mobileSidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });

        // Check Screen Size
        function checkScreenSize() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const mainHeader = document.getElementById('mainHeader');
            const toggleBtn = document.getElementById('sidebarToggleBtn');
            
            if (window.innerWidth <= 1200 && window.innerWidth > 992) {
                if (sidebar && !sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    mainHeader.classList.add('expanded');
                    if (toggleBtn) {
                        toggleBtn.querySelector('i').className = 'fas fa-chevron-right';
                    }
                }
            } else if (window.innerWidth > 1200) {
                if (sidebar && sidebar.classList.contains('collapsed')) {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    mainHeader.classList.remove('expanded');
                    if (toggleBtn) {
                        toggleBtn.querySelector('i').className = 'fas fa-chevron-left';
                    }
                }
            }
        }

        window.addEventListener('resize', checkScreenSize);

        // Initialize Chart
        function initChart() {
            const ctx = document.getElementById('activityChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'System Performance',
                        data: [65, 75, 70, 80, 85, 75, 90, 85, 88, 92, 95, 98],
                        backgroundColor: 'rgba(26, 35, 126, 0.05)',
                        borderColor: '#1a237e',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'white',
                        pointBorderColor: '#1a237e',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white',
                            titleColor: '#202124',
                            bodyColor: '#5f6368',
                            borderColor: '#e8eaed',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: '#e8eaed' },
                            ticks: { color: '#5f6368' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#5f6368' }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>