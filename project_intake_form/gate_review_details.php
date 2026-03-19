<?php
// gate_review_details.php
session_start();
require_once 'config/db_connection.php';
require_once 'includes/auth_check.php';

// Check permissions
if (!has_role(['super_admin', 'pm_manager'])) {
    echo '<div class="content-body">';
    echo '<div class="alert alert-danger">You do not have permission to access this page.</div>';
    echo '</div>';
    require_once 'includes/footer.php';
    exit();
}

$page_title = "Gate Review Details";
$user_id = $_SESSION['user_id'];

// Get meeting ID from URL
$meeting_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$meeting_id) {
    header("Location: gate_reviews.php");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Update meeting details
    if (isset($_POST['update_meeting_details'])) {
        $meeting_title = sanitize_input($_POST['meeting_title']);
        $meeting_date = sanitize_input($_POST['meeting_date']);
        $meeting_time = sanitize_input($_POST['meeting_time']);
        $location = sanitize_input($_POST['location']);
        $facilitator_id = intval($_POST['facilitator_id']);
        $meeting_type = sanitize_input($_POST['meeting_type']);
        $status = sanitize_input($_POST['status']);
        $agenda = sanitize_input($_POST['agenda']);
        $minutes = sanitize_input($_POST['minutes']);
        $decisions_made = sanitize_input($_POST['decisions_made']);
        $action_items = sanitize_input($_POST['action_items']);
        $next_meeting_date = sanitize_input($_POST['next_meeting_date']);
        
        $sql = "UPDATE gate_review_meetings SET 
                meeting_title = ?, meeting_date = ?, meeting_time = ?, location = ?,
                facilitator_id = ?, meeting_type = ?, status = ?, agenda = ?, minutes = ?,
                decisions_made = ?, action_items = ?, next_meeting_date = ?, updated_at = NOW()
                WHERE id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssssisssssssi", 
            $meeting_title, $meeting_date, $meeting_time, $location,
            $facilitator_id, $meeting_type, $status, $agenda, $minutes,
            $decisions_made, $action_items, $next_meeting_date, $meeting_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Meeting details updated successfully!";
            // Log the update
            log_meeting_action($meeting_id, $user_id, 'Meeting details updated');
        } else {
            $error = "Error updating meeting: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    // Add attendee
    if (isset($_POST['add_attendee'])) {
        $user_id_attendee = intval($_POST['user_id']);
        $role = sanitize_input($_POST['role']);
        
        // Check if already an attendee
        $check_sql = "SELECT id FROM gate_review_attendees 
                     WHERE meeting_id = ? AND user_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ii", $meeting_id, $user_id_attendee);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "User is already an attendee for this meeting.";
        } else {
            $sql = "INSERT INTO gate_review_attendees (meeting_id, user_id, role) 
                   VALUES (?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iis", $meeting_id, $user_id_attendee, $role);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Attendee added successfully!";
                log_meeting_action($meeting_id, $user_id, 'Added attendee');
            } else {
                $error = "Error adding attendee: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Update attendee status
    if (isset($_POST['update_attendee_status'])) {
        $attendee_id = intval($_POST['attendee_id']);
        $attendance_status = sanitize_input($_POST['attendance_status']);
        $notes = sanitize_input($_POST['notes']);
        
        $confirmation_date = $attendance_status == 'Confirmed' ? date('Y-m-d H:i:s') : NULL;
        
        $sql = "UPDATE gate_review_attendees SET 
                attendance_status = ?, notes = ?, confirmation_date = ?
                WHERE id = ? AND meeting_id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssii", $attendance_status, $notes, $confirmation_date, $attendee_id, $meeting_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Attendee status updated!";
            log_meeting_action($meeting_id, $user_id, 'Updated attendee status');
        } else {
            $error = "Error updating attendee status: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    // Add review item
    if (isset($_POST['add_review_item'])) {
        $project_intake_id = intval($_POST['project_intake_id']);
        $presentation_order = intval($_POST['presentation_order']);
        $presentation_duration = intval($_POST['presentation_duration']);
        $presenter_id = intval($_POST['presenter_id']);
        
        // Check if item already exists
        $check_sql = "SELECT id FROM gate_review_items 
                     WHERE meeting_id = ? AND project_intake_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ii", $meeting_id, $project_intake_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Project is already in the review list.";
        } else {
            $sql = "INSERT INTO gate_review_items (meeting_id, project_intake_id, 
                    presentation_order, presentation_duration, presenter_id) 
                   VALUES (?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iiiii", $meeting_id, $project_intake_id,
                                   $presentation_order, $presentation_duration, $presenter_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "Project added to review list!";
                log_meeting_action($meeting_id, $user_id, 'Added review item');
            } else {
                $error = "Error adding project: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Update review item decision
    if (isset($_POST['update_item_decision'])) {
        $item_id = intval($_POST['item_id']);
        $decision = sanitize_input($_POST['decision']);
        $decision_notes = sanitize_input($_POST['decision_notes']);
        $reviewer_comments = sanitize_input($_POST['reviewer_comments']);
        $follow_up_actions = sanitize_input($_POST['follow_up_actions']);
        
        // If scoring is provided
        $score_strategic = isset($_POST['score_strategic']) ? intval($_POST['score_strategic']) : NULL;
        $score_financial = isset($_POST['score_financial']) ? intval($_POST['score_financial']) : NULL;
        $score_operational = isset($_POST['score_operational']) ? intval($_POST['score_operational']) : NULL;
        $score_technical = isset($_POST['score_technical']) ? intval($_POST['score_technical']) : NULL;
        $score_risk = isset($_POST['score_risk']) ? intval($_POST['score_risk']) : NULL;
        $score_urgency = isset($_POST['score_urgency']) ? intval($_POST['score_urgency']) : NULL;
        
        // Calculate total score if all scores provided
        $total_score = NULL;
        if ($score_strategic && $score_financial && $score_operational && 
            $score_technical && $score_risk && $score_urgency) {
            $weights = [
                'strategic' => 0.25,
                'financial' => 0.20,
                'operational' => 0.15,
                'technical' => 0.15,
                'risk' => 0.15,
                'urgency' => 0.10
            ];
            
            $total_score = ($score_strategic * $weights['strategic'] * 100) +
                          ($score_financial * $weights['financial'] * 100) +
                          ($score_operational * $weights['operational'] * 100) +
                          ($score_technical * $weights['technical'] * 100) +
                          ($score_risk * $weights['risk'] * 100) +
                          ($score_urgency * $weights['urgency'] * 100);
        }
        
        $status = $decision != 'Pending' ? 'Reviewed' : 'Pending';
        
        $sql = "UPDATE gate_review_items SET 
                decision = ?, decision_notes = ?, reviewer_comments = ?, 
                follow_up_actions = ?, status = ?, 
                score_strategic = ?, score_financial = ?, score_operational = ?,
                score_technical = ?, score_risk = ?, score_urgency = ?,
                total_score = ?, updated_at = NOW()
                WHERE id = ? AND meeting_id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssssiiiiiiidii", 
            $decision, $decision_notes, $reviewer_comments, $follow_up_actions, $status,
            $score_strategic, $score_financial, $score_operational,
            $score_technical, $score_risk, $score_urgency, $total_score,
            $item_id, $meeting_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // If accepted, update project intake status
            if ($decision == 'Accept') {
                $update_intake_sql = "UPDATE project_intakes pi
                                     JOIN gate_review_items gri ON pi.id = gri.project_intake_id
                                     SET pi.status = 'Gate Review Approved'
                                     WHERE gri.id = ?";
                $update_intake_stmt = mysqli_prepare($conn, $update_intake_sql);
                mysqli_stmt_bind_param($update_intake_stmt, "i", $item_id);
                mysqli_stmt_execute($update_intake_stmt);
                mysqli_stmt_close($update_intake_stmt);
            }
            
            $_SESSION['success'] = "Review decision updated!";
            log_meeting_action($meeting_id, $user_id, 'Updated review decision');
        } else {
            $error = "Error updating decision: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    // Add follow-up action
    if (isset($_POST['add_followup_action'])) {
        $item_id = intval($_POST['item_id']);
        $action_description = sanitize_input($_POST['action_description']);
        $assigned_to = intval($_POST['assigned_to']);
        $due_date = sanitize_input($_POST['due_date']);
        $priority = sanitize_input($_POST['priority']);
        
        $sql = "INSERT INTO gate_review_actions 
                (meeting_id, item_id, action_description, assigned_to, due_date, priority, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iisissi", $meeting_id, $item_id, $action_description,
                               $assigned_to, $due_date, $priority, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Follow-up action added!";
            log_meeting_action($meeting_id, $user_id, 'Added follow-up action');
        } else {
            $error = "Error adding action: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    // Update action status
    if (isset($_POST['update_action_status'])) {
        $action_id = intval($_POST['action_id']);
        $status = sanitize_input($_POST['status']);
        $completion_notes = sanitize_input($_POST['completion_notes']);
        
        $completion_date = $status == 'Completed' ? date('Y-m-d') : NULL;
        
        $sql = "UPDATE gate_review_actions SET 
                status = ?, completion_notes = ?, completion_date = ?, updated_at = NOW()
                WHERE id = ? AND meeting_id = ?";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssii", $status, $completion_notes, $completion_date, $action_id, $meeting_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success'] = "Action status updated!";
            log_meeting_action($meeting_id, $user_id, 'Updated action status');
        } else {
            $error = "Error updating action: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    
    // Upload document
    if (isset($_POST['upload_document'])) {
        $document_type = sanitize_input($_POST['document_type']);
        $document_name = sanitize_input($_POST['document_name']);
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : NULL;
        $version = sanitize_input($_POST['version']);
        $status = sanitize_input($_POST['status']);
        
        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] == 0) {
            $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
            $file_name = $_FILES['document_file']['name'];
            $file_tmp = $_FILES['document_file']['tmp_name'];
            $file_size = $_FILES['document_file']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if (in_array($file_ext, $allowed_types)) {
                if ($file_size <= 10485760) { // 10MB
                    $upload_dir = 'uploads/gate_reviews/' . $meeting_id . '/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $new_file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file_name);
                    $file_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $sql = "INSERT INTO gate_review_documents 
                                (meeting_id, item_id, document_type, document_name, 
                                 file_path, file_size, uploaded_by, version, status)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "iisssiiss", $meeting_id, $item_id, $document_type,
                                               $document_name, $file_path, $file_size, $user_id, $version, $status);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $_SESSION['success'] = "Document uploaded successfully!";
                            log_meeting_action($meeting_id, $user_id, 'Uploaded document');
                        } else {
                            $error = "Error saving document info: " . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt);
                    } else {
                        $error = "Error uploading file.";
                    }
                } else {
                    $error = "File size too large (max 10MB).";
                }
            } else {
                $error = "Invalid file type. Allowed: " . implode(', ', $allowed_types);
            }
        } else {
            $error = "Please select a file to upload.";
        }
    }
}

// Function to log meeting actions
function log_meeting_action($meeting_id, $user_id, $action) {
    global $conn;
    $sql = "INSERT INTO checkpoint_logs (project_intake_id, user_id, action, details) 
           VALUES (0, ?, 'Gate Review', ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "is", $user_id, $action);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Get meeting details
$meeting = [];
$meeting_query = "SELECT grm.*, u.username as facilitator_name, 
                  (SELECT COUNT(*) FROM gate_review_items WHERE meeting_id = grm.id) as item_count,
                  (SELECT COUNT(*) FROM gate_review_attendees WHERE meeting_id = grm.id) as attendee_count
                  FROM gate_review_meetings grm
                  LEFT JOIN users u ON grm.facilitator_id = u.id
                  WHERE grm.id = ?";
$meeting_stmt = mysqli_prepare($conn, $meeting_query);
mysqli_stmt_bind_param($meeting_stmt, "i", $meeting_id);
mysqli_stmt_execute($meeting_stmt);
$meeting_result = mysqli_stmt_get_result($meeting_stmt);
$meeting = mysqli_fetch_assoc($meeting_result);
mysqli_stmt_close($meeting_stmt);

if (!$meeting) {
    header("Location: gate_reviews.php");
    exit();
}

// Get attendees
$attendees = [];
$attendees_query = "SELECT gra.*, u.username, u.email, u.system_role
                    FROM gate_review_attendees gra
                    JOIN users u ON gra.user_id = u.id
                    WHERE gra.meeting_id = ?
                    ORDER BY gra.role, u.username";
$attendees_stmt = mysqli_prepare($conn, $attendees_query);
mysqli_stmt_bind_param($attendees_stmt, "i", $meeting_id);
mysqli_stmt_execute($attendees_stmt);
$attendees_result = mysqli_stmt_get_result($attendees_stmt);
while ($row = mysqli_fetch_assoc($attendees_result)) {
    $attendees[] = $row;
}
mysqli_stmt_close($attendees_stmt);

// Get review items
$review_items = [];
$items_query = "SELECT gri.*, pi.project_name, pi.department_id, 
                d.department_name, u.username as presenter_name
                FROM gate_review_items gri
                JOIN project_intakes pi ON gri.project_intake_id = pi.id
                LEFT JOIN departments d ON pi.department_id = d.id
                LEFT JOIN users u ON gri.presenter_id = u.id
                WHERE gri.meeting_id = ?
                ORDER BY gri.presentation_order ASC, gri.id ASC";
$items_stmt = mysqli_prepare($conn, $items_query);
mysqli_stmt_bind_param($items_stmt, "i", $meeting_id);
mysqli_stmt_execute($items_stmt);
$items_result = mysqli_stmt_get_result($items_stmt);
while ($row = mysqli_fetch_assoc($items_result)) {
    $review_items[] = $row;
}
mysqli_stmt_close($items_stmt);

// Get follow-up actions
$actions = [];
$actions_query = "SELECT ga.*, u.username as assigned_to_name, 
                  u2.username as created_by_name, pi.project_name
                  FROM gate_review_actions ga
                  LEFT JOIN users u ON ga.assigned_to = u.id
                  LEFT JOIN users u2 ON ga.created_by = u2.id
                  LEFT JOIN gate_review_items gri ON ga.item_id = gri.id
                  LEFT JOIN project_intakes pi ON gri.project_intake_id = pi.id
                  WHERE ga.meeting_id = ?
                  ORDER BY ga.priority DESC, ga.due_date ASC";
$actions_stmt = mysqli_prepare($conn, $actions_query);
mysqli_stmt_bind_param($actions_stmt, "i", $meeting_id);
mysqli_stmt_execute($actions_stmt);
$actions_result = mysqli_stmt_get_result($actions_stmt);
while ($row = mysqli_fetch_assoc($actions_result)) {
    $row['is_overdue'] = ($row['due_date'] < date('Y-m-d') && $row['status'] != 'Completed');
    $actions[] = $row;
}
mysqli_stmt_close($actions_stmt);

// Get documents
$documents = [];
$docs_query = "SELECT gd.*, u.username as uploaded_by_name, pi.project_name
               FROM gate_review_documents gd
               LEFT JOIN users u ON gd.uploaded_by = u.id
               LEFT JOIN gate_review_items gri ON gd.item_id = gri.id
               LEFT JOIN project_intakes pi ON gri.project_intake_id = pi.id
               WHERE gd.meeting_id = ?
               ORDER BY gd.upload_date DESC";
$docs_stmt = mysqli_prepare($conn, $docs_query);
mysqli_stmt_bind_param($docs_stmt, "i", $meeting_id);
mysqli_stmt_execute($docs_stmt);
$docs_result = mysqli_stmt_get_result($docs_stmt);
while ($row = mysqli_fetch_assoc($docs_result)) {
    $documents[] = $row;
}
mysqli_stmt_close($docs_stmt);

// Get available users for attendees
$available_users = [];
$users_query = "SELECT id, username, email, system_role 
                FROM users 
                WHERE system_role IN ('super_admin', 'pm_manager', 'pm_employee', 'pm_viewer')
                AND id NOT IN (SELECT user_id FROM gate_review_attendees WHERE meeting_id = ?)
                ORDER BY username";
$users_stmt = mysqli_prepare($conn, $users_query);
mysqli_stmt_bind_param($users_stmt, "i", $meeting_id);
mysqli_stmt_execute($users_stmt);
$users_result = mysqli_stmt_get_result($users_stmt);
while ($row = mysqli_fetch_assoc($users_result)) {
    $available_users[] = $row;
}
mysqli_stmt_close($users_stmt);

// Get available projects for review
$available_projects = [];
$projects_query = "SELECT pi.id, pi.project_name, d.department_name, u.username as submitter
                   FROM project_intakes pi
                   LEFT JOIN departments d ON pi.department_id = d.id
                   LEFT JOIN users u ON pi.submitted_by = u.id
                   WHERE pi.status IN ('Approved', 'Gate Review Pending')
                   AND pi.id NOT IN (
                       SELECT project_intake_id FROM gate_review_items 
                       WHERE meeting_id = ? AND decision != 'Reject'
                   )
                   ORDER BY pi.project_name";
$projects_stmt = mysqli_prepare($conn, $projects_query);
mysqli_stmt_bind_param($projects_stmt, "i", $meeting_id);
mysqli_stmt_execute($projects_stmt);
$projects_result = mysqli_stmt_get_result($projects_stmt);
while ($row = mysqli_fetch_assoc($projects_result)) {
    $available_projects[] = $row;
}
mysqli_stmt_close($projects_stmt);

// Get all users for assignment
$all_users = [];
$all_users_query = "SELECT id, username FROM users ORDER BY username";
$all_users_result = mysqli_query($conn, $all_users_query);
while ($row = mysqli_fetch_assoc($all_users_result)) {
    $all_users[] = $row;
}

$page_css = '
<style>
    .meeting-header {
        background: linear-gradient(135deg, #273274, #3498db);
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    
    .meeting-status-badge {
        font-size: 0.9rem;
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: 600;
    }
    
    .status-scheduled { background: #3498db; }
    .status-inprogress { background: #f39c12; color: #333; }
    .status-completed { background: #27ae60; }
    .status-cancelled { background: #e74c3c; }
    .status-rescheduled { background: #9b59b6; }
    
    .nav-tabs .nav-link {
        font-weight: 600;
        color: #6c757d;
        border: none;
        padding: 10px 20px;
    }
    
    .nav-tabs .nav-link.active {
        color: #273274;
        border-bottom: 3px solid #273274;
        background: transparent;
    }
    
    .info-card {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .info-card h6 {
        color: #273274;
        margin-bottom: 10px;
    }
    
    .attendee-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        transition: all 0.3s;
    }
    
    .attendee-card:hover {
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .attendance-status {
        font-size: 0.8rem;
        padding: 3px 10px;
        border-radius: 15px;
        font-weight: 600;
    }
    
    .status-invited { background: #e9ecef; color: #495057; }
    .status-confirmed { background: #d4edda; color: #155724; }
    .status-declined { background: #f8d7da; color: #721c24; }
    .status-attended { background: #cce5ff; color: #004085; }
    .status-absent { background: #fff3cd; color: #856404; }
    
    .review-item-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        position: relative;
    }
    
    .review-item-card.pending { border-left: 4px solid #6c757d; }
    .review-item-card.presenting { border-left: 4px solid #f39c12; }
    .review-item-card.reviewed { border-left: 4px solid #27ae60; }
    .review-item-card.deferred { border-left: 4px solid #9b59b6; }
    .review-item-card.cancelled { border-left: 4px solid #e74c3c; }
    
    .decision-badge {
        font-size: 0.8rem;
        padding: 5px 15px;
        border-radius: 20px;
        font-weight: 600;
    }
    
    .decision-pending { background: #e9ecef; color: #495057; }
    .decision-accept { background: #d4edda; color: #155724; }
    .decision-conditional { background: #d1ecf1; color: #0c5460; }
    .decision-revise { background: #fff3cd; color: #856404; }
    .decision-reject { background: #f8d7da; color: #721c24; }
    .decision-defer { background: #e2e3e5; color: #383d41; }
    
    .score-circle {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 1.2rem;
        margin: 0 auto 10px;
    }
    
    .score-excellent { background: #d4edda; color: #155724; }
    .score-good { background: #d1ecf1; color: #0c5460; }
    .score-fair { background: #fff3cd; color: #856404; }
    .score-poor { background: #f8d7da; color: #721c24; }
    
    .action-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        position: relative;
    }
    
    .action-card.overdue {
        border-left: 4px solid #e74c3c;
        background: #fff5f5;
    }
    
    .action-card.completed {
        border-left: 4px solid #27ae60;
        background: #f8fff9;
        opacity: 0.8;
    }
    
    .priority-badge {
        font-size: 0.7rem;
        padding: 3px 8px;
        border-radius: 10px;
        font-weight: 600;
    }
    
    .priority-low { background: #d4edda; color: #155724; }
    .priority-medium { background: #fff3cd; color: #856404; }
    .priority-high { background: #f8d7da; color: #721c24; }
    .priority-critical { background: #e74c3c; color: white; }
    
    .document-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .document-icon {
        font-size: 2rem;
        color: #6c757d;
        margin-right: 15px;
    }
    
    .file-size {
        font-size: 0.8rem;
        color: #6c757d;
    }
    
    .agenda-item {
        padding: 10px;
        background: #f8f9fa;
        border-left: 3px solid #273274;
        margin-bottom: 10px;
        border-radius: 0 5px 5px 0;
    }
    
    .minutes-section {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: "";
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #dee2e6;
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-item::before {
        content: "";
        position: absolute;
        left: -23px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #273274;
        background: white;
    }
    
    .tab-content {
        padding: 20px;
        background: white;
        border: 1px solid #dee2e6;
        border-top: none;
        border-radius: 0 0 8px 8px;
    }
    
    .score-input {
        width: 60px;
        text-align: center;
    }
</style>
';

require_once 'includes/header.php';
?>

<div class="content-body">
    <!-- Meeting Header -->
    <div class="meeting-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2"><?php echo htmlspecialchars($meeting['meeting_title']); ?></h1>
                <div class="d-flex align-items-center mb-2">
                    <span class="meeting-status-badge status-<?php echo strtolower(str_replace(' ', '', $meeting['status'])); ?>">
                        <?php echo $meeting['status']; ?>
                    </span>
                    <span class="ms-3">
                        <i class="fas fa-calendar-alt me-1"></i>
                        <?php echo date('F d, Y', strtotime($meeting['meeting_date'])); ?>
                    </span>
                    <span class="ms-3">
                        <i class="fas fa-clock me-1"></i>
                        <?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?>
                    </span>
                    <?php if ($meeting['location']): ?>
                    <span class="ms-3">
                        <i class="fas fa-map-marker-alt me-1"></i>
                        <?php echo htmlspecialchars($meeting['location']); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center">
                    <span>
                        <i class="fas fa-user-tie me-1"></i>
                        Facilitator: <?php echo htmlspecialchars($meeting['facilitator_name'] ?? 'Not assigned'); ?>
                    </span>
                    <span class="ms-3">
                        <i class="fas fa-list me-1"></i>
                        <?php echo $meeting['item_count']; ?> Review Items
                    </span>
                    <span class="ms-3">
                        <i class="fas fa-users me-1"></i>
                        <?php echo $meeting['attendee_count']; ?> Attendees
                    </span>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="btn-group">
                    <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#editMeetingModal">
                        <i class="fas fa-edit me-1"></i>Edit Meeting
                    </button>
                    <a href="gate_reviews.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-1"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Messages -->
    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo $_SESSION['success']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    
    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs" id="meetingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                <i class="fas fa-eye me-1"></i>Overview
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="review-items-tab" data-bs-toggle="tab" data-bs-target="#review-items" type="button">
                <i class="fas fa-tasks me-1"></i>Review Items (<?php echo count($review_items); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="attendees-tab" data-bs-toggle="tab" data-bs-target="#attendees" type="button">
                <i class="fas fa-users me-1"></i>Attendees (<?php echo count($attendees); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="actions-tab" data-bs-toggle="tab" data-bs-target="#actions" type="button">
                <i class="fas fa-clipboard-list me-1"></i>Actions (<?php echo count($actions); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button">
                <i class="fas fa-file-alt me-1"></i>Documents (<?php echo count($documents); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="minutes-tab" data-bs-toggle="tab" data-bs-target="#minutes" type="button">
                <i class="fas fa-sticky-note me-1"></i>Meeting Minutes
            </button>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="meetingTabsContent">
        <!-- Overview Tab -->
        <div class="tab-pane fade show active" id="overview" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Agenda -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Meeting Agenda</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($meeting['agenda']): ?>
                            <div class="agenda-content">
                                <?php echo nl2br(htmlspecialchars($meeting['agenda'])); ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No agenda has been set for this meeting.</p>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editMeetingModal">
                                    <i class="fas fa-plus me-1"></i>Add Agenda
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="info-card">
                                <h6><i class="fas fa-chart-pie me-2"></i>Review Decisions</h6>
                                <?php
                                $decision_stats = [
                                    'Accept' => 0,
                                    'Conditional Accept' => 0,
                                    'Revise' => 0,
                                    'Reject' => 0,
                                    'Defer' => 0,
                                    'Pending' => 0
                                ];
                                
                                foreach ($review_items as $item) {
                                    if (isset($decision_stats[$item['decision']])) {
                                        $decision_stats[$item['decision']]++;
                                    }
                                }
                                ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Accept</span>
                                        <span class="badge bg-success"><?php echo $decision_stats['Accept']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $decision_stats['Accept'] > 0 ? ($decision_stats['Accept'] / count($review_items)) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Conditional</span>
                                        <span class="badge bg-info"><?php echo $decision_stats['Conditional Accept']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-info" style="width: <?php echo $decision_stats['Conditional Accept'] > 0 ? ($decision_stats['Conditional Accept'] / count($review_items)) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Revise</span>
                                        <span class="badge bg-warning"><?php echo $decision_stats['Revise']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo $decision_stats['Revise'] > 0 ? ($decision_stats['Revise'] / count($review_items)) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Pending</span>
                                        <span class="badge bg-secondary"><?php echo $decision_stats['Pending']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-secondary" style="width: <?php echo $decision_stats['Pending'] > 0 ? ($decision_stats['Pending'] / count($review_items)) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-4">
                            <div class="info-card">
                                <h6><i class="fas fa-tasks me-2"></i>Action Items Status</h6>
                                <?php
                                $action_stats = [
                                    'Pending' => 0,
                                    'In Progress' => 0,
                                    'Completed' => 0,
                                    'Overdue' => 0,
                                    'Cancelled' => 0
                                ];
                                
                                foreach ($actions as $action) {
                                    if ($action['is_overdue'] && $action['status'] != 'Completed') {
                                        $action_stats['Overdue']++;
                                    } elseif (isset($action_stats[$action['status']])) {
                                        $action_stats[$action['status']]++;
                                    }
                                }
                                
                                $total_actions = count($actions);
                                ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Completed</span>
                                        <span class="badge bg-success"><?php echo $action_stats['Completed']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $total_actions > 0 ? ($action_stats['Completed'] / $total_actions) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>In Progress</span>
                                        <span class="badge bg-warning"><?php echo $action_stats['In Progress']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-warning" style="width: <?php echo $total_actions > 0 ? ($action_stats['In Progress'] / $total_actions) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Overdue</span>
                                        <span class="badge bg-danger"><?php echo $action_stats['Overdue']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-danger" style="width: <?php echo $total_actions > 0 ? ($action_stats['Overdue'] / $total_actions) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between">
                                        <span>Pending</span>
                                        <span class="badge bg-secondary"><?php echo $action_stats['Pending']; ?></span>
                                    </div>
                                    <div class="progress mb-2" style="height: 5px;">
                                        <div class="progress-bar bg-secondary" style="width: <?php echo $total_actions > 0 ? ($action_stats['Pending'] / $total_actions) * 100 : 0; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Meeting Information -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Meeting Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong><i class="fas fa-calendar-alt me-2"></i>Date & Time</strong>
                                <p class="mb-1"><?php echo date('F d, Y', strtotime($meeting['meeting_date'])); ?></p>
                                <p class="mb-0"><?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?></p>
                            </div>
                            
                            <?php if ($meeting['location']): ?>
                            <div class="mb-3">
                                <strong><i class="fas fa-map-marker-alt me-2"></i>Location</strong>
                                <p class="mb-0"><?php echo htmlspecialchars($meeting['location']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <strong><i class="fas fa-user-tie me-2"></i>Facilitator</strong>
                                <p class="mb-0"><?php echo htmlspecialchars($meeting['facilitator_name'] ?? 'Not assigned'); ?></p>
                            </div>
                            
                            <div class="mb-3">
                                <strong><i class="fas fa-tag me-2"></i>Meeting Type</strong>
                                <p class="mb-0">
                                    <span class="badge bg-secondary"><?php echo $meeting['meeting_type']; ?></span>
                                </p>
                            </div>
                            
                            <div class="mb-3">
                                <strong><i class="fas fa-clock me-2"></i>Duration</strong>
                                <p class="mb-0">
                                    <?php
                                    $total_duration = 0;
                                    foreach ($review_items as $item) {
                                        $total_duration += $item['presentation_duration'];
                                    }
                                    echo $total_duration . ' minutes total';
                                    ?>
                                </p>
                            </div>
                            
                            <?php if ($meeting['next_meeting_date']): ?>
                            <div class="mb-3">
                                <strong><i class="fas fa-calendar-plus me-2"></i>Next Meeting</strong>
                                <p class="mb-0"><?php echo date('F d, Y', strtotime($meeting['next_meeting_date'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addReviewItemModal">
                                    <i class="fas fa-plus me-1"></i>Add Review Item
                                </button>
                                <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                                    <i class="fas fa-user-plus me-1"></i>Add Attendee
                                </button>
                                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                    <i class="fas fa-upload me-1"></i>Upload Document
                                </button>
                                <?php if ($meeting['status'] == 'Scheduled'): ?>
                                <button class="btn btn-warning start-meeting-btn" data-id="<?php echo $meeting_id; ?>">
                                    <i class="fas fa-play me-1"></i>Start Meeting
                                </button>
                                <?php elseif ($meeting['status'] == 'In Progress'): ?>
                                <button class="btn btn-success complete-meeting-btn" data-id="<?php echo $meeting_id; ?>">
                                    <i class="fas fa-check me-1"></i>Complete Meeting
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Review Items Tab -->
        <div class="tab-pane fade" id="review-items" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Review Items</h4>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReviewItemModal">
                    <i class="fas fa-plus me-1"></i>Add Review Item
                </button>
            </div>
            
            <?php if (empty($review_items)): ?>
            <div class="text-center py-5">
                <i class="fas fa-tasks fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Review Items</h4>
                <p class="text-muted">Add projects to review during this gate review meeting.</p>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReviewItemModal">
                    <i class="fas fa-plus me-1"></i>Add First Review Item
                </button>
            </div>
            <?php else: ?>
            <div class="row">
                <?php foreach ($review_items as $item): 
                    $score_class = '';
                    if ($item['total_score'] >= 80) {
                        $score_class = 'score-excellent';
                    } elseif ($item['total_score'] >= 70) {
                        $score_class = 'score-good';
                    } elseif ($item['total_score'] >= 60) {
                        $score_class = 'score-fair';
                    } else {
                        $score_class = 'score-poor';
                    }
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="review-item-card <?php echo strtolower($item['status']); ?>">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($item['project_name']); ?></h5>
                                <p class="mb-1 text-muted">
                                    <i class="fas fa-building me-1"></i>
                                    <?php echo htmlspecialchars($item['department_name']); ?>
                                </p>
                                <p class="mb-0 text-muted">
                                    <i class="fas fa-user me-1"></i>
                                    Presenter: <?php echo htmlspecialchars($item['presenter_name'] ?? 'Not assigned'); ?>
                                </p>
                            </div>
                            <div>
                                <span class="decision-badge decision-<?php echo strtolower(str_replace(' ', '-', $item['decision'])); ?>">
                                    <?php echo $item['decision']; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-4 text-center">
                                <div class="score-circle <?php echo $score_class; ?>">
                                    <?php echo $item['total_score'] ? number_format($item['total_score'], 1) : 'N/A'; ?>
                                </div>
                                <small class="text-muted">Total Score</small>
                            </div>
                            <div class="col-8">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Strategic</small>
                                        <div class="d-flex align-items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= ($item['score_strategic'] ?? 0) ? 'text-warning' : 'text-muted'; ?> me-1"></i>
                                            <?php endfor; ?>
                                            <span class="ms-2"><?php echo $item['score_strategic'] ?? 'N/A'; ?></span>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Financial</small>
                                        <div class="d-flex align-items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= ($item['score_financial'] ?? 0) ? 'text-warning' : 'text-muted'; ?> me-1"></i>
                                            <?php endfor; ?>
                                            <span class="ms-2"><?php echo $item['score_financial'] ?? 'N/A'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($item['decision_notes']): ?>
                        <div class="mb-3">
                            <strong>Decision Notes:</strong>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($item['decision_notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($item['follow_up_actions']): ?>
                        <div class="mb-3">
                            <strong>Follow-up Actions:</strong>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($item['follow_up_actions'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                Order: <?php echo $item['presentation_order']; ?> | 
                                Duration: <?php echo $item['presentation_duration']; ?> mins
                            </small>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary update-decision-btn" 
                                        data-id="<?php echo $item['id']; ?>"
                                        data-decision="<?php echo $item['decision']; ?>"
                                        data-notes="<?php echo htmlspecialchars($item['decision_notes']); ?>"
                                        data-comments="<?php echo htmlspecialchars($item['reviewer_comments']); ?>"
                                        data-actions="<?php echo htmlspecialchars($item['follow_up_actions']); ?>"
                                        data-strategic="<?php echo $item['score_strategic']; ?>"
                                        data-financial="<?php echo $item['score_financial']; ?>"
                                        data-operational="<?php echo $item['score_operational']; ?>"
                                        data-technical="<?php echo $item['score_technical']; ?>"
                                        data-risk="<?php echo $item['score_risk']; ?>"
                                        data-urgency="<?php echo $item['score_urgency']; ?>">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <button class="btn btn-outline-info add-action-btn" data-id="<?php echo $item['id']; ?>">
                                    <i class="fas fa-plus"></i> Action
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Attendees Tab -->
        <div class="tab-pane fade" id="attendees" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Meeting Attendees</h4>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                    <i class="fas fa-user-plus me-1"></i>Add Attendee
                </button>
            </div>
            
            <?php if (empty($attendees)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Attendees</h4>
                <p class="text-muted">Add attendees to this gate review meeting.</p>
            </div>
            <?php else: ?>
            <div class="row">
                <?php 
                $roles = ['Facilitator', 'Reviewer', 'Presenter', 'Observer', 'Secretary'];
                foreach ($roles as $role): 
                    $role_attendees = array_filter($attendees, function($a) use ($role) {
                        return $a['role'] == $role;
                    });
                    
                    if (!empty($role_attendees)):
                ?>
                <div class="col-12 mb-4">
                    <h5 class="mb-3"><?php echo $role; ?>s</h5>
                    <div class="row">
                        <?php foreach ($role_attendees as $attendee): ?>
                        <div class="col-md-4 mb-3">
                            <div class="attendee-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($attendee['username']); ?></h6>
                                        <p class="mb-1 small text-muted">
                                            <?php echo htmlspecialchars($attendee['email']); ?>
                                            <br>
                                            <?php echo htmlspecialchars($attendee['system_role']); ?>
                                        </p>
                                    </div>
                                    <span class="attendance-status status-<?php echo strtolower($attendee['attendance_status']); ?>">
                                        <?php echo $attendee['attendance_status']; ?>
                                    </span>
                                </div>
                                
                                <?php if ($attendee['notes']): ?>
                                <p class="mb-2 small"><?php echo nl2br(htmlspecialchars($attendee['notes'])); ?></p>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <?php if ($attendee['confirmation_date']): ?>
                                        Confirmed: <?php echo date('M d', strtotime($attendee['confirmation_date'])); ?>
                                        <?php endif; ?>
                                    </small>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary update-attendee-btn" 
                                                data-id="<?php echo $attendee['id']; ?>"
                                                data-status="<?php echo $attendee['attendance_status']; ?>"
                                                data-notes="<?php echo htmlspecialchars($attendee['notes']); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-danger remove-attendee-btn" 
                                                data-id="<?php echo $attendee['id']; ?>">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <?php endif; ?>
            
            <!-- Attendance Statistics -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Attendance Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php
                        $attendance_stats = [
                            'Invited' => 0,
                            'Confirmed' => 0,
                            'Attended' => 0,
                            'Declined' => 0,
                            'Absent' => 0
                        ];
                        
                        foreach ($attendees as $attendee) {
                            if (isset($attendance_stats[$attendee['attendance_status']])) {
                                $attendance_stats[$attendee['attendance_status']]++;
                            }
                        }
                        ?>
                        <div class="col-md-4 text-center">
                            <h1 class="text-primary"><?php echo $attendance_stats['Confirmed'] + $attendance_stats['Attended']; ?></h1>
                            <p class="text-muted">Confirmed / Attended</p>
                        </div>
                        <div class="col-md-4 text-center">
                            <h1 class="text-warning"><?php echo $attendance_stats['Invited']; ?></h1>
                            <p class="text-muted">Invited</p>
                        </div>
                        <div class="col-md-4 text-center">
                            <h1 class="text-danger"><?php echo $attendance_stats['Declined'] + $attendance_stats['Absent']; ?></h1>
                            <p class="text-muted">Declined / Absent</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Actions Tab -->
        <div class="tab-pane fade" id="actions" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Follow-up Actions</h4>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addActionModal">
                    <i class="fas fa-plus me-1"></i>Add Action
                </button>
            </div>
            
            <?php if (empty($actions)): ?>
            <div class="text-center py-5">
                <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Follow-up Actions</h4>
                <p class="text-muted">Add follow-up actions from this gate review meeting.</p>
            </div>
            <?php else: ?>
            <!-- Filter Actions -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-outline-primary filter-action active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-warning filter-action" data-filter="pending">Pending</button>
                        <button type="button" class="btn btn-outline-danger filter-action" data-filter="overdue">Overdue</button>
                        <button type="button" class="btn btn-outline-success filter-action" data-filter="completed">Completed</button>
                    </div>
                </div>
            </div>
            
            <!-- Actions List -->
            <div class="row" id="actionsList">
                <?php foreach ($actions as $action): 
                    $priority_class = '';
                    switch ($action['priority']) {
                        case 'Low': $priority_class = 'priority-low'; break;
                        case 'Medium': $priority_class = 'priority-medium'; break;
                        case 'High': $priority_class = 'priority-high'; break;
                        case 'Critical': $priority_class = 'priority-critical'; break;
                    }
                ?>
                <div class="col-12 mb-3 action-item" 
                     data-status="<?php echo $action['status']; ?>" 
                     data-overdue="<?php echo $action['is_overdue'] ? 'yes' : 'no'; ?>">
                    <div class="action-card <?php echo $action['is_overdue'] ? 'overdue' : ''; ?> <?php echo $action['status'] == 'Completed' ? 'completed' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="flex-grow-1">
                                <h6 class="mb-1"><?php echo htmlspecialchars($action['action_description']); ?></h6>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="badge <?php echo $priority_class; ?>">
                                        <?php echo $action['priority']; ?>
                                    </span>
                                    <?php if ($action['project_name']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($action['project_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php 
                                    echo $action['status'] == 'Completed' ? 'success' : 
                                         ($action['is_overdue'] ? 'danger' : 'warning'); 
                                ?>">
                                    <?php echo $action['status']; ?>
                                </span>
                                <p class="mb-0 small text-muted mt-1">
                                    Due: <?php echo date('M d, Y', strtotime($action['due_date'])); ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <strong>Assigned to:</strong> <?php echo htmlspecialchars($action['assigned_to_name'] ?? 'Unassigned'); ?>
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <strong>Created by:</strong> <?php echo htmlspecialchars($action['created_by_name']); ?>
                                    on <?php echo date('M d, Y', strtotime($action['created_at'])); ?>
                                </small>
                            </div>
                        </div>
                        
                        <?php if ($action['completion_notes']): ?>
                        <div class="mb-2">
                            <strong>Completion Notes:</strong>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($action['completion_notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">
                                <?php if ($action['completion_date']): ?>
                                Completed: <?php echo date('M d, Y', strtotime($action['completion_date'])); ?>
                                <?php endif; ?>
                            </small>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary update-action-btn" 
                                        data-id="<?php echo $action['id']; ?>"
                                        data-status="<?php echo $action['status']; ?>"
                                        data-notes="<?php echo htmlspecialchars($action['completion_notes']); ?>">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Action Statistics -->
            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h6 class="text-uppercase">Total Actions</h6>
                            <h2 class="mb-0"><?php echo count($actions); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h6 class="text-uppercase">Pending</h6>
                            <h2 class="mb-0"><?php echo count(array_filter($actions, function($a) { return $a['status'] == 'Pending'; })); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h6 class="text-uppercase">Overdue</h6>
                            <h2 class="mb-0"><?php echo count(array_filter($actions, function($a) { return $a['is_overdue']; })); ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h6 class="text-uppercase">Completed</h6>
                            <h2 class="mb-0"><?php echo count(array_filter($actions, function($a) { return $a['status'] == 'Completed'; })); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Documents Tab -->
        <div class="tab-pane fade" id="documents" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Meeting Documents</h4>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="fas fa-upload me-1"></i>Upload Document
                </button>
            </div>
            
            <?php if (empty($documents)): ?>
            <div class="text-center py-5">
                <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Documents</h4>
                <p class="text-muted">Upload documents related to this gate review meeting.</p>
            </div>
            <?php else: ?>
            <!-- Document Types -->
            <?php 
            $document_types = ['Agenda', 'Presentation', 'Minutes', 'Decision', 'Supporting', 'Other'];
            foreach ($document_types as $type): 
                $type_documents = array_filter($documents, function($d) use ($type) {
                    return $d['document_type'] == $type;
                });
                
                if (!empty($type_documents)):
            ?>
            <div class="mb-4">
                <h5 class="mb-3"><?php echo $type; ?> Documents</h5>
                <div class="row">
                    <?php foreach ($type_documents as $doc): ?>
                    <div class="col-md-6 mb-3">
                        <div class="document-card">
                            <div class="d-flex align-items-center">
                                <div class="document-icon">
                                    <?php
                                    $icon = 'fa-file';
                                    $ext = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                                    if (in_array($ext, ['pdf'])) $icon = 'fa-file-pdf';
                                    elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
                                    elseif (in_array($ext, ['xls', 'xlsx'])) $icon = 'fa-file-excel';
                                    elseif (in_array($ext, ['ppt', 'pptx'])) $icon = 'fa-file-powerpoint';
                                    elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) $icon = 'fa-file-image';
                                    ?>
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($doc['document_name']); ?></h6>
                                    <p class="mb-1 small text-muted">
                                        <?php if ($doc['project_name']): ?>
                                        Project: <?php echo htmlspecialchars($doc['project_name']); ?>
                                        <br>
                                        <?php endif; ?>
                                        Uploaded by: <?php echo htmlspecialchars($doc['uploaded_by_name']); ?>
                                        on <?php echo date('M d, Y', strtotime($doc['upload_date'])); ?>
                                    </p>
                                    <p class="mb-0">
                                        <span class="badge bg-secondary me-2"><?php echo $doc['document_type']; ?></span>
                                        <span class="badge bg-info"><?php echo $doc['version']; ?></span>
                                        <span class="badge bg-<?php echo $doc['status'] == 'Final' ? 'success' : ($doc['status'] == 'Archived' ? 'secondary' : 'warning'); ?>">
                                            <?php echo $doc['status']; ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <a href="<?php echo $doc['file_path']; ?>" class="btn btn-outline-primary" target="_blank">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button class="btn btn-outline-danger delete-doc-btn" data-id="<?php echo $doc['id']; ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php 
                endif;
            endforeach; 
            ?>
            <?php endif; ?>
            
            <!-- Document Statistics -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Document Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <h1 class="text-primary"><?php echo count($documents); ?></h1>
                            <p class="text-muted">Total Documents</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <h1 class="text-success"><?php echo count(array_filter($documents, function($d) { return $d['status'] == 'Final'; })); ?></h1>
                            <p class="text-muted">Final Documents</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <h1 class="text-warning"><?php echo count(array_filter($documents, function($d) { return $d['document_type'] == 'Presentation'; })); ?></h1>
                            <p class="text-muted">Presentations</p>
                        </div>
                        <div class="col-md-3 text-center">
                            <h1 class="text-info"><?php echo count(array_filter($documents, function($d) { return $d['document_type'] == 'Minutes'; })); ?></h1>
                            <p class="text-muted">Minutes</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Minutes Tab -->
        <div class="tab-pane fade" id="minutes" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Meeting Minutes Editor -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Meeting Minutes</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="update_meeting_details" value="1">
                                <div class="mb-3">
                                    <label class="form-label">Meeting Minutes</label>
                                    <textarea class="form-control" name="minutes" rows="12" 
                                              placeholder="Record meeting minutes here..."><?php echo htmlspecialchars($meeting['minutes'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Decisions Made</label>
                                    <textarea class="form-control" name="decisions_made" rows="4" 
                                              placeholder="List key decisions made during the meeting..."><?php echo htmlspecialchars($meeting['decisions_made'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Action Items</label>
                                    <textarea class="form-control" name="action_items" rows="4" 
                                              placeholder="List action items assigned during the meeting..."><?php echo htmlspecialchars($meeting['action_items'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i>Save Minutes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Meeting Timeline -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Meeting Timeline</h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <div class="timeline-item">
                                    <h6 class="mb-1">Meeting Created</h6>
                                    <p class="mb-1 small text-muted">
                                        <?php echo date('M d, Y H:i', strtotime($meeting['created_at'])); ?>
                                    </p>
                                    <p class="mb-0 small">By: System</p>
                                </div>
                                
                                <?php if ($meeting['updated_at'] != $meeting['created_at']): ?>
                                <div class="timeline-item">
                                    <h6 class="mb-1">Last Updated</h6>
                                    <p class="mb-1 small text-muted">
                                        <?php echo date('M d, Y H:i', strtotime($meeting['updated_at'])); ?>
                                    </p>
                                    <p class="mb-0 small">By: <?php echo htmlspecialchars($_SESSION['username']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($meeting['minutes']): ?>
                                <div class="timeline-item completed">
                                    <h6 class="mb-1">Minutes Recorded</h6>
                                    <p class="mb-0 small text-muted">Minutes have been recorded for this meeting.</p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($meeting['status'] == 'Completed'): ?>
                                <div class="timeline-item completed">
                                    <h6 class="mb-1">Meeting Completed</h6>
                                    <p class="mb-0 small text-muted">Meeting has been marked as completed.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Export Options -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-download me-2"></i>Export Meeting</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary export-btn" data-type="minutes">
                                    <i class="fas fa-file-pdf me-1"></i>Export Minutes (PDF)
                                </button>
                                <button class="btn btn-outline-success export-btn" data-type="decisions">
                                    <i class="fas fa-file-excel me-1"></i>Export Decisions (Excel)
                                </button>
                                <button class="btn btn-outline-info export-btn" data-type="attendance">
                                    <i class="fas fa-file-csv me-1"></i>Export Attendance (CSV)
                                </button>
                                <button class="btn btn-outline-warning export-btn" data-type="full">
                                    <i class="fas fa-file-archive me-1"></i>Full Meeting Package
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->

<!-- Edit Meeting Modal -->
<div class="modal fade" id="editMeetingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Meeting Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="update_meeting_details" value="1">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">Meeting Title</label>
                            <input type="text" class="form-control" name="meeting_title" required 
                                   value="<?php echo htmlspecialchars($meeting['meeting_title']); ?>">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label required">Meeting Type</label>
                            <select class="form-select" name="meeting_type" required>
                                <option value="Weekly" <?php echo $meeting['meeting_type'] == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                <option value="Bi-weekly" <?php echo $meeting['meeting_type'] == 'Bi-weekly' ? 'selected' : ''; ?>>Bi-weekly</option>
                                <option value="Monthly" <?php echo $meeting['meeting_type'] == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                <option value="Quarterly" <?php echo $meeting['meeting_type'] == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="Ad-hoc" <?php echo $meeting['meeting_type'] == 'Ad-hoc' ? 'selected' : ''; ?>>Ad-hoc</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Meeting Date</label>
                            <input type="date" class="form-control" name="meeting_date" required 
                                   value="<?php echo $meeting['meeting_date']; ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Meeting Time</label>
                            <input type="time" class="form-control" name="meeting_time" required 
                                   value="<?php echo date('H:i', strtotime($meeting['meeting_time'])); ?>">
                        </div>
                        
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" 
                                   value="<?php echo htmlspecialchars($meeting['location'] ?? ''); ?>"
                                   placeholder="e.g., Conference Room A, Zoom Meeting">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Facilitator</label>
                            <select class="form-select" name="facilitator_id">
                                <option value="">Select Facilitator</option>
                                <?php foreach ($all_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" 
                                    <?php echo $meeting['facilitator_id'] == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="Scheduled" <?php echo $meeting['status'] == 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                <option value="In Progress" <?php echo $meeting['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $meeting['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo $meeting['status'] == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="Rescheduled" <?php echo $meeting['status'] == 'Rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Next Meeting Date</label>
                            <input type="date" class="form-control" name="next_meeting_date" 
                                   value="<?php echo $meeting['next_meeting_date'] ?? ''; ?>">
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Agenda</label>
                            <textarea class="form-control" name="agenda" rows="4"><?php echo htmlspecialchars($meeting['agenda'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Meeting</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Review Item Modal -->
<div class="modal fade" id="addReviewItemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Review Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_review_item" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Select Project</label>
                            <select class="form-select" name="project_intake_id" required>
                                <option value="">Select Project</option>
                                <?php foreach ($available_projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo htmlspecialchars($project['project_name']); ?> 
                                    (<?php echo htmlspecialchars($project['department_name']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label required">Presentation Order</label>
                            <input type="number" class="form-control" name="presentation_order" required 
                                   min="1" value="<?php echo count($review_items) + 1; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label required">Duration (minutes)</label>
                            <input type="number" class="form-control" name="presentation_duration" required 
                                   min="5" max="60" value="15">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Presenter</label>
                            <select class="form-select" name="presenter_id">
                                <option value="">Select Presenter</option>
                                <?php foreach ($all_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add to Review</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Decision Modal -->
<div class="modal fade" id="updateDecisionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Update Review Decision</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="update_item_decision" value="1">
                    <input type="hidden" id="updateItemId" name="item_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Decision</label>
                            <select class="form-select" id="updateDecision" name="decision" required>
                                <option value="Pending">Pending</option>
                                <option value="Accept">Accept</option>
                                <option value="Conditional Accept">Conditional Accept</option>
                                <option value="Revise">Revise</option>
                                <option value="Reject">Reject</option>
                                <option value="Defer">Defer</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Decision Notes</label>
                            <textarea class="form-control" id="updateDecisionNotes" name="decision_notes" rows="3"></textarea>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Reviewer Comments</label>
                            <textarea class="form-control" id="updateReviewerComments" name="reviewer_comments" rows="3"></textarea>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Follow-up Actions</label>
                            <textarea class="form-control" id="updateFollowUpActions" name="follow_up_actions" rows="3"></textarea>
                        </div>
                        
                        <!-- Scoring Section -->
                        <div class="col-12 mb-3">
                            <h6>Scoring</h6>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Strategic Alignment (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreStrategic" 
                                           name="score_strategic" min="1" max="5">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Financial Viability (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreFinancial" 
                                           name="score_financial" min="1" max="5">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Operational Readiness (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreOperational" 
                                           name="score_operational" min="1" max="5">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Technical Feasibility (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreTechnical" 
                                           name="score_technical" min="1" max="5">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Risk & Compliance (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreRisk" 
                                           name="score_risk" min="1" max="5">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Urgency (1-5)</label>
                                    <input type="number" class="form-control score-input" id="updateScoreUrgency" 
                                           name="score_urgency" min="1" max="5">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Decision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Attendee Modal -->
<div class="modal fade" id="addAttendeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add Attendee</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_attendee" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Select User</label>
                            <select class="form-select" name="user_id" required>
                                <option value="">Select User</option>
                                <?php foreach ($available_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['username']); ?> 
                                    (<?php echo htmlspecialchars($user['system_role']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Role</label>
                            <select class="form-select" name="role" required>
                                <option value="Reviewer">Reviewer</option>
                                <option value="Presenter">Presenter</option>
                                <option value="Observer">Observer</option>
                                <option value="Facilitator">Facilitator</option>
                                <option value="Secretary">Secretary</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Attendee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Attendee Status Modal -->
<div class="modal fade" id="updateAttendeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-check me-2"></i>Update Attendee Status</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="update_attendee_status" value="1">
                    <input type="hidden" id="updateAttendeeId" name="attendee_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Attendance Status</label>
                            <select class="form-select" id="updateAttendanceStatus" name="attendance_status" required>
                                <option value="Invited">Invited</option>
                                <option value="Confirmed">Confirmed</option>
                                <option value="Declined">Declined</option>
                                <option value="Attended">Attended</option>
                                <option value="Absent">Absent</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" id="updateAttendeeNotes" name="notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Action Modal -->
<div class="modal fade" id="addActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add Follow-up Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_followup_action" value="1">
                    <input type="hidden" id="addActionItemId" name="item_id" value="">
                    
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label class="form-label required">Action Description</label>
                            <textarea class="form-control" name="action_description" rows="3" required></textarea>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Assigned To</label>
                            <select class="form-select" name="assigned_to" required>
                                <option value="">Select User</option>
                                <?php foreach ($all_users as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Due Date</label>
                            <input type="date" class="form-control" name="due_date" required 
                                   min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Priority</label>
                            <select class="form-select" name="priority" required>
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Action</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Action Status Modal -->
<div class="modal fade" id="updateActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Update Action Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="update_action_status" value="1">
                    <input type="hidden" id="updateActionId" name="action_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Status</label>
                            <select class="form-select" id="updateActionStatus" name="status" required>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                                <option value="Overdue">Overdue</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label">Completion Notes</label>
                            <textarea class="form-control" id="updateCompletionNotes" name="completion_notes" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Upload Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="upload_document" value="1">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Document Type</label>
                            <select class="form-select" name="document_type" required>
                                <option value="">Select Type</option>
                                <option value="Agenda">Agenda</option>
                                <option value="Presentation">Presentation</option>
                                <option value="Minutes">Minutes</option>
                                <option value="Decision">Decision</option>
                                <option value="Supporting">Supporting Document</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Related Review Item (Optional)</label>
                            <select class="form-select" name="item_id">
                                <option value="">None (General Document)</option>
                                <?php foreach ($review_items as $item): ?>
                                <option value="<?php echo $item['id']; ?>">
                                    <?php echo htmlspecialchars($item['project_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Document Name</label>
                            <input type="text" class="form-control" name="document_name" required 
                                   placeholder="e.g., Project Presentation Slides">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Version</label>
                            <input type="text" class="form-control" name="version" value="1.0">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="Draft">Draft</option>
                                <option value="Final" selected>Final</option>
                                <option value="Archived">Archived</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label class="form-label required">Select File</label>
                            <input type="file" class="form-control" name="document_file" required 
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                            <div class="form-text">Max file size: 10MB. Allowed: PDF, Word, Excel, PowerPoint, Images</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Upload Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$page_js = '
<script>
$(document).ready(function() {
    // Tab handling
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get("tab");
    if (activeTab) {
        $(\'#\' + activeTab + "-tab").tab("show");
    }
    
    // Update URL when tabs change
    $(\'button[data-bs-toggle="tab"]\').on("shown.bs.tab", function(e) {
        const tabId = $(e.target).attr("data-bs-target").substring(1);
        const url = new URL(window.location);
        url.searchParams.set("tab", tabId);
        window.history.replaceState({}, "", url.toString());
    });
    
    // Start meeting
    $(".start-meeting-btn").click(function() {
        if (confirm("Are you sure you want to start this meeting?")) {
            const meetingId = $(this).data("id");
            $.ajax({
                url: "ajax/update_meeting_status.php",
                method: "POST",
                data: { 
                    meeting_id: meetingId,
                    status: "In Progress"
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Error starting meeting: " + response.message);
                    }
                }
            });
        }
    });
    
    // Complete meeting
    $(".complete-meeting-btn").click(function() {
        if (confirm("Are you sure you want to mark this meeting as completed?")) {
            const meetingId = $(this).data("id");
            $.ajax({
                url: "ajax/update_meeting_status.php",
                method: "POST",
                data: { 
                    meeting_id: meetingId,
                    status: "Completed"
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Error completing meeting: " + response.message);
                    }
                }
            });
        }
    });
    
    // Update decision modal
    $(".update-decision-btn").click(function() {
        const itemId = $(this).data("id");
        const decision = $(this).data("decision");
        const notes = $(this).data("notes");
        const comments = $(this).data("comments");
        const actions = $(this).data("actions");
        const strategic = $(this).data("strategic");
        const financial = $(this).data("financial");
        const operational = $(this).data("operational");
        const technical = $(this).data("technical");
        const risk = $(this).data("risk");
        const urgency = $(this).data("urgency");
        
        $("#updateItemId").val(itemId);
        $("#updateDecision").val(decision);
        $("#updateDecisionNotes").val(notes);
        $("#updateReviewerComments").val(comments);
        $("#updateFollowUpActions").val(actions);
        $("#updateScoreStrategic").val(strategic);
        $("#updateScoreFinancial").val(financial);
        $("#updateScoreOperational").val(operational);
        $("#updateScoreTechnical").val(technical);
        $("#updateScoreRisk").val(risk);
        $("#updateScoreUrgency").val(urgency);
        
        $("#updateDecisionModal").modal("show");
    });
    
    // Add action modal
    $(".add-action-btn").click(function() {
        const itemId = $(this).data("id");
        $("#addActionItemId").val(itemId);
        $("#addActionModal").modal("show");
    });
    
    // Update attendee modal
    $(".update-attendee-btn").click(function() {
        const attendeeId = $(this).data("id");
        const status = $(this).data("status");
        const notes = $(this).data("notes");
        
        $("#updateAttendeeId").val(attendeeId);
        $("#updateAttendanceStatus").val(status);
        $("#updateAttendeeNotes").val(notes);
        
        $("#updateAttendeeModal").modal("show");
    });
    
    // Remove attendee
    $(".remove-attendee-btn").click(function() {
        if (confirm("Are you sure you want to remove this attendee?")) {
            const attendeeId = $(this).data("id");
            $.ajax({
                url: "ajax/remove_attendee.php",
                method: "POST",
                data: { 
                    attendee_id: attendeeId,
                    meeting_id: ' . $meeting_id . '
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Error removing attendee: " + response.message);
                    }
                }
            });
        }
    });
    
    // Update action modal
    $(".update-action-btn").click(function() {
        const actionId = $(this).data("id");
        const status = $(this).data("status");
        const notes = $(this).data("notes");
        
        $("#updateActionId").val(actionId);
        $("#updateActionStatus").val(status);
        $("#updateCompletionNotes").val(notes);
        
        $("#updateActionModal").modal("show");
    });
    
    // Filter actions
    $(".filter-action").click(function() {
        const filter = $(this).data("filter");
        $(".filter-action").removeClass("active");
        $(this).addClass("active");
        
        $(".action-item").each(function() {
            const status = $(this).data("status");
            const overdue = $(this).data("overdue");
            
            let show = false;
            if (filter === "all") {
                show = true;
            } else if (filter === "pending") {
                show = status === "Pending" || status === "In Progress";
            } else if (filter === "overdue") {
                show = overdue === "yes";
            } else if (filter === "completed") {
                show = status === "Completed";
            }
            
            if (show) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Export buttons
    $(".export-btn").click(function() {
        const type = $(this).data("type");
        const meetingId = ' . $meeting_id . ';
        
        let url = "ajax/export_meeting.php";
        let params = "?meeting_id=" + meetingId + "&type=" + type;
        
        // Open in new tab for download
        window.open(url + params, "_blank");
    });
    
    // Delete document
    $(".delete-doc-btn").click(function() {
        if (confirm("Are you sure you want to delete this document?")) {
            const docId = $(this).data("id");
            $.ajax({
                url: "ajax/delete_document.php",
                method: "POST",
                data: { 
                    document_id: docId,
                    meeting_id: ' . $meeting_id . '
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Error deleting document: " + response.message);
                    }
                }
            });
        }
    });
    
    // Auto-close alerts
    setTimeout(function() {
        $(".alert").alert("close");
    }, 5000);
    
    // Form validation
    $("form").submit(function(e) {
        let isValid = true;
        $(this).find("[required]").each(function() {
            if (!$(this).val().trim()) {
                $(this).addClass("is-invalid");
                isValid = false;
            } else {
                $(this).removeClass("is-invalid");
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            alert("Please fill in all required fields.");
            $(".is-invalid").first().focus();
        }
    });
    
    // File upload validation
    $("input[type=\'file\']").change(function() {
        const file = this.files[0];
        if (file) {
            const validTypes = ["application/pdf", 
                               "application/msword",
                               "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                               "application/vnd.ms-excel",
                               "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                               "application/vnd.ms-powerpoint",
                               "application/vnd.openxmlformats-officedocument.presentationml.presentation",
                               "image/jpeg", "image/png"];
            
            const maxSize = 10 * 1024 * 1024; // 10MB
            
            if (!validTypes.includes(file.type)) {
                alert("Invalid file type. Please upload a PDF, Word, Excel, PowerPoint, or Image file.");
                $(this).val("");
                return false;
            }
            
            if (file.size > maxSize) {
                alert("File size too large. Maximum size is 10MB.");
                $(this).val("");
                return false;
            }
        }
    });
});
</script>
';

require_once 'includes/footer.php';
?>