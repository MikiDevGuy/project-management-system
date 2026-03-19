<?php
require_once 'config/database.php';
require_once 'config/functions.php';
checkAuth();

// Get database connection
$conn = getDBConnection();

// Get report type from query string
$reportType = isset($_GET['type']) ? $_GET['type'] : 'events';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Get Dashen logo
$logoDataUri = '';
$logoPaths = [
    __DIR__ . '/../DashenHQ1.jfif',
    __DIR__ . '/../images/logo.png',
    __DIR__ . '/DashenHQ1.jfif'
];

foreach ($logoPaths as $path) {
    if (file_exists($path)) {
        $imageData = file_get_contents($path);
        $mimeType = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $imageData);
        $logoDataUri = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        break;
    }
}

// Get report data based on type
$reportData = [];
$reportTitle = '';

switch ($reportType) {
    case 'events':
        $reportTitle = 'Event Report';
        $sql = "SELECT 
                    e.*, 
                    p.name as project_name, 
                    u.username as organizer_name,
                    COUNT(ea.id) as attendee_count
                FROM events e
                LEFT JOIN projects p ON e.project_id = p.id
                LEFT JOIN users u ON e.organizer_id = u.id
                LEFT JOIN event_attendees ea ON e.id = ea.event_id
                WHERE 1=1";
        
        if ($dateFrom) {
            $sql .= " AND e.start_datetime >= '$dateFrom'";
        }
        if ($dateTo) {
            $sql .= " AND e.start_datetime <= '$dateTo'";
        }
        
        $sql .= " GROUP BY e.id ORDER BY e.start_datetime DESC";
        $result = mysqli_query($conn, $sql);
        while ($row = mysqli_fetch_assoc($result)) {
            $reportData[] = $row;
        }
        break;
        
    case 'attendees':
        $reportTitle = 'Attendee Report';
        $sql = "SELECT 
                    ea.*,
                    e.event_name,
                    e.start_datetime,
                    u.username as attendee_name,
                    u.email
                FROM event_attendees ea
                JOIN events e ON ea.event_id = e.id
                JOIN users u ON ea.user_id = u.id
                WHERE 1=1";
        
        if ($dateFrom) {
            $sql .= " AND e.start_datetime >= '$dateFrom'";
        }
        if ($dateTo) {
            $sql .= " AND e.start_datetime <= '$dateTo'";
        }
        
        $sql .= " ORDER BY e.start_datetime DESC, u.username";
        $result = mysqli_query($conn, $sql);
        while ($row = mysqli_fetch_assoc($result)) {
            $reportData[] = $row;
        }
        break;
        
    case 'tasks':
        $reportTitle = 'Task Report';
        $sql = "SELECT 
                    et.*,
                    e.event_name,
                    u.username as assigned_name
                FROM event_tasks et
                JOIN events e ON et.event_id = e.id
                JOIN users u ON et.assigned_to = u.id
                WHERE 1=1";
        
        if ($dateFrom) {
            $sql .= " AND et.created_at >= '$dateFrom'";
        }
        if ($dateTo) {
            $sql .= " AND et.created_at <= '$dateTo'";
        }
        
        $sql .= " ORDER BY et.due_date DESC";
        $result = mysqli_query($conn, $sql);
        while ($row = mysqli_fetch_assoc($result)) {
            $reportData[] = $row;
        }
        break;
        
    case 'resources':
        $reportTitle = 'Resource Report';
        $sql = "SELECT 
                    er.*,
                    e.event_name
                FROM event_resources er
                JOIN events e ON er.event_id = e.id
                WHERE 1=1";
        
        if ($dateFrom) {
            $sql .= " AND er.created_at >= '$dateFrom'";
        }
        if ($dateTo) {
            $sql .= " AND er.created_at <= '$dateTo'";
        }
        
        $sql .= " ORDER BY er.resource_name";
        $result = mysqli_query($conn, $sql);
        while ($row = mysqli_fetch_assoc($result)) {
            $reportData[] = $row;
        }
        break;
        
    default:
        $reportTitle = 'Event Report';
        $result = mysqli_query($conn, "SELECT * FROM events ORDER BY start_datetime DESC");
        while ($row = mysqli_fetch_assoc($result)) {
            $reportData[] = $row;
        }
}

