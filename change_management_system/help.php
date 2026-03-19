<?php
// help.php
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

// Handle support ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $category = $_POST['category'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $description = trim($_POST['description'] ?? '');
        
        // Validate required fields
        if (empty($subject)) {
            $error_message = "Subject is required.";
        } elseif (empty($category)) {
            $error_message = "Category is required.";
        } elseif (empty($description)) {
            $error_message = "Description is required.";
        } elseif (strlen($description) < 10) {
            $error_message = "Please provide a more detailed description (at least 10 characters).";
        } else {
            // Insert support ticket
            $query = "INSERT INTO support_tickets (user_id, subject, category, priority, description, status, created_at) 
                     VALUES (?, ?, ?, ?, ?, 'Open', NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("issss", $user_id, $subject, $category, $priority, $description);
            
            if ($stmt->execute()) {
                $ticket_id = $conn->insert_id;
                $success_message = "Support ticket #{$ticket_id} submitted successfully! Our team will respond within 24 hours.";
                
                // Send notification to admin
                $admin_query = "INSERT INTO notifications (user_id, title, message, type, created_at) 
                               SELECT id, 'New Support Ticket', CONCAT('Ticket #', ?, ' submitted by user'), 'warning', NOW()
                               FROM users WHERE system_role IN ('admin', 'super_admin')";
                $admin_stmt = $conn->prepare($admin_query);
                $admin_stmt->bind_param("i", $ticket_id);
                $admin_stmt->execute();
                $admin_stmt->close();
            } else {
                $error_message = "Failed to submit support ticket: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// Get user's previous tickets
$tickets_query = "SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$tickets_stmt = $conn->prepare($tickets_query);
$tickets_stmt->bind_param("i", $user_id);
$tickets_stmt->execute();
$tickets_result = $tickets_stmt->get_result();
$tickets = $tickets_result->fetch_all(MYSQLI_ASSOC);
$tickets_stmt->close();

// If support_tickets table doesn't exist, create it
$table_check = $conn->query("SHOW TABLES LIKE 'support_tickets'");
if ($table_check->num_rows === 0) {
    $create_table = "
    CREATE TABLE support_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        category VARCHAR(50) NOT NULL,
        priority VARCHAR(20) DEFAULT 'Medium',
        description TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'Open',
        admin_response TEXT,
        resolved_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_status (user_id, status),
        INDEX idx_category (category),
        INDEX idx_priority (priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($create_table);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support - Dashen Bank</title>
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
            --gradient-success: linear-gradient(135deg, #00d4aa 0%, #00b894 100%);
            --gradient-warning: linear-gradient(135deg, #ffb800 0%, #f39c12 100%);
            --gradient-danger: linear-gradient(135deg, #ff4757 0%, #e74c3c 100%);
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
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: url('../Images/DashenLogo1.png') no-repeat center center;
            background-size: contain;
            opacity: 0.05;
            pointer-events: none;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        
        .page-subtitle {
            color: var(--text-light);
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        /* Help Sections */
        .help-section {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dashen-primary);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        /* Quick Help Cards */
        .help-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .help-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .help-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--dashen-primary);
        }
        
        .help-icon {
            width: 70px;
            height: 70px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            color: white;
            font-size: 1.5rem;
        }
        
        .help-card h4 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }
        
        .help-card p {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        /* Form Styles */
        .form-label {
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .form-control, .form-select {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            transition: all 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dashen-primary);
            box-shadow: 0 0 0 3px rgba(39, 50, 116, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
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
        
        /* Alert Styles */
        .alert {
            border-radius: 0.5rem;
            border: none;
            margin-bottom: 1.5rem;
        }
        
        /* Ticket Status Badges */
        .status-badge {
            padding: 0.35rem 1rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .badge-open { background: #cce7ff; color: var(--dashen-primary); }
        .badge-in-progress { background: #fff3cd; color: #856404; }
        .badge-resolved { background: #d1f7e9; color: #00d4aa; }
        .badge-closed { background: #f8d7da; color: #ff4757; }
        
        /* FAQ Accordion */
        .accordion-item {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            margin-bottom: 0.75rem;
            overflow: hidden;
        }
        
        .accordion-button {
            font-weight: 500;
            color: var(--text-dark);
            background: white;
            border: none;
        }
        
        .accordion-button:not(.collapsed) {
            background: var(--dashen-light);
            color: var(--dashen-primary);
        }
        
        .accordion-button:focus {
            box-shadow: none;
            border-color: var(--dashen-primary);
        }
        
        /* Contact Info */
        .contact-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .contact-item {
            text-align: center;
            padding: 1.5rem;
            background: var(--dashen-light);
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
        }
        
        .contact-icon {
            font-size: 2rem;
            color: var(--dashen-primary);
            margin-bottom: 1rem;
        }
        
        /* Ticket List */
        .ticket-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1rem;
        }
        
        .ticket-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .ticket-item:hover {
            background: var(--dashen-light);
        }
        
        .ticket-item:last-child {
            border-bottom: none;
        }
        
        .ticket-subject {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        
        .ticket-meta {
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .page-header {
                padding: 1.5rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .help-cards {
                grid-template-columns: 1fr;
            }
            
            .contact-info {
                grid-template-columns: 1fr;
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
        .toast-warning { border-left-color: #ffb800; }
        .toast-info { border-left-color: var(--dashen-primary); }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    $_SESSION['current_page'] = 'help.php';
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
                <i class="fas fa-question-circle me-2"></i>
                Help & Support Center
            </h1>
            <p class="page-subtitle">
                Get assistance, submit tickets, and find answers to frequently asked questions
            </p>
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
        
        <!-- Quick Help Cards -->
        <div class="help-cards">
            <div class="help-card">
                <div class="help-icon">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <h4>Submit Ticket</h4>
                <p>Create a new support ticket for technical issues or questions</p>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ticketModal">
                    Submit Ticket
                </button>
            </div>
            
            <div class="help-card">
                <div class="help-icon" style="background: var(--gradient-success);">
                    <i class="fas fa-history"></i>
                </div>
                <h4>Track Tickets</h4>
                <p>View status and updates on your existing support tickets</p>
                <a href="#myTickets" class="btn btn-outline-primary btn-sm">
                    View Tickets
                </a>
            </div>
            
            <div class="help-card">
                <div class="help-icon" style="background: var(--gradient-warning);">
                    <i class="fas fa-book"></i>
                </div>
                <h4>Knowledge Base</h4>
                <p>Browse our FAQ and documentation for quick answers</p>
                <a href="#faqSection" class="btn btn-outline-primary btn-sm">
                    View FAQ
                </a>
            </div>
            
            <div class="help-card">
                <div class="help-icon" style="background: var(--gradient-danger);">
                    <i class="fas fa-phone-alt"></i>
                </div>
                <h4>Contact Support</h4>
                <p>Get in touch with our support team for urgent matters</p>
                <a href="#contactSection" class="btn btn-outline-primary btn-sm">
                    Contact Us
                </a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-8">
                <!-- Support Ticket Form Section -->
                <div class="help-section">
                    <h3 class="section-title">
                        <i class="fas fa-plus-circle me-2"></i>
                        Submit New Support Ticket
                    </h3>
                    
                    <form method="POST" onsubmit="return validateTicketForm()">
                        <input type="hidden" name="action" value="submit_ticket">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="subject" class="form-label">Subject *</label>
                                <input type="text" class="form-control" id="subject" name="subject" 
                                       placeholder="Brief description of your issue" required>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="category" class="form-label">Category *</label>
                                <select class="form-select" id="category" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Technical Issue">Technical Issue</option>
                                    <option value="Feature Request">Feature Request</option>
                                    <option value="Account Issue">Account Issue</option>
                                    <option value="Bug Report">Bug Report</option>
                                    <option value="General Inquiry">General Inquiry</option>
                                    <option value="Change Request">Change Request</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="5" placeholder="Please provide detailed information about your issue..." 
                                      required></textarea>
                            <div class="form-text">
                                Include steps to reproduce the issue, error messages, and any relevant information
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                Our support team typically responds within 24 hours
                            </div>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-paper-plane me-2"></i>
                                Submit Ticket
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- My Tickets Section -->
                <div class="help-section" id="myTickets">
                    <h3 class="section-title">
                        <i class="fas fa-history me-2"></i>
                        My Recent Tickets
                    </h3>
                    
                    <?php if (count($tickets) > 0): ?>
                        <div class="ticket-list">
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="ticket-item">
                                    <div class="ticket-subject">
                                        <i class="fas fa-ticket-alt me-2 text-primary"></i>
                                        <?php echo htmlspecialchars($ticket['subject']); ?>
                                    </div>
                                    <div class="ticket-meta">
                                        <div>
                                            <span class="me-3">
                                                <i class="far fa-calendar me-1"></i>
                                                <?php echo date('M j, Y', strtotime($ticket['created_at'])); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-tag me-1"></i>
                                                <?php echo htmlspecialchars($ticket['category']); ?>
                                            </span>
                                        </div>
                                        <span class="status-badge badge-<?php echo strtolower(str_replace(' ', '-', $ticket['status'])); ?>">
                                            <?php echo htmlspecialchars($ticket['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">You haven't submitted any support tickets yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- FAQ Section -->
                <div class="help-section" id="faqSection">
                    <h3 class="section-title">
                        <i class="fas fa-question me-2"></i>
                        Frequently Asked Questions
                    </h3>
                    
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                    How do I submit a change request?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Navigate to "Change Management" in the sidebar, click "New Change Request", fill out the form with all required details, and submit. Your request will be reviewed by the appropriate stakeholders.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                    How long does approval take?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Approval times vary based on request complexity and priority. Typically, standard requests are processed within 2-3 business days. Urgent requests may be expedited.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                    Can I track my change requests?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes! You can track all your change requests in the "Change Management" dashboard. Each request shows its current status, priority, and any comments from reviewers.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                    How do I update my profile information?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Click on "Profile Settings" in the sidebar. You can update your username, email, password, and profile picture from there.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">
                                    What if I forget my password?
                                </button>
                            </h2>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    On the login page, click "Forgot Password?" and follow the instructions. A password reset link will be sent to your registered email address.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="help-section" id="contactSection">
                    <h3 class="section-title">
                        <i class="fas fa-headset me-2"></i>
                        Contact Support
                    </h3>
                    
                    <div class="contact-info">
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <h5>Email Support</h5>
                            <p class="text-muted">support@dashenbank.com</p>
                            <small>Response within 24 hours</small>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <h5>Phone Support</h5>
                            <p class="text-muted">+251 11 123 4567</p>
                            <small>Mon-Fri, 8:30 AM - 5:30 PM</small>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="mb-2">Support Hours:</h6>
                        <p class="text-muted mb-1">
                            <i class="far fa-clock me-2"></i>
                            Monday - Friday: 8:30 AM - 5:30 PM
                        </p>
                        <p class="text-muted">
                            <i class="far fa-calendar-times me-2"></i>
                            Weekends & Holidays: Closed
                        </p>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="help-section">
                    <h3 class="section-title">
                        <i class="fas fa-link me-2"></i>
                        Quick Links
                    </h3>
                    
                    <div class="list-group">
                        <a href="change_management.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-exchange-alt me-2"></i>
                            Change Management Dashboard
                        </a>
                        <a href="approvals.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-shield-check me-2"></i>
                            Approvals Center
                        </a>
                        <a href="profile.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-user-cog me-2"></i>
                            Profile Settings
                        </a>
                        <a href="#" class="list-group-item list-group-item-action" data-bs-toggle="modal" data-bs-target="#documentationModal">
                            <i class="fas fa-book me-2"></i>
                            User Documentation
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Documentation Modal -->
    <div class="modal fade" id="documentationModal" tabindex="-1" aria-labelledby="documentationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentationModalLabel">
                        <i class="fas fa-book me-2"></i>
                        User Documentation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-4">
                        <h5>Getting Started Guide</h5>
                        <p class="text-muted">Learn how to use the Change Management System effectively.</p>
                        
                        <div class="accordion" id="docAccordion">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#doc1">
                                        1. Submitting Change Requests
                                    </button>
                                </h2>
                                <div id="doc1" class="accordion-collapse collapse show" data-bs-parent="#docAccordion">
                                    <div class="accordion-body">
                                        <p>To submit a change request:</p>
                                        <ol>
                                            <li>Navigate to Change Management</li>
                                            <li>Click "New Change Request"</li>
                                            <li>Fill in all required fields</li>
                                            <li>Provide detailed justification</li>
                                            <li>Submit for review</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#doc2">
                                        2. Tracking Request Status
                                    </button>
                                </h2>
                                <div id="doc2" class="accordion-collapse collapse" data-bs-parent="#docAccordion">
                                    <div class="accordion-body">
                                        <p>Monitor your requests:</p>
                                        <ul>
                                            <li>View all requests in the main dashboard</li>
                                            <li>Check status badges for current state</li>
                                            <li>Review comments from approvers</li>
                                            <li>Track implementation progress</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#doc3">
                                        3. Approval Process
                                    </button>
                                </h2>
                                <div id="doc3" class="accordion-collapse collapse" data-bs-parent="#docAccordion">
                                    <div class="accordion-body">
                                        <p>The approval workflow:</p>
                                        <ol>
                                            <li>Request submitted → "Open" status</li>
                                            <li>Review by approvers → "In Review"</li>
                                            <li>Decision made → "Approved" or "Rejected"</li>
                                            <li>Implementation → "In Progress"</li>
                                            <li>Completion → "Implemented"</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <a href="#" class="btn btn-outline-primary">
                            <i class="fas fa-download me-2"></i>
                            Download Full Guide (PDF)
                        </a>
                    </div>
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
        
        // Validate ticket form
        function validateTicketForm() {
            const subject = document.getElementById('subject').value.trim();
            const category = document.getElementById('category').value;
            const description = document.getElementById('description').value.trim();
            
            if (!subject) {
                showToast('Subject is required.', 'error');
                document.getElementById('subject').focus();
                return false;
            }
            
            if (!category) {
                showToast('Category is required.', 'error');
                document.getElementById('category').focus();
                return false;
            }
            
            if (!description) {
                showToast('Description is required.', 'error');
                document.getElementById('description').focus();
                return false;
            }
            
            if (description.length < 10) {
                showToast('Please provide a more detailed description (at least 10 characters).', 'error');
                document.getElementById('description').focus();
                return false;
            }
            
            showLoading();
            return true;
        }
        
        // Auto-save form data to localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const inputs = form.querySelectorAll('input, select, textarea');
            
            // Load saved data
            inputs.forEach(input => {
                const savedValue = localStorage.getItem(`ticket_${input.name}`);
                if (savedValue && input.type !== 'submit' && input.type !== 'hidden') {
                    input.value = savedValue;
                }
            });
            
            // Save data on input
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    if (this.type !== 'submit' && this.type !== 'hidden') {
                        localStorage.setItem(`ticket_${this.name}`, this.value);
                    }
                });
            });
            
            // Clear saved data on form submit
            form.addEventListener('submit', function() {
                inputs.forEach(input => {
                    if (input.type !== 'submit' && input.type !== 'hidden') {
                        localStorage.removeItem(`ticket_${input.name}`);
                    }
                });
            });
            
            // Scroll to tickets section if there are tickets
            const tickets = <?php echo count($tickets); ?>;
            if (tickets > 0 && window.location.hash === '#myTickets') {
                document.getElementById('myTickets').scrollIntoView({ behavior: 'smooth' });
            }
            
            // Auto-expand FAQ based on URL hash
            const hash = window.location.hash;
            if (hash && hash.startsWith('#faq')) {
                const faqId = hash.replace('#', '');
                const faqElement = document.getElementById(faqId);
                if (faqElement) {
                    const accordion = new bootstrap.Collapse(faqElement, { toggle: true });
                }
            }
        });
        
        // Character counter for description
        document.getElementById('description').addEventListener('input', function() {
            const count = this.value.length;
            const counter = document.getElementById('charCounter') || (() => {
                const div = document.createElement('div');
                div.id = 'charCounter';
                div.className = 'form-text text-end';
                this.parentNode.appendChild(div);
                return div;
            })();
            
            counter.textContent = `${count} characters`;
            
            if (count < 10) {
                counter.style.color = '#ff4757';
            } else if (count < 50) {
                counter.style.color = '#ffb800';
            } else {
                counter.style.color = '#00d4aa';
            }
        });
        
        // Initialize tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId !== '#') {
                    const targetElement = document.querySelector(targetId);
                    if (targetElement) {
                        targetElement.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
        });
    </script>
</body>
</html>