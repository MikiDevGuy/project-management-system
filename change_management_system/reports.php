<?php
// reports.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];
$username = $_SESSION['username'];

// Enhanced report data queries
$queries = [
    'status_data' => "SELECT status, COUNT(*) as count, 
                      ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM change_requests), 2) as percentage
                      FROM change_requests GROUP BY status",
    
    'priority_data' => "SELECT priority, COUNT(*) as count FROM change_requests GROUP BY priority",
    
    'project_data' => "SELECT p.name as project_name, COUNT(cr.change_request_id) as request_count,
                      ROUND(COUNT(cr.change_request_id) * 100.0 / (SELECT COUNT(*) FROM change_requests), 2) as percentage
                      FROM projects p LEFT JOIN change_requests cr ON p.id = cr.project_id
                      GROUP BY p.id, p.name ORDER BY request_count DESC",
    
    'monthly_data' => "SELECT DATE_FORMAT(request_date, '%Y-%m') as month, COUNT(*) as count,
                       SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_count,
                       SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected_count
                       FROM change_requests 
                       WHERE request_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                       GROUP BY DATE_FORMAT(request_date, '%Y-%m') ORDER BY month DESC LIMIT 6",
    
    'user_performance' => "SELECT u.username, COUNT(cr.change_request_id) as total_requests,
                          SUM(CASE WHEN cr.status = 'Approved' THEN 1 ELSE 0 END) as approved_requests,
                          SUM(CASE WHEN cr.status = 'Rejected' THEN 1 ELSE 0 END) as rejected_requests,
                          ROUND(SUM(CASE WHEN cr.status = 'Approved' THEN 1 ELSE 0 END) * 100.0 / COUNT(cr.change_request_id), 2) as approval_rate
                          FROM users u LEFT JOIN change_requests cr ON u.id = cr.requester_id
                          WHERE u.system_role NOT IN ('admin', 'super_admin')
                          GROUP BY u.id, u.username HAVING total_requests > 0 ORDER BY total_requests DESC",
    
    'completion_time' => "SELECT AVG(DATEDIFF(last_updated, request_date)) as avg_completion_days,
                         MAX(DATEDIFF(last_updated, request_date)) as max_completion_days,
                         MIN(DATEDIFF(last_updated, request_date)) as min_completion_days
                         FROM change_requests WHERE status IN ('Approved', 'Rejected', 'Implemented')",
    
    'escalation_data' => "SELECT escalation_required, COUNT(*) as count,
                         ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM change_requests), 2) as percentage
                         FROM change_requests GROUP BY escalation_required",
    
    'impact_analysis' => "SELECT area_of_impact, COUNT(*) as count,
                         ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM change_requests), 2) as percentage
                         FROM change_requests WHERE area_of_impact IS NOT NULL GROUP BY area_of_impact"
];

$report_data = [];
foreach ($queries as $key => $query) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data[$key] = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $report_data[$key] = [];
    }
}

// Handle export request
if (isset($_POST['export_reports'])) {
    $export_type = $_POST['export_type'] ?? 'pdf';
    $export_format = $_POST['export_format'] ?? 'summary';
    
    // Prepare export data
    $export_data = [
        'report_data' => $report_data,
        'generated_by' => $username,
        'generated_at' => date('Y-m-d H:i:s'),
        'company_name' => 'Dashen Bank',
        'report_title' => 'Change Management System Report'
    ];
    
    // Call export function based on type
    if ($export_type === 'pdf') {
        exportToPDF($export_data, $export_format);
    } elseif ($export_type === 'excel') {
        exportToExcel($export_data, $export_format);
    } elseif ($export_type === 'csv') {
        exportToCSV($export_data, $export_format);
    }
    exit();
}

// Enhanced Export Functions
function exportToPDF($data, $format) {
    // Create HTML content for PDF
    $html = generatePDFContent($data, $format);
    
    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="dashen_change_management_report_' . date('Y-m-d_H-i-s') . '.pdf"');
    
    // Use output buffering to capture HTML
    ob_start();
    echo $html;
    $content = ob_get_clean();
    
    // For production, you would use a library like TCPDF, Dompdf, or mpdf
    // This is a simplified version that creates a downloadable HTML file styled as PDF
    echo $content;
    exit();
}