// Get stats for dashboard
$totalEvents = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM events"))['count'];
$totalResources = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM event_resources"))['count'];
$totalTasks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM event_tasks"))['count'];
$totalAttendees = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM event_attendees"))['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Dashen Bank PEMS</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css">
    
    <!-- Fix for Sidebar Icons -->
    <style>
    /* Ensure sidebar icons are always visible */
    .sidebar .nav-icon,
    .sidebar.collapsed .nav-icon {
        display: inline-block !important;
        visibility: visible !important;
        opacity: 1 !important;
        font-size: 18px !important;
        width: 24px !important;
        min-width: 24px !important;
    }
    
    /* Fix for Font Awesome icons */
    .sidebar i,
    .sidebar.collapsed i {
        display: inline-block !important;
        font-style: normal !important;
        line-height: 1 !important;
    }
    
    /* Report specific styles */
    .report-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        padding: 25px;
        margin-bottom: 25px;
    }
    
    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        border-left: 4px solid var(--dashen-blue);
        transition: transform 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(39, 50, 116, 0.1);
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, var(--dashen-blue) 0%, var(--dashen-accent) 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        margin: 0 auto 15px;
    }
    
    .chart-container {
        height: 300px;
        position: relative;
    }
    
    .no-print {
        display: block;
    }
    
    @media print {
        .no-print {
            display: none !important;
        }
        .sidebar, .header {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
        }
        .report-card {
            box-shadow: none;
            border: 1px solid #ddd;
        }
    }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <?php include 'includes/header.php'; ?>
        
        <!-- Reports Content -->
        <div class="content-wrapper">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-1">Reports & Analytics</h1>
                    <p class="text-muted mb-0">Generate and export system reports</p>
                </div>
                <div class="no-print">
                    <div class="btn-group">
                        <button type="button" class="btn btn-dashen dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fas fa-download me-2"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" id="exportPdf"><i class="fas fa-file-pdf me-2"></i> PDF</a></li>
                            <li><a class="dropdown-item" href="#" id="exportExcel"><i class="fas fa-file-excel me-2"></i> Excel</a></li>
                            <li><a class="dropdown-item" href="#" id="exportCsv"><i class="fas fa-file-csv me-2"></i> CSV</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="window.print()"><i class="fas fa-print me-2"></i> Print</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-value h2"><?php echo $totalEvents; ?></div>
                        <div class="stat-label">Total Events</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card" style="border-left-color: var(--dashen-success);">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #4caf50 100%);">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-value h2"><?php echo $totalAttendees; ?></div>
                        <div class="stat-label">Total Attendees</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card" style="border-left-color: var(--dashen-warning);">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #ff9800 100%);">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-value h2"><?php echo $totalTasks; ?></div>
                        <div class="stat-label">Total Tasks</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stat-card" style="border-left-color: var(--dashen-info);">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-info) 0%, #03a9f4 100%);">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="stat-value h2"><?php echo $totalResources; ?></div>
                        <div class="stat-label">Total Resources</div>
                    </div>
                </div>
            </div>
            
            <!-- Report Filters -->
            <div class="report-card mb-4">
                <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-4">
                        <label for="reportType" class="form-label">Report Type</label>
                        <select class="form-select" id="reportType" name="type">
                            <option value="events" <?php echo $reportType == 'events' ? 'selected' : ''; ?>>Events Report</option>
                            <option value="attendees" <?php echo $reportType == 'attendees' ? 'selected' : ''; ?>>Attendees Report</option>
                            <option value="tasks" <?php echo $reportType == 'tasks' ? 'selected' : ''; ?>>Tasks Report</option>
                            <option value="resources" <?php echo $reportType == 'resources' ? 'selected' : ''; ?>>Resources Report</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="dateFrom" class="form-label">From Date</label>
                        <input type="date" class="form-control" id="dateFrom" name="date_from" value="<?php echo $dateFrom; ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="dateTo" class="form-label">To Date</label>
                        <input type="date" class="form-control" id="dateTo" name="date_to" value="<?php echo $dateTo; ?>">
                    </div>
                    
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-dashen w-100">
                            <i class="fas fa-search me-1"></i> Generate
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Chart Section -->
            <div class="report-card mb-4">
                <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Visual Analytics</h5>
                <div class="chart-container">
                    <canvas id="reportChart"></canvas>
                </div>
            </div>
            
            <!-- Report Data Table -->
            <div class="report-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-table me-2"></i>
                        <?php echo $reportTitle; ?>
                        <span class="badge bg-primary ms-2"><?php echo count($reportData); ?> records</span>
                    </h5>
                    <div class="text-muted">
                        Generated: <?php echo date('F j, Y g:i A'); ?>
                    </div>
                </div>
                
                <?php if (!empty($reportData)): ?>
                <div class="table-responsive">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <?php if ($reportType == 'events'): ?>
                                    <th>Event Name</th>
                                    <th>Date & Time</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Attendees</th>
                                <?php elseif ($reportType == 'attendees'): ?>
                                    <th>Event</th>
                                    <th>Event Date</th>
                                    <th>Attendee Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>RSVP Status</th>
                                <?php elseif ($reportType == 'tasks'): ?>
                                    <th>Task Name</th>
                                    <th>Event</th>
                                    <th>Assigned To</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                <?php elseif ($reportType == 'resources'): ?>
                                    <th>Resource Name</th>
                                    <th>Event</th>
                                    <th>Resource Type</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr class="highlight-row">
                                <?php if ($reportType == 'events'): ?>
                                    <td><?php echo htmlspecialchars($row['event_name']); ?></td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($row['start_datetime'])); ?>
                                        <div class="text-muted small">
                                            <?php echo date('g:i A', strtotime($row['start_datetime'])); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['location']); ?></td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadge($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getPriorityBadge($row['priority']); ?>">
                                            <?php echo $row['priority']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo $row['attendee_count']; ?></span>
                                    </td>
                                    
                                <?php elseif ($reportType == 'attendees'): ?>
                                    <td><?php echo htmlspecialchars($row['event_name']); ?></td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($row['start_datetime'])); ?>
                                        <div class="text-muted small">
                                            <?php echo date('g:i A', strtotime($row['start_datetime'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-circle me-2">
                                                <?php echo strtoupper(substr($row['attendee_name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <?php echo htmlspecialchars($row['attendee_name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($row['email']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($row['email']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['role_in_event']); ?></td>
                                    <td>
                                        <span class="badge <?php echo getRSVPStatusBadge($row['rsvp_status']); ?>">
                                            <?php echo $row['rsvp_status']; ?>
                                        </span>
                                    </td>
                                    
                                <?php elseif ($reportType == 'tasks'): ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['task_name']); ?></strong>
                                        <?php if (!empty($row['description'])): ?>
                                        <div class="text-muted small mt-1">
                                            <?php echo truncateText($row['description'], 100); ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['event_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['assigned_name']); ?></td>
                                    <td>
                                        <?php echo formatDate($row['due_date']); ?>
                                        <?php if ($row['due_date'] && strtotime($row['due_date']) < time() && $row['status'] != 'Completed'): ?>
                                        <div class="text-danger small">Overdue</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getTaskStatusBadge($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getPriorityBadge($row['priority']); ?>">
                                            <?php echo $row['priority']; ?>
                                        </span>
                                    </td>
                                    
                                <?php elseif ($reportType == 'resources'): ?>
                                    <td><?php echo htmlspecialchars($row['resource_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['event_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['resource_type'] ?? 'N/A'); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo $row['quantity']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getResourceStatusBadge($row['status']); ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo truncateText($row['notes'] ?? '', 50); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Summary Footer -->
                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <div class="text-muted">
                        Showing <?php echo count($reportData); ?> records
                    </div>
                    <div class="text-end">
                        <small class="text-muted">
                            Report generated by: <?php echo htmlspecialchars($_SESSION['username'] ?? 'System'); ?>
                        </small>
                    </div>
                </div>
                
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                    <h4>No data available</h4>
                    <p class="text-muted mb-4">
                        No records found for the selected report type and filters.
                    </p>
                    <a href="reports.php" class="btn btn-dashen">
                        <i class="fas fa-redo me-2"></i> Reset Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Export Instructions -->
            <div class="alert alert-info mt-4 no-print">
                <div class="d-flex align-items-center">
                    <i class="fas fa-info-circle fa-2x me-3"></i>
                    <div>
                        <h6 class="mb-1">Export Options</h6>
                        <p class="mb-0 small">
                            Use the export button to download this report as PDF, Excel, or CSV file.
                            The print option will create a printer-friendly version of this report.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Export Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Chart
        const ctx = document.getElementById('reportChart');
        if (ctx) {
            const reportType = '<?php echo $reportType; ?>';
            let chartData = {};
            
            switch (reportType) {
                case 'events':
                    chartData = {
                        labels: ['Completed', 'Upcoming', 'Ongoing', 'Planning', 'Cancelled'],
                        datasets: [{
                            label: 'Events by Status',
                            data: [12, 8, 5, 15, 3],
                            backgroundColor: [
                                'rgba(46, 125, 50, 0.8)',
                                'rgba(39, 50, 116, 0.8)',
                                'rgba(245, 124, 0, 0.8)',
                                'rgba(2, 136, 209, 0.8)',
                                'rgba(198, 40, 40, 0.8)'
                            ],
                            borderColor: [
                                'rgba(46, 125, 50, 1)',
                                'rgba(39, 50, 116, 1)',
                                'rgba(245, 124, 0, 1)',
                                'rgba(2, 136, 209, 1)',
                                'rgba(198, 40, 40, 1)'
                            ],
                            borderWidth: 1
                        }]
                    };
                    break;
                    
                case 'attendees':
                    chartData = {
                        labels: ['Confirmed', 'Pending', 'Declined', 'Maybe'],
                        datasets: [{
                            label: 'Attendees by RSVP Status',
                            data: [45, 20, 8, 12],
                            backgroundColor: [
                                'rgba(46, 125, 50, 0.8)',
                                'rgba(108, 117, 125, 0.8)',
                                'rgba(198, 40, 40, 0.8)',
                                'rgba(253, 126, 20, 0.8)'
                            ],
                            borderColor: [
                                'rgba(46, 125, 50, 1)',
                                'rgba(108, 117, 125, 1)',
                                'rgba(198, 40, 40, 1)',
                                'rgba(253, 126, 20, 1)'
                            ],
                            borderWidth: 1
                        }]
                    };
                    break;
                    
                case 'tasks':
                    chartData = {
                        labels: ['Completed', 'In Progress', 'Not Started', 'Cancelled'],
                        datasets: [{
                            label: 'Tasks by Status',
                            data: [25, 15, 20, 5],
                            backgroundColor: [
                                'rgba(46, 125, 50, 0.8)',
                                'rgba(39, 50, 116, 0.8)',
                                'rgba(108, 117, 125, 0.8)',
                                'rgba(198, 40, 40, 0.8)'
                            ],
                            borderColor: [
                                'rgba(46, 125, 50, 1)',
                                'rgba(39, 50, 116, 1)',
                                'rgba(108, 117, 125, 1)',
                                'rgba(198, 40, 40, 1)'
                            ],
                            borderWidth: 1
                        }]
                    };
                    break;
                    
                case 'resources':
                    chartData = {
                        labels: ['Delivered', 'Requested', 'Approved', 'Purchased', 'Returned'],
                        datasets: [{
                            label: 'Resources by Status',
                            data: [30, 15, 10, 8, 5],
                            backgroundColor: [
                                'rgba(46, 125, 50, 0.8)',
                                'rgba(108, 117, 125, 0.8)',
                                'rgba(2, 136, 209, 0.8)',
                                'rgba(39, 50, 116, 0.8)',
                                'rgba(253, 126, 20, 0.8)'
                            ],
                            borderColor: [
                                'rgba(46, 125, 50, 1)',
                                'rgba(108, 117, 125, 1)',
                                'rgba(2, 136, 209, 1)',
                                'rgba(39, 50, 116, 1)',
                                'rgba(253, 126, 20, 1)'
                            ],
                            borderWidth: 1
                        }]
                    };
                    break;
            }
            
            new Chart(ctx, {
                type: 'doughnut',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: true,
                            text: 'Report Distribution'
                        }
                    }
                }
            });
        }
        
        // Export to PDF
        document.getElementById('exportPdf').addEventListener('click', async function(e) {
            e.preventDefault();
            
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a4');
            const padding = 40;
            const pageWidth = doc.internal.pageSize.getWidth();
            
            // Add title
            doc.setFontSize(18);
            doc.text('Dashen Bank PEMS - Report', padding, 50);
            
            // Add report info
            doc.setFontSize(12);
            doc.text('Report Type: <?php echo $reportTitle; ?>', padding, 80);
            doc.text('Generated: <?php echo date("F j, Y g:i A"); ?>', padding, 100);
            doc.text('Generated by: <?php echo htmlspecialchars($_SESSION["username"] ?? "System"); ?>', padding, 120);
            
            // Add table header
            let y = 150;
            doc.setFontSize(10);
            
            <?php if ($reportType == 'events'): ?>
                doc.text('Event Name', padding, y);
                doc.text('Date & Time', padding + 150, y);
                doc.text('Location', padding + 300, y);
                doc.text('Status', padding + 450, y);
                doc.text('Attendees', padding + 550, y);
                y += 20;
                
                <?php foreach ($reportData as $row): ?>
                    doc.text('<?php echo addslashes($row["event_name"]); ?>', padding, y);
                    doc.text('<?php echo date("M j, Y", strtotime($row["start_datetime"])); ?>', padding + 150, y);
                    doc.text('<?php echo addslashes($row["location"]); ?>', padding + 300, y);
                    doc.text('<?php echo $row["status"]; ?>', padding + 450, y);
                    doc.text('<?php echo $row["attendee_count"]; ?>', padding + 550, y);
                    y += 15;
                    
                    // Page break if needed
                    if (y > 750) {
                        doc.addPage();
                        y = 50;
                    }
                <?php endforeach; ?>
                
            <?php elseif ($reportType == 'attendees'): ?>
                // Similar table structure for attendees
                // (Implement based on your needs)
            <?php endif; ?>
            
            doc.save('dashen-report-<?php echo $reportType; ?>-<?php echo date("Y-m-d"); ?>.pdf');
        });
        
        // Export to Excel
        document.getElementById('exportExcel').addEventListener('click', function(e) {
            e.preventDefault();
            
            // Create workbook
            const wb = XLSX.utils.book_new();
            
            <?php if ($reportType == 'events'): ?>
                // Prepare data for Excel
                const eventData = [
                    ['Event Name', 'Date & Time', 'Location', 'Status', 'Priority', 'Attendees']
                ];
                
                <?php foreach ($reportData as $row): ?>
                    eventData.push([
                        '<?php echo addslashes($row["event_name"]); ?>',
                        '<?php echo date("M j, Y g:i A", strtotime($row["start_datetime"])); ?>',
                        '<?php echo addslashes($row["location"]); ?>',
                        '<?php echo $row["status"]; ?>',
                        '<?php echo $row["priority"]; ?>',
                        <?php echo $row["attendee_count"]; ?>
                    ]);
                <?php endforeach; ?>
                
                // Create worksheet
                const ws = XLSX.utils.aoa_to_sheet(eventData);
                XLSX.utils.book_append_sheet(wb, ws, 'Events Report');
                
            <?php elseif ($reportType == 'attendees'): ?>
                // Add other report types as needed
            <?php endif; ?>
            
            // Save file
            XLSX.writeFile(wb, 'dashen-report-<?php echo $reportType; ?>-<?php echo date("Y-m-d"); ?>.xlsx');
        });
        
        // Export to CSV
        document.getElementById('exportCsv').addEventListener('click', function(e) {
            e.preventDefault();
            
            let csvContent = "data:text/csv;charset=utf-8,";
            
            <?php if ($reportType == 'events'): ?>
                csvContent += "Event Name,Date & Time,Location,Status,Priority,Attendees\r\n";
                
                <?php foreach ($reportData as $row): ?>
                    csvContent += '"<?php echo addslashes($row["event_name"]); ?>","<?php echo date("M j, Y g:i A", strtotime($row["start_datetime"])); ?>","<?php echo addslashes($row["location"]); ?>","<?php echo $row["status"]; ?>","<?php echo $row["priority"]; ?>",<?php echo $row["attendee_count"]; ?>\r\n';
                <?php endforeach; ?>
                
            <?php elseif ($reportType == 'attendees'): ?>
                // Add other report types as needed
            <?php endif; ?>
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "dashen-report-<?php echo $reportType; ?>-<?php echo date("Y-m-d"); ?>.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
        
        // Date range validation
        const dateFrom = document.getElementById('dateFrom');
        const dateTo = document.getElementById('dateTo');
        
        if (dateFrom && dateTo) {
            const form = document.querySelector('form[method="GET"]');
            form.addEventListener('submit', function(e) {
                if (dateFrom.value && dateTo.value && dateFrom.value > dateTo.value) {
                    e.preventDefault();
                    alert('Start date cannot be after end date.');
                    dateFrom.focus();
                    return false;
                }
                return true;
            });
        }
        
        // Fix sidebar icons
        function fixSidebarIcons() {
            const sidebarIcons = document.querySelectorAll('.sidebar .nav-icon, .sidebar i');
            sidebarIcons.forEach(icon => {
                icon.style.display = 'inline-block';
                icon.style.visibility = 'visible';
                icon.style.opacity = '1';
            });
        }
        
        fixSidebarIcons();
        
        // Listen for sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                setTimeout(fixSidebarIcons, 400);
            });
        }
        
        // Table row hover effects
        const tableRows = document.querySelectorAll('.highlight-row');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'rgba(39, 50, 116, 0.05)';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
    });
    </script>
    
    <style>
    /* Avatar circles */
    .avatar-circle {
        width: 32px;
        height: 32px;
        background: linear-gradient(135deg, var(--dashen-blue) 0%, var(--dashen-accent) 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 12px;
    }
    
    /* Loading spinner */
    .loading-spinner {
        width: 40px;
        height: 40px;
        border: 3px solid rgba(39, 50, 116, 0.1);
        border-top: 3px solid #273274;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 100px auto;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    </style>
</body>
</html>