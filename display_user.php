<?php
session_start();
include 'db.php';

// Check if user is logged in and is super_admin
if (!isset($_SESSION['user_id']) || $_SESSION['system_role'] !== 'super_admin') {
    die('<div class="alert alert-danger">Access denied. Super Admin privileges required.</div>');
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle status toggle via AJAX - THIS MUST BE FIRST AND EXIT BEFORE ANY HTML OUTPUT
if (isset($_POST['toggle_status'])) {
    header('Content-Type: application/json');
    
    try {
        $toggle_id = intval($_POST['user_id']);
        $new_status = intval($_POST['new_status']);
        
        // Prevent deactivating own account
        if ($toggle_id == $user_id) {
            echo json_encode(['success' => false, 'message' => 'You cannot change your own account status!']);
            exit();
        }
        
        // Simple update - just update the users table
        $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $new_status, $toggle_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            throw new Exception("Error updating status: " . $stmt->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit(); // Important: Exit after sending JSON response
}

// Function to safely delete user with foreign key checks
function safeDeleteUser($conn, $delete_id, $current_user_id) {
    // Prevent self-deletion
    if ($delete_id == $current_user_id) {
        return ['success' => false, 'message' => "You cannot delete your own account!"];
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, delete from tables that have foreign key constraints
        // Order matters - delete child records before parent
        
        // 1. Delete from tables that directly reference users with ON DELETE CASCADE
        $cascade_tables = [
            'user_systems',
            'project_users',
            'activity_logs',
            'comments',
            'attachments',
            'risk_comments',
            'risk_history',
            'risk_attachments',
            'change_logs',
            'change_request_comments',
            'event_attendees',
            'user_assignments',
            'user_workload',
            'pm_notifications',
            'pm_notification_settings',
            'email_logs'
        ];
        
        foreach ($cascade_tables as $table) {
            $table_check = $conn->query("SHOW TABLES LIKE '$table'");
            if ($table_check && $table_check->num_rows > 0) {
                // Check if user_id column exists
                $column_check = $conn->query("SHOW COLUMNS FROM $table LIKE 'user_id'");
                if ($column_check && $column_check->num_rows > 0) {
                    $delete_sql = "DELETE FROM $table WHERE user_id = ?";
                    $stmt = $conn->prepare($delete_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 2. Handle notifications table specifically (since it has the error)
        $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($table_check && $table_check->num_rows > 0) {
            // Delete notifications where user_id matches
            $delete_sql = "DELETE FROM notifications WHERE user_id = ?";
            $stmt = $conn->prepare($delete_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Also delete notifications where action_by matches
            $delete_sql = "DELETE FROM notifications WHERE action_by = ?";
            $stmt = $conn->prepare($delete_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 3. Handle issues table
        $table_check = $conn->query("SHOW TABLES LIKE 'issues'");
        if ($table_check && $table_check->num_rows > 0) {
            // Set assigned_to to NULL
            $update_sql = "UPDATE issues SET assigned_to = NULL WHERE assigned_to = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Set created_by to NULL
            $update_sql = "UPDATE issues SET created_by = NULL WHERE created_by = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Set approved_by to NULL
            $update_sql = "UPDATE issues SET approved_by = NULL WHERE approved_by = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 4. Handle risks table
        $table_check = $conn->query("SHOW TABLES LIKE 'risks'");
        if ($table_check && $table_check->num_rows > 0) {
            $risk_columns = ['owner_user_id', 'identified_by', 'approved_by', 'created_by'];
            foreach ($risk_columns as $column) {
                $column_check = $conn->query("SHOW COLUMNS FROM risks LIKE '$column'");
                if ($column_check && $column_check->num_rows > 0) {
                    $update_sql = "UPDATE risks SET $column = NULL WHERE $column = ?";
                    $stmt = $conn->prepare($update_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 5. Handle risk_mitigations table
        $table_check = $conn->query("SHOW TABLES LIKE 'risk_mitigations'");
        if ($table_check && $table_check->num_rows > 0) {
            $mitigation_columns = ['owner_user_id', 'created_by', 'updated_by'];
            foreach ($mitigation_columns as $column) {
                $column_check = $conn->query("SHOW COLUMNS FROM risk_mitigations LIKE '$column'");
                if ($column_check && $column_check->num_rows > 0) {
                    $update_sql = "UPDATE risk_mitigations SET $column = NULL WHERE $column = ?";
                    $stmt = $conn->prepare($update_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 6. Handle change_requests table
        $table_check = $conn->query("SHOW TABLES LIKE 'change_requests'");
        if ($table_check && $table_check->num_rows > 0) {
            $cr_columns = ['requester_id', 'assigned_to_id'];
            foreach ($cr_columns as $column) {
                $column_check = $conn->query("SHOW COLUMNS FROM change_requests LIKE '$column'");
                if ($column_check && $column_check->num_rows > 0) {
                    $update_sql = "UPDATE change_requests SET $column = NULL WHERE $column = ?";
                    $stmt = $conn->prepare($update_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 7. Handle events table
        $table_check = $conn->query("SHOW TABLES LIKE 'events'");
        if ($table_check && $table_check->num_rows > 0) {
            $update_sql = "UPDATE events SET organizer_id = NULL WHERE organizer_id = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 8. Handle event_tasks table
        $table_check = $conn->query("SHOW TABLES LIKE 'event_tasks'");
        if ($table_check && $table_check->num_rows > 0) {
            $task_columns = ['assigned_to', 'created_by'];
            foreach ($task_columns as $column) {
                $column_check = $conn->query("SHOW COLUMNS FROM event_tasks LIKE '$column'");
                if ($column_check && $column_check->num_rows > 0) {
                    $update_sql = "UPDATE event_tasks SET $column = NULL WHERE $column = ?";
                    $stmt = $conn->prepare($update_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 9. Handle sub_activities table
        $table_check = $conn->query("SHOW TABLES LIKE 'sub_activities'");
        if ($table_check && $table_check->num_rows > 0) {
            $update_sql = "UPDATE sub_activities SET assigned_to = NULL WHERE assigned_to = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 10. Handle test_cases table
        $table_check = $conn->query("SHOW TABLES LIKE 'test_cases'");
        if ($table_check && $table_check->num_rows > 0) {
            $update_sql = "UPDATE test_cases SET created_by = NULL WHERE created_by = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // 11. Handle budget_items table
        $table_check = $conn->query("SHOW TABLES LIKE 'budget_items'");
        if ($table_check && $table_check->num_rows > 0) {
            $budget_columns = ['created_by', 'approved_by'];
            foreach ($budget_columns as $column) {
                $column_check = $conn->query("SHOW COLUMNS FROM budget_items LIKE '$column'");
                if ($column_check && $column_check->num_rows > 0) {
                    $update_sql = "UPDATE budget_items SET $column = NULL WHERE $column = ?";
                    $stmt = $conn->prepare($update_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $delete_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }
        
        // 12. Handle actual_expenses table
        $table_check = $conn->query("SHOW TABLES LIKE 'actual_expenses'");
        if ($table_check && $table_check->num_rows > 0) {
            $update_sql = "UPDATE actual_expenses SET approved_by = NULL WHERE approved_by = ?";
            $stmt = $conn->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Finally, delete the user
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing delete statement");
        }
        
        $stmt->bind_param("i", $delete_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Error deleting user: " . $stmt->error);
        }
        
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        return ['success' => true, 'message' => "User deleted successfully!"];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        return ['success' => false, 'message' => "Error deleting user: " . $e->getMessage()];
    }
}

// Handle user deletion
if (isset($_POST['delete_user'])) {
    $delete_id = $_POST['user_id'];
    $result = safeDeleteUser($conn, $delete_id, $user_id);
    $message = $result['message'];
    $message_type = $result['success'] ? 'success' : 'error';
}

// Handle user update
if (isset($_POST['update_user'])) {
    $update_id = $_POST['user_id'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $system_role = $_POST['system_role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Prevent super admin from deactivating themselves
    if ($update_id == $user_id && $is_active == 0) {
        $message = "You cannot deactivate your own account!";
        $message_type = "error";
    } else {
        // Check if username already exists for other users
        $check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check_username->bind_param("si", $username, $update_id);
        $check_username->execute();
        $username_result = $check_username->get_result();
        
        // Check if email already exists for other users
        $check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $update_id);
        $check_email->execute();
        $email_result = $check_email->get_result();
        
        if ($username_result->num_rows > 0) {
            $message = "Username already exists. Please choose another.";
            $message_type = "error";
        } elseif ($email_result->num_rows > 0) {
            $message = "Email already exists. Please use another email address.";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, system_role = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("sssii", $username, $email, $system_role, $is_active, $update_id);
            
            if ($stmt->execute()) {
                $message = "User updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating user: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        }
        $check_username->close();
        $check_email->close();
    }
}

// Handle user registration
if (isset($_POST['register_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $system_role = $_POST['system_role'];
    $is_active = isset($_POST['is_active']) ? 1 : 1; // Default to active for new users
    $selected_systems = $_POST['systems'] ?? [];

    // Validate inputs
    $errors = [];
    
    // Check if username already exists
    $check_username = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check_username->bind_param("s", $username);
    $check_username->execute();
    $username_result = $check_username->get_result();
    
    // Check if email already exists
    $check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check_email->bind_param("s", $email);
    $check_email->execute();
    $email_result = $check_email->get_result();
    
    if ($username_result->num_rows > 0) {
        $errors[] = "Username already exists. Please choose another.";
    }
    
    if ($email_result->num_rows > 0) {
        $errors[] = "Email already exists. Please use another email address.";
    }
    
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert user
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, system_role, is_active) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $username, $email, $password, $system_role, $is_active);
            
            if (!$stmt->execute()) {
                throw new Exception("Registration failed: " . $stmt->error);
            }
            
            $new_user_id = $stmt->insert_id;
            $stmt->close();

            // Insert into user_systems if table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'user_systems'");
            if ($table_check && $table_check->num_rows > 0 && !empty($selected_systems)) {
                $sys_stmt = $conn->prepare("INSERT INTO user_systems (user_id, system_id) VALUES (?, ?)");
                if ($sys_stmt) {
                    foreach ($selected_systems as $system_id) {
                        $sys_stmt->bind_param("ii", $new_user_id, $system_id);
                        $sys_stmt->execute();
                    }
                    $sys_stmt->close();
                }
            }
            
            // Commit transaction
            $conn->commit();
            $message = "User registered successfully!";
            $message_type = "success";
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = implode("\\n", $errors);
        $message_type = "error";
    }
    
    $check_username->close();
    $check_email->close();
}

// Fetch all users with is_active field
$users_stmt = $conn->prepare("SELECT id, username, email, system_role, is_active FROM users ORDER BY id DESC");
$users_stmt->execute();
$users_result = $users_stmt->get_result();

// Fetch available systems for selection (if table exists)
$systems_result = null;
$table_check = $conn->query("SHOW TABLES LIKE 'systems'");
if ($table_check && $table_check->num_rows > 0) {
    $systems_result = $conn->query("SELECT system_id, system_name FROM systems ORDER BY system_name");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Dashen Bank</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- SweetAlert2 for beautiful alerts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            margin-bottom: 25px;
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
        
        /* Navigation Bar */
        .navbar-custom {
            background: var(--dashen-primary);
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-radius: 10px;
            margin-bottom: 20px;
            padding: 1rem 1.5rem;
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
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
        
        .badge-super_admin { 
            background-color: #ffebee; 
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        
        .badge-admin { 
            background-color: #e3f2fd; 
            color: #1565c0;
            border: 1px solid #bbdefb;
        }
        
        .badge-tester { 
            background-color: #e8f5e8; 
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        
        .badge-viewer { 
            background-color: #fff3e0; 
            color: #ef6c00;
            border: 1px solid #ffe0b2;
        }
        
        .badge-pm_manager { 
            background-color: #f3e5f5; 
            color: #7b1fa2;
            border: 1px solid #e1bee7;
        }
        
        .badge-pm_employee { 
            background-color: #e0f2f1; 
            color: #00695c;
            border: 1px solid #b2dfdb;
        }
        
        .badge-pm_viewer { 
            background-color: #fff3e0; 
            color: #ef6c00;
            border: 1px solid #ffe0b2;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 6px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .status-badge.active {
            background: linear-gradient(135deg, #1cc88a, #169b6b);
            color: white;
        }
        
        .status-badge.inactive {
            background: linear-gradient(135deg, #e74a3b, #bd2130);
            color: white;
        }
        
        /* Table Styles */
        .table-custom th {
            background-color: #f8f9fc;
            border-bottom: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
            font-weight: 600;
        }
        
        .user-row:hover {
            background-color: rgba(39, 50, 116, 0.05);
            transition: background-color 0.2s ease;
        }
        
        .current-user {
            background-color: rgba(60, 76, 158, 0.1);
            border-left: 4px solid var(--dashen-primary);
        }
        
        /* Action Buttons */
        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            border: none;
            margin: 0 3px;
            cursor: pointer;
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #4dabf7, #1971c2);
            color: white;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b, #c92a2a);
            color: white;
        }
        
        .btn-view {
            background: linear-gradient(135deg, #51cf66, #2b8a3e);
            color: white;
        }
        
        .btn-add-user {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-add-user:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.4);
            color: white;
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
        
        /* Modal Styles */
        .modal-header-custom {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            border-bottom: none;
            padding: 1.25rem;
        }
        
        .modal-content {
            border-radius: 15px;
            border: none;
        }
        
        .user-avatar-modal {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 15px;
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.3);
        }
        
        .user-details-modal {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-secondary);
            box-shadow: 0 0 0 0.2rem rgba(30, 90, 245, 0.25);
        }
        
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }
        
        .role-badge-option {
            padding: 0.75rem;
            border-radius: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
            text-align: center;
            margin-bottom: 10px;
        }
        
        .role-badge-option:hover {
            border-color: var(--dashen-secondary);
            transform: translateY(-2px);
        }
        
        .role-badge-option.selected {
            border-color: var(--dashen-primary);
            background-color: rgba(39, 50, 116, 0.1);
        }
        
        /* DataTables Customization */
        .dataTables_filter input {
            border-radius: 50px !important;
            padding: 0.5rem 1rem !important;
            border: 1px solid #dee2e6 !important;
        }
        
        .dataTables_filter input:focus {
            border-color: var(--dashen-secondary) !important;
            box-shadow: 0 0 0 0.2rem rgba(30, 90, 245, 0.25) !important;
        }
        
        .dataTables_length select {
            border-radius: 10px !important;
            border: 1px solid #dee2e6 !important;
            padding: 0.375rem 1.5rem 0.375rem 0.75rem !important;
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
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
            transition: all 0.3s ease;
            margin-bottom: 20px;
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
            <i class="fas fa-users-cog"></i>
            User Management & Access Control
        </h1>
        <div class="user-info">
            <!-- Profile Dropdown -->
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-trigger" onclick="toggleProfileDropdown()">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?>
                    </div>
                    <div class="profile-info">
                        <span class="profile-name"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
                        <span class="profile-role"><?= ucfirst(str_replace('_', ' ', $_SESSION['system_role'] ?? 'User')) ?></span>
                    </div>
                    <i class="fas fa-chevron-down" style="font-size: 0.8rem; opacity: 0.8;"></i>
                </div>
                
                <!-- Dropdown Menu -->
                <div class="dropdown-menu-custom" id="profileDropdownMenu">
                    <div class="dropdown-header">
                        <div class="dropdown-user">
                            <div class="dropdown-avatar">
                                <?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?>
                            </div>
                            <div class="dropdown-user-info">
                                <h6><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></h6>
                                <small><?= htmlspecialchars($_SESSION['email'] ?? '') ?></small>
                                <div class="mt-2">
                                    <span class="role-badge badge-<?= str_replace('_', '-', $_SESSION['system_role'] ?? 'viewer') ?>">
                                        <?= ucwords(str_replace('_', ' ', $_SESSION['system_role'] ?? 'User')) ?>
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
        <a href="dashboard.php" class="back-link fade-in-up">
            <i class="fas fa-arrow-left"></i>Back to Dashboard
        </a>

        <!-- Welcome Banner -->
        <div class="welcome-banner d-flex justify-content-between align-items-center fade-in-up">
            <div>
                <h1 class="display-6"><i class="fas fa-users me-2"></i>User Management</h1>
                <p class="lead mb-0">View, add, edit, and manage all system users</p>
            </div>
            <button class="btn btn-add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i>Add New User
            </button>
        </div>

        <!-- Stats Overview -->
        <div class="row mb-4 fade-in-up delay-1">
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-users fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number"><?= $users_result->num_rows ?></div>
                    <div class="stats-label">Total Users</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-check-circle fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number">
                        <?php 
                        $active_count = 0;
                        $users_result->data_seek(0);
                        while ($user = $users_result->fetch_assoc()) {
                            if (isset($user['is_active']) && $user['is_active'] == 1) $active_count++;
                        }
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stats-label">Active Users</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card">
                    <i class="fas fa-user-slash fa-2x" style="color: var(--dashen-primary); opacity: 0.5;"></i>
                    <div class="stats-number"><?= $users_result->num_rows - $active_count ?></div>
                    <div class="stats-label">Inactive Users</div>
                </div>
            </div>
        </div>

        <!-- Users Table Card -->
        <div class="card card-custom fade-in-up delay-2">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-list me-2"></i>Registered Users</h3>
                <span class="badge bg-light text-dark">Total: <?= $users_result->num_rows ?></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $users_result->data_seek(0);
                            while ($user = $users_result->fetch_assoc()): 
                                $is_active = isset($user['is_active']) ? $user['is_active'] : 1;
                                $email = isset($user['email']) ? $user['email'] : 'N/A';
                                $is_current_user = ($user['id'] == $user_id);
                            ?>
                                <tr class="user-row <?= $is_current_user ? 'current-user' : '' ?>" data-user-id="<?= $user['id'] ?>">
                                    <td class="fw-bold">#<?= str_pad($user['id'], 3, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="profile-avatar" style="width: 40px; height: 40px; font-size: 1rem; margin-right: 10px; background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); color: white;">
                                                <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($user['username']) ?></strong>
                                                <?php if ($is_current_user): ?>
                                                    <span class="badge bg-info ms-2">You</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($email) ?></td>
                                    <td>
                                        <span class="role-badge badge-<?= str_replace('_', '-', $user['system_role']) ?>">
                                            <i class="fas <?= $user['system_role'] == 'super_admin' ? 'fa-crown' : 
                                               ($user['system_role'] == 'admin' ? 'fa-user-shield' : 
                                               ($user['system_role'] == 'tester' ? 'fa-flask' : 
                                               ($user['system_role'] == 'pm_manager' ? 'fa-tasks' :
                                               ($user['system_role'] == 'pm_employee' ? 'fa-user-tie' : 
                                               ($user['system_role'] == 'pm_viewer' ? 'fa-clipboard-list' : 'fa-eye'))))) ?>"></i>
                                            <?= ucwords(str_replace('_', ' ', $user['system_role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_current_user): ?>
                                            <span class="status-badge active">
                                                <i class="fas fa-check-circle"></i> Active (You)
                                            </span>
                                        <?php else: ?>
                                            <div class="status-toggle" onclick="toggleStatus(<?= $user['id'] ?>, <?= $is_active ? 0 : 1 ?>)">
                                                <span class="status-badge <?= $is_active ? 'active' : 'inactive' ?>">
                                                    <i class="fas <?= $is_active ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                    <?= $is_active ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-2">
                                            <!-- View Button -->
                                            <button class="action-btn btn-view" title="View User" 
                                                    onclick="viewUser(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= htmlspecialchars($email) ?>', '<?= $user['system_role'] ?>', <?= $is_active ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Edit Button (disabled for self if trying to deactivate) -->
                                            <button class="action-btn btn-edit" title="Edit User" 
                                                    onclick="editUser(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>', '<?= htmlspecialchars($email) ?>', '<?= $user['system_role'] ?>', <?= $is_active ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <!-- Delete Button (disabled for self) -->
                                            <?php if (!$is_current_user): ?>
                                                <button class="action-btn btn-delete" title="Delete User"
                                                        onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Register New User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="addUserForm" onsubmit="return validateAddForm()">
                    <div class="modal-body">
                        <!-- Username -->
                        <div class="mb-3">
                            <label for="addUsername" class="form-label">
                                <i class="fas fa-user me-2"></i>Username
                            </label>
                            <input type="text" class="form-control" id="addUsername" name="username" 
                                   placeholder="Enter username" required minlength="3" maxlength="50">
                            <div class="invalid-feedback">Username must be at least 3 characters long.</div>
                        </div>

                        <!-- Email -->
                        <div class="mb-3">
                            <label for="addEmail" class="form-label">
                                <i class="fas fa-envelope me-2"></i>Email
                            </label>
                            <input type="email" class="form-control" id="addEmail" name="email" 
                                   placeholder="Enter email" required>
                            <div class="invalid-feedback">Please enter a valid email address.</div>
                        </div>

                        <!-- Password -->
                        <div class="mb-3 position-relative">
                            <label for="addPassword" class="form-label">
                                <i class="fas fa-lock me-2"></i>Password
                            </label>
                            <input type="password" class="form-control" id="addPassword" name="password" 
                                   placeholder="Enter password" required minlength="8">
                            <i class="fas fa-eye password-toggle" onclick="togglePassword('addPassword')"></i>
                            <div class="invalid-feedback">Password must be at least 8 characters long.</div>
                        </div>

                        <!-- Role Selection -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-user-tag me-2"></i>User Role
                            </label>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('super_admin')">
                                        <input type="radio" name="system_role" id="roleSuperAdmin" value="super_admin" class="d-none" required>
                                        <span class="role-badge badge-super_admin">
                                            <i class="fas fa-crown"></i> Super Admin
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('admin')">
                                        <input type="radio" name="system_role" id="roleAdmin" value="admin" class="d-none">
                                        <span class="role-badge badge-admin">
                                            <i class="fas fa-user-shield"></i> Admin
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('tester')">
                                        <input type="radio" name="system_role" id="roleTester" value="tester" class="d-none">
                                        <span class="role-badge badge-tester">
                                            <i class="fas fa-flask"></i> Tester
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('viewer')">
                                        <input type="radio" name="system_role" id="roleViewer" value="viewer" class="d-none">
                                        <span class="role-badge badge-viewer">
                                            <i class="fas fa-eye"></i> Viewer
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('pm_manager')">
                                        <input type="radio" name="system_role" id="rolePmManager" value="pm_manager" class="d-none">
                                        <span class="role-badge badge-pm_manager">
                                            <i class="fas fa-tasks"></i> PM Manager
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('pm_employee')">
                                        <input type="radio" name="system_role" id="rolePmEmployee" value="pm_employee" class="d-none">
                                        <span class="role-badge badge-pm_employee">
                                            <i class="fas fa-user-tie"></i> PM Employee
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="role-badge-option" onclick="selectRole('pm_viewer')">
                                        <input type="radio" name="system_role" id="rolePmViewer" value="pm_viewer" class="d-none">
                                        <span class="role-badge badge-pm_viewer">
                                            <i class="fas fa-clipboard-list"></i> PM Viewer
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Access (if systems table exists) -->
                        <?php if ($systems_result && $systems_result->num_rows > 0): ?>
                        <div class="mb-3">
                            <label for="addSystems" class="form-label">
                                <i class="fas fa-server me-2"></i>System Access
                            </label>
                            <select multiple class="form-control" id="addSystems" name="systems[]" size="4">
                                <?php 
                                $systems_result->data_seek(0);
                                while ($row = $systems_result->fetch_assoc()): 
                                ?>
                                    <option value="<?= $row['system_id'] ?>"><?= htmlspecialchars($row['system_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <div class="form-text">Hold CTRL (Windows) or CMD (Mac) to select multiple systems</div>
                        </div>
                        <?php endif; ?>

                        <!-- Active Status -->
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="addActive" name="is_active" value="1" checked>
                                <label class="form-check-label" for="addActive">
                                    <i class="fas fa-check-circle text-success me-1"></i>Active User
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" name="register_user" class="btn btn-primary" style="background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); border: none;">
                            <i class="fas fa-user-plus me-2"></i>Register User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editUserForm" onsubmit="return validateEditForm()">
                    <div class="modal-body">
                        <div class="user-details-modal text-center">
                            <div class="user-avatar-modal" id="editUserAvatar"></div>
                            <h5 id="editUserName" class="mb-2"></h5>
                            <p class="text-muted mb-0">User ID: <span id="editUserId"></span></p>
                        </div>
                        
                        <input type="hidden" name="user_id" id="editUserInput">
                        <input type="hidden" name="update_user" value="1">
                        
                        <div class="mb-3">
                            <label for="editUsername" class="form-label">Username</label>
                            <input type="text" class="form-control" id="editUsername" name="username" required minlength="3">
                        </div>
                        
                        <div class="mb-3">
                            <label for="editEmail" class="form-label">Email</label>
                            <input type="email" class="form-control" id="editEmail" name="email" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editRole" class="form-label">Role</label>
                            <select class="form-select" id="editRole" name="system_role" required>
                                <option value="super_admin">Super Admin</option>
                                <option value="admin">Admin</option>
                                <option value="tester">Tester</option>
                                <option value="viewer">Viewer</option>
                                <option value="pm_manager">PM Manager</option>
                                <option value="pm_employee">PM Employee</option>
                                <option value="pm_viewer">PM Viewer</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="editActive" name="is_active" value="1">
                                <label class="form-check-label" for="editActive">
                                    <i class="fas fa-check-circle text-success me-1"></i>Active User
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary)); border: none;">
                            <i class="fas fa-save me-2"></i>Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Deletion
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="user-details-modal text-center">
                        <div class="user-avatar-modal" id="deleteUserAvatar"></div>
                        <h5 id="deleteUserName" class="mb-2"></h5>
                        <p class="text-muted mb-0">User ID: <span id="deleteUserId"></span></p>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone. 
                        All associated data will be deleted or set to NULL.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <form method="POST" id="deleteUserForm" style="display: inline;">
                        <input type="hidden" name="user_id" id="deleteUserInput">
                        <input type="hidden" name="delete_user" value="1">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash-alt me-2"></i>Delete User
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>User Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="user-details-modal text-center">
                        <div class="user-avatar-modal" id="viewUserAvatar"></div>
                        <h4 id="viewUserName" class="mb-2"></h4>
                        <p class="text-muted mb-0">User ID: <span id="viewUserId"></span></p>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <h6 class="card-title text-muted">Email</h6>
                                    <p class="card-text" id="viewUserEmail"></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <h6 class="card-title text-muted">Role</h6>
                                    <p class="card-text" id="viewUserRole"></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title text-muted">Status</h6>
                                    <p class="card-text" id="viewUserStatus"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

        // Handle sidebar toggle synchronization
        document.addEventListener('DOMContentLoaded', function() {
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
        });

        // Initialize DataTable
        $(document).ready(function() {
            $('#usersTable').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
                order: [[0, 'desc']],
                language: {
                    search: "<i class='fas fa-search'></i> Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ users",
                    paginate: {
                        first: '<i class="fas fa-angle-double-left"></i>',
                        previous: '<i class="fas fa-angle-left"></i>',
                        next: '<i class="fas fa-angle-right"></i>',
                        last: '<i class="fas fa-angle-double-right"></i>'
                    }
                }
            });
        });

        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = event.currentTarget;
            
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

        // Select role in add form
        function selectRole(role) {
            // Remove selected class from all options
            document.querySelectorAll('.role-badge-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            // Add selected class to clicked option
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            const radioId = 'role' + role.split('_').map(word => 
                word.charAt(0).toUpperCase() + word.slice(1)
            ).join('');
            document.getElementById(radioId).checked = true;
        }

        // Validate add form
        function validateAddForm() {
            const username = document.getElementById('addUsername').value.trim();
            const email = document.getElementById('addEmail').value.trim();
            const password = document.getElementById('addPassword').value;
            const role = document.querySelector('input[name="system_role"]:checked');
            
            let isValid = true;
            let errorMessage = '';
            
            if (username.length < 3) {
                errorMessage = 'Username must be at least 3 characters long.';
                showError('addUsername', errorMessage);
                isValid = false;
            }
            
            if (!isValidEmail(email)) {
                errorMessage = 'Please enter a valid email address.';
                showError('addEmail', errorMessage);
                isValid = false;
            }
            
            if (password.length < 8) {
                errorMessage = 'Password must be at least 8 characters long.';
                showError('addPassword', errorMessage);
                isValid = false;
            }
            
            if (!role) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Role Required',
                    text: 'Please select a user role.',
                    confirmButtonColor: '#273274'
                });
                isValid = false;
            }
            
            return isValid;
        }

        // Validate edit form
        function validateEditForm() {
            const username = document.getElementById('editUsername').value.trim();
            const email = document.getElementById('editEmail').value.trim();
            
            if (username.length < 3) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Validation Error',
                    text: 'Username must be at least 3 characters long.',
                    confirmButtonColor: '#273274'
                });
                return false;
            }
            
            if (!isValidEmail(email)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Validation Error',
                    text: 'Please enter a valid email address.',
                    confirmButtonColor: '#273274'
                });
                return false;
            }
            
            return true;
        }

        // Email validation helper
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Show error message
        function showError(elementId, message) {
            const element = document.getElementById(elementId);
            element.classList.add('is-invalid');
            const feedback = element.nextElementSibling;
            if (feedback && feedback.classList.contains('invalid-feedback')) {
                feedback.textContent = message;
            }
        }

        // Clear form errors on input
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        });

        // Edit user function
        function editUser(id, username, email, role, isActive) {
            document.getElementById('editUserInput').value = id;
            document.getElementById('editUserName').textContent = username;
            document.getElementById('editUserId').textContent = id;
            document.getElementById('editUserAvatar').textContent = username.charAt(0).toUpperCase();
            document.getElementById('editUsername').value = username;
            document.getElementById('editEmail').value = email;
            document.getElementById('editRole').value = role;
            document.getElementById('editActive').checked = isActive === 1;
            
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        }

        // Delete user function
        function deleteUser(id, username) {
            document.getElementById('deleteUserInput').value = id;
            document.getElementById('deleteUserName').textContent = username;
            document.getElementById('deleteUserId').textContent = id;
            document.getElementById('deleteUserAvatar').textContent = username.charAt(0).toUpperCase();
            
            new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
        }

        // View user function
        function viewUser(id, username, email, role, isActive) {
            document.getElementById('viewUserName').textContent = username;
            document.getElementById('viewUserId').textContent = id;
            document.getElementById('viewUserEmail').textContent = email;
            document.getElementById('viewUserAvatar').textContent = username.charAt(0).toUpperCase();
            
            // Format role name
            const roleName = role.replace(/_/g, ' ');
            const roleClass = role.replace(/_/g, '-');
            document.getElementById('viewUserRole').innerHTML = 
                `<span class="role-badge badge-${roleClass}">${roleName.charAt(0).toUpperCase() + roleName.slice(1)}</span>`;
            
            // Format status
            const statusText = isActive ? 'Active' : 'Inactive';
            const statusClass = isActive ? 'active' : 'inactive';
            document.getElementById('viewUserStatus').innerHTML = 
                `<span class="status-badge ${statusClass}">${statusText}</span>`;
            
            new bootstrap.Modal(document.getElementById('viewUserModal')).show();
        }

        // Toggle status via AJAX
        function toggleStatus(userId, newStatus) {
            Swal.fire({
                title: 'Change Status?',
                text: `Are you sure you want to ${newStatus ? 'activate' : 'deactivate'} this user?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: newStatus ? '#1cc88a' : '#e74a3b',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, change it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading state
                    Swal.fire({
                        title: 'Updating...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    $.ajax({
                        url: window.location.href,
                        method: 'POST',
                        data: {
                            toggle_status: 1,
                            user_id: userId,
                            new_status: newStatus
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Updated!',
                                    text: response.message,
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: response.message
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            console.log('Response:', xhr.responseText); // For debugging
                            
                            // Check if the response is HTML (error page)
                            if (xhr.responseText && xhr.responseText.includes('<!DOCTYPE')) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Server Error',
                                    text: 'The server returned an HTML error page. Please check the error logs or contact support.'
                                });
                            } else {
                                try {
                                    // Try to parse as JSON first
                                    const response = JSON.parse(xhr.responseText);
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error!',
                                        text: response.message || 'An error occurred while updating status.'
                                    });
                                } catch(e) {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error!',
                                        text: 'An error occurred while updating status: ' + error
                                    });
                                }
                            }
                        }
                    });
                }
            });
        }

        // Display success/error messages using SweetAlert
        <?php if (!empty($message)): ?>
        Swal.fire({
            icon: '<?= $message_type === "success" ? "success" : "error" ?>',
            title: '<?= $message_type === "success" ? "Success!" : "Error!" ?>',
            text: '<?= htmlspecialchars($message, ENT_QUOTES) ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>
    </script>
</body>
</html>