<?php
session_start();
include 'db.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Initialize variables
$login_error = '';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Admin email
define('ADMIN_EMAIL', 'negamuluken1@gmail.com');

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Function to send email using PHPMailer
function sendEmailPHPMailer($to, $subject, $message) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'negamuluken1@gmail.com';
        $mail->Password   = 'qnxj hyph jlgi zeqs';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('noreply@dashenbank.com', 'Dashen Bank');
        $mail->addAddress($to);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to generate 4-digit OTP
function generateOTP() {
    return str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

// Function to log email
function logEmail($conn, $test_case_id, $project_id, $sender_id, $recipient_id, $recipient_email, $subject, $status, $error_message = null) {
    if ($sender_id !== null) {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $check_stmt->bind_param("i", $sender_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows === 0) {
            $sender_id = null;
        }
        $check_stmt->close();
    }
    
    $stmt = $conn->prepare("INSERT INTO email_logs (test_case_id, project_id, sender_id, recipient_id, recipient_email, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("iiiissss", $test_case_id, $project_id, $sender_id, $recipient_id, $recipient_email, $subject, $status, $error_message);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}

// Handle Login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $login_error = "Invalid security token. Please try again.";
    } else {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';

        if (empty($username) || empty($password)) {
            $login_error = "Please enter both username and password.";
        } else {
            $stmt = $conn->prepare("SELECT id, username, password, system_role, email, is_active FROM users WHERE username=?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if ($user['is_active'] != 1) {
                    $login_error = "Your account is inactive. Please contact the System Administrator.";
                } elseif (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['system_role'] = $user['system_role'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    
                    session_regenerate_id(true);
                    
                    // IMPORTANT FIX: Fetch systems this user has access to
                    $sql = "SELECT s.system_id, s.system_name, s.system_url
                            FROM user_systems us
                            JOIN systems s ON us.system_id = s.system_id
                            WHERE us.user_id = ?";
                    $stmt2 = $conn->prepare($sql);
                    if ($stmt2) {
                        $stmt2->bind_param("i", $user['id']);
                        $stmt2->execute();
                        $systems_result = $stmt2->get_result();
                        $systems = $systems_result->fetch_all(MYSQLI_ASSOC);
                        $_SESSION['systems'] = $systems;
                        
                        // Debug - you can remove this after testing
                        error_log("User ID: " . $user['id'] . " has " . count($systems) . " systems assigned");
                        
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        $login_error = "Database error: Unable to fetch user systems.";
                    }
                } else {
                    $login_error = "Invalid username or password.";
                }
            } else {
                $login_error = "Invalid username or password.";
            }
            $stmt->close();
        }
    }
}

// Handle Forgot Password Request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'send_otp') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your email address.']);
        exit();
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT id, username, email, is_active FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if ($user['is_active'] != 1) {
            echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact the System Administrator.']);
            exit();
        }
        
        // Generate OTP
        $otp = generateOTP();
        $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        // Store OTP in database
        $insert_stmt = $conn->prepare("INSERT INTO password_resets (user_id, otp, expiry, created_at) VALUES (?, ?, ?, NOW())");
        $insert_stmt->bind_param("iss", $user['id'], $otp, $expiry);
        
        if ($insert_stmt->execute()) {
            // Send email
            $subject = "Password Reset OTP - Dashen Bank";
            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #0033A0; color: white; padding: 20px; text-align: center; }
                    .content { padding: 30px; background: #f8f9fa; }
                    .otp { font-size: 36px; font-weight: bold; color: #0033A0; text-align: center; padding: 20px; letter-spacing: 5px; }
                    .footer { text-align: center; padding: 20px; color: #6c757d; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Dashen Bank - Password Reset</h2>
                    </div>
                    <div class='content'>
                        <p>Dear " . htmlspecialchars($user['username']) . ",</p>
                        <p>We received a request to reset your password. Use the following 4-digit OTP to proceed:</p>
                        <div class='otp'>" . $otp . "</div>
                        <p>This OTP will expire in 10 minutes.</p>
                        <p>If you didn't request this, please ignore this email.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; 2026 Dashen Bank. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>";
            
            if (sendEmailPHPMailer($email, $subject, $message)) {
                logEmail($conn, null, null, null, $user['id'], $email, $subject, 'sent');
                
                // Store in session
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_otp'] = $otp;
                $_SESSION['reset_expiry'] = time() + 600;
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'OTP sent successfully',
                    'email' => $email
                ]);
            } else {
                logEmail($conn, null, null, null, $user['id'], $email, $subject, 'failed', 'Failed to send email');
                echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to generate OTP. Please try again.']);
        }
        $insert_stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Email address not found in our system.']);
    }
    $stmt->close();
    exit();
}

// Handle OTP Verification
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'verify_otp') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    $otp = isset($_POST['otp']) ? trim($_POST['otp']) : '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    $session_otp = $_SESSION['reset_otp'] ?? '';
    
    if (empty($otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the OTP.']);
        exit();
    } elseif (strlen($otp) !== 4 || !ctype_digit($otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid 4-digit OTP.']);
        exit();
    } elseif (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    } elseif (!isset($_SESSION['reset_expiry']) || $_SESSION['reset_expiry'] < time()) {
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_expiry']);
        echo json_encode(['success' => false, 'message' => 'OTP session expired. Please start over.']);
        exit();
    }
    
    // First check session OTP for quick verification
    if ($session_otp === $otp) {
        $_SESSION['otp_verified'] = true;
        echo json_encode([
            'success' => true, 
            'message' => 'OTP verified successfully'
        ]);
        exit();
    }
    
    // If session OTP doesn't match, verify OTP from database
    $stmt = $conn->prepare("SELECT * FROM password_resets WHERE user_id = ? AND otp = ? AND expiry > NOW() AND used = 0 ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("is", $user_id, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $reset_record = $result->fetch_assoc();
        
        $update_stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
        $update_stmt->bind_param("i", $reset_record['id']);
        $update_stmt->execute();
        $update_stmt->close();
        
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['otp_verified'] = true;
        
        echo json_encode([
            'success' => true, 
            'message' => 'OTP verified successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please try again.']);
    }
    $stmt->close();
    exit();
}

// Handle Password Reset
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
        echo json_encode(['success' => false, 'message' => 'OTP verification required. Please start over.']);
        exit();
    } elseif (empty($new_password) || empty($confirm_password)) {
        echo json_encode(['success' => false, 'message' => 'Please enter both password fields.']);
        exit();
    } elseif (strlen($new_password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
        exit();
    } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/", $new_password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.']);
        exit();
    } elseif ($new_password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    } elseif (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    }
    
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);
    
    if ($stmt->execute()) {
        unset($_SESSION['reset_user_id']);
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_otp']);
        unset($_SESSION['reset_expiry']);
        unset($_SESSION['otp_verified']);
        
        $user_stmt = $conn->prepare("SELECT email, username FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        
        $subject = "Password Changed Successfully - Dashen Bank";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0033A0; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f8f9fa; }
                .footer { text-align: center; padding: 20px; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Dashen Bank - Password Changed</h2>
                </div>
                <div class='content'>
                    <p>Dear " . htmlspecialchars($user_data['username']) . ",</p>
                    <p>Your password has been successfully changed.</p>
                    <p>If you didn't make this change, please contact our support team immediately.</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2026 Dashen Bank. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        sendEmailPHPMailer($user_data['email'], $subject, $message);
        logEmail($conn, null, null, null, $user_id, $user_data['email'], $subject, 'sent');
        
        echo json_encode(['success' => true, 'message' => 'Password reset successfully! You can now login.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset password. Please try again.']);
    }
    $stmt->close();
    exit();
}

