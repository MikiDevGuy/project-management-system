<?php
require_once "../db.php";
require_once "../vendor/autoload.php"; // for PDF (TCPDF or Dompdf)

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Function to safely output values
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Build query with filters
$filter_project = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
$filter_department = isset($_GET['department_id']) && $_GET['department_id'] !== '' ? (int)$_GET['department_id'] : null;
$filter_category = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$filter_status = isset($_GET['status_id']) && $_GET['status_id'] !== '' ? (int)$_GET['status_id'] : null;
$filter_owner = isset($_GET['owner_user_id']) && $_GET['owner_user_id'] !== '' ? (int)$_GET['owner_user_id'] : null;
$filter_level = isset($_GET['risk_level']) && $_GET['risk_level'] !== '' ? $_GET['risk_level'] : null;
$filter_date_from = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
$filter_date_to = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;

$whereParts = [];
$params = []; $types = '';
if ($filter_project) { $whereParts[] = "r.project_id = ?"; $params[] = $filter_project; $types .= 'i'; }
if ($filter_department) { $whereParts[] = "r.department_id = ?"; $params[] = $filter_department; $types .= 'i'; }
if ($filter_category) { $whereParts[] = "r.category_id = ?"; $params[] = $filter_category; $types .= 'i'; }
if ($filter_status) { $whereParts[] = "r.status_id = ?"; $params[] = $filter_status; $types .= 'i'; }
if ($filter_owner) { $whereParts[] = "r.owner_user_id = ?"; $params[] = $filter_owner; $types .= 'i'; }
if ($filter_level) { $whereParts[] = "r.risk_level = ?"; $params[] = $filter_level; $types .= 's'; }
if ($filter_date_from) { $whereParts[] = "r.created_at >= ?"; $params[] = $filter_date_from; $types .= 's'; }
if ($filter_date_to) { $whereParts[] = "r.created_at <= ?"; $params[] = $filter_date_to . ' 23:59:59'; $types .= 's'; }

$whereSql = count($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// Fetch risks with detailed information
$sql = "SELECT r.*, p.name AS project_name, d.department_name, u.username AS owner_name, 
               rc.name AS category_name, rs.label AS status_label,
               (SELECT COUNT(*) FROM risk_mitigations WHERE risk_id = r.id) as mitigation_count,
               (r.likelihood * r.impact) as risk_score
        FROM risks r
        LEFT JOIN projects p ON r.project_id = p.id
        LEFT JOIN departments d ON r.department_id = d.id
        LEFT JOIN users u ON r.owner_user_id = u.id
        LEFT JOIN risk_categories rc ON r.category_id = rc.id
        LEFT JOIN risk_statuses rs ON r.status_id = rs.id
        $whereSql
        ORDER BY risk_score DESC, r.created_at DESC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $risks = $res->fetch_all(MYSQLI_ASSOC);
} else {
    $risks = [];
}

$format = $_GET['format'] ?? 'csv';

if ($format === 'csv') {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=dashen_risk_export_" . date('Y-m-d') . ".csv");
    
    $output = fopen("php://output", "w");
    
    // Add UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");
    
    // Headers
    fputcsv($output, [
        'Risk ID',
        'Title',
        'Project',
        'Department', 
        'Category',
        'Likelihood',
        'Impact',
        'Risk Score',
        'Risk Level',
        'Status',
        'Owner',
        'Mitigation Count',
        'Trigger Description',
        'Risk Description',
        'Created Date',
        'Last Updated'
    ]);
    
    // Data
    foreach ($risks as $r) {
        $score = (int)$r['likelihood'] * (int)$r['impact'];
        fputcsv($output, [
            $r['id'],
            $r['title'],
            $r['project_name'] ?: 'N/A',
            $r['department_name'] ?: 'N/A',
            $r['category_name'] ?: 'N/A',
            $r['likelihood'],
            $r['impact'],
            $score,
            $r['risk_level'],
            $r['status_label'] ?: 'N/A',
            $r['owner_name'] ?: 'N/A',
            $r['mitigation_count'],
            $r['trigger_description'] ?: '',
            $r['description'] ?: '',
            $r['created_at'],
            $r['updated_at'] ?: 'Never'
        ]);
    }
    
    fclose($output);
    exit;
}

