<?php
// PHPMailer configuration and functions
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer (you need to install via composer or include manually)
require '../vendor/autoload.php'; // If using Composer

function sendEmailNotification($to, $name, $subject, $message) {
    $mail = new PHPMailer(true);
    
    try {
            $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'negamuluken1@gmail.com';
        $mail->Password   = 'qnxj hyph jlgi zeqs';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        // Recipients
        $mail->setFrom('noreply@dashenbank.com', 'Dashen Bank BSPM');
        $mail->addAddress($to, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);
        
        $mail->send();
        
        // Log successful email
        global $conn;
        $sql = "INSERT INTO email_logs (recipient_email, subject, status, sent_at) VALUES (?, ?, 'sent', NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $to, $subject);
        mysqli_stmt_execute($stmt);
        
        return true;
    } catch (Exception $e) {
        // Log failed email
        global $conn;
        $sql = "INSERT INTO email_logs (recipient_email, subject, status, error_message, sent_at) VALUES (?, ?, 'failed', ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        $error = $mail->ErrorInfo;
        mysqli_stmt_bind_param($stmt, "sss", $to, $subject, $error);
        mysqli_stmt_execute($stmt);
        
        return false;
    }
}