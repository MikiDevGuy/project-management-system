<?php
// profile.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];
$success_message = '';
$error_message = '';

// Get current user data
$query = "SELECT id, username, email, profile_picture, system_role, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate required fields
        if (empty($username) || empty($email)) {
            $error_message = "Username and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            // Check if username or email already exists (excluding current user)
            $check_query = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ssi", $username, $email, $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error_message = "Username or email already exists.";
                $check_stmt->close();
            } else {
                $check_stmt->close();
                
                // Handle password change if provided
                if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
                    if (empty($current_password)) {
                        $error_message = "Current password is required to change password.";
                    } elseif (empty($new_password)) {
                        $error_message = "New password is required.";
                    } elseif (empty($confirm_password)) {
                        $error_message = "Please confirm your new password.";
                    } elseif ($new_password !== $confirm_password) {
                        $error_message = "New passwords do not match.";
                    } elseif (strlen($new_password) < 6) {
                        $error_message = "New password must be at least 6 characters long.";
                    } else {
                        // Verify current password
                        $verify_query = "SELECT password FROM users WHERE id = ?";
                        $verify_stmt = $conn->prepare($verify_query);
                        $verify_stmt->bind_param("i", $user_id);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        $user_data = $verify_result->fetch_assoc();
                        $verify_stmt->close();
                        
                        if (!password_verify($current_password, $user_data['password'])) {
                            $error_message = "Current password is incorrect.";
                        } else {
                            // Update with new password
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $update_query = "UPDATE users SET username = ?, email = ?, password = ?, updated_at = NOW() WHERE id = ?";
                            $update_stmt = $conn->prepare($update_query);
                            $update_stmt->bind_param("sssi", $username, $email, $hashed_password, $user_id);
                            
                            if ($update_stmt->execute()) {
                                $success_message = "Profile and password updated successfully!";
                                // Update session username
                                $_SESSION['username'] = $username;
                                $_SESSION['email'] = $email;
                                // Refresh user data
                                $user['username'] = $username;
                                $user['email'] = $email;
                            } else {
                                $error_message = "Failed to update profile: " . $conn->error;
                            }
                            $update_stmt->close();
                        }
                    }
                } else {
                    // Update without password change
                    $update_query = "UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("ssi", $username, $email, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Profile updated successfully!";
                        // Update session username
                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;
                        // Refresh user data
                        $user['username'] = $username;
                        $user['email'] = $email;
                    } else {
                        $error_message = "Failed to update profile: " . $conn->error;
                    }
                    $update_stmt->close();
                }
            }
        }
    }
    
    // Handle profile picture upload
    if ($action === 'upload_profile_picture' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $file_type = $_FILES['profile_picture']['type'];
        $file_size = $_FILES['profile_picture']['size'];
        
        if (!in_array($file_type, $allowed_types)) {
            $error_message = "Only JPG, PNG, and GIF images are allowed.";
        } elseif ($file_size > $max_size) {
            $error_message = "Image size must be less than 2MB.";
        } else {
            // Generate unique filename
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = 'uploads/profile_pictures/' . $filename;
            
            // Create directory if it doesn't exist
            if (!file_exists('uploads/profile_pictures')) {
                mkdir('uploads/profile_pictures', 0777, true);
            }
            
            // Delete old profile picture if exists
            if (!empty($user['profile_picture'])) {
                $old_file = 'uploads/profile_pictures/' . basename($user['profile_picture']);
                if (file_exists($old_file)) {
                    unlink($old_file);
                }
            }
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Update database
                $picture_query = "UPDATE users SET profile_picture = ? WHERE id = ?";
                $picture_stmt = $conn->prepare($picture_query);
                $picture_path = 'uploads/profile_pictures/' . $filename;
                $picture_stmt->bind_param("si", $picture_path, $user_id);
                
                if ($picture_stmt->execute()) {
                    $success_message = "Profile picture updated successfully!";
                    $user['profile_picture'] = $picture_path;
                } else {
                    $error_message = "Failed to update profile picture in database.";
                }
                $picture_stmt->close();
            } else {
                $error_message = "Failed to upload profile picture.";
            }
        }
    }
    
    // Handle remove profile picture
    if ($action === 'remove_profile_picture') {
        if (!empty($user['profile_picture'])) {
            $old_file = 'uploads/profile_pictures/' . basename($user['profile_picture']);
            if (file_exists($old_file)) {
                unlink($old_file);
            }
            
            $remove_query = "UPDATE users SET profile_picture = NULL WHERE id = ?";
            $remove_stmt = $conn->prepare($remove_query);
            $remove_stmt->bind_param("i", $user_id);
            
            if ($remove_stmt->execute()) {
                $success_message = "Profile picture removed successfully!";
                $user['profile_picture'] = null;
            } else {
                $error_message = "Failed to remove profile picture.";
            }
            $remove_stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f8fafc;
            --dashen-dark: #1e293b;
            --text-dark: #2c3e50;
            --text-light: #64748b;
            --border-color: #e2e8f0;
            --gradient-primary: linear-gradient(135deg, #273274 0%, #1e2559 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
            transition: all 0.3s;
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: 80px;
        }
        
        /* Header */
        .page-header {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--dashen-primary);
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
        }
        
        /* Profile Card */
        .profile-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s;
            cursor: pointer;
        }
        
        .profile-avatar:hover .avatar-overlay {
            opacity: 1;
        }
        
        .profile-info h3 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        .profile-info p {
            color: var(--text-light);
            margin-bottom: 0.25rem;
        }
        
        .role-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: var(--gradient-primary);
            color: white;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }
        
        /* Form Styles */
        .form-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .form-control {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
        }
        
        /* Button Styles */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            border: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.3);
        }
        
        .btn-outline-primary {
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
        }
        
        .btn-outline-primary:hover {
            background: var(--dashen-primary);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff4757 0%, #e74c3c 100%);
            border: none;
        }
        
        /* Alert Styles */
        .alert {
            border-radius: 0.5rem;
            border: none;
            margin-bottom: 1.5rem;
        }
        
        /* Avatar Actions */
        .avatar-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
        }
        
        .password-input-group {
            position: relative;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
        }
        
        /* Loading Spinner */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
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
        
        /* Toast Notification */
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
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
            animation: slideInRight 0.3s ease;
        }
        
        .toast-success { border-left-color: #00d4aa; }
        .toast-error { border-left-color: #ff4757; }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    $_SESSION['current_page'] = 'profile.php';
    include 'sidebar.php'; 
    ?>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-user-cog me-2"></i>
                Profile Settings
            </h1>
            <p class="text-muted mb-0">Manage your account settings and preferences</p>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Profile Information Card -->
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar" data-bs-toggle="modal" data-bs-target="#avatarModal">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Profile Picture">
                            <?php else: ?>
                                <div style="width: 100%; height: 100%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 2.5rem;">
                                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="avatar-overlay">
                                <i class="fas fa-camera text-white fa-2x"></i>
                            </div>
                        </div>
                        <div class="profile-info">
                            <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                            <p><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($user['email']); ?></p>
                            <p><i class="fas fa-user-tag me-2"></i><?php echo htmlspecialchars($user['system_role']); ?></p>
                            <p><i class="fas fa-calendar-plus me-2"></i>Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></p>
                            <span class="role-badge">
                                <i class="fas fa-shield-alt me-1"></i>
                                <?php echo htmlspecialchars($user['system_role']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Profile Update Form -->
                    <form method="POST" onsubmit="return validateProfileForm()">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="fas fa-user-edit me-2"></i>
                                Basic Information
                            </h4>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h4 class="section-title">
                                <i class="fas fa-key me-2"></i>
                                Change Password
                            </h4>
                            <p class="text-muted mb-3">Leave blank to keep current password</p>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="current_password" name="current_password">
                                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="new_password" name="new_password">
                                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Minimum 6 characters</small>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password</label>
                                    <div class="password-input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-2"></i>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Account Status Card -->
                <div class="profile-card">
                    <h4 class="section-title">
                        <i class="fas fa-chart-line me-2"></i>
                        Account Status
                    </h4>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Account Verified</span>
                            <span class="badge bg-success">
                                <i class="fas fa-check-circle me-1"></i>
                                Verified
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Last Login</span>
                            <span class="text-dark">
                                <?php echo date('M j, Y H:i'); ?>
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Account Created</span>
                            <span class="text-dark">
                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h5 class="mb-3">Security Tips</h5>
                    <ul class="list-unstyled text-muted">
                        <li class="mb-2">
                            <i class="fas fa-shield-alt text-success me-2"></i>
                            Use a strong, unique password
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-sync-alt text-primary me-2"></i>
                            Update your password regularly
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-envelope text-warning me-2"></i>
                            Keep your email address updated
                        </li>
                        <li>
                            <i class="fas fa-user-secret text-danger me-2"></i>
                            Never share your login credentials
                        </li>
                    </ul>
                </div>
                
                <!-- Quick Actions Card -->
                <div class="profile-card">
                    <h4 class="section-title">
                        <i class="fas fa-bolt me-2"></i>
                        Quick Actions
                    </h4>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary text-start" data-bs-toggle="modal" data-bs-target="#avatarModal">
                            <i class="fas fa-camera me-2"></i>
                            Change Profile Picture
                        </button>
                        
                        <a href="change_management.php" class="btn btn-outline-primary text-start">
                            <i class="fas fa-exchange-alt me-2"></i>
                            View Change Requests
                        </a>
                        
                        <a href="approvals.php" class="btn btn-outline-primary text-start">
                            <i class="fas fa-shield-check me-2"></i>
                            Approvals Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Avatar Modal -->
    <div class="modal fade" id="avatarModal" tabindex="-1" aria-labelledby="avatarModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="avatarModalLabel">
                        <i class="fas fa-camera me-2"></i>
                        Profile Picture
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="avatarForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_profile_picture">
                        
                        <div class="text-center mb-4">
                            <div class="profile-avatar mx-auto" style="width: 150px; height: 150px;">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Current Avatar" id="currentAvatar">
                                <?php else: ?>
                                    <div id="avatarInitials" style="width: 100%; height: 100%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: white; font-size: 3rem;">
                                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="profile_picture" class="form-label">Upload New Picture</label>
                            <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/*">
                            <div class="form-text">
                                Supported formats: JPG, PNG, GIF (Max 2MB)
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <button type="button" class="btn btn-danger" onclick="removeProfilePicture()">
                                    <i class="fas fa-trash me-2"></i>
                                    Remove Picture
                                </button>
                            <?php endif; ?>
                            
                            <div>
                                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-upload me-2"></i>
                                    Upload
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show loading
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Hide loading
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
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
        
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Validate profile form
        function validateProfileForm() {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const currentPassword = document.getElementById('current_password').value;
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Validate required fields
            if (!username || !email) {
                showToast('Username and email are required.', 'error');
                return false;
            }
            
            // Validate email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showToast('Please enter a valid email address.', 'error');
                return false;
            }
            
            // Validate password change
            if (currentPassword || newPassword || confirmPassword) {
                if (!currentPassword) {
                    showToast('Current password is required to change password.', 'error');
                    return false;
                }
                
                if (!newPassword) {
                    showToast('New password is required.', 'error');
                    return false;
                }
                
                if (!confirmPassword) {
                    showToast('Please confirm your new password.', 'error');
                    return false;
                }
                
                if (newPassword !== confirmPassword) {
                    showToast('New passwords do not match.', 'error');
                    return false;
                }
                
                if (newPassword.length < 6) {
                    showToast('New password must be at least 6 characters long.', 'error');
                    return false;
                }
            }
            
            showLoading();
            return true;
        }
        
        // Handle avatar form submission
        document.getElementById('avatarForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const fileInput = document.getElementById('profile_picture');
            const file = fileInput.files[0];
            
            if (!file) {
                showToast('Please select a file to upload.', 'error');
                return;
            }
            
            // Validate file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!allowedTypes.includes(file.type)) {
                showToast('Only JPG, PNG, and GIF images are allowed.', 'error');
                return;
            }
            
            // Validate file size (2MB)
            if (file.size > 2 * 1024 * 1024) {
                showToast('Image size must be less than 2MB.', 'error');
                return;
            }
            
            showLoading();
            
            const formData = new FormData(this);
            formData.append('action', 'upload_profile_picture');
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                hideLoading();
                
                // Create a temporary div to parse the response
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                // Check for success/error messages
                const successAlert = tempDiv.querySelector('.alert-success');
                const errorAlert = tempDiv.querySelector('.alert-danger');
                
                if (successAlert) {
                    showToast(successAlert.textContent.trim(), 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else if (errorAlert) {
                    showToast(errorAlert.textContent.trim(), 'error');
                } else {
                    showToast('Profile picture updated successfully!', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                }
                
                // Close modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('avatarModal'));
                modal.hide();
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        });
        
        // Remove profile picture
        function removeProfilePicture() {
            if (!confirm('Are you sure you want to remove your profile picture?')) {
                return;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'remove_profile_picture');
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                hideLoading();
                
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                
                const successAlert = tempDiv.querySelector('.alert-success');
                const errorAlert = tempDiv.querySelector('.alert-danger');
                
                if (successAlert) {
                    showToast(successAlert.textContent.trim(), 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else if (errorAlert) {
                    showToast(errorAlert.textContent.trim(), 'error');
                }
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('avatarModal'));
                modal.hide();
            })
            .catch(error => {
                hideLoading();
                showToast('Network error. Please try again.', 'error');
                console.error('Error:', error);
            });
        }
        
        // Preview image before upload
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const currentAvatar = document.getElementById('currentAvatar');
                    const avatarInitials = document.getElementById('avatarInitials');
                    
                    if (currentAvatar) {
                        currentAvatar.src = e.target.result;
                    } else if (avatarInitials) {
                        avatarInitials.style.display = 'none';
                        if (!currentAvatar) {
                            const img = document.createElement('img');
                            img.id = 'currentAvatar';
                            img.src = e.target.result;
                            img.alt = 'Preview';
                            img.style.width = '100%';
                            img.style.height = '100%';
                            img.style.objectFit = 'cover';
                            avatarInitials.parentNode.appendChild(img);
                        }
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>