<?php
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username']);
$role = $_SESSION['system_role'] ?? 'viewer';

// Configure PHPMailer path - Update this according to your installation
$phpmailer_path = __DIR__ . '/PHPMailer/';
if (!file_exists($phpmailer_path . 'PHPMailer.php')) {
    // Try alternative path
    $phpmailer_path = __DIR__ . '/vendor/phpmailer/phpmailer/';
}

// Function to send email notifications
function sendEmailNotification($toEmail, $toName, $subject, $message, $testCaseInfo = []) {
    global $phpmailer_path;
    
    // Check if PHPMailer files exist
    $required_files = [
        $phpmailer_path . 'PHPMailer.php',
        $phpmailer_path . 'SMTP.php',
        $phpmailer_path . 'Exception.php'
    ];
    
    foreach ($required_files as $file) {
        if (!file_exists($file)) {
            error_log("PHPMailer file not found: $file");
            return false;
        }
    }
    
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
    require_once $phpmailer_path . 'Exception.php';
    
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings - UPDATE THESE WITH YOUR EMAIL CONFIGURATION
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';  // Your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'your_email@gmail.com';  // Your email
        $mail->Password = 'your_app_password';      // Your app password
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Sender
        $mail->setFrom('your_email@gmail.com', 'Test Management System');
        
        // Recipient
        $mail->addAddress($toEmail, $toName);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Create email body
        $emailBody = '<!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #273274; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 20px; }
                .testcase-info { background: white; padding: 15px; border-left: 4px solid #273274; margin: 15px 0; }
                .btn { display: inline-block; background: #273274; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Test Management System Notification</h2>
                </div>
                <div class="content">
                    ' . $message . '
                    
                    <div class="testcase-info">
                        <h3>Test Case Details:</h3>
                        <p><strong>Title:</strong> ' . htmlspecialchars($testCaseInfo['title']) . '</p>
                        <p><strong>Project:</strong> ' . htmlspecialchars($testCaseInfo['project_name']) . '</p>';
        
        if (!empty($testCaseInfo['comment'])) {
            $emailBody .= '<p><strong>Comment:</strong> ' . nl2br(htmlspecialchars($testCaseInfo['comment'])) . '</p>';
        }
        if (!empty($testCaseInfo['remark'])) {
            $emailBody .= '<p><strong>Remark:</strong> ' . nl2br(htmlspecialchars($testCaseInfo['remark'])) . '</p>';
        }
        
        $emailBody .= '</div>
                    <p>
                        <a href="http://localhost/test-manager/TC_assigned_projects.php?page=view_project&id=' . $testCaseInfo['project_id'] . '&highlight_testcase=' . $testCaseInfo['id'] . '" class="btn">
                            View Test Case
                        </a>
                    </p>
                    <div class="footer">
                        <p>This is an automated notification from the Test Management System.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $emailBody;
        $mail->AltBody = strip_tags($emailBody);
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email Error: " . $e->getMessage());
        return false;
    }
}

// ========== HANDLE AJAX REQUESTS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_testcases_bulk') {
            // Add multiple test cases at once
            header('Content-Type: application/json');
            
            $project_id = intval($_POST['project_id']);
            $feature_id = intval($_POST['feature_id']);
            $titles = $_POST['titles'] ?? [];
            $steps_array = $_POST['steps_array'] ?? [];
            $expected_array = $_POST['expected_array'] ?? [];
            $statuses = $_POST['statuses'] ?? [];
            $priorities = $_POST['priorities'] ?? [];
            $frequencies = $_POST['frequencies'] ?? [];
            $channels = $_POST['channels'] ?? [];
            $tester_remarks = $_POST['tester_remarks'] ?? [];
            $vendor_comments = $_POST['vendor_comments'] ?? [];
            
            // First check if project is terminated
            $project_check = $conn->prepare("SELECT name, status FROM projects WHERE id = ?");
            $project_check->bind_param("i", $project_id);
            $project_check->execute();
            $project_result = $project_check->get_result();
            $project = $project_result->fetch_assoc();
            
            if (!$project) {
                $response = ['success' => false, 'message' => 'Project not found'];
                echo json_encode($response);
                exit;
            }
            
            if ($project['status'] === 'terminated') {
                $response = [
                    'success' => false, 
                    'message' => 'Cannot add test cases. This project "' . $project['name'] . '" is already terminated.'
                ];
                echo json_encode($response);
                exit;
            }
            
            if (empty($titles) || empty($feature_id) || empty($project_id)) {
                $response = ['success' => false, 'message' => 'Please fill in all required fields'];
                echo json_encode($response);
                exit;
            }
            
            // Check if user has access to this project via user_assignments table
            $access_check = $conn->prepare("SELECT id FROM user_assignments WHERE project_id = ? AND user_id = ? AND is_active = 1");
            $access_check->bind_param("ii", $project_id, $user_id);
            $access_check->execute();
            $access_result = $access_check->get_result();
            
            if ($access_result->num_rows === 0 && $role !== 'super_admin') {
                $response = ['success' => false, 'message' => 'Access denied. You are not assigned to this project.'];
                echo json_encode($response);
                exit;
            }
            
            $conn->begin_transaction();
            $success_count = 0;
            $testcase_ids = [];
            
            for ($i = 0; $i < count($titles); $i++) {
                $title = trim($titles[$i]);
                $steps = trim($steps_array[$i] ?? '');
                $expected = trim($expected_array[$i] ?? '');
                $status = $statuses[$i] ?? 'Pending';
                $priority = $priorities[$i] ?? 'Medium';
                $frequency = trim($frequencies[$i] ?? '');
                $channel = trim($channels[$i] ?? '');
                $tester_remark = trim($tester_remarks[$i] ?? '');
                $vendor_comment = trim($vendor_comments[$i] ?? '');
                
                if (!empty($title) && !empty($steps) && !empty($expected)) {
                    $stmt = $conn->prepare("
                        INSERT INTO test_cases (project_id, feature_id, title, steps, expected, status, priority, frequency, channel, tester_remark, vendor_comment, created_by, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param("iisssssssssi", $project_id, $feature_id, $title, $steps, $expected, $status, $priority, $frequency, $channel, $tester_remark, $vendor_comment, $user_id);
                    
                    if ($stmt->execute()) {
                        $testcase_id = $stmt->insert_id;
                        $testcase_ids[] = $testcase_id;
                        $success_count++;
                        
                        // Send notification to project users (excluding current user)
                        $notify_stmt = $conn->prepare("
                            SELECT DISTINCT ua.user_id 
                            FROM user_assignments ua 
                            WHERE ua.project_id = ? AND ua.user_id != ?
                        ");
                        $notify_stmt->bind_param("ii", $project_id, $user_id);
                        $notify_stmt->execute();
                        $user_result = $notify_stmt->get_result();
                        
                        while ($user = $user_result->fetch_assoc()) {
                            $activity_log = $conn->prepare("
                                INSERT INTO activity_logs (user_id, action, description, test_case_id, project_id, created_at) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $activity_action = "Test Case Created";
                            $activity_desc = "New test case '$title' has been created";
                            $activity_log->bind_param("issii", $user['user_id'], $activity_action, $activity_desc, $testcase_id, $project_id);
                            $activity_log->execute();
                        }
                    }
                }
            }
            
            if ($success_count > 0) {
                $conn->commit();
                $response = [
                    'success' => true, 
                    'message' => $success_count . ' test case(s) added successfully!',
                    'testcase_ids' => $testcase_ids
                ];
            } else {
                $conn->rollback();
                $response = ['success' => false, 'message' => 'Failed to add test cases'];
            }
            
            echo json_encode($response);
            exit;
        }
        
        // Check project status for add test case button click
        if ($action === 'check_project_status') {
            header('Content-Type: application/json');
            $project_id = intval($_POST['project_id']);
            
            $stmt = $conn->prepare("SELECT id, name, status FROM projects WHERE id = ?");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $project = $result->fetch_assoc();
            
            if (!$project) {
                $response = ['success' => false, 'message' => 'Project not found'];
            } else {
                $response = [
                    'success' => true,
                    'project' => $project
                ];
            }
            
            echo json_encode($response);
            exit;
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
        echo json_encode($response);
        exit;
    }
}

// Handle regular AJAX requests for notifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    try {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'mark_notification_read':
                $notification_id = intval($_POST['notification_id']);
                $notification_type = $_POST['notification_type'] ?? 'activity';
                
                if ($notification_type === 'activity') {
                    $stmt = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE id = ?");
                    $stmt->bind_param("i", $notification_id);
                } else {
                    $stmt = $conn->prepare("UPDATE tester_remark_logs SET is_read = 1 WHERE id = ?");
                    $stmt->bind_param("i", $notification_id);
                }
                
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Notification marked as read'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark notification as read'];
                }
                break;
                
            case 'mark_all_notifications_read':
                if ($role === 'super_admin' || $role === 'pm_manager') {
                    $stmt1 = $conn->prepare("UPDATE activity_logs SET is_read = 1 WHERE is_read = 0");
                    $stmt2 = $conn->prepare("UPDATE tester_remark_logs SET is_read = 1 WHERE is_read = 0");
                } else {
                    $stmt1 = $conn->prepare("UPDATE activity_logs al 
                                           JOIN test_cases tc ON al.test_case_id = tc.id 
                                           JOIN user_assignments ua ON tc.project_id = ua.project_id 
                                           SET al.is_read = 1 
                                           WHERE al.is_read = 0 AND ua.user_id = ?");
                    $stmt1->bind_param("i", $user_id);
                    
                    $stmt2 = $conn->prepare("UPDATE tester_remark_logs trl 
                                           JOIN test_cases tc ON trl.test_case_id = tc.id 
                                           JOIN user_assignments ua ON tc.project_id = ua.project_id 
                                           SET trl.is_read = 1 
                                           WHERE trl.is_read = 0 AND ua.user_id = ?");
                    $stmt2->bind_param("i", $user_id);
                }
                
                $success1 = $stmt1->execute();
                $success2 = $stmt2->execute();
                
                if ($success1 && $success2) {
                    $response = ['success' => true, 'message' => 'All notifications marked as read'];
                } else {
                    $response = ['success' => false, 'message' => 'Failed to mark notifications as read'];
                }
                break;
                
            case 'get_notifications':
                $notifications = getNotifications($conn, $user_id, $role, true);
                $response = ['success' => true, 'notifications' => $notifications];
                break;
                
            case 'get_features':
                $project_id = intval($_POST['project_id']);
                $features = getProjectFeatures($conn, $project_id);
                $response = ['success' => true, 'features' => $features];
                break;
                
            case 'send_test_remark_email':
                $testcase_id = intval($_POST['testcase_id']);
                $recipient_id = intval($_POST['recipient_id']);
                $remark = $_POST['remark'];
                $commenter = $_POST['commenter'];
                
                $stmt = $conn->prepare("SELECT email, username FROM users WHERE id = ?");
                $stmt->bind_param("i", $recipient_id);
                $stmt->execute();
                $recipient = $stmt->get_result()->fetch_assoc();
                
                $stmt = $conn->prepare("SELECT tc.title, p.name as project_name, tc.project_id 
                                      FROM test_cases tc 
                                      JOIN projects p ON tc.project_id = p.id 
                                      WHERE tc.id = ?");
                $stmt->bind_param("i", $testcase_id);
                $stmt->execute();
                $testcase = $stmt->get_result()->fetch_assoc();
                
                if ($recipient && $testcase) {
                    $subject = "Test Case Remark Updated: " . $testcase['title'];
                    $message = "Hello " . $recipient['username'] . ",<br><br>";
                    $message .= $commenter . " has updated a remark on test case: <strong>" . $testcase['title'] . "</strong><br>";
                    $message .= "<strong>Project:</strong> " . $testcase['project_name'] . "<br>";
                    $message .= "<strong>Remark:</strong> " . nl2br(htmlspecialchars($remark)) . "<br><br>";
                    
                    $testCaseInfo = [
                        'id' => $testcase_id,
                        'title' => $testcase['title'],
                        'project_name' => $testcase['project_name'],
                        'project_id' => $testcase['project_id'],
                        'remark' => $remark,
                        'commenter' => $commenter
                    ];
                    
                    // Try to send email
                    $emailSent = @sendEmailNotification($recipient['email'], $recipient['username'], $subject, $message, $testCaseInfo);
                    
                    if ($emailSent) {
                        $response = ['success' => true, 'message' => 'Email sent successfully'];
                    } else {
                        // Even if email fails, still return success for the update
                        $response = ['success' => true, 'message' => 'Update saved but email failed to send'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Recipient or test case not found'];
                }
                break;
                
            case 'send_vendor_comment_email':
                $testcase_id = intval($_POST['testcase_id']);
                $recipient_id = intval($_POST['recipient_id']);
                $comment = $_POST['comment'];
                $commenter = $_POST['commenter'];
                
                $stmt = $conn->prepare("SELECT email, username FROM users WHERE id = ?");
                $stmt->bind_param("i", $recipient_id);
                $stmt->execute();
                $recipient = $stmt->get_result()->fetch_assoc();
                
                $stmt = $conn->prepare("SELECT tc.title, p.name as project_name, tc.project_id 
                                      FROM test_cases tc 
                                      JOIN projects p ON tc.project_id = p.id 
                                      WHERE tc.id = ?");
                $stmt->bind_param("i", $testcase_id);
                $stmt->execute();
                $testcase = $stmt->get_result()->fetch_assoc();
                
                if ($recipient && $testcase) {
                    $subject = "Vendor Comment Updated: " . $testcase['title'];
                    $message = "Hello " . $recipient['username'] . ",<br><br>";
                    $message .= $commenter . " has updated a vendor comment on test case: <strong>" . $testcase['title'] . "</strong><br>";
                    $message .= "<strong>Project:</strong> " . $testcase['project_name'] . "<br>";
                    $message .= "<strong>Comment:</strong> " . nl2br(htmlspecialchars($comment)) . "<br><br>";
                    
                    $testCaseInfo = [
                        'id' => $testcase_id,
                        'title' => $testcase['title'],
                        'project_name' => $testcase['project_name'],
                        'project_id' => $testcase['project_id'],
                        'comment' => $comment,
                        'commenter' => $commenter
                    ];
                    
                    // Try to send email
                    $emailSent = @sendEmailNotification($recipient['email'], $recipient['username'], $subject, $message, $testCaseInfo);
                    
                    if ($emailSent) {
                        $response = ['success' => true, 'message' => 'Email sent successfully'];
                    } else {
                        // Even if email fails, still return success for the update
                        $response = ['success' => true, 'message' => 'Update saved but email failed to send'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Recipient or test case not found'];
                }
                break;
                
            default:
                $response = ['success' => false, 'message' => 'Unknown action'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Server error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

// Function to get notifications
function getNotifications($conn, $user_id, $role, $unread_only = false) {
    $notifications = [];
    
    if ($role === 'super_admin' || $role === 'pm_manager') {
        $query = "
            (
                SELECT al.id, al.user_id, al.action, al.description, al.test_case_id, al.project_id, 
                       al.created_at, u.username, tc.title, 'vendor' as type, al.is_read,
                       p.name as project_name, 'activity' as log_type
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = al.test_case_id
                LEFT JOIN projects p ON p.id = al.project_id
                WHERE al.action IN ('Vendor Comment Updated', 'Test Case Created', 'Test Case Updated', 'Test Case Status Changed', 'Test Case Assigned')
                  " . ($unread_only ? "AND al.is_read = 0" : "") . "
            )
            UNION
            (
                SELECT trl.id, trl.user_id, trl.action, trl.description, trl.test_case_id, trl.project_id, 
                       trl.created_at, u.username, tc.title, 'tester' as type, trl.is_read,
                       p.name as project_name, 'tester_remark' as log_type
                FROM tester_remark_logs trl
                JOIN users u ON trl.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = trl.test_case_id
                LEFT JOIN projects p ON p.id = trl.project_id
                WHERE trl.action IN ('Tester Comment Updated', 'Test Case Reviewed', 'Test Case Rejected')
                  " . ($unread_only ? "AND trl.is_read = 0" : "") . "
            )
            ORDER BY created_at DESC
            LIMIT 20
        ";
        $stmt = $conn->prepare($query);
    } else {
        $query = "
            (
                SELECT al.id, al.user_id, al.action, al.description, al.test_case_id, al.project_id, 
                       al.created_at, u.username, tc.title, 'vendor' as type, al.is_read,
                       p.name as project_name, 'activity' as log_type
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                LEFT JOIN test_cases tc ON al.test_case_id = tc.id
                LEFT JOIN projects p ON p.id = al.project_id
                JOIN user_assignments ua ON ua.project_id = tc.project_id
                WHERE al.action IN ('Vendor Comment Updated', 'Test Case Created', 'Test Case Updated', 'Test Case Status Changed', 'Test Case Assigned')
                  AND ua.user_id = ?
                  " . ($unread_only ? "AND al.is_read = 0" : "") . "
            )
            UNION
            (
                SELECT trl.id, trl.user_id, trl.action, trl.description, trl.test_case_id, trl.project_id, 
                       trl.created_at, u.username, tc.title, 'tester' as type, trl.is_read,
                       p.name as project_name, 'tester_remark' as log_type
                FROM tester_remark_logs trl
                JOIN users u ON trl.user_id = u.id
                LEFT JOIN test_cases tc ON tc.id = trl.test_case_id
                LEFT JOIN projects p ON p.id = trl.project_id
                JOIN user_assignments ua ON ua.project_id = tc.project_id
                WHERE trl.action IN ('Tester Comment Updated', 'Test Case Reviewed', 'Test Case Rejected')
                  AND ua.user_id = ?
                  " . ($unread_only ? "AND trl.is_read = 0" : "") . "
            )
            ORDER BY created_at DESC
            LIMIT 20
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    return $notifications;
}

// Function to get project features
function getProjectFeatures($conn, $project_id) {
    $features = [];
    $stmt = $conn->prepare("SELECT id, feature_name FROM features WHERE project_id = ? ORDER BY feature_name");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $features[] = $row;
    }
    
    return $features;
}

// Function to check if project is terminated
function isProjectTerminated($conn, $project_id) {
    $stmt = $conn->prepare("SELECT status FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    
    return ($project && $project['status'] === 'Terminated');
}

// Function to check if user has access to project
function hasProjectAccess($conn, $user_id, $project_id, $role) {
    if ($role === 'super_admin') {
        return true;
    }
    
    $stmt = $conn->prepare("
        SELECT id FROM user_assignments 
        WHERE project_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Get assigned projects for the user - STRICTLY from user_assignments table
function getAssignedProjects($conn, $user_id, $role) {
    if ($role === 'super_admin') {
        // Super Admin can see all projects
        $query = "SELECT id, name, description, status FROM projects";
        $stmt = $conn->prepare($query);
    } else {
        // All other users only see projects they're assigned to via user_assignments table
        $query = "
            SELECT p.id, p.name, p.description, p.status
            FROM projects p
            JOIN user_assignments ua ON p.id = ua.project_id
            WHERE ua.user_id = ? AND ua.is_active = 1
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);

    $projectIds = array_column($projects, 'id');
    $testCaseCounts = [];

    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $types = str_repeat('i', count($projectIds));

        $countQuery = "
            SELECT project_id,
                   COUNT(*) AS total,
                   SUM(CASE WHEN status = 'Passed' THEN 1 ELSE 0 END) AS passed_count,
                   SUM(CASE WHEN status = 'Failed' THEN 1 ELSE 0 END) AS failed_count,
                   SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count
            FROM test_cases
            WHERE project_id IN ($placeholders)
            GROUP BY project_id
        ";

        $countStmt = $conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$projectIds);
        $countStmt->execute();
        $countResult = $countStmt->get_result();

        while ($row = $countResult->fetch_assoc()) {
            $testCaseCounts[$row['project_id']] = $row;
        }
    }

    foreach ($projects as &$project) {
        $pid = $project['id'];
        $project['testcase_count'] = $testCaseCounts[$pid]['total'] ?? 0;
        $project['passed_count']   = $testCaseCounts[$pid]['passed_count'] ?? 0;
        $project['failed_count']   = $testCaseCounts[$pid]['failed_count'] ?? 0;
        $project['pending_count']  = $testCaseCounts[$pid]['pending_count'] ?? 0;
    }

    return $projects;
}

// Get unread notifications count
$unread_notifications = getNotifications($conn, $user_id, $role, true);
$unread_count = count($unread_notifications);

// Handle page-specific functionality
$page = $_GET['page'] ?? 'dashboard';

// Check if user has access to the requested page
$allowed_pages = ['dashboard', 'assigned_projects', 'view_project', 'add_testcase', 'edit_testcase', 'delete_testcase', 'view_testcase'];
if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Get assigned projects for the current user
$assigned_projects = getAssignedProjects($conn, $user_id, $role);

// Handle edit test case
if ($page === 'edit_testcase' && isset($_GET['id'])) {
    $testcase_id = $_GET['id'];
    $project_id = $_GET['project_id'] ?? null;
    
    // Check if user has access to this project
    if (!hasProjectAccess($conn, $user_id, $project_id, $role)) {
        $_SESSION['error_message'] = "You do not have access to this project.";
        header("Location: ?page=dashboard");
        exit;
    }
    
    // Check if project is terminated
    if (isProjectTerminated($conn, $project_id)) {
        $_SESSION['error_message'] = "Cannot edit test cases. This project is already terminated.";
        header("Location: ?page=view_project&id=$project_id");
        exit;
    }
    
    if (isset($_POST['update_testcase'])) {
        $title = trim($_POST['title']);
        $steps = trim($_POST['steps']);
        $expected = trim($_POST['expected']);
        $status = $_POST['status'];
        $priority = $_POST['priority'];
        $feature_id = $_POST['feature_id'] ?? null;
        $frequency = $_POST['frequency'] ?? null;
        $channel = $_POST['channel'] ?? null;
        
        // Convert empty string to null
        if ($feature_id === '') {
            $feature_id = null;
        }
        
        // Prepare the SQL statement
        $sql = "UPDATE test_cases SET title = ?, steps = ?, expected = ?, status = ?, priority = ?, feature_id = ?, frequency = ?, channel = ?, updated_at = NOW() WHERE id = ?";
        
        // Prepare the statement
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $_SESSION['error_message'] = "Failed to prepare statement: " . $conn->error;
            header("Location: ?page=view_project&id=$project_id");
            exit;
        }
        
        // Bind parameters based on whether feature_id is null
        if ($feature_id === null) {
            $stmt->bind_param("ssssssssi", 
                $title, 
                $steps, 
                $expected, 
                $status, 
                $priority, 
                $feature_id, 
                $frequency, 
                $channel, 
                $testcase_id
            );
        } else {
            $stmt->bind_param("sssssissi", 
                $title, 
                $steps, 
                $expected, 
                $status, 
                $priority, 
                $feature_id, 
                $frequency, 
                $channel, 
                $testcase_id
            );
        }
        
        if ($stmt->execute()) {
            // Log the activity
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, test_case_id, project_id) VALUES (?, ?, ?, ?, ?)");
            $action = "Test Case Updated";
            $description = "Updated test case '$title'";
            $log_stmt->bind_param("issii", $user_id, $action, $description, $testcase_id, $project_id);
            $log_stmt->execute();
            
            $_SESSION['success_message'] = "Test case updated successfully!";
            header("Location: ?page=view_project&id=$project_id");
            exit;
        } else {
            $_SESSION['error_message'] = "Failed to update test case: " . $stmt->error;
        }
    }
    
    // Get test case details for editing
    $stmt = $conn->prepare("SELECT * FROM test_cases WHERE id = ?");
    $stmt->bind_param("i", $testcase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $testcase = $result->fetch_assoc();
    
    if (!$testcase) {
        $_SESSION['error_message'] = "Test case not found";
        header("Location: ?page=dashboard");
        exit;
    }
    
    $project_id = $testcase['project_id'];
    
    // Verify user has access to this project
    if (!hasProjectAccess($conn, $user_id, $project_id, $role)) {
        $_SESSION['error_message'] = "You do not have access to this test case.";
        header("Location: ?page=dashboard");
        exit;
    }
}

// Handle vendor comment update with email functionality
if (isset($_POST['update_vendor_comment'])) {
    $test_case_id = $_POST['test_case_id'];
    
    // Get project ID first to check status and access
    $project_check = $conn->prepare("SELECT project_id FROM test_cases WHERE id = ?");
    $project_check->bind_param("i", $test_case_id);
    $project_check->execute();
    $project_result = $project_check->get_result();
    $testcase_info = $project_result->fetch_assoc();
    
    if (!$testcase_info || !hasProjectAccess($conn, $user_id, $testcase_info['project_id'], $role)) {
        $_SESSION['error_message'] = "Access denied or test case not found.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    if (isProjectTerminated($conn, $testcase_info['project_id'])) {
        $_SESSION['error_message'] = "Cannot update vendor comment. This project is already terminated.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $vendor_comment = trim($_POST['vendor_comment']);
    
    $conn->begin_transaction();
    
    try {
        $stmt = $conn->prepare("UPDATE test_cases SET vendor_comment = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $vendor_comment, $test_case_id);
        $stmt->execute();
        
        $info_stmt = $conn->prepare("SELECT project_id, title FROM test_cases WHERE id = ?");
        $info_stmt->bind_param("i", $test_case_id);
        $info_stmt->execute();
        $info_result = $info_stmt->get_result();
        $info = $info_result->fetch_assoc();
        
        if ($info) {
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, test_case_id, project_id) VALUES (?, ?, ?, ?, ?)");
            $action = "Vendor Comment Updated";
            $description = "Vendor updated comment: " . (strlen($vendor_comment) > 50 ? substr($vendor_comment, 0, 50) . "..." : $vendor_comment);
            $log_stmt->bind_param("issii", $user_id, $action, $description, $test_case_id, $info['project_id']);
            $log_stmt->execute();
            
            $recipients_stmt = $conn->prepare("
                SELECT DISTINCT u.id, u.email, u.username, u.system_role
                FROM users u
                JOIN user_assignments ua ON u.id = ua.user_id
                WHERE ua.project_id = ? AND u.id != ? AND u.system_role IN ('super_admin', 'tester', 'pm_manager')
            ");
            $recipients_stmt->bind_param("ii", $info['project_id'], $user_id);
            $recipients_stmt->execute();
            $recipients_result = $recipients_stmt->get_result();
            
            $_SESSION['email_recipients'] = [];
            $_SESSION['testcase_info'] = [
                'id' => $test_case_id,
                'title' => $info['title'],
                'project_id' => $info['project_id'],
                'comment' => $vendor_comment,
                'commenter' => $username,
                'type' => 'vendor_comment'
            ];
            
            while ($recipient = $recipients_result->fetch_assoc()) {
                if (!empty($recipient['email'])) {
                    $_SESSION['email_recipients'][] = $recipient;
                }
            }
            
            if (!empty($_SESSION['email_recipients'])) {
                $_SESSION['show_email_modal'] = true;
            }
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Vendor comment updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Failed to update vendor comment: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Handle tester remark update with email functionality
if (isset($_POST['update_tester_remark'])) {
    $test_case_id = $_POST['test_case_id'];
    
    // Get project ID first to check status and access
    $project_check = $conn->prepare("SELECT project_id FROM test_cases WHERE id = ?");
    $project_check->bind_param("i", $test_case_id);
    $project_check->execute();
    $project_result = $project_check->get_result();
    $testcase_info = $project_result->fetch_assoc();
    
    if (!$testcase_info || !hasProjectAccess($conn, $user_id, $testcase_info['project_id'], $role)) {
        $_SESSION['error_message'] = "Access denied or test case not found.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    if (isProjectTerminated($conn, $testcase_info['project_id'])) {
        $_SESSION['error_message'] = "Cannot update tester remark. This project is already terminated.";
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    $tester_remark = trim($_POST['tester_remark']);
    
    $conn->begin_transaction();
    
    try {
        $stmt = $conn->prepare("UPDATE test_cases SET tester_remark = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $tester_remark, $test_case_id);
        $stmt->execute();
        
        $info_stmt = $conn->prepare("SELECT project_id, title FROM test_cases WHERE id = ?");
        $info_stmt->bind_param("i", $test_case_id);
        $info_stmt->execute();
        $info_result = $info_stmt->get_result();
        $info = $info_result->fetch_assoc();
        
        if ($info) {
            $log_stmt = $conn->prepare("INSERT INTO tester_remark_logs (user_id, action, description, test_case_id, project_id) VALUES (?, ?, ?, ?, ?)");
            $action = "Tester Comment Updated";
            $description = "Tester updated remark: " . (strlen($tester_remark) > 50 ? substr($tester_remark, 0, 50) . "..." : $tester_remark);
            $log_stmt->bind_param("issii", $user_id, $action, $description, $test_case_id, $info['project_id']);
            $log_stmt->execute();
            
            $recipients_stmt = $conn->prepare("
                SELECT DISTINCT u.id, u.email, u.username, u.system_role
                FROM users u
                JOIN user_assignments ua ON u.id = ua.user_id
                WHERE ua.project_id = ? AND u.id != ? AND u.system_role IN ('super_admin', 'pm_manager', 'test_viewer')
            ");
            $recipients_stmt->bind_param("ii", $info['project_id'], $user_id);
            $recipients_stmt->execute();
            $recipients_result = $recipients_stmt->get_result();
            
            $_SESSION['email_recipients'] = [];
            $_SESSION['testcase_info'] = [
                'id' => $test_case_id,
                'title' => $info['title'],
                'project_id' => $info['project_id'],
                'remark' => $tester_remark,
                'commenter' => $username,
                'type' => 'tester_remark'
            ];
            
            while ($recipient = $recipients_result->fetch_assoc()) {
                if (!empty($recipient['email'])) {
                    $_SESSION['email_recipients'][] = $recipient;
                }
            }
            
            if (!empty($_SESSION['email_recipients'])) {
                $_SESSION['show_email_modal'] = true;
            }
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Tester remark updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Failed to update tester remark: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Handle delete test case
if ($page === 'delete_testcase' && isset($_GET['id'])) {
    $testcase_id = $_GET['id'];
    $project_id = $_GET['project_id'] ?? null;
    
    // Check if user has access to this project
    if (!hasProjectAccess($conn, $user_id, $project_id, $role)) {
        $_SESSION['error_message'] = "You do not have access to this project.";
        header("Location: ?page=dashboard");
        exit;
    }
    
    // Check if project is terminated
    if (isProjectTerminated($conn, $project_id)) {
        $_SESSION['error_message'] = "Cannot delete test cases. This project is already terminated.";
        header("Location: ?page=view_project&id=$project_id");
        exit;
    }
    
    $conn->begin_transaction();
    
    try {
        $stmt = $conn->prepare("SELECT title, project_id FROM test_cases WHERE id = ?");
        $stmt->bind_param("i", $testcase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $testcase = $result->fetch_assoc();
        
        if ($testcase) {
            $delete_logs_stmt = $conn->prepare("DELETE FROM activity_logs WHERE test_case_id = ?");
            $delete_logs_stmt->bind_param("i", $testcase_id);
            $delete_logs_stmt->execute();
            
            $delete_remark_logs_stmt = $conn->prepare("DELETE FROM tester_remark_logs WHERE test_case_id = ?");
            $delete_remark_logs_stmt->bind_param("i", $testcase_id);
            $delete_remark_logs_stmt->execute();
            
            $delete_stmt = $conn->prepare("DELETE FROM test_cases WHERE id = ?");
            $delete_stmt->bind_param("i", $testcase_id);
            $delete_stmt->execute();
            
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, project_id) VALUES (?, ?, ?, ?)");
            $action = "Test Case Deleted";
            $description = "Deleted test case '" . $testcase['title'] . "'";
            $log_stmt->bind_param("issi", $user_id, $action, $description, $testcase['project_id']);
            $log_stmt->execute();
            
            $conn->commit();
            $_SESSION['success_message'] = "Test case deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Test case not found";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Failed to delete test case: " . $e->getMessage();
    }
    
    header("Location: ?page=view_project&id=$project_id");
    exit;
}

// Handle view test case details
if ($page === 'view_testcase' && isset($_GET['id'])) {
    $testcase_id = $_GET['id'];
    
    $stmt = $conn->prepare("
        SELECT tc.*, f.feature_name, p.name as project_name 
        FROM test_cases tc
        LEFT JOIN features f ON tc.feature_id = f.id
        JOIN projects p ON tc.project_id = p.id
        WHERE tc.id = ?
    ");
    $stmt->bind_param("i", $testcase_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $testcase = $result->fetch_assoc();
    
    if (!$testcase) {
        $_SESSION['error_message'] = "Test case not found";
        header("Location: ?page=dashboard");
        exit;
    }
    
    // Check if user has access to this test case's project
    if (!hasProjectAccess($conn, $user_id, $testcase['project_id'], $role)) {
        $_SESSION['error_message'] = "You do not have access to view this test case";
        header("Location: ?page=dashboard");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Manager - My Assigned Projects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #012169;
            --dashen-accent: #e41e26;
            --dashen-gradient: linear-gradient(135deg, #273274 0%, #012169 100%);
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* Main Content Area - Adjusted for sidebar */
        .main-content {
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            padding: 20px;
            min-height: 100vh;
        }
        
        .sidebar-collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sidebar-collapsed ~ .main-content {
                margin-left: 0 !important;
            }
        }
        
        /* Dynamic Form Fields */
        .dynamic-field-group {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        
        .dynamic-field-group .field-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .dynamic-field-group .field-count {
            font-size: 0.875rem;
            color: #6c757d;
            font-weight: 600;
        }
        
        .add-field-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--dashen-primary);
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .add-field-btn:hover {
            background: var(--dashen-secondary);
            transform: scale(1.1);
        }
        
        .remove-field-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #c82333;
            color: red;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .remove-field-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }
        
        /* Project Cards */
        .project-card {
            transition: transform 0.3s ease;
        }
        
        .project-card:hover {
            transform: translateY(-5px);
        }
        
        /* Status Badges */
        .badge-status-pass { background: #d4edda; color: #155724; }
        .badge-status-fail { background: #f8d7da; color: #721c24; }
        .badge-status-pending { background: #fff3cd; color: #856404; }
        .badge-status-deferred { background: #e2e3e5; color: #383d41; }
        
        /* Project Status Badges */
        .badge-status-active { background: #d4edda; color: #155724; }
        .badge-status-completed { background: #cce5ff; color: #004085; }
        .badge-status-terminated { background: #f8d7da; color: #721c24; }
        .badge-status-onhold { background: #fff3cd; color: #856404; }
        
        /* Welcome Banner */
        .welcome-banner {
            background: var(--dashen-gradient);
            color: white;
            border-radius: 12px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(39, 50, 116, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
            border-radius: 50%;
        }
        
        /* Modal Customization */
        .modal-header-gradient {
            background: var(--dashen-gradient);
            color: white;
        }
        
        /* Table Styles */
        .table th {
            background: var(--dashen-primary);
            color: white;
        }
        
        .highlighted-row {
            background-color: #fff3cd !important;
            animation: flash 1.5s ease-in-out;
        }
        
        @keyframes flash {
            0% { background-color: #fff3cd; }
            50% { background-color: #ffeeba; }
            100% { background-color: #fff3cd; }
        }
        
        /* Toast Styles */
        .custom-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }
        
        /* Stats Cards */
        .stat-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            display: none;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast Notification */
        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            min-width: 300px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        
        .toast-notification.show {
            transform: translateX(0);
        }
        
        .toast-header {
            background: var(--dashen-gradient);
            color: white;
            padding: 0.75rem 1rem;
            border-bottom: none;
        }
        
        .toast-body {
            padding: 1rem;
        }
        
        /* Fix for sidebar overlap on mobile */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }
            
            .sidebar-collapsed .sidebar {
                transform: translateX(0);
            }
            
            .welcome-banner {
                margin-top: 20px;
            }
        }
        
        /* Email Modal - Fixed positioning */
        .email-notification-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 99999;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-backdrop-email {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99998;
        }
    </style>
</head>
<body>
    <!-- INCLUDE SIDEBAR -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <!-- Toast Notification -->
    <div class="toast-notification" id="toastNotification">
        <div class="toast-header">
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close btn-close-white" onclick="hideToast()"></button>
        </div>
        <div class="toast-body" id="toastMessage"></div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h1 class="display-6 fw-bold">
                        <i class="fas fa-tachometer-alt me-2"></i>
                        <?php 
                        switch($page) {
                            case 'dashboard': echo 'Dashboard'; break;
                            case 'assigned_projects': echo 'My Assigned Projects'; break;
                            case 'view_project': echo 'Project Details'; break;
                            case 'edit_testcase': echo 'Edit Test Case'; break;
                            case 'view_testcase': echo 'Test Case Details'; break;
                            default: echo 'Test Manager'; break;
                        }
                        ?>
                    </h1>
                    <p class="lead mb-0">Welcome back, <?= $username ?>! 
                        <?= $role === 'super_admin' ? 'You have full access.' : 'You have limited access to assigned projects.' ?>
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <!-- User role badge only - Notification bell removed -->
                    <span class="badge bg-light text-dark ms-2 p-2">
                        <i class="fas fa-user me-1"></i> <?= ucfirst(str_replace('_', ' ', $role)) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?= $_SESSION['success_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= $_SESSION['error_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Page Content -->
        <?php if ($page === 'dashboard'): ?>
            <!-- Dashboard Content -->
            <div class="row mb-4">
                <?php if ($role === 'super_admin' || $role === 'pm_manager'): ?>
                    <!-- Admin Dashboard -->
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Total Projects</div>
                                        <div class="h4 mb-0"><?= count($assigned_projects) ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-project-diagram text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Total Test Cases</div>
                                        <?php 
                                        $total_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $total_cases += $project['testcase_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $total_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-list-check text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Passed Cases</div>
                                        <?php 
                                        $passed_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $passed_cases += $project['passed_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $passed_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-check-circle text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-3 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Pending Cases</div>
                                        <?php 
                                        $pending_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $pending_cases += $project['pending_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $pending_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-clock text-warning"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($role === 'tester'): ?>
                    <!-- Tester Dashboard -->
                    <div class="col-xl-4 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">My Projects</div>
                                        <div class="h4 mb-0"><?= count($assigned_projects) ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-project-diagram text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Test Cases</div>
                                        <?php 
                                        $total_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $total_cases += $project['testcase_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $total_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-list-check text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-xl-4 col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Passed Cases</div>
                                        <?php 
                                        $passed_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $passed_cases += $project['passed_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $passed_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-check-circle text-success"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <!-- Viewer/Employee Dashboard -->
                    <div class="col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Assigned Projects</div>
                                        <div class="h4 mb-0"><?= count($assigned_projects) ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-project-diagram text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="text-muted text-uppercase small">Test Cases</div>
                                        <?php 
                                        $total_cases = 0;
                                        foreach ($assigned_projects as $project) {
                                            $total_cases += $project['testcase_count'];
                                        }
                                        ?>
                                        <div class="h4 mb-0"><?= $total_cases ?></div>
                                    </div>
                                    <div class="stat-icon">
                                        <i class="fas fa-list-check text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Assigned Projects Section -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-project-diagram me-2"></i>My Assigned Projects</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($assigned_projects)): ?>
                        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                            <?php foreach ($assigned_projects as $project): ?>
                                <div class="col">
                                    <div class="card project-card h-100 shadow-sm">
                                        <div class="card-header d-flex justify-content-between align-items-center" 
                                             style="background: <?= $project['status'] === 'Terminated' ? '#dc3545' : '#0d6efd' ?>; color: white;">
                                            <h5 class="mb-0"><?= htmlspecialchars($project['name']) ?></h5>
                                            <span class="badge <?= $project['status'] === 'Active' ? 'badge-status-active' : 
                                                                   ($project['status'] === 'Completed' ? 'badge-status-completed' : 
                                                                   ($project['status'] === 'Terminated' ? 'badge-status-terminated' : 'badge-status-onhold')) ?>">
                                                <?= htmlspecialchars($project['status']) ?>
                                            </span>
                                        </div>
                                        <div class="card-body">
                                            <p class="card-text"><?= htmlspecialchars($project['description']) ?></p>
                                            
                                            <?php if ($project['testcase_count'] > 0): ?>
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between small mb-2">
                                                        <span class="badge bg-success">
                                                            <i class="fas fa-check"></i> <?= $project['passed_count'] ?> Passed
                                                        </span>
                                                        <span class="badge bg-danger">
                                                            <i class="fas fa-times"></i> <?= $project['failed_count'] ?> Failed
                                                        </span>
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="fas fa-clock"></i> <?= $project['pending_count'] ?> Pending
                                                        </span>
                                                    </div>
                                                    <div class="progress">
                                                        <?php 
                                                        $total = $project['testcase_count'];
                                                        $passed_pct = $total ? ($project['passed_count']/$total)*100 : 0;
                                                        $failed_pct = $total ? ($project['failed_count']/$total)*100 : 0;
                                                        $pending_pct = $total ? ($project['pending_count']/$total)*100 : 0;
                                                        ?>
                                                        <div class="progress-bar bg-success" style="width: <?= $passed_pct ?>%"></div>
                                                        <div class="progress-bar bg-danger" style="width: <?= $failed_pct ?>%"></div>
                                                        <div class="progress-bar bg-warning" style="width: <?= $pending_pct ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-info small mb-3">
                                                    No test cases yet
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex justify-content-between">
                                                <a href="?page=view_project&id=<?= $project['id'] ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <?php if (($role === 'tester' || $role === 'super_admin' || $role === 'pm_manager') && $project['status'] !== 'Terminated'): ?>
                                                    <button class="btn btn-sm btn-success" 
                                                            onclick="checkProjectStatus(<?= $project['id'] ?>, '<?= htmlspecialchars($project['name']) ?>')">
                                                        <i class="fas fa-plus"></i> Add Test
                                                    </button>
                                                <?php elseif ($project['status'] === 'Terminated'): ?>
                                                    <button class="btn btn-sm btn-secondary" disabled title="Project is terminated">
                                                        <i class="fas fa-ban"></i> Add Test
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-folder-open fa-4x text-muted mb-4"></i>
                            <h3>No Projects Assigned</h3>
                            <p class="text-muted">You don't have any projects assigned to you yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php elseif ($page === 'view_project' && isset($_GET['id'])): ?>
            <!-- View Project Content -->
            <?php
            $project_id = $_GET['id'];
            $highlight_id = $_GET['highlight_testcase'] ?? null;
            
            // Check if user has access to this project
            if (!hasProjectAccess($conn, $user_id, $project_id, $role)) {
                die('<div class="alert alert-danger">You do not have access to this project.</div>');
            }
            
            // Get project details
            $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->bind_param("i", $project_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $project = $result->fetch_assoc();
            
            if (!$project) {
                die('<div class="alert alert-danger">Project not found.</div>');
            }
            
            $is_terminated = ($project['status'] === 'Terminated');
            
            $statusFilter = $_GET['status'] ?? '';
            if ($statusFilter) {
                $stmt = $conn->prepare("
                    SELECT tc.*, f.feature_name 
                    FROM test_cases tc
                    LEFT JOIN features f ON tc.feature_id = f.id
                    WHERE tc.project_id = ? AND tc.status = ?
                    ORDER BY tc.created_at DESC
                ");
                $stmt->bind_param("is", $project_id, $statusFilter);
            } else {
                $stmt = $conn->prepare("
                    SELECT tc.*, f.feature_name 
                    FROM test_cases tc
                    LEFT JOIN features f ON tc.feature_id = f.id
                    WHERE tc.project_id = ?
                    ORDER BY tc.created_at DESC
                ");
                $stmt->bind_param("i", $project_id);
            }
            $stmt->execute();
            $testcases = $stmt->get_result();
            
            $features_result = $conn->query("SELECT id, feature_name FROM features WHERE project_id = $project_id ORDER BY feature_name");
            $features = [];
            while ($row = $features_result->fetch_assoc()) {
                $features[$row['id']] = $row['feature_name'];
            }
            ?>
            
            <!-- Back Link -->
            <a href="?page=dashboard" class="btn btn-outline-primary mb-3">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            
            <!-- Project Header -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center" 
                     style="background: <?= $is_terminated ? '#dc3545' : '#0d6efd' ?>; color: white;">
                    <h3 class="card-title mb-0"><i class="fas fa-project-diagram me-2"></i><?= htmlspecialchars($project['name']) ?></h3>
                    <span class="badge <?= $project['status'] === 'Active' ? 'badge-status-active' : 
                                           ($project['status'] === 'Completed' ? 'badge-status-completed' : 
                                           ($project['status'] === 'Terminated' ? 'badge-status-terminated' : 'badge-status-onhold')) ?>">
                        <?= htmlspecialchars($project['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-text"><?= htmlspecialchars($project['description']) ?></p>
                    
                    <!-- Warning for terminated projects -->
                    <?php if ($is_terminated): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>This project is terminated.</strong> You cannot add new test cases or modify existing ones.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 mb-3">
                        <?php if (($role === 'tester' || $role === 'super_admin' || $role === 'pm_manager') && !$is_terminated): ?>
                            <button class="btn btn-primary" onclick="checkProjectStatus(<?= $project_id ?>, '<?= htmlspecialchars($project['name']) ?>')">
                                <i class="fas fa-plus me-2"></i>Add Test Case
                            </button>
                        <?php elseif ($is_terminated): ?>
                            <button class="btn btn-secondary" disabled title="Project is terminated">
                                <i class="fas fa-ban me-2"></i>Add Test Case
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Test Cases Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Steps</th>
                                    <th>Expected Result</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Frequency</th>
                                    <th>Channel</th>
                                    <th>Tester Remark</th>
                                    <th>Vendor Comment</th>
                                    <th>Feature</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($tc = $testcases->fetch_assoc()): ?>
                                    <tr id="testcase-<?= $tc['id'] ?>" class="<?= ($highlight_id == $tc['id']) ? 'highlighted-row' : '' ?>">
                                        <td><?= $tc['id'] ?></td>
                                        <td><?= htmlspecialchars($tc['title']) ?></td>
                                        <td><?= nl2br(htmlspecialchars($tc['steps'])) ?></td>
                                        <td><?= nl2br(htmlspecialchars($tc['expected'])) ?></td>
                                        <td>
                                            <span class="badge badge-status-<?= strtolower($tc['status']) ?>">
                                                <?= htmlspecialchars($tc['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= strtolower($tc['priority']) == 'high' ? 'bg-danger' : 
                                                                (strtolower($tc['priority']) == 'medium' ? 'bg-warning' : 'bg-info') ?>">
                                                <?= htmlspecialchars($tc['priority']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($tc['frequency'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($tc['channel'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php if ($role === 'tester' && !$is_terminated): ?>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateTesterRemarkModal"
                                                        data-testcase-id="<?= $tc['id'] ?>" data-current-remark="<?= htmlspecialchars($tc['tester_remark'] ?? '') ?>">
                                                    <i class="fas fa-comment"></i>
                                                </button>
                                            <?php else: ?>
                                                <?= nl2br(htmlspecialchars($tc['tester_remark'] ?? '')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (($role === 'test_viewer' || $role === 'pm_employee') && !$is_terminated): ?>
                                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateVendorCommentModal"
                                                        data-testcase-id="<?= $tc['id'] ?>" data-current-comment="<?= htmlspecialchars($tc['vendor_comment'] ?? '') ?>">
                                                    <i class="fas fa-comment"></i>
                                                </button>
                                            <?php else: ?>
                                                <?= nl2br(htmlspecialchars($tc['vendor_comment'] ?? '')) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($tc['feature_name'] ?? 'N/A') ?></td>
                                        <td><?= date('M j, Y g:i a', strtotime($tc['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="?page=view_testcase&id=<?= $tc['id'] ?>" class="btn btn-sm btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (($role === 'tester' || $role === 'super_admin' || $role === 'pm_manager') && !$is_terminated): ?>
                                                    <a href="?page=edit_testcase&id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>" 
                                                       class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?page=delete_testcase&id=<?= $tc['id'] ?>&project_id=<?= $project_id ?>" 
                                                       class="btn btn-sm btn-outline-danger" title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this test case?');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($testcases->num_rows == 0): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-list-alt fa-3x text-muted mb-3"></i>
                            <h4>No test cases found</h4>
                            <p class="text-muted">Start by adding your first test case to this project.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($highlight_id): ?>
                <script>
                    setTimeout(() => {
                        const el = document.getElementById('testcase-<?= $highlight_id ?>');
                        if (el) {
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 500);
                </script>
            <?php endif; ?>
            
        <?php elseif ($page === 'view_testcase' && isset($testcase)): ?>
            <!-- View Test Case Details -->
            <a href="?page=view_project&id=<?= $testcase['project_id'] ?>" class="btn btn-outline-primary mb-3">
                <i class="fas fa-arrow-left me-2"></i>Back to Project
            </a>
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0"><i class="fas fa-list-check me-2"></i>Test Case Details</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <h5 class="text-muted">Title</h5>
                                <p class="fs-5"><?= htmlspecialchars($testcase['title']) ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <h5 class="text-muted">Project</h5>
                                <p><?= htmlspecialchars($testcase['project_name']) ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <h5 class="text-muted">Feature</h5>
                                <p><?= htmlspecialchars($testcase['feature_name'] ?? 'N/A') ?></p>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <h5 class="text-muted">Status</h5>
                                    <span class="badge badge-status-<?= strtolower($testcase['status']) ?> fs-6">
                                        <?= htmlspecialchars($testcase['status']) ?>
                                    </span>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <h5 class="text-muted">Priority</h5>
                                    <span class="badge <?= strtolower($testcase['priority']) == 'high' ? 'bg-danger' : 
                                                        (strtolower($testcase['priority']) == 'medium' ? 'bg-warning' : 'bg-info') ?> fs-6">
                                        <?= htmlspecialchars($testcase['priority']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <h5 class="text-muted">Frequency</h5>
                                    <p><?= htmlspecialchars($testcase['frequency'] ?? 'N/A') ?></p>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <h5 class="text-muted">Channel</h5>
                                    <p><?= htmlspecialchars($testcase['channel'] ?? 'N/A') ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <h5 class="text-muted">Created At</h5>
                                <p><?= date('F j, Y, g:i a', strtotime($testcase['created_at'])) ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <h5 class="text-muted">Last Updated</h5>
                                <p><?= date('F j, Y, g:i a', strtotime($testcase['updated_at'])) ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <h5 class="text-muted">Test Steps</h5>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <?= nl2br(htmlspecialchars($testcase['steps'])) ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <h5 class="text-muted">Expected Result</h5>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <?= nl2br(htmlspecialchars($testcase['expected'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h5 class="text-muted">Tester Remark</h5>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <?= nl2br(htmlspecialchars($testcase['tester_remark'] ?? 'No remark yet')) ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <h5 class="text-muted">Vendor Comment</h5>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <?= nl2br(htmlspecialchars($testcase['vendor_comment'] ?? 'No comment yet')) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <a href="?page=view_project&id=<?= $testcase['project_id'] ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Project
                        </a>
                        <?php if (($role === 'tester' || $role === 'super_admin' || $role === 'pm_manager') && !isProjectTerminated($conn, $testcase['project_id'])): ?>
                            <a href="?page=edit_testcase&id=<?= $testcase['id'] ?>&project_id=<?= $testcase['project_id'] ?>" class="btn btn-primary">
                                <i class="fas fa-edit me-2"></i>Edit Test Case
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        <?php elseif ($page === 'edit_testcase' && isset($testcase)): ?>
            <!-- Edit Test Case Content - FIXED -->
            <a href="?page=view_project&id=<?= $project_id ?>" class="btn btn-outline-primary mb-3">
                <i class="fas fa-arrow-left me-2"></i>Back to Project
            </a>
            
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0"><i class="fas fa-edit me-2"></i>Edit Test Case</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" 
                                           value="<?= htmlspecialchars($testcase['title']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="feature_id" class="form-label">Feature</label>
                                    <select class="form-select" id="feature_id" name="feature_id">
                                        <option value="">No Feature</option>
                                        <?php 
                                        $features_result = $conn->query("SELECT id, feature_name FROM features WHERE project_id = $project_id ORDER BY feature_name");
                                        while ($feature = $features_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?= $feature['id'] ?>" <?= $testcase['feature_id'] == $feature['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($feature['feature_name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="steps" class="form-label">Steps <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="steps" name="steps" rows="4" required><?= htmlspecialchars($testcase['steps']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="expected" class="form-label">Expected Result <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="expected" name="expected" rows="4" required><?= htmlspecialchars($testcase['expected']) ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="Pending" <?= $testcase['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Pass" <?= $testcase['status'] === 'Pass' ? 'selected' : '' ?>>Pass</option>
                                        <option value="Fail" <?= $testcase['status'] === 'Fail' ? 'selected' : '' ?>>Fail</option>
                                        <option value="Deferred" <?= $testcase['status'] === 'Deferred' ? 'selected' : '' ?>>Deferred</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <select class="form-select" id="priority" name="priority" required>
                                        <option value="Medium" <?= $testcase['priority'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                        <option value="High" <?= $testcase['priority'] === 'High' ? 'selected' : '' ?>>High</option>
                                        <option value="Low" <?= $testcase['priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="frequency" class="form-label">Frequency</label>
                                    <input type="text" class="form-control" id="frequency" name="frequency" 
                                           value="<?= htmlspecialchars($testcase['frequency'] ?? '') ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="channel" class="form-label">Channel</label>
                                    <input type="text" class="form-control" id="channel" name="channel" 
                                           value="<?= htmlspecialchars($testcase['channel'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" name="update_testcase" class="btn btn-primary">Update Test Case</button>
                            <a href="?page=view_project&id=<?= $project_id ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Default Content -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Select a page from the sidebar to get started.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modals -->
    
    <!-- Add Test Case Modal (Dynamic) -->
    <div class="modal fade" id="addTestcaseModal" tabindex="-1" aria-labelledby="addTestcaseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New Test Cases for <span id="modalProjectName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addTestcaseForm" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="project_id" id="modalProjectId">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="testcase_feature_id" class="form-label">Feature <span class="text-danger">*</span></label>
                                    <select name="feature_id" id="testcase_feature_id" class="form-select" required>
                                        <option value="">-- Select Feature --</option>
                                        <!-- Features will be loaded dynamically -->
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="testcaseFieldsContainer">
                            <!-- Initial test case field -->
                            <div class="dynamic-field-group" id="testcaseGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Test Case #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addTestCaseField()" title="Add another test case">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Test Case Title <span class="text-danger">*</span></label>
                                            <input type="text" name="titles[]" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select">
                                                <option value="Pending" selected>Pending</option>
                                                <option value="Pass">Pass</option>
                                                <option value="Fail">Fail</option>
                                                <option value="Deferred">Deferred</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Priority</label>
                                            <select name="priorities[]" class="form-select">
                                                <option value="Medium" selected>Medium</option>
                                                <option value="High">High</option>
                                                <option value="Low">Low</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Test Steps <span class="text-danger">*</span></label>
                                            <textarea name="steps_array[]" class="form-control" rows="3" required></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Expected Result <span class="text-danger">*</span></label>
                                            <textarea name="expected_array[]" class="form-control" rows="3" required></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Frequency</label>
                                            <input type="text" name="frequencies[]" class="form-control">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Channel</label>
                                            <input type="text" name="channels[]" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($role === 'tester'): ?>
                                <div class="mb-3">
                                    <label class="form-label">Tester Remark</label>
                                    <textarea name="tester_remarks[]" class="form-control" rows="2"></textarea>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($role === 'test_viewer'): ?>
                                <div class="mb-3">
                                    <label class="form-label">Vendor Comment</label>
                                    <textarea name="vendor_comments[]" class="form-control" rows="2"></textarea>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="submitTestCases()">
                            <i class="fas fa-plus me-1"></i> Add Test Cases
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Vendor Comment Modal -->
    <div class="modal fade" id="updateVendorCommentModal" tabindex="-1" aria-labelledby="updateVendorCommentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="updateVendorCommentModalLabel">Update Vendor Comment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="test_case_id" id="vendorCommentTestcaseId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="vendor_comment" class="form-label">Comment</label>
                            <textarea class="form-control" id="vendor_comment" name="vendor_comment" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_vendor_comment" class="btn btn-primary">Update Comment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Tester Remark Modal -->
    <div class="modal fade" id="updateTesterRemarkModal" tabindex="-1" aria-labelledby="updateTesterRemarkModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-gradient">
                    <h5 class="modal-title" id="updateTesterRemarkModalLabel">Update Tester Remark</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="test_case_id" id="testerRemarkTestcaseId">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tester_remark" class="form-label">Remark</label>
                            <textarea class="form-control" id="tester_remark" name="tester_remark" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_tester_remark" class="btn btn-primary">Update Remark</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Email Notification Modal - Fixed Positioning -->
    <?php if (isset($_SESSION['show_email_modal']) && $_SESSION['show_email_modal']): ?>
    <div class="modal-backdrop-email"></div>
    <div class="email-notification-modal">
        <div class="modal-content">
            <div class="modal-header modal-header-gradient">
                <h5 class="modal-title">Send Notification Emails?</h5>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="closeEmailModal()"></button>
            </div>
            <div class="modal-body">
                <p>Do you want to send this update to the following users?</p>
                <div id="recipientList">
                    <?php foreach ($_SESSION['email_recipients'] as $recipient): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input recipient-checkbox" type="checkbox" name="recipients[]" value="<?= $recipient['id'] ?>" id="recipient_<?= $recipient['id'] ?>" checked>
                            <label class="form-check-label" for="recipient_<?= $recipient['id'] ?>">
                                <?= htmlspecialchars($recipient['username']) ?> (<?= htmlspecialchars($recipient['system_role']) ?>)
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEmailModal()">Skip</button>
                <button type="button" class="btn btn-primary" onclick="sendNotificationEmails()">Send Email</button>
            </div>
        </div>
    </div>
    <?php 
        $testcase_info_json = json_encode($_SESSION['testcase_info']);
        unset($_SESSION['show_email_modal']);
    ?>
    <?php endif; ?>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="position-fixed top-0 end-0 p-3" style="z-index: 99999"></div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    // Global variables
    let testcaseInfo = <?= isset($testcase_info_json) ? $testcase_info_json : '{}' ?>;
    let testCaseFieldCount = 1;
    
    $(document).ready(function() {
        // Add Test Case Modal
        const addTestcaseModal = document.getElementById('addTestcaseModal');
        if (addTestcaseModal) {
            addTestcaseModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const projectId = button.getAttribute('data-project-id');
                const projectName = button.getAttribute('data-project-name');
                
                document.getElementById('modalProjectId').value = projectId;
                document.getElementById('modalProjectName').textContent = projectName;
                
                // Reset field container
                testCaseFieldCount = 1;
                $('#testcaseFieldsContainer').html(`
                    <div class="dynamic-field-group" id="testcaseGroup_1">
                        <div class="field-header">
                            <div class="field-count">Test Case #1</div>
                            <div>
                                <button type="button" class="add-field-btn" onclick="addTestCaseField()" title="Add another test case">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Test Case Title <span class="text-danger">*</span></label>
                                    <input type="text" name="titles[]" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select name="statuses[]" class="form-select">
                                        <option value="Pending" selected>Pending</option>
                                        <option value="Pass">Pass</option>
                                        <option value="Fail">Fail</option>
                                        <option value="Deferred">Deferred</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label class="form-label">Priority</label>
                                    <select name="priorities[]" class="form-select">
                                        <option value="Medium" selected>Medium</option>
                                        <option value="High">High</option>
                                        <option value="Low">Low</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Test Steps <span class="text-danger">*</span></label>
                                    <textarea name="steps_array[]" class="form-control" rows="3" required></textarea>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Expected Result <span class="text-danger">*</span></label>
                                    <textarea name="expected_array[]" class="form-control" rows="3" required></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Frequency</label>
                                    <input type="text" name="frequencies[]" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Channel</label>
                                    <input type="text" name="channels[]" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($role === 'tester'): ?>
                        <div class="mb-3">
                            <label class="form-label">Tester Remark</label>
                            <textarea name="tester_remarks[]" class="form-control" rows="2"></textarea>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($role === 'test_viewer'): ?>
                        <div class="mb-3">
                            <label class="form-label">Vendor Comment</label>
                            <textarea name="vendor_comments[]" class="form-control" rows="2"></textarea>
                        </div>
                        <?php endif; ?>
                    </div>
                `);
                
                loadFeatures(projectId);
            });
        }
        
        // Vendor Comment Modal
        const vendorCommentModal = document.getElementById('updateVendorCommentModal');
        if (vendorCommentModal) {
            vendorCommentModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const testcaseId = button.getAttribute('data-testcase-id');
                const currentComment = button.getAttribute('data-current-comment');
                
                document.getElementById('vendorCommentTestcaseId').value = testcaseId;
                document.getElementById('vendor_comment').value = currentComment || '';
            });
        }
        
        // Tester Remark Modal
        const testerRemarkModal = document.getElementById('updateTesterRemarkModal');
        if (testerRemarkModal) {
            testerRemarkModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const testcaseId = button.getAttribute('data-testcase-id');
                const currentRemark = button.getAttribute('data-current-remark');
                
                document.getElementById('testerRemarkTestcaseId').value = testcaseId;
                document.getElementById('tester_remark').value = currentRemark || '';
            });
        }
        
        // Adjust main content when sidebar toggles
        const sidebarContainer = document.getElementById('sidebarContainer');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarContainer && mainContent) {
            // Check initial state
            if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                mainContent.classList.add('sidebar-collapsed');
            }
            
            // Observe for changes in sidebar
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                            mainContent.classList.add('sidebar-collapsed');
                        } else {
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
    
    function showLoading() {
        $('#loadingOverlay').fadeIn();
    }
    
    function hideLoading() {
        $('#loadingOverlay').fadeOut();
    }
    
    function showToast(title, message, type = 'info') {
        const toast = $('#toastNotification');
        const toastMessage = $('#toastMessage');
        
        const toastHeader = toast.find('.toast-header');
        toastHeader.removeClass('bg-success bg-danger bg-warning bg-info');
        
        switch(type) {
            case 'success':
                toastHeader.addClass('bg-success');
                break;
            case 'error':
                toastHeader.addClass('bg-danger');
                break;
            case 'warning':
                toastHeader.addClass('bg-warning');
                break;
            default:
                toastHeader.addClass('bg-info');
        }
        
        toastMessage.html(`<strong>${title}</strong><br>${message}`);
        toast.addClass('show');
        
        setTimeout(() => {
            hideToast();
        }, 5000);
    }
    
    function hideToast() {
        $('#toastNotification').removeClass('show');
    }
    
    // Check project status before opening add test case modal
    function checkProjectStatus(projectId, projectName) {
        showLoading();
        
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'check_project_status',
                project_id: projectId
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    const project = response.project;
                    
                    if (project.status.toLowerCase() === 'terminated') {
                        // Show SweetAlert warning with project name
                        Swal.fire({
                            icon: 'error',
                            title: 'Project Terminated',
                            html: `<p><strong>"${project.name}"</strong> is already terminated.</p>
                                  <p class="text-danger">You cannot add test cases to a terminated project.</p>`,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#dc3545',
                            showCancelButton: false,
                            allowOutsideClick: false,
                            allowEscapeKey: false
                        });
                    } else {
                        // Open the add test case modal
                        $('#modalProjectId').val(projectId);
                        $('#modalProjectName').text(projectName);
                        
                        // Reset field container
                        testCaseFieldCount = 1;
                        $('#testcaseFieldsContainer').html(`
                            <div class="dynamic-field-group" id="testcaseGroup_1">
                                <div class="field-header">
                                    <div class="field-count">Test Case #1</div>
                                    <div>
                                        <button type="button" class="add-field-btn" onclick="addTestCaseField()" title="Add another test case">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Test Case Title <span class="text-danger">*</span></label>
                                            <input type="text" name="titles[]" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="statuses[]" class="form-select">
                                                <option value="Pending" selected>Pending</option>
                                                <option value="Pass">Pass</option>
                                                <option value="Fail">Fail</option>
                                                <option value="Deferred">Deferred</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label class="form-label">Priority</label>
                                            <select name="priorities[]" class="form-select">
                                                <option value="Medium" selected>Medium</option>
                                                <option value="High">High</option>
                                                <option value="Low">Low</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Test Steps <span class="text-danger">*</span></label>
                                            <textarea name="steps_array[]" class="form-control" rows="3" required></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Expected Result <span class="text-danger">*</span></label>
                                            <textarea name="expected_array[]" class="form-control" rows="3" required></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Frequency</label>
                                            <input type="text" name="frequencies[]" class="form-control">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Channel</label>
                                            <input type="text" name="channels[]" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($role === 'tester'): ?>
                                <div class="mb-3">
                                    <label class="form-label">Tester Remark</label>
                                    <textarea name="tester_remarks[]" class="form-control" rows="2"></textarea>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($role === 'test_viewer'): ?>
                                <div class="mb-3">
                                    <label class="form-label">Vendor Comment</label>
                                    <textarea name="vendor_comments[]" class="form-control" rows="2"></textarea>
                                </div>
                                <?php endif; ?>
                            </div>
                        `);
                        
                        loadFeatures(projectId);
                        $('#addTestcaseModal').modal('show');
                    }
                } else {
                    showToast('Error', response.message, 'error');
                }
            },
            error: function() {
                hideLoading();
                showToast('Error', 'Failed to check project status', 'error');
            }
        });
    }
    
    // Dynamic field management functions
    function addTestCaseField() {
        testCaseFieldCount++;
        const newField = `
            <div class="dynamic-field-group" id="testcaseGroup_${testCaseFieldCount}">
                <div class="field-header">
                    <div class="field-count">Test Case #${testCaseFieldCount}</div>
                    <div>
                        <button type="button" class="remove-field-btn" onclick="removeField('testcaseGroup_${testCaseFieldCount}')" title="Remove this field">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="add-field-btn ms-1" onclick="addTestCaseField()" title="Add another test case">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Test Case Title <span class="text-danger">*</span></label>
                            <input type="text" name="titles[]" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="statuses[]" class="form-select">
                                <option value="Pending" selected>Pending</option>
                                <option value="Pass">Pass</option>
                                <option value="Fail">Fail</option>
                                <option value="Deferred">Deferred</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select name="priorities[]" class="form-select">
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Low">Low</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Test Steps <span class="text-danger">*</span></label>
                            <textarea name="steps_array[]" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Expected Result <span class="text-danger">*</span></label>
                            <textarea name="expected_array[]" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Frequency</label>
                            <input type="text" name="frequencies[]" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Channel</label>
                            <input type="text" name="channels[]" class="form-control">
                        </div>
                    </div>
                </div>
                
                <?php if ($role === 'tester'): ?>
                <div class="mb-3">
                    <label class="form-label">Tester Remark</label>
                    <textarea name="tester_remarks[]" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>
                
                <?php if ($role === 'test_viewer'): ?>
                <div class="mb-3">
                    <label class="form-label">Vendor Comment</label>
                    <textarea name="vendor_comments[]" class="form-control" rows="2"></textarea>
                </div>
                <?php endif; ?>
            </div>
        `;
        $('#testcaseFieldsContainer').append(newField);
    }
    
    function removeField(fieldId) {
        $('#' + fieldId).remove();
        testCaseFieldCount--;
        
        // Renumber the remaining fields
        const groups = $('#testcaseFieldsContainer .dynamic-field-group');
        groups.each(function(index) {
            const newNumber = index + 1;
            $(this).find('.field-count').text(`Test Case #${newNumber}`);
            $(this).attr('id', `testcaseGroup_${newNumber}`);
            
            // Update remove button onclick
            $(this).find('.remove-field-btn').attr('onclick', `removeField('testcaseGroup_${newNumber}')`);
        });
    }
    
    function loadFeatures(projectId) {
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_features',
                project_id: projectId
            },
            success: function(response) {
                const featureSelect = document.getElementById('testcase_feature_id');
                featureSelect.innerHTML = '<option value="">-- Select Feature --</option>';
                
                if (response.success && response.features.length > 0) {
                    response.features.forEach(feature => {
                        const option = document.createElement('option');
                        option.value = feature.id;
                        option.textContent = feature.feature_name;
                        featureSelect.appendChild(option);
                    });
                }
            },
            error: function() {
                console.error('Failed to load features');
                showToast('Error', 'Failed to load features', 'error');
            }
        });
    }
    
    function submitTestCases() {
        const formData = new FormData(document.getElementById('addTestcaseForm'));
        const projectId = formData.get('project_id');
        const featureId = formData.get('feature_id');
        
        // Validate required fields
        const titles = formData.getAll('titles[]');
        const steps = formData.getAll('steps_array[]');
        const expected = formData.getAll('expected_array[]');
        
        let hasError = false;
        let errorMessage = '';
        
        if (!featureId) {
            hasError = true;
            errorMessage = 'Please select a feature';
        }
        
        for (let i = 0; i < titles.length; i++) {
            if (!titles[i].trim()) {
                hasError = true;
                errorMessage = `Test Case #${i+1}: Title is required`;
                break;
            }
            if (!steps[i].trim()) {
                hasError = true;
                errorMessage = `Test Case #${i+1}: Test Steps are required`;
                break;
            }
            if (!expected[i].trim()) {
                hasError = true;
                errorMessage = `Test Case #${i+1}: Expected Result is required`;
                break;
            }
        }
        
        if (hasError) {
            showToast('Validation Error', errorMessage, 'error');
            return;
        }
        
        showLoading();
        
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'add_testcases_bulk',
                project_id: projectId,
                feature_id: featureId,
                titles: titles,
                steps_array: steps,
                expected_array: expected,
                statuses: formData.getAll('statuses[]'),
                priorities: formData.getAll('priorities[]'),
                frequencies: formData.getAll('frequencies[]'),
                channels: formData.getAll('channels[]'),
                tester_remarks: formData.getAll('tester_remarks[]'),
                vendor_comments: formData.getAll('vendor_comments[]')
            },
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('Success', response.message, 'success');
                    $('#addTestcaseModal').modal('hide');
                    
                    // Redirect to project page after a short delay
                    setTimeout(() => {
                        window.location.href = `?page=view_project&id=${projectId}&highlight_testcase=${response.testcase_ids[0]}`;
                    }, 1500);
                } else {
                    // Show SweetAlert for terminated project error
                    if (response.message.includes('terminated')) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Project Terminated',
                            html: `<p class="text-danger">${response.message}</p>`,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#dc3545'
                        });
                    } else {
                        showToast('Error', response.message, 'error');
                    }
                }
            },
            error: function(xhr, status, error) {
                hideLoading();
                showToast('Error', 'Failed to add test cases: ' + error, 'error');
            }
        });
    }
    
    function closeEmailModal() {
        $('.email-notification-modal').remove();
        $('.modal-backdrop-email').remove();
    }
    
    function sendNotificationEmails() {
        const checkboxes = document.querySelectorAll('.recipient-checkbox:checked');
        const recipients = Array.from(checkboxes).map(cb => cb.value);
        
        if (recipients.length === 0) {
            showToast('Warning', 'Please select at least one recipient', 'warning');
            return;
        }
        
        const sendBtn = document.querySelector('.email-notification-modal .btn-primary');
        const originalText = sendBtn.innerHTML;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
        sendBtn.disabled = true;
        
        let successCount = 0;
        let errorCount = 0;
        let completedCount = 0;
        
        recipients.forEach((recipientId, index) => {
            setTimeout(() => {
                const action = testcaseInfo.type === 'tester_remark' ? 'send_test_remark_email' : 
                             testcaseInfo.type === 'vendor_comment' ? 'send_vendor_comment_email' : 'send_testcase_created_email';
                
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: action,
                        testcase_id: testcaseInfo.id,
                        recipient_id: recipientId,
                        remark: testcaseInfo.remark || '',
                        comment: testcaseInfo.comment || '',
                        commenter: testcaseInfo.commenter || 'System'
                    },
                    success: function(response) {
                        completedCount++;
                        if (response.success) {
                            successCount++;
                        } else {
                            errorCount++;
                        }
                        
                        if (completedCount === recipients.length) {
                            closeEmailModal();
                            
                            if (errorCount === 0) {
                                showToast('Success', `Emails sent successfully to ${successCount} recipient(s)`, 'success');
                            } else if (successCount === 0) {
                                showToast('Error', 'Failed to send all emails. Please check your email configuration.', 'error');
                            } else {
                                showToast('Warning', `Sent to ${successCount} recipient(s), failed for ${errorCount}`, 'warning');
                            }
                            
                            sendBtn.innerHTML = originalText;
                            sendBtn.disabled = false;
                        }
                    },
                    error: function() {
                        completedCount++;
                        errorCount++;
                        
                        if (completedCount === recipients.length) {
                            closeEmailModal();
                            showToast('Error', 'Failed to send some emails. Please check your email configuration.', 'error');
                            
                            sendBtn.innerHTML = originalText;
                            sendBtn.disabled = false;
                        }
                    }
                });
            }, index * 1000);
        });
    }
    </script>
</body>
</html>