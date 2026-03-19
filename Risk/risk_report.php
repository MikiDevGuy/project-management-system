<?php
// risk_report.php - Complete Risk Management Report with SRS Compliance
// Version 4.1 - FIXED: DataTables column count error, added responsive design
// Last Updated: 2026-02-12

session_start();
require_once '../db.php';
require_once '../vendor/autoload.php'; // For Dompdf and PhpSpreadsheet

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

// =============================================
// ROLE-BASED ACCESS CONTROL (SRS Section 2.2)
// =============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// Get current user role and details
$user_sql = "SELECT id, username, email, system_role FROM users WHERE id = ?";
$stmt = $conn->prepare($user_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$user_role = $current_user['system_role'] ?? '';
$username = $current_user['username'] ?? 'User';

// =============================================
// PERMISSION CHECK (SRS Section 2.2)
// =============================================
$can_export_reports = in_array($user_role, ['super_admin', 'pm_manager']);
if (!$can_export_reports && isset($_GET['export'])) {
    header('Location: risks.php?error=access_denied');
    exit;
}

// =============================================
// HELPER FUNCTIONS
// =============================================
function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

function get_status_class($status_key) {
    $classes = [
        'pending_review' => 'bg-warning',
        'open' => 'bg-info',
        'in_progress' => 'bg-primary',
        'mitigated' => 'bg-success',
        'closed' => 'bg-secondary',
        'rejected' => 'bg-danger'
    ];
    return $classes[$status_key] ?? 'bg-secondary';
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

function get_risk_level_color($level) {
    $colors = [
        'Critical' => '#dc3545',
        'High' => '#fd7e14',
        'Medium' => '#6c757d',
        'Low' => '#28a745'
    ];
    return $colors[$level] ?? '#6c757d';
}

// =============================================
// LOAD DROPDOWN DATA
// =============================================
function load_dropdown_data($conn, $user_id, $user_role) {
    $data = [];
    
    // Projects - Based on user role (SRS Section 2.2)
    if ($user_role === 'super_admin') {
        $sql = "SELECT id, name FROM projects WHERE status != 'terminated' ORDER BY name";
        $result = $conn->query($sql);
        $data['projects'] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } else {
        $sql = "SELECT DISTINCT p.id, p.name 
                FROM projects p
                LEFT JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = ? AND ua.is_active = 1
                LEFT JOIN project_users pu ON p.id = pu.project_id AND pu.user_id = ?
                WHERE p.status != 'terminated'
                AND (ua.user_id IS NOT NULL OR pu.user_id IS NOT NULL)
                ORDER BY p.name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['projects'] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    // Departments - All active
    $res = $conn->query("SELECT id, department_name FROM departments ORDER BY department_name");
    $data['departments'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    
    // Users - All active
    $res = $conn->query("SELECT id, username, email FROM users ORDER BY username");
    $data['users'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    
    // Categories - All active
    $res = $conn->query("SELECT id, name FROM risk_categories WHERE is_active = 1 ORDER BY name");
    $data['categories'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    
    // Statuses - All active
    $res = $conn->query("SELECT id, status_key, label FROM risk_statuses WHERE is_active = 1 ORDER BY id");
    $data['statuses'] = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    
    return $data;
}

$dropdowns = load_dropdown_data($conn, $current_user_id, $user_role);

// =============================================
// FILTER HANDLING
// =============================================
$filter_project = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
$filter_department = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int)$_GET['department_id'] : null;
$filter_category = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$filter_status = isset($_GET['status_id']) && $_GET['status_id'] !== '' ? (int)$_GET['status_id'] : null;
$filter_owner = isset($_GET['owner_user_id']) && $_GET['owner_user_id'] !== '' ? (int)$_GET['owner_user_id'] : null;
$filter_level = isset($_GET['risk_level']) && $_GET['risk_level'] !== '' ? $_GET['risk_level'] : null;
$filter_date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
$filter_date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;

// =============================================
// BUILD FILTER QUERY
// =============================================
$whereParts = [];
$params = [];
$types = '';

if ($filter_project) {
    $whereParts[] = "r.project_id = ?";
    $params[] = $filter_project;
    $types .= 'i';
}

if ($filter_department) { 
    $whereParts[] = "r.department_id = ?"; 
    $params[] = $filter_department; 
    $types .= 'i'; 
}

if ($filter_category) { 
    $whereParts[] = "r.category_id = ?"; 
    $params[] = $filter_category; 
    $types .= 'i'; 
}

if ($filter_status) { 
    $whereParts[] = "r.status_id = ?"; 
    $params[] = $filter_status; 
    $types .= 'i'; 
}

if ($filter_owner) { 
    $whereParts[] = "r.owner_user_id = ?"; 
    $params[] = $filter_owner; 
    $types .= 'i'; 
}

if ($filter_level) { 
    $whereParts[] = "r.risk_level = ?"; 
    $params[] = $filter_level; 
    $types .= 's'; 
}

if ($filter_date_from) { 
    $whereParts[] = "DATE(r.created_at) >= ?"; 
    $params[] = $filter_date_from; 
    $types .= 's'; 
}

if ($filter_date_to) { 
    $whereParts[] = "DATE(r.created_at) <= ?"; 
    $params[] = $filter_date_to; 
    $types .= 's'; 
}

// Role-based access filter (SRS Section 2.2)
if ($user_role !== 'super_admin') {
    $whereParts[] = "EXISTS (
        SELECT 1 FROM user_assignments ua 
        WHERE ua.user_id = ? AND ua.project_id = r.project_id AND ua.is_active = 1
        UNION
        SELECT 1 FROM project_users pu 
        WHERE pu.user_id = ? AND pu.project_id = r.project_id
    )";
    $params[] = $current_user_id;
    $params[] = $current_user_id;
    $types .= 'ii';
}

$whereSql = count($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// =============================================
// FETCH RISK DATA
// =============================================
$sql = "SELECT 
            r.*, 
            p.name AS project_name, 
            d.department_name, 
            u.id AS owner_id,
            u.username AS owner_name,
            u.email AS owner_email,
            rc.name AS category_name, 
            rs.label AS status_label,
            rs.status_key AS status_key,
            creator.username AS created_by_name,
            approver.username AS approved_by_name,
            (SELECT COUNT(*) FROM risk_mitigations WHERE risk_id = r.id) as mitigation_count,
            (SELECT COUNT(*) FROM risk_comments WHERE risk_id = r.id) as comment_count,
            (SELECT COUNT(*) FROM risk_history WHERE risk_id = r.id) as history_count
        FROM risks r
        LEFT JOIN projects p ON r.project_id = p.id
        LEFT JOIN departments d ON r.department_id = d.id
        LEFT JOIN users u ON r.owner_user_id = u.id
        LEFT JOIN users creator ON r.created_by = creator.id
        LEFT JOIN users approver ON r.approved_by = approver.id
        LEFT JOIN risk_categories rc ON r.category_id = rc.id
        LEFT JOIN risk_statuses rs ON r.status_id = rs.id
        $whereSql
        ORDER BY 
            CASE 
                WHEN r.risk_level = 'Critical' THEN 1
                WHEN r.risk_level = 'High' THEN 2
                WHEN r.risk_level = 'Medium' THEN 3
                WHEN r.risk_level = 'Low' THEN 4
                ELSE 5
            END,
            r.risk_score DESC,
            r.created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $risks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $risks = [];
}

// =============================================
// CALCULATE STATISTICS
// =============================================
$total_risks = count($risks);
$critical_risks = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Critical'));
$high_risks = count(array_filter($risks, fn($r) => $r['risk_level'] === 'High'));
$medium_risks = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Medium'));
$low_risks = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Low'));
$total_mitigations = array_sum(array_column($risks, 'mitigation_count'));
$total_comments = array_sum(array_column($risks, 'comment_count'));

$status_counts = [
    'pending_review' => count(array_filter($risks, fn($r) => $r['status_key'] === 'pending_review')),
    'open' => count(array_filter($risks, fn($r) => $r['status_key'] === 'open')),
    'in_progress' => count(array_filter($risks, fn($r) => $r['status_key'] === 'in_progress')),
    'mitigated' => count(array_filter($risks, fn($r) => $r['status_key'] === 'mitigated')),
    'closed' => count(array_filter($risks, fn($r) => $r['status_key'] === 'closed')),
    'rejected' => count(array_filter($risks, fn($r) => $r['status_key'] === 'rejected'))
];

// =============================================
// HANDLE EXPORT - FIXED: Removed GD dependency
// =============================================
$export = $_GET['export'] ?? null;

if ($export && $can_export_reports) {
    
    // =========================================
    // PDF EXPORT - SRS Section 3.2.3
    // FIXED: No GD library required - Text-based logo
    // =========================================
    if ($export === 'pdf') {
        
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('debugKeepTemp', false);
        
        $dompdf = new Dompdf($options);
        
        // Generate PDF HTML - NO IMAGES, TEXT-BASED LOGO
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <style>
                * {
                    font-family: Helvetica, Arial, sans-serif;
                }
                body {
                    margin: 20px;
                    padding: 0;
                    background: #ffffff;
                    font-size: 11px;
                }
                .header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    border-bottom: 3px solid #273274;
                    padding-bottom: 15px;
                    margin-bottom: 20px;
                }
                .logo-container {
                    display: flex;
                    align-items: center;
                }
                .text-logo {
                    background: #273274;
                    color: white;
                    padding: 10px 20px;
                    border-radius: 8px;
                    font-size: 24px;
                    font-weight: bold;
                    letter-spacing: 2px;
                    display: inline-block;
                    text-align: center;
                }
                .text-logo-small {
                    font-size: 12px;
                    opacity: 0.9;
                    margin-top: 5px;
                }
                .title-section {
                    flex-grow: 1;
                    text-align: right;
                }
                .title-section h1 {
                    color: #273274;
                    margin: 0;
                    font-size: 24px;
                    font-weight: bold;
                }
                .title-section p {
                    color: #666;
                    margin: 5px 0 0;
                    font-size: 11px;
                }
                .report-meta {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    border-left: 5px solid #273274;
                }
                .report-meta table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .report-meta td {
                    padding: 5px;
                    font-size: 11px;
                }
                .report-meta .label {
                    font-weight: bold;
                    color: #273274;
                    width: 120px;
                }
                .stats-container {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 10px;
                    margin-bottom: 25px;
                }
                .stat-box {
                    flex: 1;
                    min-width: 100px;
                    background: white;
                    border: 1px solid #dee2e6;
                    border-radius: 8px;
                    padding: 12px;
                    text-align: center;
                    border-top: 4px solid #273274;
                }
                .stat-box.total { border-top-color: #273274; }
                .stat-box.critical { border-top-color: #dc3545; }
                .stat-box.high { border-top-color: #fd7e14; }
                .stat-box.medium { border-top-color: #6c757d; }
                .stat-box.low { border-top-color: #28a745; }
                .stat-box.mitigations { border-top-color: #17a2b8; }
                .stat-number {
                    font-size: 24px;
                    font-weight: bold;
                    margin: 5px 0;
                    color: #273274;
                }
                .stat-label {
                    font-size: 10px;
                    color: #6c757d;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .status-summary {
                    background: #f8f9fa;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 25px;
                }
                .status-summary h3 {
                    color: #273274;
                    margin: 0 0 10px 0;
                    font-size: 14px;
                    border-bottom: 2px solid #f8a01c;
                    padding-bottom: 8px;
                }
                .status-grid {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                }
                .status-item {
                    flex: 1;
                    min-width: 70px;
                    padding: 8px;
                    border-radius: 5px;
                    text-align: center;
                    font-size: 10px;
                }
                .status-pending { background: #fff3cd; color: #856404; }
                .status-open { background: #cce5ff; color: #004085; }
                .status-progress { background: #d1ecf1; color: #0c5460; }
                .status-mitigated { background: #d4edda; color: #155724; }
                .status-closed { background: #e2e3e5; color: #383d41; }
                .status-rejected { background: #f8d7da; color: #721c24; }
                .risk-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                    font-size: 9px;
                }
                .risk-table th {
                    background: #273274;
                    color: white;
                    font-weight: bold;
                    padding: 8px 5px;
                    text-align: left;
                    font-size: 9px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                .risk-table td {
                    padding: 8px 5px;
                    border: 1px solid #dee2e6;
                    vertical-align: middle;
                }
                .risk-table tr:nth-child(even) {
                    background: #f8f9fa;
                }
                .badge {
                    padding: 3px 6px;
                    border-radius: 3px;
                    font-size: 8px;
                    font-weight: bold;
                    display: inline-block;
                }
                .badge-danger { background: #dc3545; color: white; }
                .badge-warning { background: #fd7e14; color: white; }
                .badge-secondary { background: #6c757d; color: white; }
                .badge-success { background: #28a745; color: white; }
                .badge-info { background: #17a2b8; color: white; }
                .badge-primary { background: #007bff; color: white; }
                .badge-dark { background: #343a40; color: white; }
                .footer {
                    margin-top: 30px;
                    padding-top: 15px;
                    border-top: 1px solid #dee2e6;
                    text-align: center;
                    font-size: 8px;
                    color: #6c757d;
                }
                .page-break {
                    page-break-after: always;
                }
            </style>
        </head>
        <body>
            <!-- Header with Text-based Logo (No GD required) -->
            <div class="header">
                <div class="logo-container">
                    <div>
                        <div class="text-logo">DASHEN BANK</div>
                        <div class="text-logo-small">Est. 1995</div>
                    </div>
                    <div style="margin-left: 15px;">
                        <h1 style="color: #273274; margin: 0; font-size: 18px;">Dashen Bank S.C.</h1>
                        <p style="color: #666; margin: 5px 0 0;">Project Risk Management</p>
                    </div>
                </div>
                <div class="title-section">
                    <h1>Risk Register Report</h1>
                    <p>Generated: ' . date('F j, Y \a\t g:i A') . '</p>
                    <p>Generated by: ' . e($username) . ' (' . ucwords(str_replace('_', ' ', $user_role)) . ')</p>
                </div>
            </div>
            
            <!-- Report Meta Information -->
            <div class="report-meta">
                <table>
                    <tr>
                        <td class="label">Report ID:</td>
                        <td>RISK-REP-' . date('Ymd') . '-' . rand(1000, 9999) . '</td>
                        <td class="label">Total Risks:</td>
                        <td>' . $total_risks . '</td>
                    </tr>
                    <tr>
                        <td class="label">Date Range:</td>
                        <td>' . ($filter_date_from ?: 'All time') . ' to ' . ($filter_date_to ?: 'Present') . '</td>
                        <td class="label">Filters Applied:</td>
                        <td>' . count(array_filter($_GET, function($k) { return !in_array($k, ['export', 'page']); }, ARRAY_FILTER_USE_KEY)) . ' filter(s)</td>
                    </tr>
                    <tr>
                        <td class="label">Department:</td>
                        <td>' . ($filter_department ? 'Filtered' : 'All') . '</td>
                        <td class="label">Risk Level:</td>
                        <td>' . ($filter_level ?: 'All') . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-box total">
                    <div class="stat-number">' . $total_risks . '</div>
                    <div class="stat-label">Total Risks</div>
                </div>
                <div class="stat-box critical">
                    <div class="stat-number" style="color: #dc3545;">' . $critical_risks . '</div>
                    <div class="stat-label">Critical</div>
                </div>
                <div class="stat-box high">
                    <div class="stat-number" style="color: #fd7e14;">' . $high_risks . '</div>
                    <div class="stat-label">High</div>
                </div>
                <div class="stat-box medium">
                    <div class="stat-number" style="color: #6c757d;">' . $medium_risks . '</div>
                    <div class="stat-label">Medium</div>
                </div>
                <div class="stat-box low">
                    <div class="stat-number" style="color: #28a745;">' . $low_risks . '</div>
                    <div class="stat-label">Low</div>
                </div>
                <div class="stat-box mitigations">
                    <div class="stat-number" style="color: #17a2b8;">' . $total_mitigations . '</div>
                    <div class="stat-label">Mitigations</div>
                </div>
            </div>
            
            <!-- Status Summary -->
            <div class="status-summary">
                <h3>Status Distribution</h3>
                <div class="status-grid">';
        
        foreach ($status_counts as $key => $count) {
            if ($count > 0) {
                $status_class = '';
                $status_label = ucwords(str_replace('_', ' ', $key));
                if ($key == 'pending_review') $status_class = 'status-pending';
                elseif ($key == 'open') $status_class = 'status-open';
                elseif ($key == 'in_progress') $status_class = 'status-progress';
                elseif ($key == 'mitigated') $status_class = 'status-mitigated';
                elseif ($key == 'closed') $status_class = 'status-closed';
                elseif ($key == 'rejected') $status_class = 'status-rejected';
                
                $html .= '<div class="status-item ' . $status_class . '">
                            <strong>' . $count . '</strong><br>
                            <small>' . $status_label . '</small>
                          </div>';
            }
        }
        
        $html .= '</div>
            </div>';
        
        // Risk Table
        if (count($risks) > 0) {
            $html .= '<table class="risk-table" cellspacing="0" cellpadding="5">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Dept</th>
                                <th>Category</th>
                                <th>L</th>
                                <th>I</th>
                                <th>Score</th>
                                <th>Level</th>
                                <th>Status</th>
                                <th>Owner</th>
                                <th>Mit.</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>';
            
            foreach ($risks as $r) {
                $score = (int)$r['likelihood'] * (int)$r['impact'];
                $risk_level = $r['risk_level'] ?? 'Low';
                
                $level_badge_class = 'badge-secondary';
                if ($risk_level == 'Critical') $level_badge_class = 'badge-danger';
                elseif ($risk_level == 'High') $level_badge_class = 'badge-warning';
                elseif ($risk_level == 'Medium') $level_badge_class = 'badge-secondary';
                elseif ($risk_level == 'Low') $level_badge_class = 'badge-success';
                
                $status_badge_class = 'badge-secondary';
                if ($r['status_key'] == 'pending_review') $status_badge_class = 'badge-warning';
                elseif ($r['status_key'] == 'open') $status_badge_class = 'badge-info';
                elseif ($r['status_key'] == 'in_progress') $status_badge_class = 'badge-primary';
                elseif ($r['status_key'] == 'mitigated') $status_badge_class = 'badge-success';
                elseif ($r['status_key'] == 'closed') $status_badge_class = 'badge-secondary';
                elseif ($r['status_key'] == 'rejected') $status_badge_class = 'badge-danger';
                
                $html .= '<tr>
                            <td><strong>#' . $r['id'] . '</strong></td>
                            <td>' . e($r['title']) . '</td>
                            <td>' . e($r['project_name'] ?: '-') . '</td>
                            <td>' . e($r['department_name'] ?: '-') . '</td>
                            <td>' . e($r['category_name'] ?: '-') . '</td>
                            <td style="text-align: center;">' . $r['likelihood'] . '</td>
                            <td style="text-align: center;">' . $r['impact'] . '</td>
                            <td style="text-align: center;"><strong>' . $score . '</strong></td>
                            <td><span class="badge ' . $level_badge_class . '">' . $risk_level . '</span></td>
                            <td><span class="badge ' . $status_badge_class . '">' . e($r['status_label'] ?: '-') . '</span></td>
                            <td>' . e($r['owner_name'] ?: '-') . '</td>
                            <td style="text-align: center;">' . $r['mitigation_count'] . '</td>
                            <td>' . date('M j, Y', strtotime($r['created_at'])) . '</td>
                          </tr>';
            }
            
            $html .= '</tbody>
                    </table>';
        } else {
            $html .= '<div style="text-align: center; padding: 50px; background: #f8f9fa; border-radius: 8px; margin-top: 20px;">
                        <h3 style="color: #6c757d;">No Risks Found</h3>
                        <p style="color: #6c757d;">No risks match the selected filter criteria.</p>
                      </div>';
        }
        
        // Footer
        $html .= '<div class="footer">
                    <p>This report is generated by Dashen Bank Risk Management System</p>
                    <p>Document ID: RISK-REP-' . date('Ymd') . '-' . rand(1000, 9999) . ' | Generated: ' . date('Y-m-d H:i:s') . '</p>
                    <p>Confidential - For Internal Use Only</p>
                  </div>
                  
                  </body>
                  </html>';
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $dompdf->stream('Dashen_Bank_Risk_Report_' . date('Y-m-d') . '.pdf', ['Attachment' => true]);
        exit;
    }
    
    // =========================================
    // EXCEL EXPORT - SRS Section 3.2.3
    // =========================================
    if ($export === 'excel') {
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Risk Report');
        
        // Company Header
        $sheet->mergeCells('A1:N1');
        $sheet->setCellValue('A1', 'DASHEN BANK - PROJECT RISK MANAGEMENT REPORT');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FF273274');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->mergeCells('A2:N2');
        $sheet->setCellValue('A2', 'Generated: ' . date('F j, Y \a\t g:i A') . ' | Generated by: ' . $username . ' (' . ucwords(str_replace('_', ' ', $user_role)) . ')');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A2')->getFont()->setItalic(true);
        
        $sheet->mergeCells('A3:N3');
        $sheet->setCellValue('A3', 'Report ID: RISK-REP-' . date('Ymd') . '-' . rand(1000, 9999));
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A3')->getFont()->setSize(10);
        
        // Empty row
        $sheet->mergeCells('A4:N4');
        $sheet->setCellValue('A4', '');
        
        // Statistics Header
        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'RISK STATISTICS SUMMARY');
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A5')->getFont()->getColor()->setARGB('FF273274');
        
        // Statistics Table
        $sheet->setCellValue('A6', 'Total Risks');
        $sheet->setCellValue('B6', $total_risks);
        $sheet->setCellValue('C6', 'Critical');
        $sheet->setCellValue('D6', $critical_risks);
        $sheet->setCellValue('E6', 'High');
        $sheet->setCellValue('F6', $high_risks);
        $sheet->setCellValue('G6', 'Medium');
        $sheet->setCellValue('H6', $medium_risks);
        $sheet->setCellValue('I6', 'Low');
        $sheet->setCellValue('J6', $low_risks);
        $sheet->setCellValue('K6', 'Total Mitigations');
        $sheet->setCellValue('L6', $total_mitigations);
        $sheet->setCellValue('M6', 'Total Comments');
        $sheet->setCellValue('N6', $total_comments);
        
        $sheet->getStyle('A6:N6')->getFont()->setBold(true);
        $sheet->getStyle('A6:N6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
        
        // Status Summary
        $row = 8;
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->setCellValue('A' . $row, 'STATUS DISTRIBUTION');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FF273274');
        
        $row++;
        $sheet->setCellValue('A' . $row, 'Status');
        $sheet->setCellValue('B' . $row, 'Count');
        $sheet->setCellValue('C' . $row, 'Percentage');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF273274');
        $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $status_row = $row + 1;
        foreach ($status_counts as $key => $count) {
            $percentage = $total_risks > 0 ? round(($count / $total_risks) * 100, 1) : 0;
            $sheet->setCellValue('A' . $status_row, ucwords(str_replace('_', ' ', $key)));
            $sheet->setCellValue('B' . $status_row, $count);
            $sheet->setCellValue('C' . $status_row, $percentage . '%');
            $status_row++;
        }
        
        // Risk Details Header
        $row = $status_row + 2;
        $sheet->mergeCells('A' . $row . ':N' . $row);
        $sheet->setCellValue('A' . $row, 'RISK DETAILS REPORT');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A' . $row)->getFont()->getColor()->setARGB('FF273274');
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Risk Table Headers
        $row += 2;
        $headers = ['ID', 'Title', 'Project', 'Department', 'Category', 'Likelihood', 'Impact', 'Score', 'Level', 'Status', 'Owner', 'Mitigations', 'Comments', 'Created Date'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF273274');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($col . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $col++;
        }
        
        // Risk Table Data
        $row++;
        foreach ($risks as $r) {
            $score = (int)$r['likelihood'] * (int)$r['impact'];
            $col = 'A';
            
            $sheet->setCellValue($col++ . $row, $r['id']);
            $sheet->setCellValue($col++ . $row, $r['title']);
            $sheet->setCellValue($col++ . $row, $r['project_name'] ?: '-');
            $sheet->setCellValue($col++ . $row, $r['department_name'] ?: '-');
            $sheet->setCellValue($col++ . $row, $r['category_name'] ?: '-');
            $sheet->setCellValue($col++ . $row, $r['likelihood']);
            $sheet->setCellValue($col++ . $row, $r['impact']);
            $sheet->setCellValue($col++ . $row, $score);
            $sheet->setCellValue($col++ . $row, $r['risk_level']);
            $sheet->setCellValue($col++ . $row, $r['status_label'] ?: '-');
            $sheet->setCellValue($col++ . $row, $r['owner_name'] ?: '-');
            $sheet->setCellValue($col++ . $row, $r['mitigation_count']);
            $sheet->setCellValue($col++ . $row, $r['comment_count']);
            $sheet->setCellValue($col++ . $row, date('Y-m-d', strtotime($r['created_at'])));
            
            // Color coding for risk levels
            $level_color = get_risk_level_color($r['risk_level']);
            $sheet->getStyle('A' . $row . ':N' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(substr($level_color . '20', 0, 9));
            
            $row++;
        }
        
        // Auto-size columns
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders to data table
        $data_start_row = $row - count($risks) - 1;
        $data_end_row = $row - 1;
        if ($data_start_row <= $data_end_row && $data_start_row > 0) {
            $sheet->getStyle('A' . $data_start_row . ':N' . $data_end_row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }
        
        // Footer
        $footer_row = $row + 2;
        $sheet->mergeCells('A' . $footer_row . ':N' . $footer_row);
        $sheet->setCellValue('A' . $footer_row, 'This report is generated by Dashen Bank Risk Management System - Confidential - For Internal Use Only');
        $sheet->getStyle('A' . $footer_row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A' . $footer_row)->getFont()->setItalic(true)->setSize(9);
        
        // Set headers for download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Dashen_Bank_Risk_Report_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risk Management Report - Dashen Bank</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
            --critical-color: #dc3545;
            --high-color: #fd7e14;
            --medium-color: #6c757d;
            --low-color: #28a745;
            --sidebar-width: 280px;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(145deg, #f8faff 0%, #f0f5fe 100%);
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            position: relative;
        }
        
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .bg-dashen-primary { 
            background: linear-gradient(135deg, var(--dashen-primary) 0%, #1e275a 100%);
        }
        
        .text-dashen-primary { 
            color: var(--dashen-primary) !important; 
        }
        
        .btn-dashen-primary {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, #1e5af5 100%);
            border: none;
            color: white;
            font-weight: 500;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-dashen-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(39, 50, 116, 0.3);
            color: white;
        }
        
        .btn-outline-dashen {
            border: 2px solid var(--dashen-primary);
            color: var(--dashen-primary);
            background: transparent;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-outline-dashen:hover {
            background: var(--dashen-primary);
            color: white;
            transform: translateY(-2px);
        }
        
        .premium-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.03);
            transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
            overflow: hidden;
        }
        
        .premium-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px rgba(39, 50, 116, 0.12);
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            border-left: 6px solid;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
        }
        
        .stat-total { border-left-color: var(--dashen-primary); }
        .stat-critical { border-left-color: var(--critical-color); }
        .stat-high { border-left-color: var(--high-color); }
        .stat-medium { border-left-color: var(--medium-color); }
        .stat-low { border-left-color: var(--low-color); }
        .stat-mitigations { border-left-color: #17a2b8; }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
        }
        
        .filter-header {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            border-radius: 16px 16px 0 0 !important;
            padding: 1.25rem;
        }
        
        .table-premium {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-premium thead th {
            background: linear-gradient(135deg, var(--dashen-primary), #1e275a);
            color: white;
            font-weight: 600;
            padding: 1rem;
            border: none;
            white-space: nowrap;
        }
        
        .table-premium tbody tr {
            background: white;
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }
        
        .table-premium tbody tr:hover {
            background: #f8f9ff;
        }
        
        .table-premium tbody td {
            padding: 1rem;
            vertical-align: middle;
            border: none;
        }
        
        .badge-premium {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .permission-badge {
            background: rgba(39, 50, 116, 0.1);
            color: var(--dashen-primary);
            padding: 0.5rem 1.25rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .export-btn {
            border-radius: 30px;
            padding: 0.6rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-section {
            animation: slideIn 0.5s ease forwards;
            opacity: 0;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        
        /* DataTables Custom Styling */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 1rem;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e9ecef;
            border-radius: 30px;
            padding: 0.5rem 1rem;
            margin-left: 0.5rem;
        }
        
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--dashen-primary);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(39, 50, 116, 0.1);
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 30px;
            margin: 0 2px;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--dashen-primary) !important;
            border-color: var(--dashen-primary) !important;
            color: white !important;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="navbar navbar-expand-lg bg-white shadow-sm px-4 py-3 sticky-top" 
             style="backdrop-filter: blur(10px); background: rgba(255,255,255,0.95) !important;">
            <div class="container-fluid p-0">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link d-lg-none me-3" type="button" onclick="toggleSidebar()">
                        <i class="bi bi-list fs-2" style="color: var(--dashen-primary);"></i>
                    </button>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-dashen-primary px-3 py-2 rounded-pill">
                                <i class="bi bi-file-earmark-bar-graph me-1"></i>Risk Reports
                            </span>
                            <span class="permission-badge">
                                <i class="bi bi-person-badge me-1"></i>
                                <?= ucwords(str_replace('_', ' ', $user_role)) ?>
                            </span>
                        </div>
                        <h4 class="mb-0 fw-bold" style="color: var(--dashen-primary);">
                            <i class="bi bi-bar-chart-steps me-2"></i>Risk Management Report
                        </h4>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <a href="risks.php" class="btn btn-outline-dashen rounded-pill px-4">
                        <i class="bi bi-arrow-left me-2"></i>Back to Risks
                    </a>
                    
                    <?php if ($can_export_reports): ?>
                    <div class="btn-group">
                        <button type="button" class="btn btn-dashen-primary rounded-pill px-4 dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-download me-2"></i>Export Report
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg rounded-4 p-2">
                            <li>
                                <a class="dropdown-item rounded-3 py-2" href="?<?= http_build_query($_GET) ?>&export=pdf">
                                    <i class="bi bi-file-earmark-pdf text-danger me-2"></i>
                                    Export as PDF
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item rounded-3 py-2" href="?<?= http_build_query($_GET) ?>&export=excel">
                                    <i class="bi bi-file-earmark-excel text-success me-2"></i>
                                    Export as Excel
                                </a>
                            </li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4 px-4">
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-2">
                    <div class="stat-card stat-total animate-section delay-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number"><?= $total_risks ?></span>
                                <div class="stat-label">Total Risks</div>
                            </div>
                            <div class="bg-dashen-primary bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-exclamation-triangle fs-3" style="color: var(--dashen-primary);"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card stat-critical animate-section delay-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number" style="color: var(--critical-color);"><?= $critical_risks ?></span>
                                <div class="stat-label">Critical</div>
                            </div>
                            <div class="bg-danger bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-exclamation-triangle-fill fs-3 text-danger"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card stat-high animate-section delay-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number" style="color: var(--high-color);"><?= $high_risks ?></span>
                                <div class="stat-label">High</div>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-exclamation-circle-fill fs-3 text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card stat-medium animate-section delay-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number" style="color: var(--medium-color);"><?= $medium_risks ?></span>
                                <div class="stat-label">Medium</div>
                            </div>
                            <div class="bg-secondary bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-info-circle-fill fs-3 text-secondary"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card stat-low animate-section delay-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number" style="color: var(--low-color);"><?= $low_risks ?></span>
                                <div class="stat-label">Low</div>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-check-circle-fill fs-3 text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card stat-mitigations animate-section delay-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="stat-number" style="color: #17a2b8;"><?= $total_mitigations ?></span>
                                <div class="stat-label">Mitigations</div>
                            </div>
                            <div class="bg-info bg-opacity-10 p-3 rounded-3">
                                <i class="bi bi-shield-check fs-3 text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="premium-card mb-4 animate-section delay-2">
                <div class="filter-header">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-funnel fs-4 me-3"></i>
                        <h5 class="mb-0 fw-semibold">Report Filters</h5>
                        <span class="badge bg-light text-dark ms-3 px-3 py-2 rounded-pill">
                            <?= count(array_filter($_GET, function($k) { return !in_array($k, ['export', 'page']); }, ARRAY_FILTER_USE_KEY)) ?> active filters
                        </span>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Project</label>
                            <select name="project_id" class="form-select select2">
                                <option value="">All Projects</option>
                                <?php foreach ($dropdowns['projects'] as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $filter_project === (int)$p['id'] ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Department</label>
                            <select name="department_id" class="form-select select2">
                                <option value="">All Departments</option>
                                <?php foreach ($dropdowns['departments'] as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= $filter_department === (int)$d['id'] ? 'selected' : '' ?>>
                                        <?= e($d['department_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category_id" class="form-select select2">
                                <option value="">All Categories</option>
                                <?php foreach ($dropdowns['categories'] as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $filter_category === (int)$c['id'] ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Risk Level</label>
                            <select name="risk_level" class="form-select">
                                <option value="">All Levels</option>
                                <option value="Critical" <?= $filter_level === 'Critical' ? 'selected' : '' ?>>Critical</option>
                                <option value="High" <?= $filter_level === 'High' ? 'selected' : '' ?>>High</option>
                                <option value="Medium" <?= $filter_level === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                <option value="Low" <?= $filter_level === 'Low' ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status_id" class="form-select select2">
                                <option value="">All Statuses</option>
                                <?php foreach ($dropdowns['statuses'] as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $filter_status === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= e($s['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Owner</label>
                            <select name="owner_user_id" class="form-select select2">
                                <option value="">All Owners</option>
                                <?php foreach ($dropdowns['users'] as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= $filter_owner === (int)$u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?= e($filter_date_from) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?= e($filter_date_to) ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-dashen-primary flex-grow-1 rounded-pill">
                                    <i class="bi bi-filter me-2"></i>Apply
                                </button>
                                <a href="risk_report.php" class="btn btn-outline-secondary rounded-pill">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                    
                    <!-- Active Filters Display -->
                    <?php
                    $active_filters = [];
                    if ($filter_project) {
                        $project_name = array_filter($dropdowns['projects'], fn($p) => $p['id'] == $filter_project);
                        $project_name = reset($project_name)['name'] ?? 'Unknown';
                        $active_filters[] = '<span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2 rounded-pill">
                                                <i class="bi bi-folder me-1"></i>Project: ' . e($project_name) . '
                                            </span>';
                    }
                    if ($filter_level) {
                        $active_filters[] = '<span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2 rounded-pill">
                                                <i class="bi bi-shield me-1"></i>Level: ' . e($filter_level) . '
                                            </span>';
                    }
                    if ($filter_status) {
                        $status_name = array_filter($dropdowns['statuses'], fn($s) => $s['id'] == $filter_status);
                        $status_name = reset($status_name)['label'] ?? 'Unknown';
                        $active_filters[] = '<span class="badge bg-dashen-primary bg-opacity-10 text-dashen-primary px-3 py-2 rounded-pill">
                                                <i class="bi bi-check-circle me-1"></i>Status: ' . e($status_name) . '
                                            </span>';
                    }
                    ?>
                    <?php if (!empty($active_filters)): ?>
                    <div class="mt-3 pt-3 border-top">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="text-muted small me-2">Active Filters:</span>
                            <?= implode(' ', $active_filters) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Distribution Chart -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="premium-card p-4 animate-section delay-3 h-100">
                        <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-pie-chart-fill me-2"></i>Risk Level Distribution
                        </h5>
                        <div class="chart-container">
                            <canvas id="riskLevelChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="premium-card p-4 animate-section delay-3 h-100">
                        <h5 class="fw-bold mb-4" style="color: var(--dashen-primary);">
                            <i class="bi bi-bar-chart-fill me-2"></i>Status Distribution
                        </h5>
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Risk Report Table -->
            <div class="premium-card animate-section delay-4">
                <div class="filter-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-table fs-4 me-3"></i>
                            <h5 class="mb-0 fw-semibold">Risk Report Details</h5>
                        </div>
                        <span class="badge bg-light text-dark px-4 py-2 rounded-pill">
                            <?= count($risks) ?> Risks Found
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-premium" id="riskReportTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Project</th>
                                    <th>Department</th>
                                    <th>Category</th>
                                    <th>L</th>
                                    <th>I</th>
                                    <th>Score</th>
                                    <th>Level</th>
                                    <th>Status</th>
                                    <th>Owner</th>
                                    <th>Mit.</th>
                                    <th>Comments</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($risks)): ?>
                                    <?php foreach ($risks as $r): 
                                        $score = (int)$r['likelihood'] * (int)$r['impact'];
                                        $risk_class = strtolower($r['risk_level'] ?? 'low');
                                    ?>
                                        <tr>
                                            <td><strong class="text-dashen-primary">#<?= (int)$r['id'] ?></strong></td>
                                            <td>
                                                <div class="fw-semibold"><?= e($r['title']) ?></div>
                                                <?php if ($r['response_strategy']): ?>
                                                    <small class="text-muted">Strategy: <?= e($r['response_strategy']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= e($r['project_name'] ?: '-') ?></td>
                                            <td><?= e($r['department_name'] ?: '-') ?></td>
                                            <td><?= e($r['category_name'] ?: '-') ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill px-3"><?= (int)$r['likelihood'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill px-3"><?= (int)$r['impact'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-dark rounded-pill px-3"><?= $score ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?= get_risk_level_class($r['risk_level']) ?> rounded-pill px-3">
                                                    <?= e($r['risk_level']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= get_status_class($r['status_key']) ?> rounded-pill px-3">
                                                    <?= e($r['status_label'] ?: '-') ?>
                                                </span>
                                            </td>
                                            <td><?= e($r['owner_name'] ?: '-') ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-info rounded-pill"><?= (int)$r['mitigation_count'] ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary rounded-pill"><?= (int)$r['comment_count'] ?></span>
                                            </td>
                                            <td>
                                                <small><?= date('M j, Y', strtotime($r['created_at'])) ?></small>
                                            </td>
                                            <td>
                                                <a href="risk_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-dashen rounded-pill">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="15" class="text-center py-5">
                                            <i class="bi bi-inbox display-1 text-muted mb-3"></i>
                                            <h5 class="text-muted">No Risks Found</h5>
                                            <p class="text-muted">No risks match the selected filter criteria.</p>
                                            <a href="risk_report.php" class="btn btn-dashen-primary rounded-pill px-5 mt-2">
                                                <i class="bi bi-arrow-clockwise me-2"></i>Reset Filters
                                            </a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
    
    <script>
        // =============================================
        // INITIALIZATION
        // =============================================
        $(document).ready(function() {
            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true
            });
            
            // =============================================
            // FIXED: DataTables Column Count Error
            // Now matches exactly 15 columns in the table
            // =============================================
            if ($.fn.DataTable.isDataTable('#riskReportTable')) {
                $('#riskReportTable').DataTable().destroy();
            }
            
            $('#riskReportTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[7, 'desc']], // Order by Score column (index 7)
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search risks...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ risks",
                    infoEmpty: "Showing 0 to 0 of 0 risks",
                    infoFiltered: "(filtered from _MAX_ total risks)",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [14] }, // Actions column (index 14) - not orderable
                    { responsivePriority: 1, targets: [0, 1, 8, 14] }, // ID, Title, Level, Actions
                    { responsivePriority: 2, targets: [2, 9, 10] }, // Project, Status, Owner
                    { responsivePriority: 3, targets: [3, 4, 13] } // Department, Category, Created
                ],
                drawCallback: function() {
                    $('.dataTables_paginate > .pagination').addClass('pagination-sm');
                }
            });
            
            // Risk Level Distribution Chart
            const riskCtx = document.getElementById('riskLevelChart').getContext('2d');
            new Chart(riskCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Critical', 'High', 'Medium', 'Low'],
                    datasets: [{
                        data: [<?= $critical_risks ?>, <?= $high_risks ?>, <?= $medium_risks ?>, <?= $low_risks ?>],
                        backgroundColor: [
                            '#dc3545',
                            '#fd7e14',
                            '#6c757d',
                            '#28a745'
                        ],
                        borderWidth: 0,
                        hoverOffset: 10
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
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
            
            // Status Distribution Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'bar',
                data: {
                    labels: ['Pending', 'Open', 'In Progress', 'Mitigated', 'Closed', 'Rejected'],
                    datasets: [{
                        label: 'Number of Risks',
                        data: [
                            <?= $status_counts['pending_review'] ?>,
                            <?= $status_counts['open'] ?>,
                            <?= $status_counts['in_progress'] ?>,
                            <?= $status_counts['mitigated'] ?>,
                            <?= $status_counts['closed'] ?>,
                            <?= $status_counts['rejected'] ?>
                        ],
                        backgroundColor: [
                            '#ffc107',
                            '#17a2b8',
                            '#007bff',
                            '#28a745',
                            '#6c757d',
                            '#dc3545'
                        ],
                        borderRadius: 6,
                        maxBarThickness: 50
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw || 0;
                                    const total = <?= $total_risks ?>;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${value} risks (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: true,
                                color: 'rgba(0,0,0,0.05)'
                            }
                        }
                    }
                }
            });
        });

        // =============================================
        // SIDEBAR TOGGLE
        // =============================================
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.toggle('expanded');
            mainContent.classList.toggle('expanded');
        }

        // =============================================
        // AUTO SUBMIT ON DATE SELECT
        // =============================================
        document.addEventListener('DOMContentLoaded', function() {
            const dateFrom = document.querySelector('input[name="date_from"]');
            const dateTo = document.querySelector('input[name="date_to"]');
            
            if (dateFrom && dateTo) {
                function submitForm() {
                    if (dateFrom.value && dateTo.value) {
                        dateFrom.form.submit();
                    }
                }
                
                dateFrom.addEventListener('change', submitForm);
                dateTo.addEventListener('change', submitForm);
            }
        });

        // =============================================
        // EXPORT CONFIRMATION
        // =============================================
        document.querySelectorAll('.dropdown-item[href*="export"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const format = this.href.includes('pdf') ? 'PDF' : 'Excel';
                
                Swal.fire({
                    title: 'Generate ' + format + ' Report',
                    text: 'This report will include all currently filtered data. Continue?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#273274',
                    confirmButtonText: 'Yes, generate',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = this.href;
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>