if ($format === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Risk Export');
    
    // Add header with company info
    $sheet->setCellValue('A1', 'Dashen Bank - Risk Management Export');
    $sheet->mergeCells('A1:P1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
    $sheet->setCellValue('A2', 'Generated on: ' . date('F j, Y \a\t g:i A'));
    $sheet->mergeCells('A2:P2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
    
    $sheet->setCellValue('A3', 'Total Risks: ' . count($risks));
    $sheet->mergeCells('A3:P3');
    $sheet->getStyle('A3')->getAlignment()->setHorizontal('center');
    
    // Headers
    $headers = [
        'Risk ID', 'Title', 'Project', 'Department', 'Category', 
        'Likelihood', 'Impact', 'Risk Score', 'Risk Level', 'Status',
        'Owner', 'Mitigation Count', 'Trigger Description', 'Risk Description',
        'Created Date', 'Last Updated'
    ];
    
    foreach ($headers as $col => $header) {
        $sheet->setCellValue(chr(65 + $col) . '5', $header);
        $sheet->getStyle(chr(65 + $col) . '5')->getFont()->setBold(true);
        $sheet->getStyle(chr(65 + $col) . '5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle(chr(65 + $col) . '5')->getFill()->getStartColor()->setARGB('FF273274');
        $sheet->getStyle(chr(65 + $col) . '5')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle(chr(65 + $col) . '5')->getAlignment()->setHorizontal('center');
    }
    
    // Data
    $row = 6;
    foreach ($risks as $r) {
        $score = (int)$r['likelihood'] * (int)$r['impact'];
        $sheet->setCellValue('A' . $row, $r['id']);
        $sheet->setCellValue('B' . $row, $r['title']);
        $sheet->setCellValue('C' . $row, $r['project_name'] ?: 'N/A');
        $sheet->setCellValue('D' . $row, $r['department_name'] ?: 'N/A');
        $sheet->setCellValue('E' . $row, $r['category_name'] ?: 'N/A');
        $sheet->setCellValue('F' . $row, $r['likelihood']);
        $sheet->setCellValue('G' . $row, $r['impact']);
        $sheet->setCellValue('H' . $row, $score);
        $sheet->setCellValue('I' . $row, $r['risk_level']);
        $sheet->setCellValue('J' . $row, $r['status_label'] ?: 'N/A');
        $sheet->setCellValue('K' . $row, $r['owner_name'] ?: 'N/A');
        $sheet->setCellValue('L' . $row, $r['mitigation_count']);
        $sheet->setCellValue('M' . $row, $r['trigger_description'] ?: '');
        $sheet->setCellValue('N' . $row, $r['description'] ?: '');
        $sheet->setCellValue('O' . $row, $r['created_at']);
        $sheet->setCellValue('P' . $row, $r['updated_at'] ?: 'Never');
        $row++;
    }
    
    // Auto size columns
    foreach (range('A', 'P') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Add borders and styling
    $sheet->getStyle('A5:P' . ($row-1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    
    // Add risk level color coding
    for ($i = 6; $i < $row; $i++) {
        $riskLevel = $sheet->getCell('I' . $i)->getValue();
        $color = '';
        
        switch ($riskLevel) {
            case 'Critical': $color = 'FFFFCCCC'; break;
            case 'High': $color = 'FFFFE5CC'; break;
            case 'Medium': $color = 'FFFFFFCC'; break;
            case 'Low': $color = 'FFCCFFCC'; break;
        }
        
        if ($color) {
            $sheet->getStyle('I' . $i)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle('I' . $i)->getFill()->getStartColor()->setARGB($color);
        }
    }
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="dashen_risk_export_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

if ($format === 'pdf') {
    // Create PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                margin: 20px; 
                font-size: 12px;
                color: #333;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                border-bottom: 3px solid #273274; 
                padding-bottom: 15px;
            }
            .header h1 { 
                color: #273274; 
                margin: 0; 
                font-size: 24px;
            }
            .header p { 
                color: #666; 
                margin: 5px 0; 
                font-size: 14px;
            }
            .summary { 
                margin-bottom: 20px; 
                padding: 15px; 
                background-color: #f5f5f5; 
                border-radius: 5px;
                border-left: 4px solid #273274;
            }
            .summary-item { 
                display: inline-block; 
                margin-right: 25px; 
                font-weight: bold;
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-top: 20px;
                font-size: 10px;
            }
            th { 
                background-color: #273274; 
                color: white; 
                padding: 8px; 
                text-align: left; 
                border: 1px solid #ddd;
                font-weight: bold;
            }
            td { 
                padding: 6px 8px; 
                border: 1px solid #ddd; 
                vertical-align: top;
            }
            tr:nth-child(even) { 
                background-color: #f9f9f9; 
            }
            .risk-critical { background-color: #ffe6e6; }
            .risk-high { background-color: #fff0e6; }
            .risk-medium { background-color: #fffae6; }
            .risk-low { background-color: #e6ffe6; }
            .footer {
                margin-top: 30px;
                padding-top: 10px;
                border-top: 1px solid #ddd;
                text-align: center;
                color: #666;
                font-size: 10px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Dashen Bank - Risk Management Export</h1>
            <p>Generated on: ' . date('F j, Y \a\t g:i A') . '</p>
            <p>Confidential - For Internal Use Only</p>
        </div>';
    
    // Calculate summary statistics
    $critical_count = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Critical'));
    $high_count = count(array_filter($risks, fn($r) => $r['risk_level'] === 'High'));
    $medium_count = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Medium'));
    $low_count = count(array_filter($risks, fn($r) => $r['risk_level'] === 'Low'));
    $total_mitigations = array_sum(array_column($risks, 'mitigation_count'));
    
    $html .= '<div class="summary">
                <div class="summary-item">Total Risks: ' . count($risks) . '</div>
                <div class="summary-item">Critical: ' . $critical_count . '</div>
                <div class="summary-item">High: ' . $high_count . '</div>
                <div class="summary-item">Medium: ' . $medium_count . '</div>
                <div class="summary-item">Low: ' . $low_count . '</div>
                <div class="summary-item">Total Mitigations: ' . $total_mitigations . '</div>
              </div>';
    
    $html .= '<table>
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
                        <th>Mitigations</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>';
    
    foreach ($risks as $r) {
        $score = (int)$r['likelihood'] * (int)$r['impact'];
        $risk_class = strtolower($r['risk_level']);
        $html .= "<tr class='risk-{$risk_class}'>
                    <td>{$r['id']}</td>
                    <td>" . e($r['title']) . "</td>
                    <td>" . e($r['project_name'] ?: 'N/A') . "</td>
                    <td>" . e($r['department_name'] ?: 'N/A') . "</td>
                    <td>" . e($r['category_name'] ?: 'N/A') . "</td>
                    <td>{$r['likelihood']}</td>
                    <td>{$r['impact']}</td>
                    <td><strong>{$score}</strong></td>
                    <td>" . e($r['risk_level']) . "</td>
                    <td>" . e($r['status_label'] ?: 'N/A') . "</td>
                    <td>" . e($r['owner_name'] ?: 'N/A') . "</td>
                    <td>{$r['mitigation_count']}</td>
                    <td>" . date('M j, Y', strtotime($r['created_at'])) . "</td>
                  </tr>";
    }
    
    $html .= '</tbody>
            </table>
            <div class="footer">
                <p>Dashen Bank Risk Management System | Page 1 of 1 | ' . date('Y-m-d H:i:s') . '</p>
            </div>
        </body>
    </html>';
    
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("dashen_risk_export_" . date('Y-m-d') . ".pdf", ['Attachment' => true]);
    exit;
}

// If no format specified or invalid format, show export page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Risks - Dashen Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-secondary: #1e5af5;
            --dashen-accent: #f8a01c;
        }
        
        .bg-dashen-primary { background-color: var(--dashen-primary) !important; }
        .text-dashen-primary { color: var(--dashen-primary) !important; }
        
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        
        @media (max-width: 991.98px) {
            .main-content {
                margin-left: 0 !important;
            }
        }
        
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border-radius: 10px;
        }
        
        .card-header {
            background-color: var(--dashen-primary);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 500;
        }
        
        .btn-dashen-primary {
            background-color: var(--dashen-primary);
            border-color: var(--dashen-primary);
            color: white;
        }
        
        .btn-dashen-primary:hover {
            background-color: #1e275a;
            border-color: #1e275a;
            color: white;
        }
        
        .export-option {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
        }
        
        .export-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border-color: var(--dashen-primary);
        }
        
        .stat-card {
            border-left: 4px solid var(--dashen-primary);
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <nav class="navbar navbar-light bg-white border-bottom">
            <div class="container-fluid">
                <div class="d-flex align-items-center">
                    <h4 class="mb-0 text-dashen-primary">
                        <i class="bi bi-download me-2"></i>Export Risks
                    </h4>
                </div>
                <div class="d-flex align-items-center">
                    <a href="risks.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Risks
                    </a>
                </div>
            </div>
        </nav>

        <div class="container-fluid py-4">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <!-- Statistics -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title text-dashen-primary"><?= count($risks) ?></h5>
                                            <p class="card-text text-muted">Total Risks</p>
                                        </div>
                                        <i class="bi bi-clipboard-data fs-4 text-dashen-primary opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title text-danger"><?= count(array_filter($risks, fn($r) => $r['risk_level'] === 'Critical')) ?></h5>
                                            <p class="card-text text-muted">Critical Risks</p>
                                        </div>
                                        <i class="bi bi-exclamation-triangle fs-4 text-danger opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title text-primary"><?= array_sum(array_column($risks, 'mitigation_count')) ?></h5>
                                            <p class="card-text text-muted">Total Mitigations</p>
                                        </div>
                                        <i class="bi bi-shield-check fs-4 text-primary opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card stat-card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="card-title text-success"><?= count(array_unique(array_column($risks, 'project_name'))) - 1 ?></h5>
                                            <p class="card-text text-muted">Projects</p>
                                        </div>
                                        <i class="bi bi-folder fs-4 text-success opacity-50"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Export Options -->
                    <div class="card">
                        <div class="card-header bg-dashen-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-download me-2"></i>Export Options</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <!-- CSV Export -->
                                <div class="col-md-4">
                                    <div class="card export-option h-100 text-center">
                                        <div class="card-body">
                                            <i class="bi bi-file-earmark-spreadsheet text-success display-4 d-block mb-3"></i>
                                            <h5 class="text-dashen-primary">CSV Export</h5>
                                            <p class="text-muted">Comma-separated values format. Ideal for data analysis in Excel or other spreadsheet applications.</p>
                                            <ul class="list-unstyled text-start small text-muted">
                                                <li><i class="bi bi-check text-success me-2"></i>Excel compatible</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Lightweight file size</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Easy data processing</li>
                                            </ul>
                                            <a href="?<?= http_build_query($_GET) ?>&format=csv" class="btn btn-success mt-3">
                                                <i class="bi bi-download me-1"></i>Download CSV
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Excel Export -->
                                <div class="col-md-4">
                                    <div class="card export-option h-100 text-center">
                                        <div class="card-body">
                                            <i class="bi bi-file-earmark-excel text-success display-4 d-block mb-3"></i>
                                            <h5 class="text-dashen-primary">Excel Export</h5>
                                            <p class="text-muted">Microsoft Excel format with formatting and styling. Perfect for reports and presentations.</p>
                                            <ul class="list-unstyled text-start small text-muted">
                                                <li><i class="bi bi-check text-success me-2"></i>Formatted columns</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Color-coded risk levels</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Professional styling</li>
                                            </ul>
                                            <a href="?<?= http_build_query($_GET) ?>&format=excel" class="btn btn-success mt-3">
                                                <i class="bi bi-download me-1"></i>Download Excel
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <!-- PDF Export -->
                                <div class="col-md-4">
                                    <div class="card export-option h-100 text-center">
                                        <div class="card-body">
                                            <i class="bi bi-file-earmark-pdf text-danger display-4 d-block mb-3"></i>
                                            <h5 class="text-dashen-primary">PDF Export</h5>
                                            <p class="text-muted">Portable Document Format. Best for printing, sharing, and formal documentation.</p>
                                            <ul class="list-unstyled text-start small text-muted">
                                                <li><i class="bi bi-check text-success me-2"></i>Print-ready format</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Professional layout</li>
                                                <li><i class="bi bi-check text-success me-2"></i>Fixed formatting</li>
                                            </ul>
                                            <a href="?<?= http_build_query($_GET) ?>&format=pdf" class="btn btn-danger mt-3">
                                                <i class="bi bi-download me-1"></i>Download PDF
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Export Information -->
                            <div class="mt-4 p-3 bg-light rounded">
                                <h6 class="text-dashen-primary"><i class="bi bi-info-circle me-2"></i>Export Information</h6>
                                <ul class="list-unstyled mb-0">
                                    <li><strong>Data Included:</strong> All risk information including descriptions, mitigation counts, and calculated risk scores</li>
                                    <li><strong>Filters Applied:</strong> The export will respect all currently applied filters</li>
                                    <li><strong>File Size:</strong> Approximately <?= number_format(count($risks) * 0.5, 2) ?> KB for <?= count($risks) ?> risks</li>
                                    <li><strong>Confidentiality:</strong> This data is confidential and for internal use only</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>