<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

//Load Composer's autoloader (created by composer, not included with PHPMailer)
require 'vendor/autoload.php';

function sendEmail($recipientEmail, $recipientName, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'make05865@gmail.com'; // YOUR GMAIL ADDRESS
        $mail->Password   = 'apcdmlzzvqqpgais';   // YOUR GMAIL APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Sender - This should generally match your SMTP Username for best deliverability.
        // You can use a generic "no-reply" email for your system, or your main system email.
        $mail->setFrom('make05865@gmail.com', 'Test Manager System Notifications'); // Example: From your system's official email

        // Add the primary recipient
        $mail->addAddress($recipientEmail, $recipientName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        //$mail->AltBody = strip_tags($body); // Plain text for non-HTML mail clients

        $mail->send();
        // Remove `echo` statements here. Use logging for debugging.
        error_log("Email sent successfully to: " . $recipientEmail);
        return true;
    } catch (Exception $e) {
        // Log the error for server-side debugging, don't echo directly to user
        error_log("Email to $recipientEmail could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>