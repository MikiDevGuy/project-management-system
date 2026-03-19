<?php
session_start();
require_once 'db.php';
require_once 'send_email.php'; // Include your email sending utility

header('Content-Type: application/json'); // Ensure JSON response

$response = ['success' => false, 'message' => 'An unknown error occurred.', 'recipients' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; // New: To differentiate between update and email send actions

    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_username = $_SESSION['username'] ?? 'Unknown tester';

    if (!$current_user_id) {
        $response['message'] = "User not logged in.";
        echo json_encode($response);
        exit;
    }

    $conn->begin_transaction(); // Start transaction

    try {
        if ($action === 'update_remark') {
            $test_case_id = $_POST['testcase_id'] ?? null;
            $new_tester_remark = $_POST['tester_remark'] ?? null;

            if (!$test_case_id || $new_tester_remark === null) {
                throw new Exception("Invalid request parameters for update_remark.");
            }

            // 1. Update test_cases table
            $update_stmt = $conn->prepare("UPDATE test_cases SET tester_remark = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->bind_param("si", $new_tester_remark, $test_case_id);
            $update_stmt->execute();

            if ($update_stmt->affected_rows > 0) {
                // Get project_id and title for activity log and email
                $tc_info_stmt = $conn->prepare("SELECT project_id, title FROM test_cases WHERE id = ?");
                $tc_info_stmt->bind_param("i", $test_case_id);
                $tc_info_stmt->execute();
                $tc_info_result = $tc_info_stmt->get_result();
                $tc_info = $tc_info_result->fetch_assoc();

                if (!$tc_info) {
                    throw new Exception("Test case not found for ID: " . $test_case_id);
                }

                $project_id = $tc_info['project_id'];
                $test_case_title = $tc_info['title'];

                // 2. Insert into tester_remark_logs
                $log_action = 'Tester Comment Updated';
                $log_description = "Tester updated comment: " . $new_tester_remark;
                $log_stmt = $conn->prepare("INSERT INTO tester_remark_logs (user_id, action, description, test_case_id, project_id) VALUES (?, ?, ?, ?, ?)");
                $log_stmt->bind_param("issii", $current_user_id, $log_action, $log_description, $test_case_id, $project_id);
                $log_stmt->execute();

                // 3. Get potential recipients to send back to JS
                $potential_recipients = [];
                $get_recipients_stmt = $conn->prepare("
                    SELECT DISTINCT u.id, u.email, u.username, u.system_role
                    FROM users u
                    LEFT JOIN project_users pu ON u.id = pu.user_id
                    WHERE (u.system_role = 'super_admin' OR (u.system_role = 'test_viewer' AND pu.project_id = ?))
                    AND u.id != ? -- Don't include the vendor who made the comment
                ");
                $get_recipients_stmt->bind_param("ii", $project_id, $current_user_id);
                $get_recipients_stmt->execute();
                $recipients_result = $get_recipients_stmt->get_result();

                while ($recipient = $recipients_result->fetch_assoc()) {
                    if (!empty($recipient['email'])) { // Only add if email exists
                        $potential_recipients[] = $recipient;
                    }
                }

                $response['success'] = true;
                $response['message'] = "Remark updated and logged successfully. Ready to send notifications.";
                $response['recipients'] = $potential_recipients;
                $response['test_case_id'] = $test_case_id; // Pass back for the second AJAX call
                $response['project_id'] = $project_id; // Pass back for the second AJAX call
                $response['test_case_title'] = $test_case_title; // Pass back for email body
                $response['current_username'] = $current_username; // Pass back for email body
                $response['new_tester_remark'] = $new_tester_remark; // Pass back for email body

            } else {
                $response['message'] = "No changes made to Tester Remark or test case not found.";
            }

        } elseif ($action === 'send_notification_emails') {
            $test_case_id = $_POST['testcase_id'] ?? null;
            $selected_recipient_ids = json_decode($_POST['selected_recipient_ids'] ?? '[]', true); // Array of IDs
            $project_id = $_POST['project_id'] ?? null;
            $test_case_title = $_POST['test_case_title'] ?? 'Unknown Test Case';
            $commenting_username = $_POST['tester_username'] ?? 'Unknown Tester';
            $vendor_comment_body = $_POST['tester_remark_body'] ?? '';

            if (!$test_case_id || empty($selected_recipient_ids) || !$project_id) {
                throw new Exception("Invalid request parameters for send_notification_emails.");
            }

            // Fetch details for selected recipients
            $email_recipients = [];
            $placeholders = implode(',', array_fill(0, count($selected_recipient_ids), '?'));
            $types = str_repeat('i', count($selected_recipient_ids));

            $get_selected_stmt = $conn->prepare("SELECT id, email, username FROM users WHERE id IN ($placeholders)");
            $get_selected_stmt->bind_param($types, ...$selected_recipient_ids);
            $get_selected_stmt->execute();
            $selected_result = $get_selected_stmt->get_result();

            while ($recipient = $selected_result->fetch_assoc()) {
                if (!empty($recipient['email'])) {
                    $email_recipients[] = $recipient;
                }
            }

            if (empty($email_recipients)) {
                $response['message'] = "No valid recipients found for selected IDs.";
                echo json_encode($response);
                $conn->rollback(); // No emails to send, rollback transaction (if any, though none for this specific action)
                exit;
            }

            $email_subject = "New Tester Remark on Test Case: " . htmlspecialchars($test_case_title);
            $email_body = "
                <p>Hello,</p>
                <p><strong>" . htmlspecialchars($commenting_username) . "</strong> has updated a tester remark on the test case: <strong>" . htmlspecialchars($test_case_title) . "</strong> (Project ID: " . htmlspecialchars($project_id) . ").</p>
                <p><strong>Remark:</strong><br>" . nl2br(htmlspecialchars($vendor_comment_body)) . "</p>
                <p>You can view the test case here: <a href='http://localhost/test-manager/view_project.php?id=" . $project_id . "&highlight_testcase=" . $test_case_id . "'>View Test Case</a></p>
                <p>Regards,<br>Your Test Manager System</p>
            ";

            foreach ($email_recipients as $recipient) {
                sendEmail($recipient['email'], $recipient['username'], $email_subject, $email_body);
            }

            $response['success'] = true;
            $response['message'] = "Notifications sent successfully!";

        } else {
            throw new Exception("Invalid action specified.");
        }

        $conn->commit(); // Commit transaction if all successful
        echo json_encode($response);

    } catch (Exception $e) {
        $conn->rollback(); // Rollback transaction on error
        $response['message'] = "Error: " . $e->getMessage();
        error_log("Vendor Comment Process Error: " . $e->getMessage());
        echo json_encode($response);
    }
} else {
    $response['message'] = "Invalid request method.";
    echo json_encode($response);
}
?>