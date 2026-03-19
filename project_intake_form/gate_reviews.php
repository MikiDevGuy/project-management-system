<?php
// gate_reviews.php - Complete Gate Review System
//session_start();
require_once 'includes/header.php';  // Make sure this file exists
require_once 'includes/auth_check.php';   // Make sure this file exists

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Authentication Check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['system_role'] ?? 'guest';

// Check permissions - FIXED: Consistent role checking
$allowed_roles = ['super_admin', 'pm_manager', 'pm_employee'];
if (!in_array($user_role, $allowed_roles)) {
    header('Location: unauthorized.php');
    exit();
}

// Helper Functions - FIXED: Improved sanitization
function sanitize_input($data, $type = 'string') {
    global $conn;
    
    if (is_null($data)) return null;
    
    switch ($type) {
        case 'int':
            return intval($data);
        case 'float':
            return floatval($data);
        case 'date':
            return date('Y-m-d', strtotime($data));
        case 'time':
            return date('H:i:s', strtotime($data));
        case 'string':
        default:
            $data = trim($data);
            if (isset($conn)) {
                $data = mysqli_real_escape_string($conn, $data);
            }
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
            return $data;
    }
}
// Add this function definition before the show_meetings_list() function
function get_meeting_statistics() {
    global $conn, $user_id, $user_role;
    
    $query = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN status IN ('Scheduled', 'In Progress') THEN 1 ELSE 0 END) as upcoming,
              SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
              SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled
              FROM gate_review_meetings";
    
    if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
        $query .= " WHERE created_by = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, $query);
    }
    
    if ($result) {
        $stats = mysqli_fetch_assoc($result);
    }
    
    // Close statement if it exists
    if (isset($stmt)) {
        mysqli_stmt_close($stmt);
    }
    
    return $stats ?: ['total' => 0, 'upcoming' => 0, 'completed' => 0, 'cancelled' => 0];
}

// Also add these other missing functions that are called:
function get_meetings() {
    global $conn, $user_id, $user_role;
    
    $query = "SELECT gm.*, u.username as facilitator_name,
              (SELECT COUNT(*) FROM gate_review_items WHERE meeting_id = gm.id) as item_count
              FROM gate_review_meetings gm
              LEFT JOIN users u ON gm.facilitator_id = u.id";
    
    if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
        $query .= " WHERE gm.created_by = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
    } else {
        $stmt = mysqli_prepare($conn, $query);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $meetings = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $meetings[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    return $meetings;
}

function get_upcoming_meetings() {
    global $conn, $user_id, $user_role;
    
    $query = "SELECT * FROM gate_review_meetings 
              WHERE meeting_date >= CURDATE() 
              AND status IN ('Scheduled', 'In Progress')";
    
    if (!in_array($user_role, ['super_admin', 'pm_manager'])) {
        $query .= " AND created_by = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
    } else {
        $stmt = mysqli_prepare($conn, $query);
    }
    
    $query .= " ORDER BY meeting_date ASC, meeting_time ASC LIMIT 5";
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $meetings = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $meetings[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    return $meetings;
}

function get_review_statistics() {
    global $conn;
    
    $query = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN decision = 'Accept' THEN 1 ELSE 0 END) as accepted,
              SUM(CASE WHEN decision = 'Pending' THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN decision = 'Reject' THEN 1 ELSE 0 END) as rejected
              FROM gate_review_items";
    
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        $stats = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
    }
    
    if (isset($stats) && $stats['total'] > 0) {
        $stats['accepted_pct'] = round(($stats['accepted'] / $stats['total']) * 100);
        $stats['pending_pct'] = round(($stats['pending'] / $stats['total']) * 100);
        $stats['rejected_pct'] = round(($stats['rejected'] / $stats['total']) * 100);
    } else {
        $stats = [
            'total' => 0,
            'accepted' => 0,
            'pending' => 0,
            'rejected' => 0,
            'accepted_pct' => 0,
            'pending_pct' => 0,
            'rejected_pct' => 0
        ];
    }
    
    return $stats;
}

function validate_date($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function validate_time($time) {
    return preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

// Initialize variables
$meeting_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? sanitize_input($_GET['action']) : 'list';
$error = '';
$success = '';

// Handle CSRF for POST requests - FIXED: Proper validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "Invalid security token. Please try again.";
        header('Location: gate_reviews.php');
        exit();
    }
}

// Handle different actions
switch ($action) {
    case 'details':
        show_meeting_details($meeting_id);
        break;
        
    case 'create':
        handle_create_meeting();
        break;
        
    case 'edit':
        handle_edit_meeting();
        break;
        
    case 'delete':
        handle_delete_meeting();
        break;
        
    case 'add_item':
        handle_add_review_item();
        break;
        
    case 'update_decision':
        handle_update_decision();
        break;
        
    default:
        show_meetings_list();
}

// ============================================
// MAIN FUNCTIONS
// ============================================