// Handle Resend OTP
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'resend_otp') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    $email = $_SESSION['reset_email'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (empty($email) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        exit();
    }
    
    $otp = generateOTP();
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    $insert_stmt = $conn->prepare("INSERT INTO password_resets (user_id, otp, expiry, created_at) VALUES (?, ?, ?, NOW())");
    $insert_stmt->bind_param("iss", $user_id, $otp, $expiry);
    
    if ($insert_stmt->execute()) {
        $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        
        $subject = "New Password Reset OTP - Dashen Bank";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0033A0; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f8f9fa; }
                .otp { font-size: 36px; font-weight: bold; color: #0033A0; text-align: center; padding: 20px; letter-spacing: 5px; }
                .footer { text-align: center; padding: 20px; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Dashen Bank - New OTP Requested</h2>
                </div>
                <div class='content'>
                    <p>Dear " . htmlspecialchars($user['username']) . ",</p>
                    <p>You requested a new OTP. Use the following 4-digit code to reset your password:</p>
                    <div class='otp'>" . $otp . "</div>
                    <p>This OTP will expire in 10 minutes.</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2026 Dashen Bank. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        if (sendEmailPHPMailer($email, $subject, $message)) {
            logEmail($conn, null, null, null, $user_id, $email, $subject, 'sent');
            $_SESSION['reset_otp'] = $otp;
            $_SESSION['reset_expiry'] = time() + 600;
            echo json_encode(['success' => true, 'message' => 'New OTP sent successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to generate OTP']);
    }
    $insert_stmt->close();
    exit();
}

// Handle Contact Admin
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'contact_admin') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit();
    }
    
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit();
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Valid email is required.']);
        exit();
    }
    if (empty($subject)) {
        echo json_encode(['success' => false, 'message' => 'Subject is required.']);
        exit();
    }
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Message is required.']);
        exit();
    }
    
    $email_subject = "Contact Form: " . $subject;
    $email_message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0033A0; color: white; padding: 20px; text-align: center; }
            .content { padding: 30px; background: #f8f9fa; }
            .field { margin-bottom: 15px; }
            .label { font-weight: bold; color: #0033A0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Contact Form Submission</h2>
            </div>
            <div class='content'>
                <div class='field'>
                    <div class='label'>Name:</div>
                    <div>" . htmlspecialchars($name) . "</div>
                </div>
                <div class='field'>
                    <div class='label'>Email:</div>
                    <div>" . htmlspecialchars($email) . "</div>
                </div>
                <div class='field'>
                    <div class='label'>Subject:</div>
                    <div>" . htmlspecialchars($subject) . "</div>
                </div>
                <div class='field'>
                    <div class='label'>Message:</div>
                    <div>" . nl2br(htmlspecialchars($message)) . "</div>
                </div>
            </div>
        </div>
    </body>
    </html>";
    
    if (sendEmailPHPMailer(ADMIN_EMAIL, $email_subject, $email_message)) {
        logEmail($conn, null, null, null, null, $email, $email_subject, 'sent');
        echo json_encode(['success' => true, 'message' => 'Your message has been sent to the administrator.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send message. Please try again.']);
    }
    exit();
}

// Create password_resets table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    otp VARCHAR(4) NOT NULL,
    expiry DATETIME NOT NULL,
    used TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_otp (user_id, otp, expiry),
    INDEX idx_expiry (expiry)
)");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Dashen Bank Enterprise Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow-y: auto;
            padding: 20px;
        }

        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 20s infinite linear;
        }

        .shape-1 {
            width: 300px;
            height: 300px;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 200px;
            height: 200px;
            top: 60%;
            right: 10%;
            animation-delay: -5s;
        }

        .shape-3 {
            width: 150px;
            height: 150px;
            bottom: 20%;
            left: 20%;
            animation-delay: -10s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-30px) rotate(120deg); }
            66% { transform: translateY(30px) rotate(240deg); }
        }

        .login-wrapper {
            width: 100%;
            max-width: 450px;
            margin: auto;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .login-card:hover {
            transform: translateY(-5px);
        }

        .login-header {
            background: linear-gradient(135deg, #0033A0, #002366);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .bank-logo {
            width: 100px;
            height: auto;
            margin-bottom: 20px;
        }

        .login-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .login-subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
        }

        .login-body {
            padding: 40px 30px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .input-group {
            position: relative;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            border-color: #0033A0;
            box-shadow: 0 0 0 4px rgba(0, 51, 160, 0.1);
            outline: none;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .form-control:focus + .input-icon {
            color: #0033A0;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            z-index: 2;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #0033A0;
        }

        .btn-login, .btn-action {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #0033A0, #002366);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-login:hover, .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px rgba(0, 51, 160, 0.3);
        }

        .forgot-link, .contact-link {
            color: #0033A0;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: color 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin-top: 16px;
        }

        .contact-link {
            margin-top: 8px;
            font-weight: 500;
            opacity: 0.9;
        }

        .forgot-link:hover, .contact-link:hover {
            color: #002366;
        }

        .forgot-link i, .contact-link i {
            margin-right: 8px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }

        .alert i {
            margin-right: 12px;
            font-size: 1.2rem;
        }

        .login-footer {
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.8);
        }

        .footer-text {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .copyright {
            color: #94a3b8;
            font-size: 0.85rem;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, #0033A0, #002366);
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 20px;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .modal-body {
            padding: 30px;
        }

        .otp-input {
            font-size: 32px;
            letter-spacing: 12px;
            text-align: center;
            font-weight: 700;
            font-family: monospace;
            padding: 16px;
        }

        .otp-timer {
            font-size: 1rem;
            font-weight: 600;
            color: #0033A0;
            margin-top: 12px;
        }

        .timer-expired {
            color: #dc3545;
        }

        @media (max-width: 576px) {
            .login-header {
                padding: 30px 20px;
            }
            .login-body {
                padding: 30px 20px;
            }
            .login-title {
                font-size: 1.75rem;
            }
            .bank-logo {
                width: 80px;
            }
        }

        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 6px;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-group {
            animation: slideInUp 0.5s ease-out forwards;
            opacity: 0;
        }

        .form-group:nth-child(1) { animation-delay: 0.1s; }
        .form-group:nth-child(2) { animation-delay: 0.2s; }
        .btn-login { animation: slideInUp 0.5s ease-out 0.3s forwards; opacity: 0; }
        .forgot-link { animation: slideInUp 0.5s ease-out 0.4s forwards; opacity: 0; }
        .contact-link { animation: slideInUp 0.5s ease-out 0.5s forwards; opacity: 0; }
    </style>
</head>
<body>
    <div class="animated-bg">
        <div class="floating-shape shape-1"></div>
        <div class="floating-shape shape-2"></div>
        <div class="floating-shape shape-3"></div>
    </div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <img src="Images/DashenLogo12.png" alt="Dashen Bank" class="bank-logo" onerror="this.style.display='none'">
                <h1 class="login-title">PM PULSE PORTAL</h1>
                <p class="login-subtitle">Secure Access to Banking Systems</p>
            </div>

            <div class="login-body">
                <?php if (!empty($login_error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div class="flex-grow-1">
                            <?= htmlspecialchars($login_error) ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="post" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="login" value="1">
                    
                    <div class="form-group">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <input type="text" 
                                   class="form-control" 
                                   id="username" 
                                   name="username" 
                                   placeholder="Enter your username" 
                                   autocomplete="username" 
                                   required>
                            <i class="bi bi-person-fill input-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter your password" 
                                   autocomplete="current-password" 
                                   required>
                            <i class="bi bi-lock-fill input-icon"></i>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="bi bi-eye-fill"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn-login" id="loginBtn">
                            <span class="btn-text">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                            </span>
                            <span class="btn-loading" style="display: none;">
                                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                Signing In...
                            </span>
                        </button>
                    </div>

                    <a href="#" class="forgot-link" onclick="openForgotPasswordModal()">
                        <i class="bi bi-key"></i>Forgot Password?
                    </a>

                    <a href="#" class="contact-link" onclick="openContactAdminModal()">
                        <i class="bi bi-envelope"></i>Contact System Administrator
                    </a>
                </form>
            </div>

            <div class="login-footer">
                <p class="footer-text">Secure Enterprise Portal</p>
                <p class="copyright">&copy; 2026 Dashen Bank. All rights reserved.</p>
            </div>
        </div>
    </div>

    <!-- Email Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-envelope me-2"></i>Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-envelope" style="font-size: 48px; color: #0033A0;"></i>
                        <h6 class="mt-3">Enter your registered email address</h6>
                        <p class="text-muted small">We'll send a 4-digit OTP to verify your identity</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="modal_email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="modal_email" placeholder="Enter your email" required>
                    </div>
                    
                    <button type="button" class="btn-action" onclick="sendOTP()" id="sendOtpBtn">
                        <i class="bi bi-send me-2"></i>Send OTP
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- OTP Modal -->
    <div class="modal fade" id="otpModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-shield-lock me-2"></i>Verify OTP
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-shield-lock" style="font-size: 48px; color: #0033A0;"></i>
                        <h6 class="mt-3">Enter 4-Digit OTP</h6>
                        <p class="text-muted small" id="otpSentEmail"></p>
                        <div class="otp-timer" id="otpTimer"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="modal_otp" class="form-label">4-Digit OTP</label>
                        <input type="text" class="form-control otp-input" id="modal_otp" placeholder="____" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" required>
                    </div>
                    
                    <button type="button" class="btn-action mb-2" id="verifyOtpBtn" onclick="verifyOTP()">
                        <i class="bi bi-check-circle me-2"></i>Verify OTP
                    </button>
                    
                    <div class="text-center">
                        <small class="text-muted">Didn't receive OTP? 
                            <a href="#" onclick="resendOTP(event)" class="text-primary" id="resendOtpLink">Resend</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-key me-2"></i>Create New Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 48px;"></i>
                        <h6 class="mt-3">Create New Password</h6>
                        <p class="text-muted small">Password must be at least 8 characters with uppercase, lowercase, and numbers</p>
                    </div>
                    
                    <div class="form-group position-relative">
                        <label for="modal_new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="modal_new_password" placeholder="Enter new password" required>
                        <button type="button" class="password-toggle" onclick="toggleModalPassword('modal_new_password', event)">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                    
                    <div class="form-group position-relative">
                        <label for="modal_confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="modal_confirm_password" placeholder="Confirm new password" required>
                        <button type="button" class="password-toggle" onclick="toggleModalPassword('modal_confirm_password', event)">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                    
                    <button type="button" class="btn-action" onclick="resetPassword()" id="resetPasswordBtn">
                        <i class="bi bi-arrow-repeat me-2"></i>Reset Password
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Admin Modal -->
    <div class="modal fade" id="contactAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-envelope me-2"></i>Contact Administrator
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-headset" style="font-size: 48px; color: #0033A0;"></i>
                        <h6 class="mt-3">How can we help you?</h6>
                        <p class="text-muted small">Our administrator will respond within 24 hours</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_name" class="form-label">Your Name</label>
                        <input type="text" class="form-control" id="contact_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_email" class="form-label">Your Email</label>
                        <input type="email" class="form-control" id="contact_email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_subject" class="form-label">Subject</label>
                        <input type="text" class="form-control" id="contact_subject" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_message" class="form-label">Message</label>
                        <textarea class="form-control" id="contact_message" rows="4" required></textarea>
                    </div>
                    
                    <button type="button" class="btn-action" onclick="sendContactMessage()" id="sendContactBtn">
                        <i class="bi bi-send me-2"></i>Send Message
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle me-2"></i>Success
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 64px;"></i>
                    <p class="mt-3 mb-0" id="successMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle me-2"></i>Error
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <i class="bi bi-exclamation-circle-fill text-danger" style="font-size: 64px;"></i>
                    <p class="mt-3 mb-0" id="errorMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 mb-0">Processing...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Modal instances
        let emailModal, otpModal, resetModal, contactAdminModal, successModal, errorModal, loadingModal;
        
        document.addEventListener('DOMContentLoaded', function() {
            emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
            otpModal = new bootstrap.Modal(document.getElementById('otpModal'));
            resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
            contactAdminModal = new bootstrap.Modal(document.getElementById('contactAdminModal'));
            successModal = new bootstrap.Modal(document.getElementById('successModal'));
            errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
            loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
        });

        // Toggle Password Visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            password.type = password.type === 'password' ? 'text' : 'password';
            icon.className = password.type === 'password' ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
        });

        function toggleModalPassword(fieldId, event) {
            const field = document.getElementById(fieldId);
            const icon = event.currentTarget.querySelector('i');
            field.type = field.type === 'password' ? 'text' : 'password';
            icon.className = field.type === 'password' ? 'bi bi-eye-fill' : 'bi bi-eye-slash-fill';
        }

        function openForgotPasswordModal() {
            document.getElementById('modal_email').value = '';
            emailModal.show();
        }

        function openContactAdminModal() {
            document.getElementById('contact_name').value = '';
            document.getElementById('contact_email').value = '';
            document.getElementById('contact_subject').value = '';
            document.getElementById('contact_message').value = '';
            contactAdminModal.show();
        }

        async function sendOTP() {
            const email = document.getElementById('modal_email').value.trim();
            
            if (!email) {
                showError('Please enter your email address.');
                return;
            }
            
            if (!validateEmail(email)) {
                showError('Please enter a valid email address.');
                return;
            }
            
            const sendOtpBtn = document.getElementById('sendOtpBtn');
            const originalText = sendOtpBtn.innerHTML;
            sendOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Sending...';
            sendOtpBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('action', 'send_otp');
            formData.append('email', email);
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    emailModal.hide();
                    setTimeout(() => {
                        document.getElementById('modal_otp').value = '';
                        document.getElementById('otpSentEmail').textContent = `OTP sent to ${data.email}`;
                        otpModal.show();
                        startOTPTimer(600);
                    }, 500);
                    showSuccess(data.message);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                sendOtpBtn.innerHTML = originalText;
                sendOtpBtn.disabled = false;
            }
        }

        async function verifyOTP() {
            const otp = document.getElementById('modal_otp').value.trim();
            
            if (!otp) {
                showError('Please enter the OTP.');
                return;
            }
            
            if (otp.length !== 4 || !/^\d+$/.test(otp)) {
                showError('Please enter a valid 4-digit OTP.');
                return;
            }
            
            const verifyBtn = document.getElementById('verifyOtpBtn');
            const originalText = verifyBtn.innerHTML;
            verifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Verifying...';
            verifyBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('action', 'verify_otp');
            formData.append('otp', otp);
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    otpModal.hide();
                    if (window.timerInterval) clearInterval(window.timerInterval);
                    setTimeout(() => {
                        document.getElementById('modal_new_password').value = '';
                        document.getElementById('modal_confirm_password').value = '';
                        resetModal.show();
                    }, 500);
                    showSuccess(data.message);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                verifyBtn.innerHTML = originalText;
                verifyBtn.disabled = false;
            }
        }

        async function resetPassword() {
            const newPassword = document.getElementById('modal_new_password').value;
            const confirmPassword = document.getElementById('modal_confirm_password').value;
            
            if (!newPassword || !confirmPassword) {
                showError('Please enter both password fields.');
                return;
            }
            
            if (newPassword.length < 8) {
                showError('Password must be at least 8 characters long.');
                return;
            }
            
            const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
            if (!passwordRegex.test(newPassword)) {
                showError('Password must contain at least one uppercase letter, one lowercase letter, and one number.');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showError('Passwords do not match.');
                return;
            }
            
            const resetBtn = document.getElementById('resetPasswordBtn');
            const originalText = resetBtn.innerHTML;
            resetBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Resetting...';
            resetBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('action', 'reset_password');
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    resetModal.hide();
                    setTimeout(() => showSuccess(data.message), 500);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                resetBtn.innerHTML = originalText;
                resetBtn.disabled = false;
            }
        }

        async function resendOTP(event) {
            event.preventDefault();
            const resendLink = document.getElementById('resendOtpLink');
            const originalText = resendLink.innerHTML;
            resendLink.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Sending...';
            resendLink.style.pointerEvents = 'none';
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('action', 'resend_otp');
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    if (window.timerInterval) clearInterval(window.timerInterval);
                    startOTPTimer(600);
                    showSuccess(data.message);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('Failed to resend OTP. Please try again.');
            } finally {
                resendLink.innerHTML = originalText;
                resendLink.style.pointerEvents = 'auto';
            }
        }

        async function sendContactMessage() {
            const name = document.getElementById('contact_name').value.trim();
            const email = document.getElementById('contact_email').value.trim();
            const subject = document.getElementById('contact_subject').value.trim();
            const message = document.getElementById('contact_message').value.trim();
            
            if (!name) { showError('Please enter your name.'); return; }
            if (!email || !validateEmail(email)) { showError('Please enter a valid email address.'); return; }
            if (!subject) { showError('Please enter a subject.'); return; }
            if (!message) { showError('Please enter a message.'); return; }
            
            const sendBtn = document.getElementById('sendContactBtn');
            const originalText = sendBtn.innerHTML;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Sending...';
            sendBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            formData.append('action', 'contact_admin');
            formData.append('name', name);
            formData.append('email', email);
            formData.append('subject', subject);
            formData.append('message', message);
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    contactAdminModal.hide();
                    showSuccess(data.message);
                } else {
                    showError(data.message);
                }
            } catch (error) {
                showError('Failed to send message. Please try again.');
            } finally {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
            }
        }

        function startOTPTimer(seconds) {
            const otpTimer = document.getElementById('otpTimer');
            const verifyBtn = document.getElementById('verifyOtpBtn');
            let timeLeft = seconds;
            
            function updateTimer() {
                if (timeLeft <= 0) {
                    clearInterval(window.timerInterval);
                    otpTimer.textContent = 'OTP expired! Please request again.';
                    otpTimer.classList.add('timer-expired');
                    verifyBtn.disabled = true;
                    return;
                }
                const minutes = Math.floor(timeLeft / 60);
                const secs = timeLeft % 60;
                otpTimer.textContent = `OTP expires in: ${minutes}:${secs.toString().padStart(2, '0')}`;
                timeLeft--;
            }
            updateTimer();
            window.timerInterval = setInterval(updateTimer, 1000);
        }

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            successModal.show();
        }

        function showError(message) {
            document.getElementById('errorMessage').textContent = message;
            errorModal.show();
        }

        function validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        document.getElementById('modal_otp').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 4);
        });

        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const btnText = loginBtn.querySelector('.btn-text');
        const btnLoading = loginBtn.querySelector('.btn-loading');

        loginForm.addEventListener('submit', function(e) {
            let isValid = true;
            const inputs = loginForm.querySelectorAll('input[required]');
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    const formGroup = input.closest('.form-group');
                    let errorElement = formGroup.querySelector('.error-message');
                    if (!errorElement) {
                        errorElement = document.createElement('div');
                        errorElement.className = 'error-message';
                        formGroup.appendChild(errorElement);
                    }
                    errorElement.textContent = 'This field is required';
                    input.style.borderColor = '#dc3545';
                } else {
                    const formGroup = input.closest('.form-group');
                    const errorElement = formGroup.querySelector('.error-message');
                    if (errorElement) errorElement.remove();
                    input.style.borderColor = '#e2e8f0';
                }
            });

            if (!isValid) {
                e.preventDefault();
            } else {
                btnText.style.display = 'none';
                btnLoading.style.display = 'inline-block';
                loginBtn.disabled = true;
            }
        });

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        document.getElementById('otpModal').addEventListener('hidden.bs.modal', () => {
            if (window.timerInterval) clearInterval(window.timerInterval);
        });
    </script>
</body>
</html>