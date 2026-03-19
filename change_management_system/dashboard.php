<?php
// dashboard.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'db.php';

// Get dashboard statistics
$query = "SELECT status, COUNT(*) as count FROM change_requests GROUP BY status";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $status_counts = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $status_counts = [];
}

$stats = [];
foreach ($status_counts as $row) {
    $stats[$row['status']] = $row['count'];
}

// Get recent change requests
$query = "SELECT cr.*, p.name as project_name, u.username as requester_name
          FROM change_requests cr
          JOIN projects p ON cr.project_id = p.id
          JOIN users u ON cr.requester_id = u.id
          ORDER BY cr.request_date DESC
          LIMIT 5";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $recent_requests = [];
}

// Get priority distribution
$query = "SELECT priority, COUNT(*) as count FROM change_requests GROUP BY priority";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $priority_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $priority_data = [];
}

// Get monthly trend data
$query = "SELECT 
            DATE_FORMAT(request_date, '%Y-%m') as month,
            COUNT(*) as count
          FROM change_requests 
          WHERE request_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          GROUP BY DATE_FORMAT(request_date, '%Y-%m')
          ORDER BY month DESC
          LIMIT 6";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $monthly_trends = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $monthly_trends = [];
}

// Get user-specific stats if not admin/manager
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['system_role'];