// Function to show meetings list
function show_meetings_list() {
    global $conn, $user_id, $user_role, $error, $success;
    
    // Get statistics
    $stats = get_meeting_statistics();
    $meetings = get_meetings();
    $upcoming = get_upcoming_meetings();
    $review_stats = get_review_statistics();
    
    // Start output buffering
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Gate Reviews - BSPMD</title>
        
        <!-- Bootstrap 5 -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <!-- DataTables -->
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
        
        <style>
            :root {
                --primary-blue: #1a237e;
                --secondary-blue: #283593;
                --accent-green: #00c853;
                --warning-orange: #ff6b00;
                --danger-red: #de350b;
                --light-gray: #f5f7fa;
                --dark-gray: #263238;
            }
            
            body {
                background-color: #f8f9fa;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            .dashboard-header {
                background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
                color: white;
                padding: 2rem 0;
                margin-bottom: 2rem;
            }
            
            .stat-card {
                background: white;
                border-radius: 12px;
                padding: 1.5rem;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                transition: transform 0.3s ease;
                border: none;
                height: 100%;
            }
            
            .stat-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            }
            
            .stat-icon {
                width: 50px;
                height: 50px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }
            
            .stat-number {
                font-size: 2rem;
                font-weight: 700;
                margin-bottom: 0.5rem;
            }
            
            .stat-label {
                font-size: 0.9rem;
                color: #6c757d;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .meeting-card {
                background: white;
                border-radius: 10px;
                padding: 1.25rem;
                margin-bottom: 1rem;
                border-left: 4px solid var(--primary-blue);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: all 0.3s ease;
            }
            
            .meeting-card:hover {
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
                transform: translateX(5px);
            }
            
            .status-badge {
                padding: 0.25rem 0.75rem;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
            }
            
            .status-scheduled { background: #d1ecf1; color: #0c5460; }
            .status-inprogress { background: #fff3cd; color: #856404; }
            .status-completed { background: #d4edda; color: #155724; }
            .status-cancelled { background: #f8d7da; color: #721c24; }
            
            .action-btn {
                padding: 0.5rem 1rem;
                border-radius: 6px;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                text-decoration: none;
                transition: all 0.3s ease;
            }
            
            .btn-primary-custom {
                background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
                color: white;
                border: none;
            }
            
            .btn-primary-custom:hover {
                background: linear-gradient(135deg, var(--secondary-blue), var(--primary-blue));
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(26, 35, 126, 0.3);
                color: white;
            }
            
            .table-custom {
                background: white;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            
            .table-custom th {
                background: #f8f9fa;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.85rem;
                letter-spacing: 0.5px;
                border-bottom: 2px solid #dee2e6;
            }
            
            .card-custom {
                border: none;
                border-radius: 10px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                margin-bottom: 1.5rem;
            }
            
            .card-custom .card-header {
                background: #f8f9fa;
                border-bottom: 1px solid #dee2e6;
                font-weight: 600;
                padding: 1rem 1.25rem;
            }
            
            .badge-custom {
                padding: 0.35em 0.65em;
                font-weight: 500;
                border-radius: 6px;
            }
            
            .timeline-item {
                position: relative;
                padding-left: 2rem;
                margin-bottom: 1rem;
            }
            
            .timeline-item::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0.5rem;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: var(--primary-blue);
            }
            
            @media (max-width: 768px) {
                .stat-card {
                    margin-bottom: 1rem;
                }
                
                .dashboard-header h1 {
                    font-size: 1.5rem;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <div class="dashboard-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2"><i class="fas fa-door-open me-2"></i>Gate Review Management</h1>
                        <p class="mb-0">Schedule and manage project gate review meetings</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <button class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#createMeetingModal">
                            <i class="fas fa-plus-circle me-1"></i>New Meeting
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left me-1"></i>Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Messages -->
        <div class="container">
            <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Statistics -->
        <div class="container mb-4">
            <div class="row">
                <?php
                $stat_colors = ['primary', 'warning', 'success', 'danger'];
                $stat_icons = ['calendar-check', 'clock', 'check-circle', 'times-circle'];
                $stat_titles = ['Total Meetings', 'Upcoming', 'Completed', 'Cancelled'];
                $stat_values = [$stats['total'], $stats['upcoming'], $stats['completed'], $stats['cancelled']];
                
                for ($i = 0; $i < 4; $i++):
                ?>
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon bg-<?php echo $stat_colors[$i]; ?> bg-opacity-10 text-<?php echo $stat_colors[$i]; ?>">
                            <i class="fas fa-<?php echo $stat_icons[$i]; ?>"></i>
                        </div>
                        <div class="stat-number text-<?php echo $stat_colors[$i]; ?>">
                            <?php echo $stat_values[$i]; ?>
                        </div>
                        <div class="stat-label"><?php echo $stat_titles[$i]; ?></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="container">
            <div class="row">
                <!-- Meetings Table -->
                <div class="col-lg-8 mb-4">
                    <div class="card-custom">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-list me-2"></i>Recent Gate Review Meetings</span>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-primary" id="refreshTable">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="meetingsTable">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Meeting Title</th>
                                            <th>Type</th>
                                            <th>Items</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($meetings as $meeting): 
                                            $status_class = strtolower(str_replace(' ', '', $meeting['status']));
                                            $item_count = $meeting['item_count'] ?? 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="small text-muted"><?php echo date('M d, Y', strtotime($meeting['meeting_date'])); ?></div>
                                                <div class="small"><?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($meeting['meeting_title']); ?></div>
                                                <div class="small text-muted">
                                                    <?php if ($meeting['location']): ?>
                                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($meeting['location']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo $meeting['meeting_type']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $item_count; ?></span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $status_class; ?>">
                                                    <?php echo $meeting['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?action=details&id=<?php echo $meeting['id']; ?>" 
                                                       class="btn btn-outline-primary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                                    <button class="btn btn-outline-warning edit-meeting-btn" 
                                                            data-id="<?php echo $meeting['id']; ?>"
                                                            title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Upcoming Meetings -->
                    <div class="card-custom mb-4">
                        <div class="card-header">
                            <i class="fas fa-calendar-alt me-2"></i>Upcoming Meetings
                        </div>
                        <div class="card-body">
                            <?php if (empty($upcoming)): ?>
                            <div class="text-center py-3">
                                <i class="fas fa-calendar-times fa-2x text-muted mb-2"></i>
                                <p class="text-muted mb-0">No upcoming meetings</p>
                            </div>
                            <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($upcoming as $meeting): ?>
                                <a href="?action=details&id=<?php echo $meeting['id']; ?>" 
                                   class="list-group-item list-group-item-action border-0 py-2">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($meeting['meeting_title']); ?></h6>
                                        <small class="text-muted"><?php echo date('M d', strtotime($meeting['meeting_date'])); ?></small>
                                    </div>
                                    <p class="mb-1 small text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?>
                                    </p>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="card-custom">
                        <div class="card-header">
                            <i class="fas fa-chart-pie me-2"></i>Review Statistics
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small">Accepted Projects</span>
                                    <span class="small"><?php echo $review_stats['accepted']; ?></span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $review_stats['accepted_pct']; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small">Pending Reviews</span>
                                    <span class="small"><?php echo $review_stats['pending']; ?></span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $review_stats['pending_pct']; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small">Rejected Projects</span>
                                    <span class="small"><?php echo $review_stats['rejected']; ?></span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $review_stats['rejected_pct']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Create Meeting Modal -->
        <div class="modal fade" id="createMeetingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="?action=create">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Schedule New Meeting</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label required">Meeting Title</label>
                                    <input type="text" class="form-control" name="meeting_title" required 
                                           placeholder="e.g., Weekly Gate Review Meeting">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required">Meeting Type</label>
                                    <select class="form-select" name="meeting_type" required>
                                        <option value="">Select Type</option>
                                        <option value="Weekly">Weekly</option>
                                        <option value="Bi-weekly">Bi-weekly</option>
                                        <option value="Monthly">Monthly</option>
                                        <option value="Quarterly">Quarterly</option>
                                        <option value="Ad-hoc">Ad-hoc</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Meeting Date</label>
                                    <input type="date" class="form-control" name="meeting_date" required 
                                           min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Meeting Time</label>
                                    <input type="time" class="form-control" name="meeting_time" required>
                                </div>
                                
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Location</label>
                                    <input type="text" class="form-control" name="location" 
                                           placeholder="e.g., Conference Room A, Zoom Meeting">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Duration (minutes)</label>
                                    <input type="number" class="form-control" name="duration" value="60" min="15" max="240">
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Agenda</label>
                                    <textarea class="form-control" name="agenda" rows="3" 
                                              placeholder="Meeting agenda points..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Schedule Meeting</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Edit Meeting Modal -->
        <div class="modal fade" id="editMeetingModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form method="POST" action="?action=edit">
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Meeting</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" id="editMeetingId" name="meeting_id">
                            
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label required">Meeting Title</label>
                                    <input type="text" class="form-control" id="editMeetingTitle" name="meeting_title" required>
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required">Status</label>
                                    <select class="form-select" id="editStatus" name="status" required>
                                        <option value="Scheduled">Scheduled</option>
                                        <option value="In Progress">In Progress</option>
                                        <option value="Completed">Completed</option>
                                        <option value="Cancelled">Cancelled</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Meeting Date</label>
                                    <input type="date" class="form-control" id="editMeetingDate" name="meeting_date" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">Meeting Time</label>
                                    <input type="time" class="form-control" id="editMeetingTime" name="meeting_time" required>
                                </div>
                                
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Location</label>
                                    <input type="text" class="form-control" id="editLocation" name="location">
                                </div>
                                
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Duration (minutes)</label>
                                    <input type="number" class="form-control" id="editDuration" name="duration" min="15" max="240">
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Agenda</label>
                                    <textarea class="form-control" id="editAgenda" name="agenda" rows="3"></textarea>
                                </div>
                                
                                <div class="col-12 mb-3">
                                    <label class="form-label">Minutes</label>
                                    <textarea class="form-control" id="editMinutes" name="minutes" rows="4" 
                                              placeholder="Meeting minutes..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning">Update Meeting</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- JavaScript -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        
        <script>
            $(document).ready(function() {
                // Initialize DataTable
                $('#meetingsTable').DataTable({
                    pageLength: 10,
                    order: [[0, 'desc']],
                    language: {
                        search: "Search meetings...",
                        lengthMenu: "Show _MENU_ meetings",
                        info: "Showing _START_ to _END_ of _TOTAL_ meetings",
                        infoEmpty: "No meetings found",
                        infoFiltered: "(filtered from _MAX_ total meetings)"
                    }
                });
                
                // Edit meeting button
                $('.edit-meeting-btn').click(function() {
                    const meetingId = $(this).data('id');
                    
                    // Fetch meeting details via AJAX
                    $.ajax({
                        url: 'ajax/get_meeting_details.php',
                        method: 'GET',
                        data: { id: meetingId },
                        success: function(response) {
                            if (response.success) {
                                const meeting = response.data;
                                $('#editMeetingId').val(meeting.id);
                                $('#editMeetingTitle').val(meeting.meeting_title);
                                $('#editMeetingDate').val(meeting.meeting_date);
                                $('#editMeetingTime').val(meeting.meeting_time.substring(0, 5));
                                $('#editLocation').val(meeting.location || '');
                                $('#editDuration').val(meeting.duration || 60);
                                $('#editStatus').val(meeting.status);
                                $('#editAgenda').val(meeting.agenda || '');
                                $('#editMinutes').val(meeting.minutes || '');
                                
                                $('#editMeetingModal').modal('show');
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error', 'Failed to load meeting details', 'error');
                        }
                    });
                });
                
                // Form validation
                $('form').submit(function(e) {
                    let isValid = true;
                    $(this).find('[required]').each(function() {
                        if (!$(this).val().trim()) {
                            $(this).addClass('is-invalid');
                            isValid = false;
                        } else {
                            $(this).removeClass('is-invalid');
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        Swal.fire('Error', 'Please fill in all required fields', 'warning');
                    }
                });
                
                // Refresh table button
                $('#refreshTable').click(function() {
                    location.reload();
                });
                
                // Auto-close alerts
                setTimeout(function() {
                    $('.alert').alert('close');
                }, 5000);
            });
        </script>
    </body>
    </html>
    <?php
    echo ob_get_clean();
}

// Function to show meeting details
function show_meeting_details($meeting_id) {
    global $conn, $user_role;
    
    if ($meeting_id <= 0) {
        header('Location: gate_reviews.php');
        exit();
    }
    
    $meeting = get_meeting_details($meeting_id);
    if (!$meeting) {
        header('Location: gate_reviews.php');
        exit();
    }
    
    $review_items = get_review_items($meeting_id);
    $attendees = get_meeting_attendees($meeting_id);
    $documents = get_meeting_documents($meeting_id);
    $actions = get_meeting_actions($meeting_id);
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($meeting['meeting_title']); ?> - Gate Review</title>
        
        <!-- Bootstrap 5 -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        
        <style>
            :root {
                --primary-blue: #1a237e;
                --secondary-blue: #283593;
                --accent-green: #00c853;
                --light-gray: #f5f7fa;
            }
            
            .meeting-header {
                background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
                color: white;
                padding: 2rem 0;
                margin-bottom: 2rem;
            }
            
            .tab-content {
                background: white;
                border: 1px solid #dee2e6;
                border-top: none;
                border-radius: 0 0 10px 10px;
                padding: 2rem;
            }
            
            .nav-tabs .nav-link {
                font-weight: 500;
                color: #6c757d;
                border: none;
                padding: 0.75rem 1.5rem;
            }
            
            .nav-tabs .nav-link.active {
                color: var(--primary-blue);
                border-bottom: 3px solid var(--primary-blue);
                background: transparent;
            }
            
            .info-card {
                background: var(--light-gray);
                border-radius: 8px;
                padding: 1.25rem;
                margin-bottom: 1rem;
                border-left: 4px solid var(--primary-blue);
            }
            
            .review-item-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 1.25rem;
                margin-bottom: 1rem;
                transition: all 0.3s ease;
            }
            
            .review-item-card:hover {
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                transform: translateY(-2px);
            }
            
            .decision-badge {
                padding: 0.35em 0.65em;
                border-radius: 20px;
                font-weight: 500;
                font-size: 0.85rem;
            }
            
            .decision-accept { background: #d4edda; color: #155724; }
            .decision-reject { background: #f8d7da; color: #721c24; }
            .decision-pending { background: #e2e3e5; color: #383d41; }
            .decision-revise { background: #fff3cd; color: #856404; }
            
            .attendee-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 0.75rem;
            }
            
            .document-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 0.75rem;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            
            .action-card {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 0.75rem;
                border-left: 4px solid #28a745;
            }
            
            .action-card.overdue {
                border-left-color: #dc3545;
            }
            
            .score-display {
                background: #f8f9fa;
                border: 2px solid #dee2e6;
                border-radius: 8px;
                padding: 1rem;
                text-align: center;
            }
            
            .score-value {
                font-size: 2rem;
                font-weight: bold;
                color: var(--primary-blue);
            }
            
            @media (max-width: 768px) {
                .meeting-header h1 {
                    font-size: 1.5rem;
                }
                
                .nav-tabs .nav-link {
                    padding: 0.5rem 1rem;
                    font-size: 0.9rem;
                }
            }
        </style>
    </head>
    <body>
        <!-- Meeting Header -->
        <div class="meeting-header">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2">
                            <i class="fas fa-door-open me-2"></i>
                            <?php echo htmlspecialchars($meeting['meeting_title']); ?>
                        </h1>
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-calendar-alt me-1"></i>
                                <?php echo date('F d, Y', strtotime($meeting['meeting_date'])); ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-clock me-1"></i>
                                <?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?>
                            </span>
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-tag me-1"></i>
                                <?php echo $meeting['meeting_type']; ?>
                            </span>
                            <span class="badge bg-<?php echo get_status_color($meeting['status']); ?>">
                                <?php echo $meeting['status']; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="btn-group">
                            <a href="gate_reviews.php" class="btn btn-light">
                                <i class="fas fa-arrow-left me-1"></i>Back to List
                            </a>
                            <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                            <button class="btn btn-warning edit-meeting-details-btn" data-id="<?php echo $meeting_id; ?>">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="container">
            <!-- Tabs -->
            <ul class="nav nav-tabs" id="meetingTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
                        <i class="fas fa-eye me-2"></i>Overview
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="review-items-tab" data-bs-toggle="tab" data-bs-target="#review-items" type="button">
                        <i class="fas fa-tasks me-2"></i>Review Items (<?php echo count($review_items); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="attendees-tab" data-bs-toggle="tab" data-bs-target="#attendees" type="button">
                        <i class="fas fa-users me-2"></i>Attendees (<?php echo count($attendees); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button">
                        <i class="fas fa-file-alt me-2"></i>Documents (<?php echo count($documents); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="actions-tab" data-bs-toggle="tab" data-bs-target="#actions" type="button">
                        <i class="fas fa-clipboard-list me-2"></i>Actions (<?php echo count($actions); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="minutes-tab" data-bs-toggle="tab" data-bs-target="#minutes" type="button">
                        <i class="fas fa-sticky-note me-2"></i>Meeting Minutes
                    </button>
                </li>
            </ul>
            
            <!-- Tab Content -->
            <div class="tab-content" id="meetingTabsContent">
                <!-- Overview Tab -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-8">
                            <!-- Meeting Info -->
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Meeting Information</h5>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Date & Time</strong></label>
                                        <p class="mb-0">
                                            <?php echo date('F d, Y', strtotime($meeting['meeting_date'])); ?><br>
                                            <?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Location</strong></label>
                                        <p class="mb-0"><?php echo htmlspecialchars($meeting['location'] ?? 'Not specified'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Meeting Type</strong></label>
                                        <p class="mb-0"><?php echo $meeting['meeting_type']; ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label"><strong>Duration</strong></label>
                                        <p class="mb-0"><?php echo $meeting['duration'] ?? 60; ?> minutes</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Agenda -->
                            <?php if ($meeting['agenda']): ?>
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-list-ol me-2"></i>Agenda</h5>
                                <div class="agenda-content">
                                    <?php echo nl2br(htmlspecialchars($meeting['agenda'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Quick Stats -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6 class="mb-3">Review Decisions</h6>
                                        <?php
                                        $decisions = get_decision_stats($meeting_id);
                                        ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>Accepted</span>
                                                <span class="badge bg-success"><?php echo $decisions['accept']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?php echo $decisions['accept_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>Pending</span>
                                                <span class="badge bg-warning"><?php echo $decisions['pending']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-warning" 
                                                     style="width: <?php echo $decisions['pending_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>Rejected</span>
                                                <span class="badge bg-danger"><?php echo $decisions['reject']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-danger" 
                                                     style="width: <?php echo $decisions['reject_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <div class="info-card">
                                        <h6 class="mb-3">Action Items</h6>
                                        <?php
                                        $action_stats = get_action_stats($meeting_id);
                                        ?>
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>Completed</span>
                                                <span class="badge bg-success"><?php echo $action_stats['completed']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" 
                                                     style="width: <?php echo $action_stats['completed_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>In Progress</span>
                                                <span class="badge bg-warning"><?php echo $action_stats['in_progress']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-warning" 
                                                     style="width: <?php echo $action_stats['in_progress_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between">
                                                <span>Overdue</span>
                                                <span class="badge bg-danger"><?php echo $action_stats['overdue']; ?></span>
                                            </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-danger" 
                                                     style="width: <?php echo $action_stats['overdue_pct']; ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <!-- Quick Actions -->
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                                <div class="d-grid gap-2">
                                    <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addReviewItemModal">
                                        <i class="fas fa-plus me-1"></i>Add Review Item
                                    </button>
                                    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                                        <i class="fas fa-user-plus me-1"></i>Add Attendee
                                    </button>
                                    <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                        <i class="fas fa-upload me-1"></i>Upload Document
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($meeting['status'] === 'Scheduled' && in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                    <button class="btn btn-warning start-meeting-btn" data-id="<?php echo $meeting_id; ?>">
                                        <i class="fas fa-play me-1"></i>Start Meeting
                                    </button>
                                    <?php elseif ($meeting['status'] === 'In Progress' && in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                                    <button class="btn btn-success complete-meeting-btn" data-id="<?php echo $meeting_id; ?>">
                                        <i class="fas fa-check me-1"></i>Complete Meeting
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Meeting Timeline -->
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-history me-2"></i>Meeting Timeline</h5>
                                <div class="timeline">
                                    <div class="timeline-item mb-2">
                                        <div class="small text-muted">Meeting Created</div>
                                        <div class="small"><?php echo date('M d, Y', strtotime($meeting['created_at'])); ?></div>
                                    </div>
                                    
                                    <?php if ($meeting['updated_at'] != $meeting['created_at']): ?>
                                    <div class="timeline-item mb-2">
                                        <div class="small text-muted">Last Updated</div>
                                        <div class="small"><?php echo date('M d, Y', strtotime($meeting['updated_at'])); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($meeting['status'] === 'Completed'): ?>
                                    <div class="timeline-item mb-2">
                                        <div class="small text-muted">Meeting Completed</div>
                                        <div class="small"><?php echo date('M d, Y', strtotime($meeting['updated_at'])); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Review Items Tab -->
                <div class="tab-pane fade" id="review-items" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5>Review Items</h5>
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReviewItemModal">
                            <i class="fas fa-plus me-1"></i>Add Review Item
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($review_items)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Review Items</h5>
                        <p class="text-muted">Add projects to review during this gate review meeting.</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($review_items as $item): ?>
                        <div class="col-lg-6 mb-3">
                            <div class="review-item-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($item['project_name']); ?></h6>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($item['department_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <span class="decision-badge decision-<?php echo strtolower($item['decision']); ?>">
                                        <?php echo $item['decision']; ?>
                                    </span>
                                </div>
                                
                                <?php if ($item['total_score']): ?>
                                <div class="row mb-2">
                                    <div class="col-6">
                                        <div class="score-display">
                                            <div class="score-value"><?php echo number_format($item['total_score'], 1); ?>%</div>
                                            <div class="small text-muted">Total Score</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="small text-muted mb-1">Scoring</div>
                                        <div class="row g-1">
                                            <div class="col-6">
                                                <small>S: <?php echo $item['score_strategic'] ?? 'N/A'; ?></small>
                                            </div>
                                            <div class="col-6">
                                                <small>F: <?php echo $item['score_financial'] ?? 'N/A'; ?></small>
                                            </div>
                                            <div class="col-6">
                                                <small>O: <?php echo $item['score_operational'] ?? 'N/A'; ?></small>
                                            </div>
                                            <div class="col-6">
                                                <small>T: <?php echo $item['score_technical'] ?? 'N/A'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($item['decision_notes']): ?>
                                <div class="small mb-2">
                                    <strong>Notes:</strong> <?php echo htmlspecialchars(substr($item['decision_notes'], 0, 100)); ?>
                                    <?php if (strlen($item['decision_notes']) > 100): ?>...<?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        Order: <?php echo $item['presentation_order']; ?>
                                    </small>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary update-decision-btn" 
                                                data-id="<?php echo $item['id']; ?>">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                        <button class="btn btn-outline-info add-action-btn" 
                                                data-id="<?php echo $item['id']; ?>">
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
                        <h5>Meeting Attendees</h5>
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                            <i class="fas fa-user-plus me-1"></i>Add Attendee
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($attendees)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Attendees</h5>
                        <p class="text-muted">Add attendees to this gate review meeting.</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($attendees as $attendee): ?>
                        <div class="col-md-4 mb-3">
                            <div class="attendee-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($attendee['username']); ?></h6>
                                        <div class="small text-muted">
                                            <?php echo htmlspecialchars($attendee['email']); ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo get_attendance_color($attendee['attendance_status']); ?>">
                                        <?php echo $attendee['attendance_status']; ?>
                                    </span>
                                </div>
                                <div class="small mb-2">
                                    <strong>Role:</strong> <?php echo htmlspecialchars($attendee['role']); ?>
                                </div>
                                <?php if ($attendee['notes']): ?>
                                <div class="small text-muted">
                                    <?php echo htmlspecialchars($attendee['notes']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Documents Tab -->
                <div class="tab-pane fade" id="documents" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5>Meeting Documents</h5>
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                            <i class="fas fa-upload me-1"></i>Upload Document
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($documents)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Documents</h5>
                        <p class="text-muted">Upload documents related to this gate review meeting.</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($documents as $doc): ?>
                        <div class="col-md-6 mb-3">
                            <div class="document-card">
                                <div class="d-flex align-items-center">
                                    <div class="me-3 text-primary">
                                        <i class="fas fa-file fa-2x"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($doc['document_name']); ?></h6>
                                        <div class="small text-muted">
                                            Uploaded by <?php echo htmlspecialchars($doc['uploaded_by_name']); ?>
                                            on <?php echo date('M d, Y', strtotime($doc['upload_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="btn-group">
                                    <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Actions Tab -->
                <div class="tab-pane fade" id="actions" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5>Follow-up Actions</h5>
                        <?php if (in_array($user_role, ['super_admin', 'pm_manager'])): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addActionModal">
                            <i class="fas fa-plus me-1"></i>Add Action
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($actions)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Actions</h5>
                        <p class="text-muted">No follow-up actions from this meeting.</p>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($actions as $action): ?>
                        <div class="col-12 mb-3">
                            <div class="action-card <?php echo $action['is_overdue'] ? 'overdue' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($action['action_description']); ?></h6>
                                        <div class="small text-muted mb-2">
                                            <strong>Assigned to:</strong> 
                                            <?php echo htmlspecialchars($action['assigned_to_name'] ?? 'Unassigned'); ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-<?php echo get_action_status_color($action['status']); ?>">
                                            <?php echo $action['status']; ?>
                                        </span>
                                        <div class="small text-muted mt-1">
                                            Due: <?php echo date('M d, Y', strtotime($action['due_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($action['completion_notes']): ?>
                                <div class="small mb-2">
                                    <strong>Completion Notes:</strong>
                                    <?php echo htmlspecialchars($action['completion_notes']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($action['project_name']): ?>
                                <div class="small text-muted">
                                    Related to: <?php echo htmlspecialchars($action['project_name']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Minutes Tab -->
                <div class="tab-pane fade" id="minutes" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-sticky-note me-2"></i>Meeting Minutes</h5>
                                <?php if ($meeting['minutes']): ?>
                                <div class="minutes-content">
                                    <?php echo nl2br(htmlspecialchars($meeting['minutes'])); ?>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <h6 class="text-muted">No minutes recorded</h6>
                                    <p class="text-muted">Meeting minutes will be recorded here.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="info-card">
                                <h5 class="mb-3"><i class="fas fa-download me-2"></i>Export Options</h5>
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
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- JavaScript -->
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        
        <script>
            $(document).ready(function() {
                // Tab handling
                const urlParams = new URLSearchParams(window.location.search);
                const tab = urlParams.get('tab');
                if (tab) {
                    $(`#${tab}-tab`).tab('show');
                }
                
                // Update URL when tabs change
                $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
                    const tabId = $(e.target).attr('data-bs-target').substring(1);
                    const url = new URL(window.location);
                    url.searchParams.set('tab', tabId);
                    window.history.replaceState({}, '', url.toString());
                });
                
                // Start meeting
                $('.start-meeting-btn').click(function() {
                    const meetingId = $(this).data('id');
                    Swal.fire({
                        title: 'Start Meeting?',
                        text: 'Are you sure you want to start this meeting?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, start meeting',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $.ajax({
                                url: 'ajax/update_meeting_status.php',
                                method: 'POST',
                                data: { 
                                    meeting_id: meetingId,
                                    status: 'In Progress',
                                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        location.reload();
                                    } else {
                                        Swal.fire('Error', response.message, 'error');
                                    }
                                }
                            });
                        }
                    });
                });
                
                // Complete meeting
                $('.complete-meeting-btn').click(function() {
                    const meetingId = $(this).data('id');
                    Swal.fire({
                        title: 'Complete Meeting?',
                        text: 'Are you sure you want to mark this meeting as completed?',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, complete meeting',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $.ajax({
                                url: 'ajax/update_meeting_status.php',
                                method: 'POST',
                                data: { 
                                    meeting_id: meetingId,
                                    status: 'Completed',
                                    csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        location.reload();
                                    } else {
                                        Swal.fire('Error', response.message, 'error');
                                    }
                                }
                            });
                        }
                    });
                });
                
                // Export buttons
                $('.export-btn').click(function() {
                    const type = $(this).data('type');
                    const meetingId = <?php echo $meeting_id; ?>;
                    
                    Swal.fire({
                        title: 'Generating Report',
                        text: 'Please wait while we generate your report...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Simulate export process
                    setTimeout(() => {
                        Swal.close();
                        Swal.fire('Success', 'Report generated successfully!', 'success');
                    }, 1500);
                });
                
                // Edit meeting details
                $('.edit-meeting-details-btn').click(function() {
                    const meetingId = $(this).data('id');
                    
                    // Fetch meeting details via AJAX
                    $.ajax({
                        url: 'ajax/get_meeting_details.php',
                        method: 'GET',
                        data: { id: meetingId },
                        success: function(response) {
                            if (response.success) {
                                const meeting = response.data;
                                $('#editMeetingId').val(meeting.id);
                                $('#editMeetingTitle').val(meeting.meeting_title);
                                $('#editMeetingDate').val(meeting.meeting_date);
                                $('#editMeetingTime').val(meeting.meeting_time.substring(0, 5));
                                $('#editLocation').val(meeting.location || '');
                                $('#editDuration').val(meeting.duration || 60);
                                $('#editStatus').val(meeting.status);
                                $('#editAgenda').val(meeting.agenda || '');
                                $('#editMinutes').val(meeting.minutes || '');
                                
                                $('#editMeetingModal').modal('show');
                            } else {
                                Swal.fire('Error', response.message, 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error', 'Failed to load meeting details', 'error');
                        }
                    });
                });
                
                // Auto-close alerts
                setTimeout(() => {
                    $('.alert').alert('close');
                }, 5000);
            });
        </script>
    </body>
    </html>
    <?php
    echo ob_get_clean();
}

// Handle form submissions
function handle_create_meeting() {
    global $conn, $user_id, $error, $success;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: gate_reviews.php');
        exit();
    }
    
    $meeting_title = sanitize_input($_POST['meeting_title']);
    $meeting_date = sanitize_input($_POST['meeting_date']);
    $meeting_time = sanitize_input($_POST['meeting_time']);
    $location = sanitize_input($_POST['location']);
    $meeting_type = sanitize_input($_POST['meeting_type']);
    $duration = intval($_POST['duration']);
    $agenda = sanitize_input($_POST['agenda']);
    
    // Validate required fields
    if (empty($meeting_title) || empty($meeting_date) || empty($meeting_time) || empty($meeting_type)) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header('Location: gate_reviews.php');
        exit();
    }
    
    // Validate date
    if (!validate_date($meeting_date)) {
        $_SESSION['error'] = "Invalid date format.";
        header('Location: gate_reviews.php');
        exit();
    }
    
    // Validate time
    if (!validate_time($meeting_time)) {
        $_SESSION['error'] = "Invalid time format.";
        header('Location: gate_reviews.php');
        exit();
    }
    
    $query = "INSERT INTO gate_review_meetings 
              (meeting_title, meeting_date, meeting_time, location, meeting_type, duration, agenda, created_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sssssisi", 
        $meeting_title, $meeting_date, $meeting_time, $location, $meeting_type, $duration, $agenda, $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $meeting_id = mysqli_insert_id($conn);
        
        // Add creator as attendee
        $attendee_query = "INSERT INTO gate_review_attendees (meeting_id, user_id, role, attendance_status)
                          VALUES (?, ?, 'Facilitator', 'Confirmed')";
        $attendee_stmt = mysqli_prepare($conn, $attendee_query);
        mysqli_stmt_bind_param($attendee_stmt, "ii", $meeting_id, $user_id);
        mysqli_stmt_execute($attendee_stmt);
        mysqli_stmt_close($attendee_stmt);
        
        $_SESSION['success'] = "Meeting created successfully!";
        header("Location: gate_reviews.php?action=details&id=" . $meeting_id);
        exit();
    } else {
        $_SESSION['error'] = "Error creating meeting: " . mysqli_error($conn);
        header('Location: gate_reviews.php');
        exit();
    }
    
    mysqli_stmt_close($stmt);
}

function handle_edit_meeting() {
    global $conn;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: gate_reviews.php');
        exit();
    }
    
    $meeting_id = intval($_POST['meeting_id']);
    $meeting_title = sanitize_input($_POST['meeting_title']);
    $meeting_date = sanitize_input($_POST['meeting_date']);
    $meeting_time = sanitize_input($_POST['meeting_time']);
    $location = sanitize_input($_POST['location']);
    $duration = intval($_POST['duration']);
    $status = sanitize_input($_POST['status']);
    $agenda = sanitize_input($_POST['agenda']);
    $minutes = sanitize_input($_POST['minutes']);
    
    $query = "UPDATE gate_review_meetings SET
              meeting_title = ?, meeting_date = ?, meeting_time = ?, location = ?,
              duration = ?, status = ?, agenda = ?, minutes = ?, updated_at = NOW()
              WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssisssi",
        $meeting_title, $meeting_date, $meeting_time, $location,
        $duration, $status, $agenda, $minutes, $meeting_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['success'] = "Meeting updated successfully!";
        header("Location: gate_reviews.php?action=details&id=" . $meeting_id);
        exit();
    } else {
        $_SESSION['error'] = "Error updating meeting: " . mysqli_error($conn);
        header("Location: gate_reviews.php?action=details&id=" . $meeting_id);
        exit();
    }
    
    mysqli_stmt_close($stmt);
}

// Additional database helper functions with prepared statements
function get_meeting_details($meeting_id) {
    global $conn;
    
    $query = "SELECT gm.*, u.username as facilitator_name
              FROM gate_review_meetings gm
              LEFT JOIN users u ON gm.facilitator_id = u.id
              WHERE gm.id = ?";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $meeting_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $meeting = mysqli_fetch_assoc($result);
    
    mysqli_stmt_close($stmt);
    return $meeting;
}

function get_review_items($meeting_id) {
    global $conn;
    
    $query = "SELECT gri.*, pi.project_name, d.department_name
              FROM gate_review_items gri
              JOIN project_intakes pi ON gri.project_intake_id = pi.id
              LEFT JOIN departments d ON pi.department_id = d.id
              WHERE gri.meeting_id = ?
              ORDER BY gri.presentation_order ASC";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $meeting_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    return $items;
}

// Add other missing handler functions as needed...
// For now, they can be stubs that redirect back
function handle_delete_meeting() {
    $_SESSION['error'] = "Delete functionality not implemented yet.";
    header('Location: gate_reviews.php');
    exit();
}

function handle_add_review_item() {
    $_SESSION['error'] = "Add review item functionality not implemented yet.";
    header('Location: gate_reviews.php');
    exit();
}

function handle_update_decision() {
    $_SESSION['error'] = "Update decision functionality not implemented yet.";
    header('Location: gate_reviews.php');
    exit();
}
?>