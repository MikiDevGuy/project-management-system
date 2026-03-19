<?php
// config/config.php

// Application Settings
define('APP_NAME', 'BSPMD Project Intake System');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/bspmd/');

// File Upload Settings
define('MAX_FILE_SIZE', 10485760); // 10MB in bytes
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png']);
define('UPLOAD_PATH', 'uploads/');

// Email Settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com');
define('SMTP_PASS', 'your-password');
define('FROM_EMAIL', 'noreply@bspmd.com');
define('FROM_NAME', 'BSPMD System');

// Scoring Settings
define('SCORING_THRESHOLD', 70); // Minimum score to pass checkpoint
define('SCORING_WEIGHTS', [
    'strategic_alignment' => 0.25,
    'financial_viability' => 0.20,
    'operational_readiness' => 0.15,
    'technical_feasibility' => 0.15,
    'risk_compliance' => 0.15,
    'urgency' => 0.10
]);

// Display Settings
define('ITEMS_PER_PAGE', 25);
define('DATE_FORMAT', 'F d, Y');
define('DATETIME_FORMAT', 'F d, Y H:i:s');

// Security Settings
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes in seconds

// Function to get weighted score
function calculate_weighted_score($scores) {
    $total = 0;
    foreach ($scores as $dimension => $score) {
        if (isset(SCORING_WEIGHTS[$dimension])) {
            $total += $score * SCORING_WEIGHTS[$dimension] * 100;
        }
    }
    return $total;
}

// Function to get status color
function get_status_color($status) {
    $colors = [
        'Draft' => 'secondary',
        'Submitted' => 'info',
        'Under Review' => 'warning',
        'Approved' => 'success',
        'Rejected' => 'danger',
        'Deferred' => 'primary'
    ];
    
    return $colors[$status] ?? 'secondary';
}

// Function to format currency
function format_currency($amount) {
    return '$' . number_format($amount, 2);
}

// Function to validate email
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Function to generate reference ID
function generate_reference_id($id, $prefix = 'PI') {
    return $prefix . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);
}
?>