function generatePDFContent($data, $format) {
    $company_logo = '../Images/DashenLogo1.png';
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Dashen Bank - Change Management Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
            .header { border-bottom: 3px solid #273274; padding-bottom: 20px; margin-bottom: 30px; }
            .logo { max-width: 150px; margin-bottom: 10px; }
            .company-name { color: #273274; font-size: 24px; font-weight: bold; margin-bottom: 5px; }
            .report-title { color: #f58220; font-size: 20px; margin-bottom: 10px; }
            .report-meta { color: #666; font-size: 14px; margin-bottom: 20px; }
            .section { margin-bottom: 30px; }
            .section-title { background: #273274; color: white; padding: 10px; font-weight: bold; margin-bottom: 15px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th { background: #f8f9fa; color: #273274; padding: 12px; text-align: left; border: 1px solid #ddd; }
            td { padding: 10px; border: 1px solid #ddd; }
            .summary-box { background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-left: 4px solid #273274; }
            .metric { display: inline-block; margin-right: 30px; text-align: center; }
            .metric-value { font-size: 24px; font-weight: bold; color: #273274; }
            .metric-label { font-size: 12px; color: #666; }
            .footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="company-name">DASHEN BANK</div>
            <div class="report-title">Change Management System Report</div>
            <div class="report-meta">
                Generated by: ' . $data['generated_by'] . '<br>
                Generated on: ' . date('F j, Y g:i A', strtotime($data['generated_at'])) . '<br>
                Report Type: ' . ucfirst($format) . ' Report
            </div>
        </div>';
    
    if ($format === 'summary' || $format === 'detailed') {
        // Executive Summary
        $total_requests = array_sum(array_column($data['report_data']['status_data'], 'count'));
        $approved_requests = 0;
        foreach ($data['report_data']['status_data'] as $status) {
            if ($status['status'] === 'Approved') {
                $approved_requests = $status['count'];
                break;
            }
        }
        
        $html .= '
        <div class="section">
            <div class="section-title">Executive Summary</div>
            <div class="summary-box">
                <div class="metric">
                    <div class="metric-value">' . $total_requests . '</div>
                    <div class="metric-label">Total Requests</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . $approved_requests . '</div>
                    <div class="metric-label">Approved Requests</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . ($data['report_data']['completion_time'][0]['avg_completion_days'] ?? 'N/A') . '</div>
                    <div class="metric-label">Avg. Completion Days</div>
                </div>
            </div>
        </div>';
        
        // Status Distribution
        $html .= '
        <div class="section">
            <div class="section-title">Request Status Distribution</div>
            <table>
                <tr>
                    <th>Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>';
        foreach ($data['report_data']['status_data'] as $row) {
            $html .= '
                <tr>
                    <td>' . htmlspecialchars($row['status']) . '</td>
                    <td>' . $row['count'] . '</td>
                    <td>' . $row['percentage'] . '%</td>
                </tr>';
        }
        $html .= '
            </table>
        </div>';
    }
    
    if ($format === 'detailed') {
        // Priority Distribution
        $html .= '
        <div class="section">
            <div class="section-title">Priority Distribution</div>
            <table>
                <tr>
                    <th>Priority</th>
                    <th>Count</th>
                </tr>';
        foreach ($data['report_data']['priority_data'] as $row) {
            $html .= '
                <tr>
                    <td>' . htmlspecialchars($row['priority']) . '</td>
                    <td>' . $row['count'] . '</td>
                </tr>';
        }
        $html .= '
            </table>
        </div>';
        
        // Project Distribution
        $html .= '
        <div class="section">
            <div class="section-title">Project Distribution</div>
            <table>
                <tr>
                    <th>Project</th>
                    <th>Request Count</th>
                    <th>Percentage</th>
                </tr>';
        foreach ($data['report_data']['project_data'] as $row) {
            $html .= '
                <tr>
                    <td>' . htmlspecialchars($row['project_name']) . '</td>
                    <td>' . $row['request_count'] . '</td>
                    <td>' . $row['percentage'] . '%</td>
                </tr>';
        }
        $html .= '
            </table>
        </div>';
        
        // User Performance
        if (!empty($data['report_data']['user_performance'])) {
            $html .= '
            <div class="section">
                <div class="section-title">User Performance Metrics</div>
                <table>
                    <tr>
                        <th>User</th>
                        <th>Total Requests</th>
                        <th>Approved</th>
                        <th>Rejected</th>
                        <th>Approval Rate</th>
                    </tr>';
            foreach ($data['report_data']['user_performance'] as $row) {
                $html .= '
                    <tr>
                        <td>' . htmlspecialchars($row['username']) . '</td>
                        <td>' . $row['total_requests'] . '</td>
                        <td>' . $row['approved_requests'] . '</td>
                        <td>' . $row['rejected_requests'] . '</td>
                        <td>' . $row['approval_rate'] . '%</td>
                    </tr>';
            }
            $html .= '
                </table>
            </div>';
        }
        
        // Monthly Trends
        $html .= '
        <div class="section">
            <div class="section-title">Monthly Trends (Last 6 Months)</div>
            <table>
                <tr>
                    <th>Month</th>
                    <th>Total Requests</th>
                    <th>Approved</th>
                    <th>Rejected</th>
                </tr>';
        foreach ($data['report_data']['monthly_data'] as $row) {
            $date = DateTime::createFromFormat('Y-m', $row['month']);
            $html .= '
                <tr>
                    <td>' . $date->format('F Y') . '</td>
                    <td>' . $row['count'] . '</td>
                    <td>' . $row['approved_count'] . '</td>
                    <td>' . $row['rejected_count'] . '</td>
                </tr>';
        }
        $html .= '
            </table>
        </div>';
    }
    
    $html .= '
        <div class="footer">
            Dashen Bank Change Management System - Confidential Report<br>
            Page generated on ' . date('F j, Y \a\t g:i A') . '
        </div>
    </body>
    </html>';
    
    return $html;
}

function exportToExcel($data, $format) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="dashen_change_management_report_' . date('Y-m-d_H-i-s') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='4' style='background:#273274;color:white;padding:15px;font-size:16px;'>Dashen Bank - Change Management Report</th></tr>";
    echo "<tr><td colspan='4'><strong>Generated by:</strong> " . $data['generated_by'] . " | <strong>Date:</strong> " . $data['generated_at'] . "</td></tr>";
    echo "<tr><td colspan='4'></td></tr>";
    
    if ($format === 'summary' || $format === 'detailed') {
        echo "<tr><th colspan='4' style='background:#f58220;color:white;'>Status Distribution</th></tr>";
        echo "<tr><th>Status</th><th>Count</th><th>Percentage</th></tr>";
        foreach ($data['report_data']['status_data'] as $row) {
            echo "<tr><td>{$row['status']}</td><td>{$row['count']}</td><td>{$row['percentage']}%</td></tr>";
        }
        echo "<tr><td colspan='4'></td></tr>";
    }
    
    if ($format === 'detailed') {
        echo "<tr><th colspan='2' style='background:#f58220;color:white;'>Priority Distribution</th></tr>";
        echo "<tr><th>Priority</th><th>Count</th></tr>";
        foreach ($data['report_data']['priority_data'] as $row) {
            echo "<tr><td>{$row['priority']}</td><td>{$row['count']}</td></tr>";
        }
        echo "<tr><td colspan='4'></td></tr>";
        
        echo "<tr><th colspan='3' style='background:#f58220;color:white;'>Project Distribution</th></tr>";
        echo "<tr><th>Project</th><th>Request Count</th><th>Percentage</th></tr>";
        foreach ($data['report_data']['project_data'] as $row) {
            echo "<tr><td>{$row['project_name']}</td><td>{$row['request_count']}</td><td>{$row['percentage']}%</td></tr>";
        }
        echo "<tr><td colspan='4'></td></tr>";
        
        if (!empty($data['report_data']['user_performance'])) {
            echo "<tr><th colspan='5' style='background:#f58220;color:white;'>User Performance</th></tr>";
            echo "<tr><th>User</th><th>Total Requests</th><th>Approved</th><th>Rejected</th><th>Approval Rate</th></tr>";
            foreach ($data['report_data']['user_performance'] as $row) {
                echo "<tr><td>{$row['username']}</td><td>{$row['total_requests']}</td><td>{$row['approved_requests']}</td><td>{$row['rejected_requests']}</td><td>{$row['approval_rate']}%</td></tr>";
            }
            echo "<tr><td colspan='4'></td></tr>";
        }
        
        echo "<tr><th colspan='4' style='background:#f58220;color:white;'>Monthly Trends</th></tr>";
        echo "<tr><th>Month</th><th>Total Requests</th><th>Approved</th><th>Rejected</th></tr>";
        foreach ($data['report_data']['monthly_data'] as $row) {
            $date = DateTime::createFromFormat('Y-m', $row['month']);
            echo "<tr><td>" . $date->format('F Y') . "</td><td>{$row['count']}</td><td>{$row['approved_count']}</td><td>{$row['rejected_count']}</td></tr>";
        }
    }
    echo "</table>";
    exit();
}

function exportToCSV($data, $format) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="dashen_change_management_report_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, ['Dashen Bank - Change Management Report']);
    fputcsv($output, ['Generated by:', $data['generated_by']]);
    fputcsv($output, ['Generated at:', $data['generated_at']]);
    fputcsv($output, []); // Empty row
    
    if ($format === 'summary' || $format === 'detailed') {
        fputcsv($output, ['Status Distribution']);
        fputcsv($output, ['Status', 'Count', 'Percentage']);
        foreach ($data['report_data']['status_data'] as $row) {
            fputcsv($output, [$row['status'], $row['count'], $row['percentage'] . '%']);
        }
        fputcsv($output, []); // Empty row
    }
    
    if ($format === 'detailed') {
        fputcsv($output, ['Priority Distribution']);
        fputcsv($output, ['Priority', 'Count']);
        foreach ($data['report_data']['priority_data'] as $row) {
            fputcsv($output, [$row['priority'], $row['count']]);
        }
        fputcsv($output, []); // Empty row
        
        fputcsv($output, ['Project Distribution']);
        fputcsv($output, ['Project', 'Request Count', 'Percentage']);
        foreach ($data['report_data']['project_data'] as $row) {
            fputcsv($output, [$row['project_name'], $row['request_count'], $row['percentage'] . '%']);
        }
        fputcsv($output, []); // Empty row
        
        if (!empty($data['report_data']['user_performance'])) {
            fputcsv($output, ['User Performance']);
            fputcsv($output, ['User', 'Total Requests', 'Approved', 'Rejected', 'Approval Rate']);
            foreach ($data['report_data']['user_performance'] as $row) {
                fputcsv($output, [$row['username'], $row['total_requests'], $row['approved_requests'], $row['rejected_requests'], $row['approval_rate'] . '%']);
            }
            fputcsv($output, []); // Empty row
        }
        
        fputcsv($output, ['Monthly Trends']);
        fputcsv($output, ['Month', 'Total Requests', 'Approved', 'Rejected']);
        foreach ($data['report_data']['monthly_data'] as $row) {
            $date = DateTime::createFromFormat('Y-m', $row['month']);
            fputcsv($output, [$date->format('F Y'), $row['count'], $row['approved_count'], $row['rejected_count']]);
        }
    }
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Dashen Bank Change Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../Images/DashenLogo1.png">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e2559;
            --dashen-accent: #f58220;
            --dashen-light: #f5f7fb;
            --dashen-success: #2ecc71;
            --dashen-warning: #f39c12;
            --dashen-danger: #e74c3c;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --border-color: #ecf0f1;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --transition-speed: 0.3s;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4e8f0 100%);
            min-height: 100vh;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
            min-height: 100vh;
            transition: all var(--transition-speed);
        }
        
        .sidebar.collapsed ~ .main-content {
            margin-left: var(--sidebar-collapsed-width);
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 10px 20px rgba(39, 50, 116, 0.15);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: url('../Images/DashenLogo1.png') no-repeat center center;
            background-size: contain;
            opacity: 0.1;
            pointer-events: none;
        }
        
        .page-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: white;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        
        .welcome-text {
            color: rgba(255, 255, 255, 0.85);
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        /* Cards */
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }
        
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem 1.5rem;
        }
        
        .card-title {
            font-weight: 600;
            color: var(--dashen-primary);
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .card-title i {
            margin-right: 0.5rem;
            color: var(--dashen-accent);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
            padding: 1rem;
        }
        
        /* Table Styles */
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--text-light);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: var(--dashen-light);
        }
        
        .table td {
            vertical-align: middle;
            padding: 1rem 0.75rem;
            border-color: var(--border-color);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            border: none;
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.3);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(39, 50, 116, 0.4);
            background: linear-gradient(135deg, var(--dashen-secondary) 0%, var(--dashen-primary) 100%);
        }
        
        .badge.bg-accent {
            background: var(--dashen-accent) !important;
        }
        
        /* Export Button Styles */
        .export-btn {
            background: var(--dashen-success);
            border-color: var(--dashen-success);
            color: white;
        }
        
        .export-btn:hover {
            background: #27ae60;
            border-color: #27ae60;
            color: white;
        }
        
        .export-dropdown {
            min-width: 200px;
        }
        
        .export-section {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .export-options {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .export-option-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .export-option-group label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        
        /* KPI Cards */
        .kpi-card {
            text-align: center;
            padding: 1.5rem;
            border-radius: 1rem;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .kpi-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .kpi-label {
            color: var(--text-light);
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        /* Animation Classes */
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
            animation: fadeInUp 0.6s ease-out;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .export-options {
                flex-direction: column;
                align-items: stretch;
            }
            
            .export-option-group {
                width: 100%;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            background: var(--dashen-primary);
            border: none;
            color: white;
            padding: 0.5rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php 
    $_SESSION['current_page'] = 'reports.php';
    include 'sidebar.php'; 
    ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Mobile Menu Button -->
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>
        
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-chart-bar me-2"></i>Analytics & Reports Dashboard
                </h1>
                <p class="welcome-text">Comprehensive insights and performance metrics</p>
            </div>
        </div>
        
        <!-- KPI Section -->
        <div class="row mb-4">
            <?php
            $total_requests = array_sum(array_column($report_data['status_data'], 'count'));
            $approved_requests = 0;
            foreach ($report_data['status_data'] as $status) {
                if ($status['status'] === 'Approved') {
                    $approved_requests = $status['count'];
                    break;
                }
            }
            $approval_rate = $total_requests > 0 ? round(($approved_requests / $total_requests) * 100, 2) : 0;
            $avg_completion = $report_data['completion_time'][0]['avg_completion_days'] ?? 0;
            ?>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="kpi-card fade-in-up">
                    <div class="kpi-value"><?php echo $total_requests; ?></div>
                    <div class="kpi-label">Total Change Requests</div>
                    <small class="text-muted">All time</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="kpi-card fade-in-up delay-1">
                    <div class="kpi-value"><?php echo $approved_requests; ?></div>
                    <div class="kpi-label">Approved Requests</div>
                    <small class="text-muted">Successful changes</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="kpi-card fade-in-up delay-2">
                    <div class="kpi-value"><?php echo $approval_rate; ?>%</div>
                    <div class="kpi-label">Approval Rate</div>
                    <small class="text-muted">Success ratio</small>
                </div>
            </div>
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="kpi-card fade-in-up delay-3">
                    <div class="kpi-value"><?php echo round($avg_completion, 1); ?></div>
                    <div class="kpi-label">Avg. Completion Days</div>
                    <small class="text-muted">Processing time</small>
                </div>
            </div>
        </div>
        
        <!-- Export Section -->
        <div class="export-section fade-in-up">
            <h5 class="mb-3"><i class="fas fa-download me-2"></i>Export Reports</h5>
            <form method="POST" class="export-options">
                <div class="export-option-group">
                    <label for="export_type">Export Format:</label>
                    <select class="form-select" id="export_type" name="export_type" required>
                        <option value="pdf">PDF Document</option>
                        <option value="excel">Excel Spreadsheet</option>
                        <option value="csv">CSV File</option>
                    </select>
                </div>
                
                <div class="export-option-group">
                    <label for="export_format">Report Detail:</label>
                    <select class="form-select" id="export_format" name="export_format" required>
                        <option value="summary">Summary Report</option>
                        <option value="detailed">Detailed Report</option>
                    </select>
                </div>
                
                <div class="export-option-group">
                    <label>&nbsp;</label>
                    <button type="submit" name="export_reports" class="btn export-btn">
                        <i class="fas fa-file-export me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Charts Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-card fade-in-up">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-chart-pie me-2"></i>Requests by Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-card fade-in-up delay-1">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-chart-bar me-2"></i>Requests by Priority</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="priorityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-card fade-in-up">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-project-diagram me-2"></i>Requests by Project</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="projectChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-card fade-in-up delay-1">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-chart-line me-2"></i>Monthly Trend</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detailed Data Tables -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="glass-card fade-in-up">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-table me-2"></i>Status Distribution</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['status_data'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                                            <td><?php echo $row['count']; ?></td>
                                            <td><?php echo $row['percentage']; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="glass-card fade-in-up delay-1">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-users me-2"></i>User Performance</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Total</th>
                                        <th>Approved</th>
                                        <th>Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data['user_performance'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                                            <td><?php echo $row['total_requests']; ?></td>
                                            <td><?php echo $row['approved_requests']; ?></td>
                                            <td><?php echo $row['approval_rate']; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
    // Status Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusChart = new Chart(statusCtx, {
        type: 'pie',
        data: {
            labels: [<?php echo implode(',', array_map(function($item) { return "'" . $item['status'] . "'"; }, $report_data['status_data'])); ?>],
            datasets: [{
                data: [<?php echo implode(',', array_column($report_data['status_data'], 'count')); ?>],
                backgroundColor: [
                    '#273274', '#f58220', '#2ecc71', '#e74c3c', '#3498db', '#95a5a6', '#9b59b6', '#1abc9c'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            }
        }
    });

    // Priority Chart
    const priorityCtx = document.getElementById('priorityChart').getContext('2d');
    const priorityChart = new Chart(priorityCtx, {
        type: 'bar',
        data: {
            labels: [<?php echo implode(',', array_map(function($item) { return "'" . $item['priority'] . "'"; }, $report_data['priority_data'])); ?>],
            datasets: [{
                label: 'Number of Requests',
                data: [<?php echo implode(',', array_column($report_data['priority_data'], 'count')); ?>],
                backgroundColor: '#273274',
                borderColor: '#1e2559',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Project Chart
    const projectCtx = document.getElementById('projectChart').getContext('2d');
    const projectChart = new Chart(projectCtx, {
        type: 'doughnut',
        data: {
            labels: [<?php echo implode(',', array_map(function($item) { return "'" . $item['project_name'] . "'"; }, $report_data['project_data'])); ?>],
            datasets: [{
                data: [<?php echo implode(',', array_column($report_data['project_data'], 'request_count')); ?>],
                backgroundColor: [
                    '#273274', '#f58220', '#2ecc71', '#e74c3c', '#3498db', '#95a5a6', '#9b59b6', '#1abc9c'
                ],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            }
        }
    });

    // Monthly Trend Chart
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    const monthlyChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: [<?php echo implode(',', array_map(function($item) { 
                $date = DateTime::createFromFormat('Y-m', $item['month']);
                return "'" . $date->format('M Y') . "'"; 
            }, $report_data['monthly_data'])); ?>],
            datasets: [{
                label: 'Total Requests',
                data: [<?php echo implode(',', array_column($report_data['monthly_data'], 'count')); ?>],
                borderColor: '#273274',
                backgroundColor: 'rgba(39, 50, 116, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true
            }, {
                label: 'Approved',
                data: [<?php echo implode(',', array_column($report_data['monthly_data'], 'approved_count')); ?>],
                borderColor: '#2ecc71',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mobile menu functionality
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.querySelector('.sidebar');
        
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
            }
        });
    });
</script>

</body>
</html>