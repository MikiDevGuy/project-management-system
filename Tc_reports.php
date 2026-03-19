<?php
// reports.php - Complete Report Generator with Simple PDF Generation
session_start();
include 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username']);
$role = $_SESSION['system_role'] ?? 'viewer';

// Set active dashboard for sidebar
$active_dashboard = 'reports';

// ========== REPORT FILTERS ==========
$filters = [
    'project_id' => $_GET['project_id'] ?? '',
    'feature_id' => $_GET['feature_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'report_type' => $_GET['report_type'] ?? 'testcases'
];

// ========== GET DATA BASED ON FILTERS ==========
$report_data = getReportData($conn, $user_id, $role, $filters);
$projects = getProjectsForReports($conn, $user_id, $role);
$statistics = getReportStatistics($conn, $user_id, $role, $filters);

// ========== FUNCTIONS ==========

function getReportData($conn, $user_id, $role, $filters) {
    if ($filters['report_type'] === 'features') {
        $query = "
            SELECT 
                f.*,
                p.name as project_name,
                COUNT(tc.id) as testcase_count,
                SUM(CASE WHEN tc.status = 'Passed' THEN 1 ELSE 0 END) as passed_count,
                SUM(CASE WHEN tc.status = 'Failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN tc.status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                DATE(f.created_at) as created_date
            FROM features f
            JOIN projects p ON f.project_id = p.id
            LEFT JOIN test_cases tc ON f.id = tc.feature_id
        ";
    } else {
        // Get ALL test case columns
        $query = "
            SELECT 
                tc.*,
                p.name as project_name,
                p.description as project_description,
                f.feature_name,
                DATE(tc.created_at) as created_date,
                u.username as created_by_name
            FROM test_cases tc
            JOIN projects p ON tc.project_id = p.id
            LEFT JOIN features f ON tc.feature_id = f.id
            LEFT JOIN users u ON tc.created_by = u.id
        ";
    }
    
    // Add access control based on role
    if ($role !== 'super_admin') {
        $query .= " JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = $user_id";
    }
    
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($filters['project_id'])) {
        $where[] = "p.id = ?";
        $params[] = $filters['project_id'];
        $types .= 'i';
    }
    
    if (!empty($filters['feature_id']) && $filters['report_type'] === 'testcases') {
        $where[] = "tc.feature_id = ?";
        $params[] = $filters['feature_id'];
        $types .= 'i';
    }
    
    if (!empty($filters['status'])) {
        if ($filters['report_type'] === 'features') {
            $where[] = "f.status = ?";
        } else {
            $where[] = "tc.status = ?";
        }
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    if (!empty($filters['priority']) && $filters['report_type'] === 'testcases') {
        $where[] = "tc.priority = ?";
        $params[] = $filters['priority'];
        $types .= 's';
    }
    
    if (!empty($filters['date_from'])) {
        if ($filters['report_type'] === 'features') {
            $where[] = "DATE(f.created_at) >= ?";
        } else {
            $where[] = "DATE(tc.created_at) >= ?";
        }
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    
    if (!empty($filters['date_to'])) {
        if ($filters['report_type'] === 'features') {
            $where[] = "DATE(f.created_at) <= ?";
        } else {
            $where[] = "DATE(tc.created_at) <= ?";
        }
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    if ($filters['report_type'] === 'features') {
        $query .= " GROUP BY f.id";
    }
    
    $query .= " ORDER BY ";
    if ($filters['report_type'] === 'features') {
        $query .= "f.created_at DESC";
    } else {
        $query .= "tc.created_at DESC";
    }
    
    try {
        if ($params) {
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($query);
        }
        
        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Report data query error: " . $e->getMessage());
    }
    
    return [];
}

function getProjectsForReports($conn, $user_id, $role) {
    if ($role === 'super_admin') {
        $query = "SELECT id, name FROM projects ORDER BY name";
        $result = $conn->query($query);
    } else {
        $query = "SELECT p.id, p.name 
                 FROM projects p
                 JOIN user_assignments ua ON p.id = ua.project_id
                 WHERE ua.user_id = ? AND ua.is_active = 1
                 ORDER BY p.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $projects = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    }
    return $projects;
}

function getFeaturesForProject($conn, $project_id, $user_id, $role) {
    if ($role !== 'super_admin') {
        // Check if user has access to this project
        $check_query = "SELECT p.id FROM projects p
                       JOIN user_assignments ua ON p.id = ua.project_id
                       WHERE p.id = ? AND ua.user_id = ? AND ua.is_active = 1";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ii", $project_id, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            return [];
        }
    }
    
    $query = "SELECT id, feature_name FROM features WHERE project_id = ? ORDER BY feature_name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $features = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $features[] = $row;
        }
    }
    return $features;
}

function getReportStatistics($conn, $user_id, $role, $filters) {
    $stats = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'pending' => 0,
        'deferred' => 0
    ];
    
    if ($filters['report_type'] === 'features') {
        $data = getReportData($conn, $user_id, $role, $filters);
        $stats['total'] = count($data);
        return $stats;
    }
    
    // For test cases report
    $query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN tc.status = 'Passed' THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN tc.status = 'Failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN tc.status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN tc.status = 'Deferred' THEN 1 ELSE 0 END) as deferred
        FROM test_cases tc
        JOIN projects p ON tc.project_id = p.id
    ";
    
    if ($role !== 'super_admin') {
        $query .= " JOIN user_assignments ua ON p.id = ua.project_id AND ua.user_id = $user_id";
    }
    
    $where = [];
    $params = [];
    $types = '';
    
    if (!empty($filters['project_id'])) {
        $where[] = "p.id = ?";
        $params[] = $filters['project_id'];
        $types .= 'i';
    }
    
    if (!empty($filters['feature_id'])) {
        $where[] = "tc.feature_id = ?";
        $params[] = $filters['feature_id'];
        $types .= 'i';
    }
    
    if (!empty($filters['status'])) {
        $where[] = "tc.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    if (!empty($filters['priority'])) {
        $where[] = "tc.priority = ?";
        $params[] = $filters['priority'];
        $types .= 's';
    }
    
    if (!empty($filters['date_from'])) {
        $where[] = "DATE(tc.created_at) >= ?";
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    
    if (!empty($filters['date_to'])) {
        $where[] = "DATE(tc.created_at) <= ?";
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    
    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }
    
    try {
        if ($params) {
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($query);
        }
        
        if ($result && $row = $result->fetch_assoc()) {
            $stats['total'] = (int)($row['total'] ?? 0);
            $stats['passed'] = (int)($row['passed'] ?? 0);
            $stats['failed'] = (int)($row['failed'] ?? 0);
            $stats['pending'] = (int)($row['pending'] ?? 0);
            $stats['deferred'] = (int)($row['deferred'] ?? 0);
        }
    } catch (Exception $e) {
        error_log("Statistics query error: " . $e->getMessage());
    }
    
    return $stats;
}

// ========== SIMPLE PDF REPORT GENERATION ==========
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    generatePDFReport($conn, $user_id, $role);
    exit;
}