if ($user_role !== 'super_Admin' && $user_role !== 'Pm_Manager') {
    $query = "SELECT status, COUNT(*) as count FROM change_requests WHERE requester_id = ? GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_status_counts = $result->fetch_all(MYSQLI_ASSOC);
    
    $user_stats = [];
    foreach ($user_status_counts as $row) {
        $user_stats[$row['status']] = $row['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Dashen Bank Change Management</title>
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
        
        .stat-card {
            padding: 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--dashen-primary);
        }
        
        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            color: white;
            box-shadow: 0 5px 15px rgba(39, 50, 116, 0.3);
        }
        
        .stat-number {
            font-size: 2.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, var(--dashen-primary) 0%, var(--dashen-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            color: var(--text-light);
            font-weight: 500;
            font-size: 1rem;
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
            padding: 1rem;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            font-size: 0.875rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .bg-pending { background: #fff3cd; color: #856404; }
        .bg-in-progress { background: #cce7ff; color: var(--dashen-primary); }
        .bg-approved { background: #d1f7e9; color: var(--dashen-success); }
        .bg-rejected { background: #f8d7da; color: var(--dashen-danger); }
        .bg-implemented { background: #e0e7ff; color: var(--dashen-primary); }
        
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
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .dashboard-header::before {
                width: 150px;
                height: 150px;
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
            box-shadow: 0 4px 10px rgba(39, 50, 116, 0.3);
        }
        
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
        }
        
        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-card {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--dashen-primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--dashen-secondary);
        }
        
        /* Dashen Logo in header */
        .header-logo {
            height: 40px;
            margin-right: 15px;
        }
        
        /* Quick stats section */
        .quick-stats {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .quick-stat-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .quick-stat-item:last-child {
            border-bottom: none;
        }
        
        .quick-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            background: var(--dashen-light);
            color: var(--dashen-primary);
        }
        
        .quick-stat-value {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--dashen-primary);
        }
        
        .quick-stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>
    
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
                    <img src="../Images/DashenLogo1.png" alt="Dashen Bank Logo" class="header-logo">
                    Dashboard
                </h1>
                <p class="welcome-text">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! Here's what's happening today.</p>
            </div>
            <div class="d-flex align-items-center">
                <span class="me-3 fw-medium" style="color: rgba(255, 255, 255, 0.9);"><?php echo date('l, F j, Y'); ?></span>
                <div class="bg-white rounded-pill px-3 py-1" style="color: var(--dashen-primary);">
                    <i class="fas fa-calendar-alt me-2"></i>
                    <span>Today</span>
                </div>
            </div>
        </div>
        
        <?php if ($user_role === 'super_Admin' || $user_role === 'Pm_Manager'): ?>
            <!-- Admin/Manager Dashboard -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-number"><?php echo array_sum($stats); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-1">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['Approved']) ? $stats['Approved'] : 0; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-2">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%);">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['In Progress']) ? $stats['In Progress'] : 0; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-3">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-danger) 0%, #c0392b 100%);">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-danger) 0%, #c0392b 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($stats['Open']) ? $stats['Open'] : 0; ?></div>
                        <div class="stat-label">Pending Review</div>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-xl-8 mb-4">
                    <div class="glass-card animate-card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-chart-line"></i> Change Requests Trend</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="trendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4 mb-4">
                    <div class="glass-card animate-card delay-1">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-exclamation-triangle"></i> Priority Distribution</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="priorityChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-xl-4 mb-4">
                    <div class="glass-card animate-card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="fas fa-chart-pie"></i> Status Overview</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-8 mb-4">
                    <div class="glass-card animate-card delay-1">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title"><i class="fas fa-history"></i> Recent Change Requests</h5>
                            <a href="change_management.php" class="btn btn-primary btn-sm">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($recent_requests) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Project</th>
                                                <th>Title</th>
                                                <th>Status</th>
                                                <th>Priority</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_requests as $request): ?>
                                                <tr>
                                                    <td class="fw-bold">#<?php echo $request['change_request_id']; ?></td>
                                                    <td><?php echo htmlspecialchars($request['project_name']); ?></td>
                                                    <td>
                                                        <div class="fw-medium"><?php echo htmlspecialchars($request['change_title']); ?></div>
                                                        <small class="text-muted">by <?php echo htmlspecialchars($request['requester_name']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge 
                                                            <?php
                                                            switch($request['status']) {
                                                                case 'Approved': echo 'bg-approved'; break;
                                                                case 'In Progress': echo 'bg-in-progress'; break;
                                                                case 'Rejected': echo 'bg-rejected'; break;
                                                                case 'Open': echo 'bg-pending'; break;
                                                                case 'Implemented': echo 'bg-implemented'; break;
                                                                default: echo 'bg-pending';
                                                            }
                                                            ?>
                                                        ">
                                                            <?php echo $request['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge 
                                                            <?php 
                                                            switch($request['priority']) {
                                                                case 'High': echo 'bg-danger'; break;
                                                                case 'Medium': echo 'bg-warning text-dark'; break;
                                                                case 'Low': echo 'bg-secondary'; break;
                                                                case 'Urgent': echo 'bg-danger'; break;
                                                                default: echo 'bg-secondary';
                                                            }
                                                            ?>
                                                        ">
                                                            <?php echo $request['priority']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No change requests found.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Regular User Dashboard -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-number"><?php echo array_sum($user_stats); ?></div>
                        <div class="stat-label">My Requests</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-1">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%);">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-success) 0%, #27ae60 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($user_stats['Approved']) ? $user_stats['Approved'] : 0; ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-2">
                        <div class="stat-icon" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%);">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, var(--dashen-warning) 0%, #e67e22 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($user_stats['In Progress']) ? $user_stats['In Progress'] : 0; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="glass-card stat-card animate-card delay-3">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-number" style="background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"><?php echo isset($user_stats['Open']) ? $user_stats['Open'] : 0; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <div class="glass-card animate-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title"><i class="fas fa-history"></i> My Recent Requests</h5>
                            <a href="change_management.php" class="btn btn-primary btn-sm">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($recent_requests) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Project</th>
                                                <th>Title</th>
                                                <th>Status</th>
                                                <th>Priority</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_requests as $request): ?>
                                                <tr>
                                                    <td class="fw-bold">#<?php echo $request['change_request_id']; ?></td>
                                                    <td><?php echo htmlspecialchars($request['project_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['change_title']); ?></td>
                                                    <td>
                                                        <span class="status-badge 
                                                            <?php
                                                            switch($request['status']) {
                                                                case 'Approved': echo 'bg-approved'; break;
                                                                case 'In Progress': echo 'bg-in-progress'; break;
                                                                case 'Rejected': echo 'bg-rejected'; break;
                                                                case 'Open': echo 'bg-pending'; break;
                                                                case 'Implemented': echo 'bg-implemented'; break;
                                                                default: echo 'bg-pending';
                                                            }
                                                            ?>
                                                        ">
                                                            <?php echo $request['status']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge 
                                                            <?php 
                                                            switch($request['priority']) {
                                                                case 'High': echo 'bg-danger'; break;
                                                                case 'Medium': echo 'bg-warning text-dark'; break;
                                                                case 'Low': echo 'bg-secondary'; break;
                                                                case 'Urgent': echo 'bg-danger'; break;
                                                                default: echo 'bg-secondary';
                                                            }
                                                            ?>
                                                        ">
                                                            <?php echo $request['priority']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($request['request_date'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No change requests found.</p>
                                    <a href="change_management.php" class="btn btn-primary">Create Your First Request</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($user_role === 'super_Admin' || $user_role === 'Pm_Manager'): ?>
    <script>
        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($key) { return "'" . $key . "'"; }, array_keys($stats))); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_values($stats)); ?>],
                    backgroundColor: [
                        '#273274', '#f58220', '#2ecc71', '#e74c3c', '#3498db', '#95a5a6'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
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
            type: 'pie',
            data: {
                labels: [<?php echo implode(',', array_map(function($item) { return "'" . $item['priority'] . "'"; }, $priority_data)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_column($priority_data, 'count')); ?>],
                    backgroundColor: [
                        '#e74c3c', '#f58220', '#2ecc71', '#273274'
                    ],
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
                            usePointStyle: true
                        }
                    }
                }
            }
        });

        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($item) { 
                    $date = DateTime::createFromFormat('Y-m', $item['month']);
                    return "'" . $date->format('M Y') . "'"; 
                }, array_reverse($monthly_trends))); ?>],
                datasets: [{
                    label: 'Change Requests',
                    data: [<?php echo implode(',', array_map(function($item) { 
                        return $item['count']; 
                    }, array_reverse($monthly_trends))); ?>],
                    borderColor: '#273274',
                    backgroundColor: 'rgba(39, 50, 116, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
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
    <?php endif; ?>

    <!-- Notifications Modal -->
    <div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationsModalLabel">Notifications</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="notificationsBody">
                    <!-- Notifications will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

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