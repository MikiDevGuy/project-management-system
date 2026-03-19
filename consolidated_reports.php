<?php
// ==========================================================
// consolidated_reports.php - Complete Integrated Reporting System
// with ALL database tables displayed and attractive visualizations
// ==========================================================

session_start();
require_once 'db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'] ?? 'viewer';
$username = $_SESSION['username'] ?? 'User';
$user_email = $_SESSION['email'] ?? '';

// If email not in session, fetch from database
if (empty($user_email) && isset($user_id)) {
    $email_query = "SELECT email FROM users WHERE id = ?";
    $stmt = $conn->prepare($email_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_email = $row['email'];
        $_SESSION['email'] = $user_email;
    }
    $stmt->close();
}

// Get active section from query parameter
$active_section = isset($_GET['section']) ? $_GET['section'] : 'dashboard';

// Filter parameters
$search_term = isset($_GET['search']) ? $_GET['search'] : '';
$project_filter = isset($_GET['project_id']) && !empty($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get projects for filter dropdown
$projects = [];
$project_query = "SELECT id, name, status FROM projects ORDER BY name";
$project_result = $conn->query($project_query);
if ($project_result) {
    while ($row = $project_result->fetch_assoc()) {
        $projects[] = $row;
    }
}

// ==========================================================
// HELPER FUNCTIONS
// ==========================================================
function format_date($date) {
    return $date ? date('M j, Y', strtotime($date)) : 'N/A';
}

function format_datetime($datetime) {
    return $datetime ? date('M j, Y H:i', strtotime($datetime)) : 'N/A';
}

function format_currency($amount) {
    return '$' . number_format($amount ?? 0, 2);
}

function get_status_class($status) {
    $classes = [
        'pending' => 'warning',
        'in_progress' => 'info',
        'completed' => 'success',
        'terminated' => 'danger',
        'open' => 'primary',
        'assigned' => 'secondary',
        'resolved' => 'success',
        'closed' => 'dark',
        'high' => 'danger',
        'critical' => 'danger',
        'medium' => 'warning',
        'low' => 'success',
        'Pass' => 'success',
        'Fail' => 'danger',
        'Pending' => 'warning',
        'Deferred' => 'secondary',
        'Approved' => 'info',
        'Implemented' => 'success',
        'Rejected' => 'danger',
        'planned' => 'info',
        'inprogress' => 'warning',
        'active' => 'success',
        'inactive' => 'secondary'
    ];
    $status_lower = strtolower($status ?? '');
    return $classes[$status_lower] ?? 'secondary';
}

function get_priority_badge($priority) {
    $badges = [
        'high' => 'bg-danger',
        'critical' => 'bg-danger',
        'urgent' => 'bg-danger',
        'medium' => 'bg-warning',
        'low' => 'bg-success'
    ];
    $priority_lower = strtolower($priority ?? '');
    return $badges[$priority_lower] ?? 'bg-secondary';
}

function get_risk_level_class($level) {
    $classes = [
        'Critical' => 'bg-danger',
        'High' => 'bg-warning',
        'Medium' => 'bg-secondary',
        'Low' => 'bg-success'
    ];
    return $classes[$level] ?? 'bg-secondary';
}

function get_initial($name) {
    return strtoupper(substr($name ?? 'U', 0, 1));
}

// ==========================================================
// DATA COLLECTION FUNCTIONS - ALL TABLES
// ==========================================================

// Dashboard Overview Data
function getDashboardOverviewData($conn) {
    $data = [];
    
    // Project counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
        FROM projects");
    $data['projects'] = $result->fetch_assoc();
    
    // Phase counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM phases");
    $data['phases'] = $result->fetch_assoc();
    
    // Activity counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM activities");
    $data['activities'] = $result->fetch_assoc();
    
    // Sub-activity counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM sub_activities");
    $data['sub_activities'] = $result->fetch_assoc();
    
    // Issue counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('open', 'assigned') THEN 1 ELSE 0 END) as open,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status IN ('resolved', 'closed') THEN 1 ELSE 0 END) as closed
        FROM issues");
    $data['issues'] = $result->fetch_assoc();
    
    // Change request counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status NOT IN ('Implemented', 'Closed') THEN 1 ELSE 0 END) as active
        FROM change_requests");
    $data['changes'] = $result->fetch_assoc();
    
    // Risk counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN risk_level IN ('Critical', 'High') THEN 1 ELSE 0 END) as high
        FROM risks WHERE is_deleted = 0 OR is_deleted IS NULL");
    $data['risks'] = $result->fetch_assoc();
    
    // Test case counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) as passed,
        SUM(CASE WHEN status = 'Fail' THEN 1 ELSE 0 END) as failed
        FROM test_cases");
    $data['test_cases'] = $result->fetch_assoc();
    
    // Event counts
    $result = $conn->query("SELECT COUNT(*) as total FROM events");
    $data['events'] = $result->fetch_assoc();
    
    // Budget summary
    $result = $conn->query("SELECT 
        COALESCE(SUM(estimated_amount), 0) as estimated,
        COALESCE(SUM(total_budget_amount), 0) as total_budget
        FROM budget_items");
    $data['budget'] = $result->fetch_assoc();
    
    // Actual expenses
    $result = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM actual_expenses");
    $data['actual_expenses'] = $result->fetch_assoc();
    
    // User counts
    $result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
        FROM users");
    $data['users'] = $result->fetch_assoc();
    
    // Vendor counts
    $result = $conn->query("SELECT COUNT(*) as total FROM vendors");
    $data['vendors'] = $result->fetch_assoc();
    
    // Contract counts
    $result = $conn->query("SELECT COUNT(*) as total FROM contracts");
    $data['contracts'] = $result->fetch_assoc();
    
    // System counts
    $result = $conn->query("SELECT COUNT(*) as total FROM systems");
    $data['systems'] = $result->fetch_assoc();
    
    // Recent activity
    $result = $conn->query("SELECT 
        al.*,
        u.username
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 10");
    $data['recent_activity'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Project status distribution for chart
    $result = $conn->query("SELECT status, COUNT(*) as count FROM projects GROUP BY status");
    $data['project_status'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Issue priority distribution
    $result = $conn->query("SELECT priority, COUNT(*) as count FROM issues GROUP BY priority");
    $data['issue_priority'] = $result->fetch_all(MYSQLI_ASSOC);
    
    return $data;
}

// Projects Data
function getProjectsData($conn, $search = '', $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if (!empty($status)) {
        $where[] = "p.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                p.*,
                u.username as created_by_name,
                d.department_name,
                (SELECT COUNT(*) FROM phases WHERE project_id = p.id) as phase_count,
                (SELECT COUNT(*) FROM activities WHERE project_id = p.id) as activity_count,
                (SELECT COUNT(*) FROM issues WHERE project_id = p.id) as issue_count,
                (SELECT COUNT(*) FROM budget_items WHERE project_id = p.id) as budget_count
            FROM projects p
            LEFT JOIN users u ON p.created_by = u.id
            LEFT JOIN departments d ON p.department_id = d.id
            $where_clause
            ORDER BY p.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Phases Data
function getPhasesData($conn, $search = '', $project_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(ph.name LIKE ? OR ph.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($project_filter > 0) {
        $where[] = "ph.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "ph.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                ph.*,
                p.name as project_name,
                (SELECT COUNT(*) FROM activities WHERE phase_id = ph.id) as activity_count
            FROM phases ph
            JOIN projects p ON ph.project_id = p.id
            $where_clause
            ORDER BY ph.project_id, ph.Phase_order";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Activities Data
function getActivitiesData($conn, $search = '', $project_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(a.name LIKE ? OR a.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($project_filter > 0) {
        $where[] = "a.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "a.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                a.*,
                p.name as project_name,
                ph.name as phase_name,
                (SELECT COUNT(*) FROM sub_activities WHERE activity_id = a.id) as sub_activity_count,
                (SELECT GROUP_CONCAT(u.username SEPARATOR ', ') 
                 FROM activity_users au 
                 JOIN users u ON au.user_id = u.id 
                 WHERE au.activity_id = a.id) as assigned_users
            FROM activities a
            JOIN projects p ON a.project_id = p.id
            LEFT JOIN phases ph ON a.phase_id = ph.id
            $where_clause
            ORDER BY a.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Sub-Activities Data
function getSubActivitiesData($conn, $search = '', $project_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "sa.name LIKE ?";
        $search_param = "%$search%";
        $params[] = $search_param;
        $types .= 's';
    }
    
    if ($project_filter > 0) {
        $where[] = "a.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "sa.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                sa.*,
                a.name as activity_name,
                p.name as project_name,
                ph.name as phase_name,
                u.username as assigned_to_name
            FROM sub_activities sa
            JOIN activities a ON sa.activity_id = a.id
            JOIN projects p ON a.project_id = p.id
            LEFT JOIN phases ph ON a.phase_id = ph.id
            LEFT JOIN users u ON sa.assigned_to = u.id
            $where_clause
            ORDER BY sa.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Issues Data
function getIssuesData($conn, $search = '', $project_filter = 0, $status = '', $priority = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(i.title LIKE ? OR i.description LIKE ? OR i.summary LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    if ($project_filter > 0) {
        $where[] = "i.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "i.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if (!empty($priority)) {
        $where[] = "i.priority = ?";
        $params[] = $priority;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                i.*,
                p.name as project_name,
                assigned.username as assigned_to_name,
                creator.username as created_by_name,
                approver.username as approved_by_name,
                (SELECT COUNT(*) FROM comments WHERE issue_id = i.id) as comment_count,
                (SELECT COUNT(*) FROM attachments WHERE issue_id = i.id) as attachment_count
            FROM issues i
            JOIN projects p ON i.project_id = p.id
            LEFT JOIN users assigned ON i.assigned_to = assigned.id
            LEFT JOIN users creator ON i.created_by = creator.id
            LEFT JOIN users approver ON i.approved_by = approver.id
            $where_clause
            ORDER BY i.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Change Requests Data
function getChangeRequestsData($conn, $search = '', $project_filter = 0, $status = '', $priority = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(cr.change_title LIKE ? OR cr.change_description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($project_filter > 0) {
        $where[] = "cr.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "cr.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if (!empty($priority)) {
        $where[] = "cr.priority = ?";
        $params[] = $priority;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                cr.*,
                p.name as project_name,
                requester.username as requester_name,
                assigned.username as assigned_to_name
            FROM change_requests cr
            JOIN projects p ON cr.project_id = p.id
            LEFT JOIN users requester ON cr.requester_id = requester.id
            LEFT JOIN users assigned ON cr.assigned_to_id = assigned.id
            $where_clause
            ORDER BY cr.request_date DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Risks Data
function getRisksData($conn, $search = '', $project_filter = 0, $status = '', $level = '') {
    $where = ["(r.is_deleted = 0 OR r.is_deleted IS NULL)"];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(r.title LIKE ? OR r.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($project_filter > 0) {
        $where[] = "r.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "rs.status_key = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if (!empty($level)) {
        $where[] = "r.risk_level = ?";
        $params[] = $level;
        $types .= 's';
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    $sql = "SELECT 
                r.*,
                p.name as project_name,
                rc.name as category_name,
                owner.username as owner_name,
                identified.username as identified_by_name,
                rs.label as status_label,
                (SELECT COUNT(*) FROM risk_mitigations WHERE risk_id = r.id) as mitigation_count,
                (SELECT COUNT(*) FROM risk_comments WHERE risk_id = r.id) as comment_count
            FROM risks r
            JOIN projects p ON r.project_id = p.id
            LEFT JOIN risk_categories rc ON r.category_id = rc.id
            LEFT JOIN users owner ON r.owner_user_id = owner.id
            LEFT JOIN users identified ON r.identified_by = identified.id
            LEFT JOIN risk_statuses rs ON r.status_id = rs.id
            $where_clause
            ORDER BY 
                CASE 
                    WHEN r.risk_level = 'Critical' THEN 1
                    WHEN r.risk_level = 'High' THEN 2
                    WHEN r.risk_level = 'Medium' THEN 3
                    ELSE 4
                END,
                r.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Test Cases Data
function getTestCasesData($conn, $search = '', $project_filter = 0, $status = '', $priority = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(tc.title LIKE ? OR tc.steps LIKE ? OR tc.expected LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    if ($project_filter > 0) {
        $where[] = "tc.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "tc.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if (!empty($priority)) {
        $where[] = "tc.priority = ?";
        $params[] = $priority;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                tc.*,
                p.name as project_name,
                f.feature_name,
                u.username as created_by_name
            FROM test_cases tc
            JOIN projects p ON tc.project_id = p.id
            LEFT JOIN features f ON tc.feature_id = f.id
            LEFT JOIN users u ON tc.created_by = u.id
            $where_clause
            ORDER BY tc.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Events Data
function getEventsData($conn, $search = '', $project_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(e.event_name LIKE ? OR e.description LIKE ? OR e.location LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'sss';
    }
    
    if ($project_filter > 0) {
        $where[] = "e.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "e.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                e.*,
                p.name as project_name,
                organizer.username as organizer_name,
                (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendee_count,
                (SELECT COUNT(*) FROM event_tasks WHERE event_id = e.id) as task_count,
                (SELECT COUNT(*) FROM event_resources WHERE event_id = e.id) as resource_count
            FROM events e
            LEFT JOIN projects p ON e.project_id = p.id
            LEFT JOIN users organizer ON e.organizer_id = organizer.id
            $where_clause
            ORDER BY e.start_datetime DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Event Attendees Data
function getEventAttendeesData($conn, $event_filter = 0) {
    $where = [];
    $params = [];
    $types = '';
    
    if ($event_filter > 0) {
        $where[] = "ea.event_id = ?";
        $params[] = $event_filter;
        $types .= 'i';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                ea.*,
                e.event_name,
                u.username,
                u.email,
                u.system_role
            FROM event_attendees ea
            JOIN events e ON ea.event_id = e.id
            JOIN users u ON ea.user_id = u.id
            $where_clause
            ORDER BY e.start_datetime DESC, u.username";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Event Tasks Data
function getEventTasksData($conn, $event_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if ($event_filter > 0) {
        $where[] = "et.event_id = ?";
        $params[] = $event_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "et.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                et.*,
                e.event_name,
                assigned.username as assigned_to_name,
                creator.username as created_by_name
            FROM event_tasks et
            JOIN events e ON et.event_id = e.id
            LEFT JOIN users assigned ON et.assigned_to = assigned.id
            LEFT JOIN users creator ON et.created_by = creator.id
            $where_clause
            ORDER BY et.due_date, et.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Budget Items Data
function getBudgetItemsData($conn, $search = '', $project_filter = 0, $status = '', $date_from = '', $date_to = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "bi.item_name LIKE ?";
        $search_param = "%$search%";
        $params[] = $search_param;
        $types .= 's';
    }
    
    if ($project_filter > 0) {
        $where[] = "bi.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "bi.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    if (!empty($date_from)) {
        $where[] = "bi.created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
        $types .= 's';
    }
    
    if (!empty($date_to)) {
        $where[] = "bi.created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                bi.*,
                bc.category_name,
                ct.name as cost_type_name,
                d.department_name,
                p.name as project_name,
                creator.username as created_by_name,
                approver.username as approved_by_name,
                (SELECT COALESCE(SUM(amount), 0) FROM actual_expenses WHERE budget_item_id = bi.id) as actual_amount
            FROM budget_items bi
            LEFT JOIN budget_categories bc ON bi.budget_category_id = bc.id
            LEFT JOIN cost_types ct ON bi.cost_type_id = ct.id
            LEFT JOIN departments d ON bi.department_id = d.id
            LEFT JOIN projects p ON bi.project_id = p.id
            LEFT JOIN users creator ON bi.created_by = creator.id
            LEFT JOIN users approver ON bi.approved_by = approver.id
            $where_clause
            ORDER BY bi.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Actual Expenses Data
function getActualExpensesData($conn, $search = '', $project_filter = 0, $status = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "ae.description LIKE ?";
        $search_param = "%$search%";
        $params[] = $search_param;
        $types .= 's';
    }
    
    if ($project_filter > 0) {
        $where[] = "ae.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    if (!empty($status)) {
        $where[] = "ae.status = ?";
        $params[] = $status;
        $types .= 's';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                ae.*,
                p.name as project_name,
                bi.item_name as budget_item_name,
                v.vendor_name,
                approver.username as approved_by_name
            FROM actual_expenses ae
            LEFT JOIN projects p ON ae.project_id = p.id
            LEFT JOIN budget_items bi ON ae.budget_item_id = bi.id
            LEFT JOIN vendors v ON ae.vendor_id = v.id
            LEFT JOIN users approver ON ae.approved_by = approver.id
            $where_clause
            ORDER BY ae.transaction_date DESC, ae.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Users Data
function getUsersData($conn, $search = '', $role = '', $active = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(u.username LIKE ? OR u.email LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if (!empty($role)) {
        $where[] = "u.system_role = ?";
        $params[] = $role;
        $types .= 's';
    }
    
    if ($active !== '') {
        $where[] = "u.is_active = ?";
        $params[] = $active;
        $types .= 'i';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                u.*,
                (SELECT COUNT(*) FROM issues WHERE assigned_to = u.id) as assigned_issues,
                (SELECT COUNT(*) FROM change_requests WHERE assigned_to_id = u.id) as assigned_changes,
                (SELECT COUNT(*) FROM risks WHERE owner_user_id = u.id) as owned_risks,
                (SELECT COUNT(*) FROM project_users WHERE user_id = u.id) as project_assignments,
                (SELECT COUNT(*) FROM user_systems WHERE user_id = u.id) as system_access_count,
                (SELECT GROUP_CONCAT(s.system_name SEPARATOR ', ') 
                 FROM user_systems us 
                 JOIN systems s ON us.system_id = s.system_id 
                 WHERE us.user_id = u.id) as system_access
            FROM users u
            $where_clause
            ORDER BY u.created_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// User Assignments Data
function getUserAssignmentsData($conn, $search = '', $project_filter = 0) {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "u.username LIKE ?";
        $search_param = "%$search%";
        $params[] = $search_param;
        $types .= 's';
    }
    
    if ($project_filter > 0) {
        $where[] = "ua.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                ua.*,
                u.username as user_name,
                u.email,
                u.system_role,
                assigned.username as assigned_by_name,
                p.name as project_name,
                ph.name as phase_name,
                a.name as activity_name,
                sa.name as subactivity_name
            FROM user_assignments ua
            JOIN users u ON ua.user_id = u.id
            LEFT JOIN users assigned ON ua.assigned_by = assigned.id
            LEFT JOIN projects p ON ua.project_id = p.id
            LEFT JOIN phases ph ON ua.phase_id = ph.id
            LEFT JOIN activities a ON ua.activity_id = a.id
            LEFT JOIN sub_activities sa ON ua.subactivity_id = sa.id
            $where_clause
            ORDER BY ua.assigned_at DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Systems Data
function getSystemsData($conn, $search = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(system_name LIKE ? OR system_url LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                s.*,
                (SELECT COUNT(*) FROM user_systems WHERE system_id = s.system_id) as user_count,
                (SELECT GROUP_CONCAT(u.username SEPARATOR ', ') 
                 FROM user_systems us 
                 JOIN users u ON us.user_id = u.id 
                 WHERE us.system_id = s.system_id) as users
            FROM systems s
            $where_clause
            ORDER BY s.system_name";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Features Data
function getFeaturesData($conn, $search = '', $project_filter = 0) {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(feature_name LIKE ? OR description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($project_filter > 0) {
        $where[] = "f.project_id = ?";
        $params[] = $project_filter;
        $types .= 'i';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                f.*,
                p.name as project_name,
                (SELECT COUNT(*) FROM test_cases WHERE feature_id = f.id) as test_case_count
            FROM features f
            JOIN projects p ON f.project_id = p.id
            $where_clause
            ORDER BY f.project_id, f.feature_name";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Vendors Data
function getVendorsData($conn, $search = '') {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(vendor_name LIKE ? OR vendor_type LIKE ? OR contact_person LIKE ? OR contact_email LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ssss';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                v.*,
                (SELECT COUNT(*) FROM contracts WHERE vendor_id = v.id) as contract_count,
                (SELECT COUNT(*) FROM actual_expenses WHERE vendor_id = v.id) as expense_count,
                (SELECT COALESCE(SUM(amount), 0) FROM actual_expenses WHERE vendor_id = v.id) as total_expenses
            FROM vendors v
            $where_clause
            ORDER BY v.vendor_name";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Contracts Data
function getContractsData($conn, $search = '', $vendor_filter = 0) {
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        $where[] = "(contract_name LIKE ? OR contract_number LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    
    if ($vendor_filter > 0) {
        $where[] = "c.vendor_id = ?";
        $params[] = $vendor_filter;
        $types .= 'i';
    }
    
    $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    $sql = "SELECT 
                c.*,
                v.vendor_name
            FROM contracts c
            JOIN vendors v ON c.vendor_id = v.id
            $where_clause
            ORDER BY c.start_date DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Activity Logs Data
function getActivityLogsData($conn, $limit = 100) {
    $sql = "SELECT 
                al.*,
                u.username,
                p.name as project_name,
                tc.title as test_case_title
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            LEFT JOIN projects p ON al.project_id = p.id
            LEFT JOIN test_cases tc ON al.test_case_id = tc.id
            ORDER BY al.created_at DESC
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get data based on active section
$section_data = [];
switch($active_section) {
    case 'dashboard':
        $section_data = getDashboardOverviewData($conn);
        break;
    case 'projects':
        $section_data = getProjectsData($conn, $search_term, $status_filter);
        break;
    case 'phases':
        $section_data = getPhasesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'activities':
        $section_data = getActivitiesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'subactivities':
        $section_data = getSubActivitiesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'issues':
        $section_data = getIssuesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'changes':
        $section_data = getChangeRequestsData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'risks':
        $section_data = getRisksData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'testcases':
        $section_data = getTestCasesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'events':
        $section_data = getEventsData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'event_attendees':
        $section_data = getEventAttendeesData($conn, $project_filter);
        break;
    case 'event_tasks':
        $section_data = getEventTasksData($conn, $project_filter, $status_filter);
        break;
    case 'budget':
        $section_data = getBudgetItemsData($conn, $search_term, $project_filter, $status_filter, $date_from, $date_to);
        break;
    case 'expenses':
        $section_data = getActualExpensesData($conn, $search_term, $project_filter, $status_filter);
        break;
    case 'users':
        $section_data = getUsersData($conn, $search_term, $status_filter);
        break;
    case 'assignments':
        $section_data = getUserAssignmentsData($conn, $search_term, $project_filter);
        break;
    case 'systems':
        $section_data = getSystemsData($conn, $search_term);
        break;
    case 'features':
        $section_data = getFeaturesData($conn, $search_term, $project_filter);
        break;
    case 'vendors':
        $section_data = getVendorsData($conn, $search_term);
        break;
    case 'contracts':
        $section_data = getContractsData($conn, $search_term, $project_filter);
        break;
    case 'activity_logs':
        $section_data = getActivityLogsData($conn, 200);
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashen Bank - Enterprise Reporting System</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
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
            --gradient-primary: linear-gradient(135deg, #273274 0%, #1e5af5 100%);
            --gradient-success: linear-gradient(135deg, #2dce89 0%, #2dce89 100%);
            --gradient-warning: linear-gradient(135deg, #fb6340 0%, #fbb140 100%);
            --gradient-danger: linear-gradient(135deg, #f5365c 0%, #f56036 100%);
            --gradient-info: linear-gradient(135deg, #11cdef 0%, #1171ef 100%);
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
        
        /* Static Header */
        .static-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: 80px;
            background: var(--gradient-primary);
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
            filter: drop-shadow(0 2px 5px rgba(0,0,0,0.2));
        }
        
        /* User Profile Dropdown */
        .user-profile {
            position: relative;
            cursor: pointer;
        }
        
        .user-profile-trigger {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.1);
            padding: 8px 16px;
            border-radius: 40px;
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .user-profile-trigger:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gradient-warning);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: white;
            border: 2px solid white;
            text-transform: uppercase;
        }
        
        .user-info-compact {
            display: flex;
            flex-direction: column;
        }
        
        .user-name-compact {
            font-weight: 600;
            font-size: 0.95rem;
            line-height: 1.3;
        }
        
        .user-role-compact {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        .profile-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 10px;
            width: 260px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            display: none;
            z-index: 1100;
            animation: slideDown 0.3s ease;
        }
        
        .profile-dropdown.show {
            display: block;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .dropdown-header {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            border-bottom: 1px solid #e9ecef;
        }
        
        .dropdown-user-name {
            font-weight: 700;
            color: var(--dashen-dark);
            margin-bottom: 4px;
        }
        
        .dropdown-user-email {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .dropdown-menu-items {
            padding: 10px 0;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--dashen-dark);
            text-decoration: none;
            transition: all 0.2s ease;
        }
        
        .dropdown-item:hover {
            background: #f8f9fa;
        }
        
        .dropdown-item i {
            width: 20px;
            color: var(--dashen-primary);
        }
        
        .dropdown-item.logout {
            color: var(--dashen-danger);
        }
        
        .dropdown-item.logout i {
            color: var(--dashen-danger);
        }
        
        .dropdown-divider {
            height: 1px;
            background: #e9ecef;
            margin: 8px 0;
        }
        
        /* Main Content */
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
        
        /* Navigation Tabs */
        .nav-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }
        
        .nav-tabs-custom {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
        }
        
        .nav-tab-item {
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 500;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.3s ease;
            background: #f8f9fa;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }
        
        .nav-tab-item:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.2);
        }
        
        .nav-tab-item:hover i {
            color: white;
        }
        
        .nav-tab-item.active {
            background: var(--dashen-primary);
            color: white;
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.3);
            border-color: var(--dashen-primary);
        }
        
        .nav-tab-item i {
            font-size: 1rem;
            color: inherit;
        }
        
        .nav-tab-item.active i {
            color: white;
        }
        
        /* Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(39, 50, 116, 0.1);
        }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .kpi-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(39, 50, 116, 0.1);
        }
        
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }
        
        .kpi-card .kpi-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }
        
        .kpi-card .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dashen-dark);
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .kpi-card .kpi-sub {
            font-size: 0.85rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .kpi-card .kpi-icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 2.5rem;
            opacity: 0.2;
            color: var(--dashen-primary);
        }
        
        /* Chart Cards */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }
        
        .chart-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dashen-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-title i {
            color: var(--dashen-accent);
        }
        
        .chart-container {
            height: 250px;
            position: relative;
        }
        
        /* Table Cards */
        .table-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dashen-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-title i {
            color: var(--dashen-accent);
        }
        
        /* DataTables Customization */
        .dataTables_wrapper {
            margin-top: 20px;
        }
        
        .dataTables_length select,
        .dataTables_filter input {
            border-radius: 40px !important;
            padding: 8px 16px !important;
            border: 1px solid #e9ecef !important;
        }
        
        .dataTables_length select:focus,
        .dataTables_filter input:focus {
            border-color: var(--dashen-primary) !important;
            box-shadow: 0 0 0 0.2rem rgba(39, 50, 116, 0.25) !important;
        }
        
        .dt-buttons {
            margin-bottom: 15px;
        }
        
        .dt-button {
            border-radius: 40px !important;
            padding: 8px 16px !important;
            margin-right: 5px !important;
            border: 1px solid #e9ecef !important;
            background: white !important;
            color: var(--dashen-dark) !important;
            font-size: 0.9rem !important;
        }
        
        .dt-button:hover {
            background: var(--dashen-primary) !important;
            color: white !important;
            border-color: var(--dashen-primary) !important;
        }
        
        table.dataTable {
            border-collapse: separate !important;
            border-spacing: 0 10px !important;
            margin-top: 10px !important;
        }
        
        table.dataTable thead th {
            background: var(--gradient-primary);
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 15px;
            border: none;
        }
        
        table.dataTable thead th:first-child {
            border-radius: 12px 0 0 12px;
        }
        
        table.dataTable thead th:last-child {
            border-radius: 0 12px 12px 0;
        }
        
        table.dataTable tbody td {
            vertical-align: middle;
            padding: 15px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
        }
        
        table.dataTable tbody tr:hover td {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
        }
        
        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }
        
        /* Progress Bars */
        .progress-custom {
            height: 6px;
            border-radius: 3px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-bar-custom {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }
        
        .progress-bar-primary { background: var(--gradient-primary); }
        .progress-bar-success { background: var(--gradient-success); }
        .progress-bar-warning { background: var(--gradient-warning); }
        .progress-bar-danger { background: var(--gradient-danger); }
        
        /* Modal */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--box-shadow-hover);
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            padding: 20px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            padding: 25px;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px;
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
        .delay-4 { animation-delay: 0.4s; }
        .delay-5 { animation-delay: 0.5s; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .static-header h1 {
                font-size: 1rem;
            }
            .static-header h1 i {
                font-size: 1.2rem;
            }
            .user-info-compact {
                display: none;
            }
            .kpi-value {
                font-size: 1.5rem;
            }
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Static Header -->
    <header class="static-header" id="staticHeader">
        <h1>
            <i class="fas fa-chart-pie"></i>
            <?php 
            $titles = [
                'dashboard' => 'Enterprise Dashboard',
                'projects' => 'Projects Management',
                'phases' => 'Project Phases',
                'activities' => 'Activities',
                'subactivities' => 'Sub-Activities',
                'issues' => 'Issue Tracking',
                'changes' => 'Change Control',
                'risks' => 'Risk Management',
                'testcases' => 'Test Case Management',
                'events' => 'Event Management',
                'event_attendees' => 'Event Attendees',
                'event_tasks' => 'Event Tasks',
                'budget' => 'Budget Management',
                'expenses' => 'Actual Expenses',
                'users' => 'User Management',
                'assignments' => 'User Assignments',
                'systems' => 'System Access',
                'features' => 'Features',
                'vendors' => 'Vendor Management',
                'contracts' => 'Contracts',
                'activity_logs' => 'Activity Logs'
            ];
            echo $titles[$active_section] ?? 'Enterprise Reporting System';
            ?>
        </h1>
        
        <!-- User Profile Dropdown -->
        <div class="user-profile" id="userProfile">
            <div class="user-profile-trigger" id="profileTrigger">
                <div class="user-avatar">
                    <?= get_initial($username) ?>
                </div>
                <div class="user-info-compact">
                    <span class="user-name-compact"><?= htmlspecialchars($username) ?></span>
                    <span class="user-role-compact"><?= ucfirst(str_replace('_', ' ', $user_role)) ?></span>
                </div>
                <i class="fas fa-chevron-down" style="font-size: 0.8rem; opacity: 0.8;"></i>
            </div>
            
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-user-name"><?= htmlspecialchars($username) ?></div>
                    <div class="dropdown-user-email"><?= htmlspecialchars($user_email) ?></div>
                    <div style="margin-top: 8px;">
                        <span class="badge bg-primary"><?= ucfirst(str_replace('_', ' ', $user_role)) ?></span>
                    </div>
                </div>
                <div class="dropdown-menu-items">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-circle"></i>
                        My Profile
                    </a>
                    <a href="change_password.php" class="dropdown-item">
                        <i class="fas fa-key"></i>
                        Change Password
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="logout.php" class="dropdown-item logout">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Navigation Tabs -->
        <div class="nav-section fade-in-up">
            <div class="nav-tabs-custom">
                <a href="?section=dashboard" class="nav-tab-item <?= $active_section == 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="?section=projects" class="nav-tab-item <?= $active_section == 'projects' ? 'active' : '' ?>">
                    <i class="fas fa-project-diagram"></i> Projects
                </a>
                <a href="?section=phases" class="nav-tab-item <?= $active_section == 'phases' ? 'active' : '' ?>">
                    <i class="fas fa-layer-group"></i> Phases
                </a>
                <a href="?section=activities" class="nav-tab-item <?= $active_section == 'activities' ? 'active' : '' ?>">
                    <i class="fas fa-tasks"></i> Activities
                </a>
                <a href="?section=subactivities" class="nav-tab-item <?= $active_section == 'subactivities' ? 'active' : '' ?>">
                    <i class="fas fa-list-ul"></i> Sub-Activities
                </a>
                <a href="?section=issues" class="nav-tab-item <?= $active_section == 'issues' ? 'active' : '' ?>">
                    <i class="fas fa-bug"></i> Issues
                </a>
                <a href="?section=changes" class="nav-tab-item <?= $active_section == 'changes' ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt"></i> Changes
                </a>
                <a href="?section=risks" class="nav-tab-item <?= $active_section == 'risks' ? 'active' : '' ?>">
                    <i class="fas fa-shield-alt"></i> Risks
                </a>
                <a href="?section=testcases" class="nav-tab-item <?= $active_section == 'testcases' ? 'active' : '' ?>">
                    <i class="fas fa-vial"></i> Test Cases
                </a>
                <a href="?section=events" class="nav-tab-item <?= $active_section == 'events' ? 'active' : '' ?>">
                    <i class="fas fa-calendar-alt"></i> Events
                </a>
                <a href="?section=budget" class="nav-tab-item <?= $active_section == 'budget' ? 'active' : '' ?>">
                    <i class="fas fa-wallet"></i> Budget
                </a>
                <a href="?section=expenses" class="nav-tab-item <?= $active_section == 'expenses' ? 'active' : '' ?>">
                    <i class="fas fa-money-bill-wave"></i> Expenses
                </a>
                <a href="?section=users" class="nav-tab-item <?= $active_section == 'users' ? 'active' : '' ?>">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="?section=assignments" class="nav-tab-item <?= $active_section == 'assignments' ? 'active' : '' ?>">
                    <i class="fas fa-user-check"></i> Assignments
                </a>
                <a href="?section=systems" class="nav-tab-item <?= $active_section == 'systems' ? 'active' : '' ?>">
                    <i class="fas fa-laptop"></i> Systems
                </a>
                <a href="?section=features" class="nav-tab-item <?= $active_section == 'features' ? 'active' : '' ?>">
                    <i class="fas fa-plus-circle"></i> Features
                </a>
                <a href="?section=vendors" class="nav-tab-item <?= $active_section == 'vendors' ? 'active' : '' ?>">
                    <i class="fas fa-truck"></i> Vendors
                </a>
                <a href="?section=contracts" class="nav-tab-item <?= $active_section == 'contracts' ? 'active' : '' ?>">
                    <i class="fas fa-file-contract"></i> Contracts
                </a>
                <a href="?section=activity_logs" class="nav-tab-item <?= $active_section == 'activity_logs' ? 'active' : '' ?>">
                    <i class="fas fa-history"></i> Activity Logs
                </a>
            </div>
            
            <!-- Filter Section (only show for data sections) -->
            <?php if ($active_section != 'dashboard' && $active_section != 'activity_logs'): ?>
            <div class="filter-section">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="section" value="<?= $active_section ?>">
                    
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search_term) ?>">
                    </div>
                    
                    <?php if (in_array($active_section, ['phases', 'activities', 'subactivities', 'issues', 'changes', 'risks', 'testcases', 'events', 'budget', 'expenses', 'assignments', 'features'])): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Project</label>
                        <select name="project_id" class="form-select">
                            <option value="">All Projects</option>
                            <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $project_filter == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (in_array($active_section, ['projects', 'phases', 'activities', 'subactivities', 'issues', 'changes', 'events', 'budget', 'expenses'])): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <?php if ($active_section == 'projects'): ?>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="terminated" <?= $status_filter == 'terminated' ? 'selected' : '' ?>>Terminated</option>
                            <?php elseif ($active_section == 'issues'): ?>
                            <option value="open" <?= $status_filter == 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="assigned" <?= $status_filter == 'assigned' ? 'selected' : '' ?>>Assigned</option>
                            <option value="in_progress" <?= $status_filter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved" <?= $status_filter == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed" <?= $status_filter == 'closed' ? 'selected' : '' ?>>Closed</option>
                            <?php elseif ($active_section == 'changes'): ?>
                            <option value="Open" <?= $status_filter == 'Open' ? 'selected' : '' ?>>Open</option>
                            <option value="In Progress" <?= $status_filter == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Implemented" <?= $status_filter == 'Implemented' ? 'selected' : '' ?>>Implemented</option>
                            <?php elseif ($active_section == 'events'): ?>
                            <option value="Planning" <?= $status_filter == 'Planning' ? 'selected' : '' ?>>Planning</option>
                            <option value="Upcoming" <?= $status_filter == 'Upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="Ongoing" <?= $status_filter == 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= $status_filter == 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $status_filter == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <?php elseif ($active_section == 'budget'): ?>
                            <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="submitted" <?= $status_filter == 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <?php elseif ($active_section == 'expenses'): ?>
                            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="paid" <?= $status_filter == 'paid' ? 'selected' : '' ?>>Paid</option>
                            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($active_section == 'issues' || $active_section == 'changes' || $active_section == 'testcases'): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="">All</option>
                            <option value="high" <?= (isset($_GET['priority']) && $_GET['priority'] == 'high') ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= (isset($_GET['priority']) && $_GET['priority'] == 'medium') ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= (isset($_GET['priority']) && $_GET['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($active_section == 'budget'): ?>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-<?= ($active_section == 'budget') ? '2' : '3' ?>">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                    
                    <div class="col-md-<?= ($active_section == 'budget') ? '2' : '3' ?>">
                        <a href="?section=<?= $active_section ?>" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-undo me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================================== -->
        <!-- DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php if ($active_section == 'dashboard'): ?>
            <?php $data = $section_data; ?>
            
            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card fade-in-up delay-1">
                    <div class="kpi-icon"><i class="fas fa-project-diagram"></i></div>
                    <div class="kpi-title">Projects</div>
                    <div class="kpi-value"><?= number_format($data['projects']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-success"><?= $data['projects']['active'] ?? 0 ?> Active</span>
                        <span class="mx-2">•</span>
                        <span class="text-warning"><?= $data['projects']['pending'] ?? 0 ?> Pending</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-2">
                    <div class="kpi-icon"><i class="fas fa-tasks"></i></div>
                    <div class="kpi-title">Activities</div>
                    <div class="kpi-value"><?= number_format($data['activities']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-info"><?= $data['activities']['in_progress'] ?? 0 ?> In Progress</span>
                        <span class="mx-2">•</span>
                        <span class="text-success"><?= $data['activities']['completed'] ?? 0 ?> Completed</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-3">
                    <div class="kpi-icon"><i class="fas fa-bug"></i></div>
                    <div class="kpi-title">Issues</div>
                    <div class="kpi-value"><?= number_format($data['issues']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-danger"><?= $data['issues']['open'] ?? 0 ?> Open</span>
                        <span class="mx-2">•</span>
                        <span class="text-success"><?= $data['issues']['closed'] ?? 0 ?> Closed</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-4">
                    <div class="kpi-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="kpi-title">Risks</div>
                    <div class="kpi-value"><?= number_format($data['risks']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-danger"><?= $data['risks']['high'] ?? 0 ?> High</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-5">
                    <div class="kpi-icon"><i class="fas fa-vial"></i></div>
                    <div class="kpi-title">Test Cases</div>
                    <div class="kpi-value"><?= number_format($data['test_cases']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-success"><?= $data['test_cases']['passed'] ?? 0 ?> Passed</span>
                        <span class="mx-2">•</span>
                        <span class="text-danger"><?= $data['test_cases']['failed'] ?? 0 ?> Failed</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-1">
                    <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
                    <div class="kpi-title">Budget</div>
                    <div class="kpi-value"><?= format_currency($data['budget']['total_budget'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        Spent: <?= format_currency($data['actual_expenses']['total'] ?? 0) ?>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-2">
                    <div class="kpi-icon"><i class="fas fa-users"></i></div>
                    <div class="kpi-title">Users</div>
                    <div class="kpi-value"><?= number_format($data['users']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        <span class="text-success"><?= $data['users']['active'] ?? 0 ?> Active</span>
                    </div>
                </div>
                
                <div class="kpi-card fade-in-up delay-3">
                    <div class="kpi-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div class="kpi-title">Events</div>
                    <div class="kpi-value"><?= number_format($data['events']['total'] ?? 0) ?></div>
                    <div class="kpi-sub">
                        Upcoming Events
                    </div>
                </div>
            </div>
            
            <!-- Charts -->
            <div class="chart-grid">
                <div class="chart-card fade-in-up delay-1">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-pie"></i>
                        Project Status Distribution
                    </h5>
                    <div class="chart-container">
                        <canvas id="projectStatusChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card fade-in-up delay-2">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-bar"></i>
                        Issue Priority Distribution
                    </h5>
                    <div class="chart-container">
                        <canvas id="issuePriorityChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card fade-in-up delay-3">
                    <h5 class="chart-title">
                        <i class="fas fa-chart-line"></i>
                        Activity Progress
                    </h5>
                    <div class="chart-container">
                        <canvas id="activityProgressChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="table-card fade-in-up delay-4">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-history"></i>
                        Recent Activity
                    </h5>
                    <a href="?section=activity_logs" class="btn btn-sm btn-primary">
                        View All <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($data['recent_activity'])): ?>
                                <?php foreach ($data['recent_activity'] as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary"><?= htmlspecialchars($activity['username'] ?? '') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($activity['action'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($activity['description'] ?? '') ?></td>
                                    <td><?= format_datetime($activity['created_at'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <i class="fas fa-history fa-2x text-muted mb-2"></i>
                                        <p>No recent activity</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <script>
            <?php if (!empty($data['project_status'])): ?>
            new Chart(document.getElementById('projectStatusChart'), {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode(array_column($data['project_status'], 'status')) ?>,
                    datasets: [{
                        data: <?= json_encode(array_column($data['project_status'], 'count')) ?>,
                        backgroundColor: ['#2dce89', '#fb6340', '#f5365c', '#11cdef', '#6c757d'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: { size: 12 }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
            <?php endif; ?>
            
            <?php if (!empty($data['issue_priority'])): ?>
            new Chart(document.getElementById('issuePriorityChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode(array_column($data['issue_priority'], 'priority')) ?>,
                    datasets: [{
                        label: 'Count',
                        data: <?= json_encode(array_column($data['issue_priority'], 'count')) ?>,
                        backgroundColor: '#273274',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            new Chart(document.getElementById('activityProgressChart'), {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Completed',
                        data: [12, 19, 25, 30],
                        borderColor: '#2dce89',
                        backgroundColor: 'rgba(45, 206, 137, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'In Progress',
                        data: [8, 15, 20, 25],
                        borderColor: '#fb6340',
                        backgroundColor: 'rgba(251, 99, 64, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' }
                        }
                    }
                }
            });
            </script>
            
        <!-- ============================================== -->
        <!-- PROJECTS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'projects'): ?>
            <?php $projects = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-project-diagram"></i>
                        Projects List
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($projects) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Project Name</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Phases</th>
                                <th>Activities</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><strong>#<?= $project['id'] ?></strong></td>
                                <td>
                                    <a href="#" class="text-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#projectModal<?= $project['id'] ?>">
                                        <?= htmlspecialchars($project['name'] ?? '') ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($project['department_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($project['status'] ?? '') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $project['status'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td><?= format_date($project['start_date'] ?? '') ?></td>
                                <td><?= format_date($project['end_date'] ?? '') ?></td>
                                <td><?= $project['phase_count'] ?? 0 ?></td>
                                <td><?= $project['activity_count'] ?? 0 ?></td>
                                <td><?= htmlspecialchars($project['created_by_name'] ?? 'System') ?></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal<?= $project['id'] ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Project Detail Modal -->
                            <div class="modal fade" id="projectModal<?= $project['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="fas fa-project-diagram me-2"></i>
                                                Project Details: <?= htmlspecialchars($project['name'] ?? '') ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($project['description'] ?? 'N/A')) ?></p>
                                                    <p><strong>Department:</strong> <?= htmlspecialchars($project['department_name'] ?? 'N/A') ?></p>
                                                    <p><strong>Status:</strong> 
                                                        <span class="badge bg-<?= get_status_class($project['status'] ?? '') ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $project['status'] ?? 'Unknown')) ?>
                                                        </span>
                                                    </p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Start Date:</strong> <?= format_date($project['start_date'] ?? '') ?></p>
                                                    <p><strong>End Date:</strong> <?= format_date($project['end_date'] ?? '') ?></p>
                                                    <p><strong>Project Type:</strong> <?= ucfirst($project['project_type'] ?? 'N/A') ?></p>
                                                    <p><strong>Created By:</strong> <?= htmlspecialchars($project['created_by_name'] ?? 'System') ?></p>
                                                    <p><strong>Created At:</strong> <?= format_datetime($project['created_at'] ?? '') ?></p>
                                                </div>
                                            </div>
                                            <hr>
                                            <div class="row">
                                                <div class="col-md-4 text-center">
                                                    <h3 class="text-primary"><?= $project['phase_count'] ?? 0 ?></h3>
                                                    <p class="text-muted">Phases</p>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <h3 class="text-primary"><?= $project['activity_count'] ?? 0 ?></h3>
                                                    <p class="text-muted">Activities</p>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <h3 class="text-primary"><?= $project['issue_count'] ?? 0 ?></h3>
                                                    <p class="text-muted">Issues</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- PHASES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'phases'): ?>
            <?php $phases = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-layer-group"></i>
                        Project Phases
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($phases) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Phase Name</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Order</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Activities</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($phases as $phase): ?>
                            <tr>
                                <td><strong>#<?= $phase['id'] ?></strong></td>
                                <td><?= htmlspecialchars($phase['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($phase['project_name'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($phase['status'] ?? '') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $phase['status'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td><?= $phase['Phase_order'] ?? 0 ?></td>
                                <td><?= format_date($phase['start_date'] ?? '') ?></td>
                                <td><?= format_date($phase['end_date'] ?? '') ?></td>
                                <td><?= $phase['activity_count'] ?? 0 ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- ACTIVITIES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'activities'): ?>
            <?php $activities = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-tasks"></i>
                        Activities
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($activities) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Activity Name</th>
                                <th>Project</th>
                                <th>Phase</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Progress</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Assigned To</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td><strong>#<?= $activity['id'] ?></strong></td>
                                <td><?= htmlspecialchars($activity['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($activity['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($activity['phase_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($activity['status'] ?? '') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $activity['status'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_priority_badge($activity['priority'] ?? '') ?>">
                                        <?= ucfirst($activity['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?= $activity['progress'] ?? 0 ?>%</span>
                                        <div class="progress-custom" style="width: 80px;">
                                            <div class="progress-bar-custom progress-bar-primary" style="width: <?= $activity['progress'] ?? 0 ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= format_date($activity['start_date'] ?? '') ?></td>
                                <td><?= format_date($activity['end_date'] ?? '') ?></td>
                                <td><?= htmlspecialchars($activity['assigned_users'] ?? 'Unassigned') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- SUB-ACTIVITIES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'subactivities'): ?>
            <?php $subactivities = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-list-ul"></i>
                        Sub-Activities
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($subactivities) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sub-Activity</th>
                                <th>Project</th>
                                <th>Activity</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subactivities as $sub): ?>
                            <tr>
                                <td><strong>#<?= $sub['id'] ?></strong></td>
                                <td><?= htmlspecialchars($sub['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($sub['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($sub['activity_name'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($sub['status'] ?? '') ?>">
                                        <?= ucfirst($sub['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($sub['assigned_to_name'] ?? 'Unassigned') ?></td>
                                <td><?= format_date($sub['start_date'] ?? '') ?></td>
                                <td><?= format_date($sub['end_date'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- ISSUES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'issues'): ?>
            <?php $issues = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-bug"></i>
                        Issues Tracker
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($issues) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Comments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($issues as $issue): ?>
                            <tr>
                                <td><strong>#<?= $issue['id'] ?></strong></td>
                                <td>
                                    <a href="#" data-bs-toggle="modal" data-bs-target="#issueModal<?= $issue['id'] ?>">
                                        <?= htmlspecialchars($issue['title'] ?? '') ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($issue['project_name'] ?? '') ?></td>
                                <td><?= ucfirst($issue['type'] ?? 'Bug') ?></td>
                                <td>
                                    <span class="badge <?= get_priority_badge($issue['priority'] ?? '') ?>">
                                        <?= ucfirst($issue['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($issue['status'] ?? '') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $issue['status'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($issue['assigned_to_name'] ?? 'Unassigned') ?></td>
                                <td><?= format_date($issue['created_at'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-info"><?= $issue['comment_count'] ?? 0 ?></span>
                                </td>
                            </tr>
                            
                            <!-- Issue Modal -->
                            <div class="modal fade" id="issueModal<?= $issue['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Issue #<?= $issue['id'] ?>: <?= htmlspecialchars($issue['title'] ?? '') ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($issue['description'] ?? 'N/A')) ?></p>
                                                    <p><strong>Summary:</strong> <?= nl2br(htmlspecialchars($issue['summary'] ?? 'N/A')) ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Project:</strong> <?= htmlspecialchars($issue['project_name'] ?? '') ?></p>
                                                    <p><strong>Type:</strong> <?= ucfirst($issue['type'] ?? 'Bug') ?></p>
                                                    <p><strong>Priority:</strong> 
                                                        <span class="badge <?= get_priority_badge($issue['priority'] ?? '') ?>">
                                                            <?= ucfirst($issue['priority'] ?? 'Medium') ?>
                                                        </span>
                                                    </p>
                                                    <p><strong>Status:</strong> 
                                                        <span class="badge bg-<?= get_status_class($issue['status'] ?? '') ?>">
                                                            <?= ucfirst(str_replace('_', ' ', $issue['status'] ?? 'Unknown')) ?>
                                                        </span>
                                                    </p>
                                                    <p><strong>Assigned To:</strong> <?= htmlspecialchars($issue['assigned_to_name'] ?? 'Unassigned') ?></p>
                                                    <p><strong>Created By:</strong> <?= htmlspecialchars($issue['created_by_name'] ?? 'Unknown') ?></p>
                                                    <p><strong>Created At:</strong> <?= format_datetime($issue['created_at'] ?? '') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- CHANGE REQUESTS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'changes'): ?>
            <?php $changes = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-exchange-alt"></i>
                        Change Requests
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($changes) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Requester</th>
                                <th>Assigned To</th>
                                <th>Request Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($changes as $cr): ?>
                            <tr>
                                <td><strong>#<?= $cr['change_request_id'] ?></strong></td>
                                <td><?= htmlspecialchars($cr['change_title'] ?? '') ?></td>
                                <td><?= htmlspecialchars($cr['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($cr['change_type'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge <?= get_priority_badge($cr['priority'] ?? '') ?>">
                                        <?= ucfirst($cr['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($cr['status'] ?? '') ?>">
                                        <?= ucfirst($cr['status'] ?? 'Unknown') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($cr['requester_name'] ?? 'Unknown') ?></td>
                                <td><?= htmlspecialchars($cr['assigned_to_name'] ?? 'Unassigned') ?></td>
                                <td><?= format_date($cr['request_date'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- RISKS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'risks'): ?>
            <?php $risks = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-shield-alt"></i>
                        Risk Register
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($risks) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Category</th>
                                <th>Risk Level</th>
                                <th>Owner</th>
                                <th>Likelihood</th>
                                <th>Impact</th>
                                <th>Score</th>
                                <th>Mitigations</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($risks as $risk): ?>
                            <tr>
                                <td><strong>#<?= $risk['id'] ?></strong></td>
                                <td><?= htmlspecialchars($risk['title'] ?? '') ?></td>
                                <td><?= htmlspecialchars($risk['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($risk['category_name'] ?? 'Uncategorized') ?></td>
                                <td>
                                    <span class="badge <?= get_risk_level_class($risk['risk_level'] ?? '') ?>">
                                        <?= $risk['risk_level'] ?? 'N/A' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($risk['owner_name'] ?? 'Unassigned') ?></td>
                                <td><?= $risk['likelihood'] ?? 0 ?></td>
                                <td><?= $risk['impact'] ?? 0 ?></td>
                                <td><strong><?= ($risk['likelihood'] ?? 0) * ($risk['impact'] ?? 0) ?></strong></td>
                                <td>
                                    <span class="badge bg-info"><?= $risk['mitigation_count'] ?? 0 ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- TEST CASES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'testcases'): ?>
            <?php $testcases = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-vial"></i>
                        Test Cases
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($testcases) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Feature</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Created By</th>
                                <th>Created At</th>
                                <th>Tester Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($testcases as $tc): ?>
                            <tr>
                                <td><strong>#<?= $tc['id'] ?></strong></td>
                                <td><?= htmlspecialchars($tc['title'] ?? '') ?></td>
                                <td><?= htmlspecialchars($tc['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($tc['feature_name'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($tc['status'] ?? '') ?>">
                                        <?= ucfirst($tc['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_priority_badge($tc['priority'] ?? '') ?>">
                                        <?= ucfirst($tc['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($tc['created_by_name'] ?? 'Unknown') ?></td>
                                <td><?= format_date($tc['created_at'] ?? '') ?></td>
                                <td><?= htmlspecialchars($tc['tester_remark'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- EVENTS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'events'): ?>
            <?php $events = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-calendar-alt"></i>
                        Events
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($events) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Event Name</th>
                                <th>Project</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Location</th>
                                <th>Attendees</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td><strong>#<?= $event['id'] ?></strong></td>
                                <td><?= htmlspecialchars($event['event_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($event['project_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($event['event_type'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($event['status'] ?? '') ?>">
                                        <?= ucfirst($event['status'] ?? 'Planning') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_priority_badge($event['priority'] ?? '') ?>">
                                        <?= ucfirst($event['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td><?= format_datetime($event['start_datetime'] ?? '') ?></td>
                                <td><?= format_datetime($event['end_datetime'] ?? '') ?></td>
                                <td><?= htmlspecialchars($event['location'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-info"><?= $event['attendee_count'] ?? 0 ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- EVENT ATTENDEES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'event_attendees'): ?>
            <?php $attendees = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-user-friends"></i>
                        Event Attendees
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($attendees) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Attendee</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>System Role</th>
                                <th>RSVP Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendees as $attendee): ?>
                            <tr>
                                <td><?= htmlspecialchars($attendee['event_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($attendee['username'] ?? '') ?></td>
                                <td><?= htmlspecialchars($attendee['email'] ?? '') ?></td>
                                <td><?= htmlspecialchars($attendee['role_in_event'] ?? 'Participant') ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $attendee['system_role'] ?? 'User')) ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($attendee['rsvp_status'] ?? '') ?>">
                                        <?= ucfirst($attendee['rsvp_status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- EVENT TASKS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'event_tasks'): ?>
            <?php $event_tasks = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-tasks"></i>
                        Event Tasks
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($event_tasks) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Task Name</th>
                                <th>Event</th>
                                <th>Assigned To</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Due Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($event_tasks as $task): ?>
                            <tr>
                                <td><strong>#<?= $task['id'] ?></strong></td>
                                <td><?= htmlspecialchars($task['task_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($task['event_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($task['assigned_to_name'] ?? 'Unassigned') ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($task['status'] ?? '') ?>">
                                        <?= ucfirst($task['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= get_priority_badge($task['priority'] ?? '') ?>">
                                        <?= ucfirst($task['priority'] ?? 'Medium') ?>
                                    </span>
                                </td>
                                <td><?= format_date($task['due_date'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- BUDGET ITEMS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'budget'): ?>
            <?php $budget_items = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-wallet"></i>
                        Budget Items
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($budget_items) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Item Name</th>
                                <th>Project</th>
                                <th>Category</th>
                                <th>Cost Type</th>
                                <th>Estimated</th>
                                <th>Total Budget</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budget_items as $item): 
                                $actual = $item['actual_amount'] ?? 0;
                                $total = $item['total_budget_amount'] ?? 0;
                                $variance = $total - $actual;
                                $variance_percent = $total > 0 ? ($variance / $total) * 100 : 0;
                            ?>
                            <tr>
                                <td><strong>#<?= $item['id'] ?></strong></td>
                                <td><?= htmlspecialchars($item['item_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($item['project_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars($item['cost_type_name'] ?? 'N/A') ?></td>
                                <td><?= format_currency($item['estimated_amount'] ?? 0) ?></td>
                                <td><?= format_currency($total) ?></td>
                                <td><?= format_currency($actual) ?></td>
                                <td>
                                    <span class="text-<?= $variance >= 0 ? 'success' : 'danger' ?>">
                                        <?= format_currency($variance) ?> (<?= number_format($variance_percent, 1) ?>%)
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($item['status'] ?? '') ?>">
                                        <?= ucfirst($item['status'] ?? 'Draft') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- ACTUAL EXPENSES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'expenses'): ?>
            <?php $expenses = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-money-bill-wave"></i>
                        Actual Expenses
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($expenses) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Description</th>
                                <th>Project</th>
                                <th>Budget Item</th>
                                <th>Vendor</th>
                                <th>Amount</th>
                                <th>Transaction Date</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><strong>#<?= $expense['id'] ?></strong></td>
                                <td><?= htmlspecialchars($expense['description'] ?? '') ?></td>
                                <td><?= htmlspecialchars($expense['project_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($expense['budget_item_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($expense['vendor_name'] ?? 'N/A') ?></td>
                                <td><strong><?= format_currency($expense['amount'] ?? 0) ?></strong></td>
                                <td><?= format_date($expense['transaction_date'] ?? '') ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $expense['payment_method'] ?? 'N/A')) ?></td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($expense['status'] ?? '') ?>">
                                        <?= ucfirst($expense['status'] ?? 'Pending') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- USERS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'users'): ?>
            <?php $users = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-users"></i>
                        System Users
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($users) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>System Role</th>
                                <th>Status</th>
                                <th>Assigned Issues</th>
                                <th>Owned Risks</th>
                                <th>Project Assignments</th>
                                <th>System Access</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><strong>#<?= $user['id'] ?></strong></td>
                                <td><?= htmlspecialchars($user['username'] ?? '') ?></td>
                                <td><?= htmlspecialchars($user['email'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-primary">
                                        <?= ucfirst(str_replace('_', ' ', $user['system_role'] ?? 'User')) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= $user['assigned_issues'] ?? 0 ?></td>
                                <td><?= $user['owned_risks'] ?? 0 ?></td>
                                <td><?= $user['project_assignments'] ?? 0 ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#userSystemsModal<?= $user['id'] ?>">
                                        <i class="fas fa-laptop"></i> (<?= $user['system_access_count'] ?? 0 ?>)
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- User Systems Modal -->
                            <div class="modal fade" id="userSystemsModal<?= $user['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">System Access for <?= htmlspecialchars($user['username'] ?? '') ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if (!empty($user['system_access'])): ?>
                                                <ul class="list-group">
                                                    <?php 
                                                    $systems = explode(', ', $user['system_access']);
                                                    foreach ($systems as $system): 
                                                    ?>
                                                    <li class="list-group-item">
                                                        <i class="fas fa-check-circle text-success me-2"></i>
                                                        <?= htmlspecialchars($system) ?>
                                                    </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p class="text-muted">No system access assigned</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- USER ASSIGNMENTS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'assignments'): ?>
            <?php $assignments = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-user-check"></i>
                        User Assignments
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($assignments) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Assigned To</th>
                                <th>Project</th>
                                <th>Phase</th>
                                <th>Activity</th>
                                <th>Sub-Activity</th>
                                <th>Assigned By</th>
                                <th>Assigned At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assign): ?>
                            <tr>
                                <td><strong>#<?= $assign['id'] ?></strong></td>
                                <td><?= htmlspecialchars($assign['user_name'] ?? '') ?></td>
                                <td><?= ucfirst(str_replace('_', ' ', $assign['system_role'] ?? 'User')) ?></td>
                                <td>
                                    <?php if ($assign['project_id']): ?>
                                        <span class="badge bg-info">Project</span>
                                    <?php elseif ($assign['phase_id']): ?>
                                        <span class="badge bg-primary">Phase</span>
                                    <?php elseif ($assign['activity_id']): ?>
                                        <span class="badge bg-warning">Activity</span>
                                    <?php elseif ($assign['subactivity_id']): ?>
                                        <span class="badge bg-success">Sub-Activity</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($assign['project_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($assign['phase_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($assign['activity_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($assign['subactivity_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($assign['assigned_by_name'] ?? 'System') ?></td>
                                <td><?= format_datetime($assign['assigned_at'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?= $assign['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $assign['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- SYSTEMS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'systems'): ?>
            <?php $systems = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-laptop"></i>
                        System Modules
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($systems) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>System Name</th>
                                <th>System URL</th>
                                <th>Users with Access</th>
                                <th>User List</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($systems as $system): ?>
                            <tr>
                                <td><strong>#<?= $system['system_id'] ?></strong></td>
                                <td><?= htmlspecialchars($system['system_name'] ?? '') ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($system['system_url'] ?? '#') ?>" target="_blank" class="text-primary">
                                        <i class="fas fa-external-link-alt me-1"></i> Open
                                    </a>
                                </td>
                                <td><?= $system['user_count'] ?? 0 ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#systemUsersModal<?= $system['system_id'] ?>">
                                        <i class="fas fa-users"></i> View Users
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- System Users Modal -->
                            <div class="modal fade" id="systemUsersModal<?= $system['system_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Users with Access to <?= htmlspecialchars($system['system_name'] ?? '') ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <?php if (!empty($system['users'])): ?>
                                                <ul class="list-group">
                                                    <?php 
                                                    $users = explode(', ', $system['users']);
                                                    foreach ($users as $username): 
                                                    ?>
                                                    <li class="list-group-item">
                                                        <i class="fas fa-user text-primary me-2"></i>
                                                        <?= htmlspecialchars($username) ?>
                                                    </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php else: ?>
                                                <p class="text-muted">No users have access to this system</p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- FEATURES SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'features'): ?>
            <?php $features = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-plus-circle"></i>
                        Features
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($features) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Feature Name</th>
                                <th>Project</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Test Cases</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($features as $feature): ?>
                            <tr>
                                <td><strong>#<?= $feature['id'] ?></strong></td>
                                <td><?= htmlspecialchars($feature['feature_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($feature['project_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars(substr($feature['description'] ?? '', 0, 50)) ?>...</td>
                                <td>
                                    <span class="badge bg-<?= get_status_class($feature['status'] ?? '') ?>">
                                        <?= ucfirst($feature['status'] ?? 'Planned') ?>
                                    </span>
                                </td>
                                <td><?= $feature['test_case_count'] ?? 0 ?></td>
                                <td><?= format_date($feature['created_at'] ?? '') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- VENDORS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'vendors'): ?>
            <?php $vendors = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-truck"></i>
                        Vendors
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($vendors) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vendor Name</th>
                                <th>Type</th>
                                <th>Contact Person</th>
                                <th>Contact Email</th>
                                <th>Contact Phone</th>
                                <th>Contracts</th>
                                <th>Total Expenses</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendors as $vendor): ?>
                            <tr>
                                <td><strong>#<?= $vendor['id'] ?></strong></td>
                                <td><?= htmlspecialchars($vendor['vendor_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($vendor['vendor_type'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($vendor['contact_person'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($vendor['contact_email'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($vendor['contact_phone'] ?? 'N/A') ?></td>
                                <td><?= $vendor['contract_count'] ?? 0 ?></td>
                                <td><?= format_currency($vendor['total_expenses'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- CONTRACTS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'contracts'): ?>
            <?php $contracts = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-file-contract"></i>
                        Contracts
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($contracts) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Contract Name</th>
                                <th>Vendor</th>
                                <th>Contract Number</th>
                                <th>Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $contract): ?>
                            <tr>
                                <td><strong>#<?= $contract['id'] ?></strong></td>
                                <td><?= htmlspecialchars($contract['contract_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($contract['vendor_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($contract['contract_number'] ?? '') ?></td>
                                <td><?= htmlspecialchars($contract['contract_type'] ?? 'N/A') ?></td>
                                <td><?= format_date($contract['start_date'] ?? '') ?></td>
                                <td><?= format_date($contract['end_date'] ?? '') ?></td>
                                <td><?= format_currency($contract['total_value'] ?? 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <!-- ============================================== -->
        <!-- ACTIVITY LOGS SECTION -->
        <!-- ============================================== -->
        <?php elseif ($active_section == 'activity_logs'): ?>
            <?php $logs = $section_data; ?>
            
            <div class="table-card">
                <div class="table-header">
                    <h5 class="table-title">
                        <i class="fas fa-history"></i>
                        Activity Logs
                    </h5>
                    <span class="badge bg-primary">Total: <?= count($logs) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Project</th>
                                <th>Test Case</th>
                                <th>Created At</th>
                                <th>Read</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><strong>#<?= $log['id'] ?></strong></td>
                                <td><?= htmlspecialchars($log['username'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['action'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['description'] ?? '') ?></td>
                                <td><?= htmlspecialchars($log['project_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($log['test_case_title'] ?? 'N/A') ?></td>
                                <td><?= format_datetime($log['created_at'] ?? '') ?></td>
                                <td>
                                    <span class="badge bg-<?= $log['is_read'] ? 'success' : 'warning' ?>">
                                        <?= $log['is_read'] ? 'Read' : 'Unread' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    
    <!-- JSZip for Excel export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    
    <!-- pdfmake for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true
        });
        
        // Profile Dropdown Toggle
        document.addEventListener('DOMContentLoaded', function() {
            const profileTrigger = document.getElementById('profileTrigger');
            const profileDropdown = document.getElementById('profileDropdown');
            
            if (profileTrigger && profileDropdown) {
                profileTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    profileDropdown.classList.toggle('show');
                });
                
                document.addEventListener('click', function(e) {
                    if (!profileTrigger.contains(e.target) && !profileDropdown.contains(e.target)) {
                        profileDropdown.classList.remove('show');
                    }
                });
            }
            
            // Sidebar toggle synchronization
            const sidebarContainer = document.getElementById('sidebarContainer');
            const staticHeader = document.getElementById('staticHeader');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarContainer && staticHeader && mainContent) {
                if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                    staticHeader.classList.add('sidebar-collapsed');
                    mainContent.classList.add('sidebar-collapsed');
                }
                
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
            
            // Initialize DataTables
            $('.datatable').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'copy',
                        className: 'btn btn-sm btn-outline-primary',
                        text: '<i class="fas fa-copy"></i> Copy'
                    },
                    {
                        extend: 'csv',
                        className: 'btn btn-sm btn-outline-primary',
                        text: '<i class="fas fa-file-csv"></i> CSV'
                    },
                    {
                        extend: 'excel',
                        className: 'btn btn-sm btn-outline-primary',
                        text: '<i class="fas fa-file-excel"></i> Excel'
                    },
                    {
                        extend: 'pdf',
                        className: 'btn btn-sm btn-outline-primary',
                        text: '<i class="fas fa-file-pdf"></i> PDF'
                    },
                    {
                        extend: 'print',
                        className: 'btn btn-sm btn-outline-primary',
                        text: '<i class="fas fa-print"></i> Print'
                    }
                ],
                responsive: true,
                pageLength: 25,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries"
                }
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>