function generatePDFReport($conn, $user_id, $role) {
    $filters = [
        'project_id' => $_GET['project_id'] ?? '',
        'feature_id' => $_GET['feature_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'priority' => $_GET['priority'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'report_type' => $_GET['report_type'] ?? 'testcases'
    ];
    
    $data = getReportData($conn, $user_id, $role, $filters);
    $stats = getReportStatistics($conn, $user_id, $role, $filters);
    
    // Generate HTML for PDF (print-friendly)
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Dashen Bank Test Report</title>
        <style>
            @media print {
                @page { margin: 0.5in; size: landscape; }
                body { -webkit-print-color-adjust: exact; }
            }
            body { 
                font-family: Arial, sans-serif; 
                margin: 0; 
                padding: 20px;
                color: #333;
            }
            .print-only { display: block; }
            .no-print { display: none; }
            .header { 
                text-align: center; 
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid #273274;
            }
            .logo { 
                font-size: 28px; 
                font-weight: bold; 
                color: #273274;
                margin-bottom: 10px;
            }
            .report-title { 
                font-size: 20px; 
                color: #012169;
                margin: 10px 0;
            }
            .report-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .summary-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 10px;
                margin: 20px 0;
            }
            .stat-box {
                padding: 15px;
                text-align: center;
                border-radius: 5px;
                font-weight: bold;
                color: white;
            }
            .stat-total { background: #273274; }
            .stat-passed { background: #28a745; }
            .stat-failed { background: #dc3545; }
            .stat-pending { background: #ffc107; color: #000; }
            .stat-deferred { background: #6c757d; }
            .data-table {
                width: 100%;
                border-collapse: collapse;
                margin: 20px 0;
                font-size: 10px;
                page-break-inside: avoid;
            }
            .data-table th {
                background: #273274;
                color: white;
                padding: 8px;
                border: 1px solid #ddd;
                text-align: left;
                font-weight: bold;
            }
            .data-table td {
                padding: 6px;
                border: 1px solid #ddd;
                text-align: left;
                vertical-align: top;
            }
            .data-table tr:nth-child(even) {
                background: #f9f9f9;
            }
            .badge {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 10px;
                font-weight: bold;
                display: inline-block;
            }
            .badge-success { background: #d4edda; color: #155724; }
            .badge-danger { background: #f8d7da; color: #721c24; }
            .badge-warning { background: #fff3cd; color: #856404; }
            .badge-info { background: #d1ecf1; color: #0c5460; }
            .badge-primary { background: #cce5ff; color: #004085; }
            .footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
                color: #666;
                font-size: 11px;
                text-align: center;
            }
            .text-truncate {
                max-width: 120px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .page-break {
                page-break-before: always;
            }
            .print-instructions {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                display: none;
            }
        </style>
    </head>
    <body>
        <div class="print-instructions no-print">
            <h3>Print Instructions:</h3>
            <p>1. Click Print button or press Ctrl+P</p>
            <p>2. Set Orientation to <strong>Landscape</strong></p>
            <p>3. Set Margins to <strong>Minimum</strong></p>
            <p>4. Check "Background graphics"</p>
            <p>5. Click Print/Save as PDF</p>
        </div>
        
        <div class="header">
            <div class="logo">🏦 Dashen Bank</div>
            <div class="report-title">Test Management System - Comprehensive Report</div>
        </div>';
    
    // Report information
    $html .= '<div class="report-info">
                <p><strong>Generated on:</strong> ' . date('F j, Y H:i:s') . '</p>
                <p><strong>Generated by:</strong> ' . htmlspecialchars($_SESSION['username']) . ' (' . htmlspecialchars($_SESSION['system_role']) . ')</p>
                <p><strong>Report Type:</strong> ' . ($filters['report_type'] === 'features' ? 'Features Report' : 'Test Cases Report') . '</p>';
    
    // Show applied filters
    $html .= '<p><strong>Applied Filters:</strong> ';
    $filter_parts = [];
    if (!empty($filters['project_id'])) $filter_parts[] = 'Project ID: ' . $filters['project_id'];
    if (!empty($filters['feature_id'])) $filter_parts[] = 'Feature ID: ' . $filters['feature_id'];
    if (!empty($filters['status'])) $filter_parts[] = 'Status: ' . $filters['status'];
    if (!empty($filters['priority'])) $filter_parts[] = 'Priority: ' . $filters['priority'];
    if (!empty($filters['date_from'])) $filter_parts[] = 'From: ' . $filters['date_from'];
    if (!empty($filters['date_to'])) $filter_parts[] = 'To: ' . $filters['date_to'];
    if (empty($filter_parts)) {
        $html .= 'None';
    } else {
        $html .= implode(', ', $filter_parts);
    }
    $html .= '</p></div>';
    
    // Summary Statistics
    $html .= '<div class="summary-grid">
                <div class="stat-box stat-total">
                    <div style="font-size: 16px;">' . $stats['total'] . '</div>
                    <div style="font-size: 12px;">Total Items</div>
                </div>
                <div class="stat-box stat-passed">
                    <div style="font-size: 16px;">' . $stats['passed'] . '</div>
                    <div style="font-size: 12px;">Passed</div>
                </div>
                <div class="stat-box stat-failed">
                    <div style="font-size: 16px;">' . $stats['failed'] . '</div>
                    <div style="font-size: 12px;">Failed</div>
                </div>
                <div class="stat-box stat-pending">
                    <div style="font-size: 16px;">' . $stats['pending'] . '</div>
                    <div style="font-size: 12px;">Pending</div>
                </div>
                <div class="stat-box stat-deferred">
                    <div style="font-size: 16px;">' . $stats['deferred'] . '</div>
                    <div style="font-size: 12px;">Deferred</div>
                </div>
              </div>';
    
    // Detailed Data Table
    if (!empty($data)) {
        $html .= '<h3 style="color: #273274; margin: 20px 0 10px 0;">Detailed Report (' . count($data) . ' records)</h3>';
        
        if ($filters['report_type'] === 'features') {
            $html .= '<table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Feature Name</th>
                                <th>Project</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Test Cases</th>
                                <th>Passed</th>
                                <th>Failed</th>
                                <th>Pending</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>';
            
            foreach ($data as $row) {
                $status_class = 'badge-primary';
                if ($row['status'] === 'Completed') $status_class = 'badge-success';
                if ($row['status'] === 'Blocked') $status_class = 'badge-danger';
                if ($row['status'] === 'In Progress') $status_class = 'badge-warning';
                
                $html .= '<tr>
                            <td>' . ($row['id'] ?? '') . '</td>
                            <td class="text-truncate">' . htmlspecialchars($row['feature_name'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['project_name'] ?? '') . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['description'] ?? '', 0, 50)) . '</td>
                            <td><span class="badge ' . $status_class . '">' . htmlspecialchars($row['status'] ?? '') . '</span></td>
                            <td>' . ($row['testcase_count'] ?? 0) . '</td>
                            <td>' . ($row['passed_count'] ?? 0) . '</td>
                            <td>' . ($row['failed_count'] ?? 0) . '</td>
                            <td>' . ($row['pending_count'] ?? 0) . '</td>
                            <td>' . date('M d, Y', strtotime($row['created_date'] ?? date('Y-m-d'))) . '</td>
                          </tr>';
            }
            
            $html .= '</tbody></table>';
        } else {
            // Test Cases Report with ALL columns
            $html .= '<table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Project</th>
                                <th>Feature</th>
                                <th>Steps</th>
                                <th>Expected</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Frequency</th>
                                <th>Channel</th>
                                <th>Tester Remark</th>
                                <th>Vendor Comment</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                            </tr>
                        </thead>
                        <tbody>';
            
            foreach ($data as $row) {
                $status_class = 'badge-warning';
                if ($row['status'] === 'Passed') $status_class = 'badge-success';
                if ($row['status'] === 'Failed') $status_class = 'badge-danger';
                if ($row['status'] === 'Deferred') $status_class = 'badge-info';
                
                $priority_class = 'badge-warning';
                if ($row['priority'] === 'High') $priority_class = 'badge-danger';
                if ($row['priority'] === 'Low') $priority_class = 'badge-success';
                
                $html .= '<tr>
                            <td>' . ($row['id'] ?? '') . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['title'] ?? '', 0, 30)) . '</td>
                            <td>' . htmlspecialchars($row['project_name'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['feature_name'] ?? 'N/A') . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['steps'] ?? '', 0, 30)) . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['expected'] ?? '', 0, 30)) . '</td>
                            <td><span class="badge ' . $status_class . '">' . htmlspecialchars($row['status'] ?? '') . '</span></td>
                            <td><span class="badge ' . $priority_class . '">' . htmlspecialchars($row['priority'] ?? '') . '</span></td>
                            <td>' . htmlspecialchars($row['frequency'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['channel'] ?? '') . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['tester_remark'] ?? '', 0, 20)) . '</td>
                            <td class="text-truncate">' . htmlspecialchars(substr($row['vendor_comment'] ?? '', 0, 20)) . '</td>
                            <td>' . htmlspecialchars($row['created_by_name'] ?? '') . '</td>
                            <td>' . date('M d, Y', strtotime($row['created_date'] ?? date('Y-m-d'))) . '</td>
                          </tr>';
            }
            
            $html .= '</tbody></table>';
        }
    } else {
        $html .= '<p>No data found for the selected filters.</p>';
    }
    
    // Footer
    $html .= '<div class="footer">
                <p>© ' . date('Y') . ' Dashen Bank. All rights reserved.</p>
                <p>This report was automatically generated by the Test Management System.</p>
                <p style="font-size: 10px;">Report ID: DB' . date('Ymd') . rand(1000, 9999) . ' | Generated on: ' . date('Y-m-d H:i:s') . '</p>
              </div>
              
              <script>
              // Auto-print when page loads
              window.onload = function() {
                  setTimeout(function() {
                      window.print();
                  }, 1000);
              };
              
              // After print, show message
              window.onafterprint = function() {
                  document.body.innerHTML = \'<div style="text-align:center;padding:50px;"><h2>Report Generated Successfully!</h2><p>You can now close this window.</p></div>\';
              };
              </script>
            </body>
          </html>';
    
    // Set headers for HTML download (users can print to PDF)
    $filename = 'Dashen_Bank_Test_Report_' . date('Y-m-d_H-i-s') . '.html';
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $html;
    exit;
}

// ========== CSV REPORT GENERATION ==========
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    generateCSVReport($conn, $user_id, $role);
    exit;
}

function generateCSVReport($conn, $user_id, $role) {
    $filters = [
        'project_id' => $_GET['project_id'] ?? '',
        'feature_id' => $_GET['feature_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'priority' => $_GET['priority'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'report_type' => $_GET['report_type'] ?? 'testcases'
    ];
    
    $data = getReportData($conn, $user_id, $role, $filters);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Dashen_Bank_Test_Report_' . date('Y-m-d_H-i-s') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // CSV Header with Dashen Bank branding
    fputcsv($output, ['Dashen Bank - Test Management System Report']);
    fputcsv($output, ['Generated on:', date('F j, Y H:i:s')]);
    fputcsv($output, ['Generated by:', htmlspecialchars($_SESSION['username'])]);
    fputcsv($output, ['User Role:', htmlspecialchars($_SESSION['system_role'])]);
    fputcsv($output, ['Report Type:', $filters['report_type'] === 'features' ? 'Features Report' : 'Test Cases Report']);
    fputcsv($output, []); // Empty line
    
    if ($filters['report_type'] === 'features') {
        // Features CSV headers
        fputcsv($output, ['ID', 'Feature Name', 'Project', 'Description', 'Status', 'Test Cases', 'Passed', 'Failed', 'Pending', 'Created At', 'Updated At']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['id'] ?? '',
                $row['feature_name'] ?? '',
                $row['project_name'] ?? '',
                $row['description'] ?? '',
                $row['status'] ?? '',
                $row['testcase_count'] ?? 0,
                $row['passed_count'] ?? 0,
                $row['failed_count'] ?? 0,
                $row['pending_count'] ?? 0,
                $row['created_at'] ?? '',
                $row['updated_at'] ?? ''
            ]);
        }
    } else {
        // Test Cases CSV headers with ALL columns
        fputcsv($output, ['ID', 'Title', 'Project', 'Feature', 'Steps', 'Expected Result', 'Status', 'Priority', 
                         'Frequency', 'Channel', 'Tester Remark', 'Vendor Comment', 'Created By', 
                         'Created At', 'Updated At']);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['id'] ?? '',
                $row['title'] ?? '',
                $row['project_name'] ?? '',
                $row['feature_name'] ?? 'N/A',
                $row['steps'] ?? '',
                $row['expected'] ?? '',
                $row['status'] ?? '',
                $row['priority'] ?? '',
                $row['frequency'] ?? '',
                $row['channel'] ?? '',
                $row['tester_remark'] ?? '',
                $row['vendor_comment'] ?? '',
                $row['created_by_name'] ?? '',
                $row['created_at'] ?? '',
                $row['updated_at'] ?? ''
            ]);
        }
    }
    
    fclose($output);
    exit;
}

// ========== EXCEL REPORT GENERATION ==========
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    generateExcelReport($conn, $user_id, $role);
    exit;
}

function generateExcelReport($conn, $user_id, $role) {
    $filters = [
        'project_id' => $_GET['project_id'] ?? '',
        'feature_id' => $_GET['feature_id'] ?? '',
        'status' => $_GET['status'] ?? '',
        'priority' => $_GET['priority'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'report_type' => $_GET['report_type'] ?? 'testcases'
    ];
    
    $data = getReportData($conn, $user_id, $role, $filters);
    $stats = getReportStatistics($conn, $user_id, $role, $filters);
    
    // Create HTML table for Excel with Dashen Bank logo
    $html = '<!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <title>Dashen Bank Test Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { 
                background: #273274; 
                color: white; 
                padding: 20px; 
                text-align: center; 
                border-radius: 5px; 
                margin-bottom: 20px; 
            }
            .header h1 { margin: 0; font-size: 28px; }
            .header h2 { margin: 5px 0 0 0; font-size: 18px; color: #e0e0e0; }
            .report-info { 
                background: #f8f9fa; 
                padding: 15px; 
                border-radius: 5px; 
                margin: 20px 0; 
                border: 1px solid #ddd;
            }
            .summary-grid { 
                display: table; 
                width: 100%; 
                margin: 20px 0; 
                border-collapse: collapse; 
            }
            .summary-row { display: table-row; }
            .stat-cell { 
                display: table-cell; 
                padding: 15px; 
                text-align: center; 
                font-weight: bold; 
                border: 1px solid #ddd;
                color: white;
            }
            .stat-total { background: #273274; }
            .stat-passed { background: #28a745; }
            .stat-failed { background: #dc3545; }
            .stat-pending { background: #ffc107; color: #000; }
            .stat-deferred { background: #6c757d; }
            table.data-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 20px 0; 
            }
            table.data-table th { 
                background: #273274; 
                color: white; 
                padding: 10px; 
                border: 1px solid #ddd; 
                text-align: left; 
                font-weight: bold;
            }
            table.data-table td { 
                padding: 8px; 
                border: 1px solid #ddd; 
                text-align: left; 
            }
            table.data-table tr:nth-child(even) { 
                background: #f9f9f9; 
            }
            .footer { 
                margin-top: 30px; 
                padding-top: 15px; 
                border-top: 1px solid #ddd; 
                color: #666; 
                font-size: 12px; 
                text-align: center; 
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>🏦 Dashen Bank</h1>
            <h2>Test Management System - Comprehensive Report</h2>
        </div>';
    
    // Report information
    $html .= '<div class="report-info">
                <p><strong>Generated on:</strong> ' . date('F j, Y H:i:s') . '</p>
                <p><strong>Generated by:</strong> ' . htmlspecialchars($_SESSION['username']) . ' (' . htmlspecialchars($_SESSION['system_role']) . ')</p>
                <p><strong>Report Type:</strong> ' . ($filters['report_type'] === 'features' ? 'Features Report' : 'Test Cases Report') . '</p>';
    
    // Show applied filters
    $html .= '<p><strong>Applied Filters:</strong> ';
    $filter_parts = [];
    if (!empty($filters['project_id'])) $filter_parts[] = 'Project ID: ' . $filters['project_id'];
    if (!empty($filters['feature_id'])) $filter_parts[] = 'Feature ID: ' . $filters['feature_id'];
    if (!empty($filters['status'])) $filter_parts[] = 'Status: ' . $filters['status'];
    if (!empty($filters['priority'])) $filter_parts[] = 'Priority: ' . $filters['priority'];
    if (!empty($filters['date_from'])) $filter_parts[] = 'From: ' . $filters['date_from'];
    if (!empty($filters['date_to'])) $filter_parts[] = 'To: ' . $filters['date_to'];
    if (empty($filter_parts)) {
        $html .= 'None';
    } else {
        $html .= implode(', ', $filter_parts);
    }
    $html .= '</p></div>';
    
    // Summary Statistics
    $html .= '<div class="summary-grid">
                <div class="summary-row">
                    <div class="stat-cell stat-total">
                        <div style="font-size: 16px;">' . $stats['total'] . '</div>
                        <div style="font-size: 12px;">Total Items</div>
                    </div>
                    <div class="stat-cell stat-passed">
                        <div style="font-size: 16px;">' . $stats['passed'] . '</div>
                        <div style="font-size: 12px;">Passed</div>
                    </div>
                    <div class="stat-cell stat-failed">
                        <div style="font-size: 16px;">' . $stats['failed'] . '</div>
                        <div style="font-size: 12px;">Failed</div>
                    </div>
                    <div class="stat-cell stat-pending">
                        <div style="font-size: 16px;">' . $stats['pending'] . '</div>
                        <div style="font-size: 12px;">Pending</div>
                    </div>
                    <div class="stat-cell stat-deferred">
                        <div style="font-size: 16px;">' . $stats['deferred'] . '</div>
                        <div style="font-size: 12px;">Deferred</div>
                    </div>
                </div>
              </div>';
    
    // Detailed Data
    if (!empty($data)) {
        $html .= '<h3 style="color: #273274; margin: 20px 0 10px 0;">Detailed Report (' . count($data) . ' records)</h3>';
        
        if ($filters['report_type'] === 'features') {
            $html .= '<table class="data-table">
                        <tr>
                            <th>ID</th>
                            <th>Feature Name</th>
                            <th>Project</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Test Cases</th>
                            <th>Passed</th>
                            <th>Failed</th>
                            <th>Pending</th>
                            <th>Created At</th>
                        </tr>';
            
            foreach ($data as $row) {
                $html .= '<tr>
                            <td>' . ($row['id'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['feature_name'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['project_name'] ?? '') . '</td>
                            <td>' . htmlspecialchars(substr($row['description'] ?? '', 0, 100)) . (strlen($row['description'] ?? '') > 100 ? '...' : '') . '</td>
                            <td>' . ($row['status'] ?? '') . '</td>
                            <td>' . ($row['testcase_count'] ?? 0) . '</td>
                            <td style="color: #28a745;">' . ($row['passed_count'] ?? 0) . '</td>
                            <td style="color: #dc3545;">' . ($row['failed_count'] ?? 0) . '</td>
                            <td style="color: #ffc107;">' . ($row['pending_count'] ?? 0) . '</td>
                            <td>' . ($row['created_at'] ?? '') . '</td>
                          </tr>';
            }
            
            $html .= '</table>';
        } else {
            // Test Cases with ALL columns
            $html .= '<table class="data-table">
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Project</th>
                            <th>Feature</th>
                            <th>Steps</th>
                            <th>Expected</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Frequency</th>
                            <th>Channel</th>
                            <th>Tester Remark</th>
                            <th>Vendor Comment</th>
                            <th>Created By</th>
                            <th>Created At</th>
                        </tr>';
            
            foreach ($data as $row) {
                $html .= '<tr>
                            <td>' . ($row['id'] ?? '') . '</td>
                            <td>' . htmlspecialchars(substr($row['title'] ?? '', 0, 50)) . (strlen($row['title'] ?? '') > 50 ? '...' : '') . '</td>
                            <td>' . htmlspecialchars($row['project_name'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['feature_name'] ?? 'N/A') . '</td>
                            <td>' . htmlspecialchars(substr($row['steps'] ?? '', 0, 50)) . (strlen($row['steps'] ?? '') > 50 ? '...' : '') . '</td>
                            <td>' . htmlspecialchars(substr($row['expected'] ?? '', 0, 50)) . (strlen($row['expected'] ?? '') > 50 ? '...' : '') . '</td>
                            <td>' . ($row['status'] ?? '') . '</td>
                            <td>' . ($row['priority'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['frequency'] ?? '') . '</td>
                            <td>' . htmlspecialchars($row['channel'] ?? '') . '</td>
                            <td>' . htmlspecialchars(substr($row['tester_remark'] ?? '', 0, 30)) . (strlen($row['tester_remark'] ?? '') > 30 ? '...' : '') . '</td>
                            <td>' . htmlspecialchars(substr($row['vendor_comment'] ?? '', 0, 30)) . (strlen($row['vendor_comment'] ?? '') > 30 ? '...' : '') . '</td>
                            <td>' . htmlspecialchars($row['created_by_name'] ?? '') . '</td>
                            <td>' . ($row['created_at'] ?? '') . '</td>
                          </tr>';
            }
            
            $html .= '</table>';
        }
    } else {
        $html .= '<p>No data found for the selected filters.</p>';
    }
    
    $html .= '<div class="footer">
                <p>© ' . date('Y') . ' Dashen Bank. All rights reserved.</p>
                <p>This report was automatically generated by the Test Management System.</p>
              </div>';
    $html .= '</body></html>';
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Dashen_Bank_Test_Report_' . date('Y-m-d_H-i-s') . '.xls"');
    
    echo $html;
    exit;
}

// Get features for selected project
$features = [];
if (!empty($filters['project_id'])) {
    $features = getFeaturesForProject($conn, $filters['project_id'], $user_id, $role);
}

// AJAX request to get features
if (isset($_GET['get_features']) && !empty($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);
    $features_list = getFeaturesForProject($conn, $project_id, $user_id, $role);
    
    header('Content-Type: application/json');
    echo json_encode($features_list);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Generator - Test Management System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.bootstrap5.min.css">
    
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #012169;
            --dashen-accent: #e41e26;
            --dashen-gradient: linear-gradient(135deg, #273274 0%, #012169 100%);
            --sidebar-width: 280px;
        }
        
        body {
            background-color: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        /* Main Content Area */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            overflow-x: hidden;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
        }
        
        /* Hero Section with Dashen Logo */
        .hero-section {
            background: var(--dashen-gradient);
            color: white;
            border-radius: 12px;
            padding: 2rem 2.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(39, 50, 116, 0.15);
        }
        
        .dashen-logo {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        /* Scrollable Table Container */
        .table-responsive-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
            min-height: 400px;
        }
        
        .table-scroll {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 0;
        }
        
        .table-scroll thead th {
            background: var(--dashen-primary);
            color: white;
            padding: 12px 15px;
            border: none;
            font-weight: 600;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
            min-width: 120px;
        }
        
        .table-scroll tbody td {
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: top;
            min-width: 120px;
        }
        
        .table-scroll tbody tr:hover {
            background-color: rgba(39, 50, 116, 0.05);
        }
        
        .table-scroll tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        /* Fixed first column */
        .fixed-col {
            position: sticky;
            left: 0;
            background: white;
            z-index: 5;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        
        .fixed-col-header {
            position: sticky;
            left: 0;
            background: var(--dashen-secondary);
            z-index: 20;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        
        /* Badge Styles */
        .badge-status-passed { background: #d4edda; color: #155724; }
        .badge-status-failed { background: #f8d7da; color: #721c24; }
        .badge-status-pending { background: #fff3cd; color: #856404; }
        .badge-status-deferred { background: #e2e3e5; color: #383d41; }
        
        .badge-priority-high { background: #dc3545; color: white; }
        .badge-priority-medium { background: #ffc107; color: #000; }
        .badge-priority-low { background: #28a745; color: white; }
        
        /* Cell content */
        .cell-content {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: block;
        }
        
        .cell-content:hover {
            position: absolute;
            background: white;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 10px;
            border-radius: 4px;
            z-index: 1000;
            max-width: 300px;
            white-space: normal;
            word-wrap: break-word;
        }
        
        /* Export Controls */
        .export-controls {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        
        /* Scroll indicator */
        .scroll-hint {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--dashen-primary);
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
            display: none;
        }
        
        /* Filter Panel */
        .filter-panel {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }
        
        /* Statistics Cards */
        .stat-card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body>
    <!-- INCLUDE SIDEBAR -->
    <?php 
    $active_page = 'reports';
    include 'sidebar.php'; 
    ?>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h4 class="mt-3">Generating Report</h4>
            <p class="text-muted">Please wait while we prepare your report...</p>
        </div>
    </div>
    
    <!-- Scroll Hint -->
    <div class="scroll-hint" id="scrollHint">
        <i class="fas fa-arrows-left-right me-2"></i>
        Scroll horizontally to view all columns
    </div>
    
    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="dashen-logo">🏦 Dashen Bank</div>
                    <h1 class="display-6 fw-bold">Comprehensive Report Generator</h1>
                    <p class="lead mb-0">Generate detailed reports with ALL columns and export in multiple formats</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <span class="badge bg-light text-dark p-2">
                        <i class="fas fa-user me-1"></i> <?= ucfirst(str_replace('_', ' ', $role)) ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted text-uppercase small">Total Items</div>
                                <div class="h4 mb-0"><?= $statistics['total'] ?></div>
                            </div>
                            <div style="width: 50px; height: 50px; border-radius: 10px; background: rgba(39, 50, 116, 0.1); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--dashen-primary);">
                                <i class="fas fa-list-check"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted text-uppercase small">Passed</div>
                                <div class="h4 mb-0 text-success"><?= $statistics['passed'] ?></div>
                            </div>
                            <div style="width: 50px; height: 50px; border-radius: 10px; background: rgba(40, 167, 69, 0.1); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #28a745;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted text-uppercase small">Failed</div>
                                <div class="h4 mb-0 text-danger"><?= $statistics['failed'] ?></div>
                            </div>
                            <div style="width: 50px; height: 50px; border-radius: 10px; background: rgba(220, 53, 69, 0.1); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #dc3545;">
                                <i class="fas fa-times-circle"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-muted text-uppercase small">Pending</div>
                                <div class="h4 mb-0 text-warning"><?= $statistics['pending'] ?></div>
                            </div>
                            <div style="width: 50px; height: 50px; border-radius: 10px; background: rgba(255, 193, 7, 0.1); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #ffc107;">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Export Controls -->
        <div class="export-controls">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="mb-0">
                        <i class="fas fa-download me-2"></i>
                        Export Report
                    </h5>
                    <small class="text-muted">Export in your preferred format with Dashen Bank branding</small>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group">
                        <button type="button" class="btn btn-danger" onclick="exportReport('pdf')">
                            <i class="fas fa-file-pdf me-2"></i>PDF (Print-friendly)
                        </button>
                        <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                            <i class="fas fa-file-excel me-2"></i>Excel
                        </button>
                        <button type="button" class="btn btn-info" onclick="exportReport('csv')">
                            <i class="fas fa-file-csv me-2"></i>CSV
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Panel -->
        <div class="filter-panel">
            <form method="GET" action="" id="reportFilterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-select" onchange="this.form.submit()">
                                <option value="testcases" <?= $filters['report_type'] === 'testcases' ? 'selected' : '' ?>>Test Cases Report</option>
                                <option value="features" <?= $filters['report_type'] === 'features' ? 'selected' : '' ?>>Features Report</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Project</label>
                            <select name="project_id" class="form-select" onchange="this.form.submit()">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= $project['id'] ?>" <?= $filters['project_id'] == $project['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($project['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <?php if ($filters['report_type'] === 'features'): ?>
                                    <option value="Planned" <?= $filters['status'] === 'Planned' ? 'selected' : '' ?>>Planned</option>
                                    <option value="In Progress" <?= $filters['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="Completed" <?= $filters['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="Blocked" <?= $filters['status'] === 'Blocked' ? 'selected' : '' ?>>Blocked</option>
                                <?php else: ?>
                                    <option value="Pending" <?= $filters['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Passed" <?= $filters['status'] === 'Passed' ? 'selected' : '' ?>>Passed</option>
                                    <option value="Failed" <?= $filters['status'] === 'Failed' ? 'selected' : '' ?>>Failed</option>
                                    <option value="Deferred" <?= $filters['status'] === 'Deferred' ? 'selected' : '' ?>>Deferred</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <?php if ($filters['report_type'] === 'testcases'): ?>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select" onchange="this.form.submit()">
                                <option value="">All Priority</option>
                                <option value="High" <?= $filters['priority'] === 'High' ? 'selected' : '' ?>>High</option>
                                <option value="Medium" <?= $filters['priority'] === 'Medium' ? 'selected' : '' ?>>Medium</option>
                                <option value="Low" <?= $filters['priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Date From</label>
                            <input type="date" name="date_from" class="form-control" 
                                   value="<?= $filters['date_from'] ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Date To</label>
                            <input type="date" name="date_to" class="form-control" 
                                   value="<?= $filters['date_to'] ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                    
                    <div class="col-md-6 d-flex align-items-end justify-content-end">
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo me-2"></i>Reset
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Report Data -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    <?= $filters['report_type'] === 'features' ? 'Features Report' : 'Test Cases Report' ?>
                    <span class="badge bg-light text-dark ms-2"><?= count($report_data) ?> records</span>
                </h3>
            </div>
            
            <div class="card-body p-0">
                <?php if (!empty($report_data)): ?>
                    <!-- Scrollable Table -->
                    <div class="table-responsive-scroll" id="scrollableTable">
                        <table class="table-scroll">
                            <thead>
                                <?php if ($filters['report_type'] === 'features'): ?>
                                <tr>
                                    <th class="fixed-col-header">ID</th>
                                    <th>Feature Name</th>
                                    <th>Project</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Test Cases</th>
                                    <th>Passed</th>
                                    <th>Failed</th>
                                    <th>Pending</th>
                                    <th>Created</th>
                                    <th>Updated</th>
                                </tr>
                                <?php else: ?>
                                <tr>
                                    <th class="fixed-col-header">ID</th>
                                    <th>Title</th>
                                    <th>Project</th>
                                    <th>Feature</th>
                                    <th>Steps</th>
                                    <th>Expected</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Frequency</th>
                                    <th>Channel</th>
                                    <th>Tester Remark</th>
                                    <th>Vendor Comment</th>
                                    <th>Created By</th>
                                    <th>Created Date</th>
                                    <th>Updated Date</th>
                                </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <?php if ($filters['report_type'] === 'features'): ?>
                                    <tr>
                                        <td class="fixed-col fw-bold">#<?= $row['id'] ?></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['feature_name']) ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['project_name']) ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['description'] ?? '', 0, 50)) . (strlen($row['description'] ?? '') > 50 ? '...' : '') ?></span></td>
                                        <td><span class="badge"><?= $row['status'] ?></span></td>
                                        <td><?= $row['testcase_count'] ?? 0 ?></td>
                                        <td class="text-success"><?= $row['passed_count'] ?? 0 ?></td>
                                        <td class="text-danger"><?= $row['failed_count'] ?? 0 ?></td>
                                        <td class="text-warning"><?= $row['pending_count'] ?? 0 ?></td>
                                        <td><?= date('M d, Y', strtotime($row['created_date'])) ?></td>
                                        <td><?= !empty($row['updated_at']) ? date('M d, Y', strtotime($row['updated_at'])) : 'N/A' ?></td>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <td class="fixed-col fw-bold">#<?= $row['id'] ?></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['title'], 0, 40)) . (strlen($row['title']) > 40 ? '...' : '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['project_name']) ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['feature_name'] ?? 'N/A') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['steps'], 0, 30)) . (strlen($row['steps']) > 30 ? '...' : '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['expected'], 0, 30)) . (strlen($row['expected']) > 30 ? '...' : '') ?></span></td>
                                        <td><span class="badge badge-status-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span></td>
                                        <td><span class="badge badge-priority-<?= strtolower($row['priority']) ?>"><?= $row['priority'] ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['frequency'] ?? '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['channel'] ?? '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['tester_remark'] ?? '', 0, 20)) . (strlen($row['tester_remark'] ?? '') > 20 ? '...' : '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars(substr($row['vendor_comment'] ?? '', 0, 20)) . (strlen($row['vendor_comment'] ?? '') > 20 ? '...' : '') ?></span></td>
                                        <td><span class="cell-content"><?= htmlspecialchars($row['created_by_name'] ?? '') ?></span></td>
                                        <td><?= date('M d, Y', strtotime($row['created_date'])) ?></td>
                                        <td><?= !empty($row['updated_at']) ? date('M d, Y', strtotime($row['updated_at'])) : 'N/A' ?></td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Table Info -->
                    <div class="p-3 border-top">
                        <div class="row">
                            <div class="col-md-8">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle text-primary me-1"></i>
                                    Showing <?= count($report_data) ?> records. Hover over cells to see full content. Table is scrollable horizontally.
                                </small>
                            </div>
                            <div class="col-md-4 text-end">
                                <small class="text-muted">
                                    <i class="fas fa-columns text-primary me-1"></i>
                                    <?= $filters['report_type'] === 'features' ? '11' : '15' ?> columns
                                </small>
                            </div>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h4 class="text-muted">No Data Found</h4>
                        <p class="text-muted">Try adjusting your filters to see results.</p>
                        <button type="button" class="btn btn-primary" onclick="resetFilters()">
                            <i class="fas fa-redo me-2"></i>Reset Filters
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Show scroll hint if table is scrollable
        const tableContainer = document.getElementById('scrollableTable');
        if (tableContainer && tableContainer.scrollWidth > tableContainer.clientWidth) {
            $('#scrollHint').fadeIn();
            setTimeout(() => {
                $('#scrollHint').fadeOut();
            }, 5000);
        }
        
        // Update scroll hint on resize
        window.addEventListener('resize', function() {
            if (tableContainer && tableContainer.scrollWidth > tableContainer.clientWidth) {
                $('#scrollHint').fadeIn();
                setTimeout(() => {
                    $('#scrollHint').fadeOut();
                }, 3000);
            }
        });
        
        // Cell hover effect
        $('.cell-content').hover(
            function() {
                if (this.scrollWidth > this.clientWidth) {
                    $(this).addClass('hover');
                }
            },
            function() {
                $(this).removeClass('hover');
            }
        );
    });
    
    function exportReport(format) {
        // Show loading overlay
        $('#loadingOverlay').show();
        
        // Get current URL parameters
        let params = new URLSearchParams(window.location.search);
        params.set('export', format);
        
        // Open in new tab for download
        window.open(window.location.pathname + '?' + params.toString(), '_blank');
        
        // Hide loading overlay after delay
        setTimeout(() => {
            $('#loadingOverlay').hide();
        }, 2000);
    }
    
    function resetFilters() {
        window.location.href = window.location.pathname;
    }
    
    // Handle sidebar collapse
    $(document).ready(function() {
        const sidebarContainer = document.getElementById('sidebarContainer');
        const mainContent = document.getElementById('mainContent');
        
        if (sidebarContainer && mainContent) {
            if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                mainContent.classList.add('sidebar-collapsed');
            }
            
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        if (sidebarContainer.classList.contains('sidebar-collapsed')) {
                            mainContent.classList.add('sidebar-collapsed');
                        } else {
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
    });
    </script>
</body>
</html>