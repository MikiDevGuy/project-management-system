<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database and functions
$config_path = file_exists('config/database.php') ? 'config/database.php' : '../config/database.php';
require_once $config_path;

$config_path = file_exists('config/functions.php') ? 'config/functions.php' : '../config/functions.php';
require_once $config_path;

$conn = getDBConnection();
checkAuth();

// Get statistics
$stats = [];
try {
    // Total events
    $sql = "SELECT COUNT(*) as total FROM events";
    $result = mysqli_query($conn, $sql);
    $stats['total_events'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;
    
    // Upcoming events (next 7 days)
    $sql = "SELECT COUNT(*) as upcoming FROM events 
            WHERE status = 'Upcoming' 
            AND start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    $result = mysqli_query($conn, $sql);
    $stats['upcoming_events'] = $result ? mysqli_fetch_assoc($result)['upcoming'] : 0;
    
    // Ongoing events
    $sql = "SELECT COUNT(*) as ongoing FROM events WHERE status = 'Ongoing'";
    $result = mysqli_query($conn, $sql);
    $stats['ongoing_events'] = $result ? mysqli_fetch_assoc($result)['ongoing'] : 0;
    
    // Pending tasks
    $sql = "SELECT COUNT(*) as pending FROM event_tasks WHERE status != 'Completed'";
    $result = mysqli_query($conn, $sql);
    $stats['pending_tasks'] = $result ? mysqli_fetch_assoc($result)['pending'] : 0;
    
    // Resources needed
    $sql = "SELECT COUNT(*) as resources FROM event_resources WHERE status != 'Delivered'";
    $result = mysqli_query($conn, $sql);
    $stats['resources_needed'] = $result ? mysqli_fetch_assoc($result)['resources'] : 0;
    
    // Total attendees
    $sql = "SELECT COUNT(DISTINCT user_id) as attendees FROM event_attendees";
    $result = mysqli_query($conn, $sql);
    $stats['total_attendees'] = $result ? mysqli_fetch_assoc($result)['attendees'] : 0;
    
    // Recent events with more details
    $sql = "SELECT e.*, p.name as project_name, u.username as organizer_name,
            (SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id) as attendee_count
            FROM events e 
            LEFT JOIN projects p ON e.project_id = p.id 
            LEFT JOIN users u ON e.organizer_id = u.id 
            ORDER BY e.start_datetime DESC 
            LIMIT 8";
    $eventsResult = mysqli_query($conn, $sql);
    $recentEvents = [];
    if ($eventsResult) {
        while ($row = mysqli_fetch_assoc($eventsResult)) {
            $recentEvents[] = $row;
        }
    }
    
    // Recent tasks
    $sql = "SELECT et.*, e.event_name, e.event_type, u.username as assigned_name 
            FROM event_tasks et 
            JOIN events e ON et.event_id = e.id 
            JOIN users u ON et.assigned_to = u.id 
            ORDER BY et.created_at DESC 
            LIMIT 8";
    $tasksResult = mysqli_query($conn, $sql);
    $recentTasks = [];
    if ($tasksResult) {
        while ($row = mysqli_fetch_assoc($tasksResult)) {
            $recentTasks[] = $row;
        }
    }
    
    // Get event status counts for chart
    $sql = "SELECT status, COUNT(*) as count FROM events GROUP BY status";
    $statusResult = mysqli_query($conn, $sql);
    $statusData = [];
    $statusLabels = [];
    $statusCounts = [];
    $statusColors = [
        'Planning' => '#FFB74D',
        'Upcoming' => '#4FC3F7',
        'Ongoing' => '#273274',
        'Completed' => '#66BB6A',
        'Cancelled' => '#EF5350'
    ];
    
    if ($statusResult) {
        while ($row = mysqli_fetch_assoc($statusResult)) {
            $statusData[] = $row;
            $statusLabels[] = $row['status'];
            $statusCounts[] = $row['count'];
        }
    }
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
}

// Get custom colors from session
$custom_colors = $_SESSION['custom_colors'] ?? [
    'primary' => '#273274',
    'secondary' => '#3c4c9e',
    'accent' => '#fff'
];

// Dark mode check
$dark_mode = $_SESSION['dark_mode'] ?? false;

// Helper function for status badges
function getStatusBadge($status) {
    $statusClasses = [
        'Planning' => 'badge-planning',
        'Upcoming' => 'badge-upcoming',
        'Ongoing' => 'badge-ongoing',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled'
    ];
    
    return $statusClasses[$status] ?? 'badge-secondary';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Dashen Bank PEMS</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- FullCalendar -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* Modern CSS Reset and Variables */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Color System */
            --dashen-primary: <?php echo $custom_colors['primary']; ?>;
            --dashen-primary-light: <?php echo $custom_colors['primary'] . '20'; ?>;
            --dashen-primary-dark: #1a1f4f;
            --dashen-secondary: <?php echo $custom_colors['secondary']; ?>;
            --dashen-accent: <?php echo $custom_colors['accent']; ?>;
            
            /* Status Colors */
            --status-planning: #FFB74D;
            --status-upcoming: #4FC3F7;
            --status-ongoing: #273274;
            --status-completed: #66BB6A;
            --status-cancelled: #EF5350;
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            --gradient-success: linear-gradient(135deg, #66BB6A, #43A047);
            --gradient-warning: linear-gradient(135deg, #FFB74D, #FF9800);
            --gradient-danger: linear-gradient(135deg, #EF5350, #E53935);
            --gradient-info: linear-gradient(135deg, #4FC3F7, #039BE5);
            
            /* Shadows */
            --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.05), 0 10px 10px -5px rgba(0,0,0,0.02);
            --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,0.15);
            
            /* Spacing */
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --header-height: 80px;
            
            /* Border Radius */
            --border-radius-sm: 8px;
            --border-radius-md: 12px;
            --border-radius-lg: 16px;
            --border-radius-xl: 20px;
            --border-radius-2xl: 24px;
            
            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition-base: 0.3s ease;
            --transition-slow: 0.5s ease;
        }

        /* Dark Mode Variables */
        body.dark-mode {
            --bg-primary: #0f0f13;
            --bg-secondary: #1a1a24;
            --bg-card: #242430;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --border-color: #2a2a35;
            --hover-color: #2f2f3a;
        }

        body:not(.dark-mode) {
            --bg-primary: #f5f7fb;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --text-primary: #1a1a24;
            --text-secondary: #6b6b7b;
            --border-color: #e2e4e9;
            --hover-color: #f8f9fc;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            overflow-x: hidden;
            transition: background-color var(--transition-base);
        }

        /* Main Content Layout */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left var(--transition-base);
            display: flex;
            flex-direction: column;
            position: relative;
            width: calc(100% - var(--sidebar-width));
        }

        .main-content.expanded {
            margin-left: var(--sidebar-collapsed-width);
            width: calc(100% - var(--sidebar-collapsed-width));
        }

        .content-wrapper {
            padding: 32px;
            flex: 1;
            transition: all var(--transition-base);
            margin-top: var(--header-height);
            position: relative;
            background: var(--bg-primary);
        }

        /* Welcome Banner - Modern Glassmorphism */
        .welcome-banner {
            margin-bottom: 32px;
            animation: slideDown 0.6s ease-out;
        }

        .welcome-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-2xl);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }

        .welcome-content {
            padding: 32px;
        }

        .welcome-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .date-display-modern {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: var(--gradient-primary);
            padding: 8px 24px;
            border-radius: 40px;
            color: white;
            font-weight: 600;
            box-shadow: var(--shadow-md);
        }

        .date-day {
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
        }

        .date-month-year {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.2;
        }

        /* Stats Grid - Modern Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card-modern {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 24px;
            transition: all var(--transition-base);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity var(--transition-base);
            z-index: 1;
        }

        .stat-card-modern:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-2xl);
            border-color: transparent;
        }

        .stat-card-modern:hover::before {
            opacity: 0.03;
        }

        .stat-icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
        }

        .stat-icon-wrapper i {
            font-size: 24px;
            color: white;
        }

        .stat-content {
            position: relative;
            z-index: 2;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 12px;
            font-weight: 500;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
        }

        .trend-up {
            color: #66BB6A;
        }

        .trend-down {
            color: #EF5350;
        }

        .trend-neutral {
            color: #FFB74D;
        }

        .trend-badge {
            padding: 4px 8px;
            border-radius: 20px;
            background: var(--bg-primary);
            font-size: 12px;
        }

        /* Section Title */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-title-modern {
            font-size: 20px;
            font-weight: 700;
            position: relative;
            padding-left: 16px;
        }

        .section-title-modern::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--gradient-primary);
            border-radius: 2px;
        }

        .view-all-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all var(--transition-fast);
            padding: 8px 16px;
            border-radius: 30px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
        }

        .view-all-link:hover {
            background: var(--gradient-primary);
            color: white;
            transform: translateX(4px);
        }

        /* Quick Actions Grid */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .quick-action-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-lg);
            padding: 20px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all var(--transition-base);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .quick-action-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity var(--transition-base);
        }

        .quick-action-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: transparent;
        }

        .quick-action-item:hover::before {
            opacity: 0.05;
        }

        .quick-action-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
        }

        .quick-action-icon i {
            font-size: 20px;
            color: white;
        }

        .quick-action-text {
            font-size: 14px;
            font-weight: 600;
            position: relative;
            z-index: 2;
        }

        .quick-action-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--status-danger);
            color: white;
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 10px;
            font-weight: 600;
            z-index: 3;
        }

        /* Modern Tables */
        .table-modern-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            overflow: hidden;
        }

        .table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .table-modern thead th {
            background: var(--bg-primary);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .table-modern tbody tr {
            transition: all var(--transition-fast);
            cursor: pointer;
        }

        .table-modern tbody tr:hover {
            background: var(--hover-color);
        }

        .table-modern td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .table-modern tbody tr:last-child td {
            border-bottom: none;
        }

        /* Event Item */
        .event-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .event-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .event-info h6 {
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text-primary);
        }

        .event-info .event-meta {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Modern Badges */
        .badge-modern {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-planning {
            background: rgba(255, 183, 77, 0.1);
            color: #FFB74D;
            border: 1px solid rgba(255, 183, 77, 0.2);
        }

        .badge-upcoming {
            background: rgba(79, 195, 247, 0.1);
            color: #4FC3F7;
            border: 1px solid rgba(79, 195, 247, 0.2);
        }

        .badge-ongoing {
            background: rgba(39, 50, 116, 0.1);
            color: #273274;
            border: 1px solid rgba(39, 50, 116, 0.2);
        }

        .badge-completed {
            background: rgba(102, 187, 106, 0.1);
            color: #66BB6A;
            border: 1px solid rgba(102, 187, 106, 0.2);
        }

        .badge-cancelled {
            background: rgba(239, 83, 80, 0.1);
            color: #EF5350;
            border: 1px solid rgba(239, 83, 80, 0.2);
        }

        /* Action Buttons */
        .action-buttons-modern {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
            text-decoration: none;
        }

        .btn-icon:hover {
            background: var(--gradient-primary);
            color: white;
            transform: translateY(-2px);
            border-color: transparent;
        }

        /* Chart Container */
        .chart-container-modern {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 24px;
        }

        /* Empty State */
        .empty-state-modern {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            border-radius: 40px;
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            color: var(--text-secondary);
        }

        .empty-state-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .empty-state-text {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }

        .btn-create {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all var(--transition-base);
        }

        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: white;
        }

        /* Calendar Customization */
        #calendar {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-xl);
            padding: 20px;
        }

        .fc .fc-toolbar-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .fc .fc-button {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all var(--transition-fast);
        }

        .fc .fc-button:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: transparent;
        }

        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: var(--gradient-primary);
            border-color: transparent;
        }

        .fc .fc-daygrid-day-number {
            color: var(--text-primary);
            text-decoration: none;
        }

        .fc .fc-day-today {
            background: var(--bg-primary) !important;
        }

        .fc .fc-event {
            border: none;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 500;
        }

        /* Animations */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .slide-up {
            animation: slideUp 0.6s ease-out forwards;
        }

        .scale-in {
            animation: scaleIn 0.5s ease-out forwards;
        }

        .fade-in {
            animation: fadeIn 0.8s ease-out forwards;
        }

        /* Stagger children animations */
        .stagger-children > * {
            opacity: 0;
            animation: slideUp 0.5s ease-out forwards;
        }

        .stagger-children > *:nth-child(1) { animation-delay: 0.1s; }
        .stagger-children > *:nth-child(2) { animation-delay: 0.2s; }
        .stagger-children > *:nth-child(3) { animation-delay: 0.3s; }
        .stagger-children > *:nth-child(4) { animation-delay: 0.4s; }
        .stagger-children > *:nth-child(5) { animation-delay: 0.5s; }
        .stagger-children > *:nth-child(6) { animation-delay: 0.6s; }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .content-wrapper {
                padding: 20px;
            }

            .col-lg-4, .col-lg-6, .col-lg-8 {
                margin-bottom: 20px;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .welcome-content {
                padding: 24px;
            }

            .welcome-title {
                font-size: 24px;
            }

            .date-display-modern {
                padding: 6px 18px;
            }

            .date-day {
                font-size: 24px;
            }
        }

        @media (max-width: 480px) {
            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="<?php echo $dark_mode ? 'dark-mode' : ''; ?>">
    <!-- Modern Sidebar -->
    <?php 
    $sidebar_path = file_exists('includes/sidebar.php') ? 'includes/sidebar.php' : '../includes/sidebar.php';
    include $sidebar_path; 
    ?>
    
    <!-- Main Content -->
    <main class="main-content <?php echo isset($_SESSION['sidebar_collapsed']) && $_SESSION['sidebar_collapsed'] ? 'expanded' : ''; ?>" id="mainContent">
        <!-- Header -->
        <?php 
        $header_path = file_exists('includes/header.php') ? 'includes/header.php' : '../includes/header.php';
        include $header_path; 
        ?>
        
        <!-- Dashboard Content -->
        <div class="content-wrapper">
            <!-- Welcome Banner - Modern -->
            <div class="welcome-banner">
                <div class="welcome-card">
                    <div class="welcome-content">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h2 class="welcome-title">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>! 👋</h2>
                                <p class="text-secondary mb-3" style="color: var(--text-secondary);">Here's your event management overview for today.</p>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="date-display-modern">
                                        <span class="date-day"><?php echo date('d'); ?></span>
                                        <div class="date-month-year">
                                            <div><?php echo date('M'); ?></div>
                                            <div><?php echo date('Y'); ?></div>
                                        </div>
                                    </div>
                                    <span class="text-secondary small">
                                        <i class="fas fa-clock me-1"></i>
                                        Last login: <?php echo date('F j, Y, g:i a'); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <div class="d-flex justify-content-md-end gap-2">
                                    <button class="btn-icon" onclick="refreshDashboard()" title="Refresh">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                    <button class="btn-icon" onclick="downloadReport()" title="Download Report">
                                        <i class="fas fa-download"></i>
                                    </button>
                                    <button class="btn-icon" onclick="shareDashboard()" title="Share">
                                        <i class="fas fa-share-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Overview - Modern Cards -->
            <div class="stats-overview stagger-children">
                <div class="section-header">
                    <h3 class="section-title-modern">Overview</h3>
                    <a href="reports.php" class="view-all-link">
                        <i class="fas fa-chart-line"></i>
                        View Analytics
                    </a>
                </div>
                <div class="stats-grid">
                    <!-- Total Events -->
                    <div class="stat-card-modern" onclick="navigateTo('events.php')" data-aos="fade-up" data-aos-delay="100">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-total-events"><?php echo $stats['total_events']; ?></div>
                            <div class="stat-label">Total Events</div>
                            <div class="stat-trend trend-up">
                                <i class="fas fa-arrow-up"></i>
                                <span>12% from last month</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Upcoming Events -->
                    <div class="stat-card-modern" onclick="navigateTo('events.php?filter=upcoming')" data-aos="fade-up" data-aos-delay="150">
                        <div class="stat-icon-wrapper" style="background: var(--gradient-success);">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-upcoming-events"><?php echo $stats['upcoming_events']; ?></div>
                            <div class="stat-label">Upcoming Events</div>
                            <div class="stat-trend trend-up">
                                <i class="fas fa-clock"></i>
                                <span>Next 7 days</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ongoing Events -->
                    <div class="stat-card-modern" onclick="navigateTo('events.php?filter=ongoing')" data-aos="fade-up" data-aos-delay="200">
                        <div class="stat-icon-wrapper" style="background: var(--gradient-warning);">
                            <i class="fas fa-spinner"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-ongoing-events"><?php echo $stats['ongoing_events']; ?></div>
                            <div class="stat-label">Ongoing Events</div>
                            <div class="stat-trend trend-neutral">
                                <i class="fas fa-minus"></i>
                                <span>Currently active</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pending Tasks -->
                    <div class="stat-card-modern" onclick="navigateTo('tasks.php?filter=pending')" data-aos="fade-up" data-aos-delay="250">
                        <div class="stat-icon-wrapper" style="background: var(--gradient-info);">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-pending-tasks"><?php echo $stats['pending_tasks']; ?></div>
                            <div class="stat-label">Pending Tasks</div>
                            <div class="stat-trend trend-down">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>Needs attention</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resources Needed -->
                    <div class="stat-card-modern" onclick="navigateTo('resources.php')" data-aos="fade-up" data-aos-delay="300">
                        <div class="stat-icon-wrapper" style="background: var(--gradient-danger);">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-resources-needed"><?php echo $stats['resources_needed']; ?></div>
                            <div class="stat-label">Resources Needed</div>
                            <div class="stat-trend trend-down">
                                <i class="fas fa-clock"></i>
                                <span>Pending delivery</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Attendees -->
                    <div class="stat-card-modern" onclick="navigateTo('attendees.php')" data-aos="fade-up" data-aos-delay="350">
                        <div class="stat-icon-wrapper">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-total-attendees"><?php echo $stats['total_attendees']; ?></div>
                            <div class="stat-label">Total Attendees</div>
                            <div class="stat-trend trend-up">
                                <i class="fas fa-arrow-up"></i>
                                <span>8% from last month</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts and Quick Actions -->
            <div class="row">
                <!-- Event Status Chart -->
                <div class="col-lg-8 mb-4">
                    <div class="chart-container-modern" data-aos="fade-right">
                        <div class="section-header mb-4">
                            <h4 class="section-title-modern">Event Distribution</h4>
                            <div class="d-flex gap-2">
                                <span class="badge-modern badge-planning">
                                    <i class="fas fa-circle me-1"></i> Planning
                                </span>
                                <span class="badge-modern badge-upcoming">
                                    <i class="fas fa-circle me-1"></i> Upcoming
                                </span>
                                <span class="badge-modern badge-ongoing">
                                    <i class="fas fa-circle me-1"></i> Ongoing
                                </span>
                            </div>
                        </div>
                        <canvas id="eventStatusChart" height="250"></canvas>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="col-lg-4 mb-4">
                    <div class="chart-container-modern" data-aos="fade-left">
                        <div class="section-header mb-4">
                            <h4 class="section-title-modern">Quick Actions</h4>
                        </div>
                        <div class="quick-actions-grid">
                            <a href="events.php?action=add" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-calendar-plus"></i>
                                </div>
                                <span class="quick-action-text">Create Event</span>
                            </a>
                            
                            <a href="tasks.php?action=add" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-tasks"></i>
                                </div>
                                <span class="quick-action-text">Add Task</span>
                            </a>
                            
                            <a href="resources.php?action=add" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-box"></i>
                                </div>
                                <span class="quick-action-text">Add Resource</span>
                            </a>
                            
                            <a href="attendees.php?action=add" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <span class="quick-action-text">Add Attendee</span>
                            </a>
                            
                            <a href="reports.php" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-chart-pie"></i>
                                </div>
                                <span class="quick-action-text">Reports</span>
                            </a>
                            
                            <a href="notifications.php" class="quick-action-item">
                                <div class="quick-action-icon">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <span class="quick-action-text">Notifications</span>
                                <?php if ($stats['pending_tasks'] > 0): ?>
                                <span class="quick-action-badge"><?php echo $stats['pending_tasks']; ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Events & Tasks -->
            <div class="row">
                <!-- Recent Events -->
                <div class="col-lg-6 mb-4">
                    <div class="table-modern-container" data-aos="fade-up">
                        <div class="section-header p-4 pb-0">
                            <h4 class="section-title-modern">Recent Events</h4>
                            <a href="events.php" class="view-all-link">
                                View All
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>Event</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentEvents)): ?>
                                    <?php foreach ($recentEvents as $event): ?>
                                    <tr onclick="window.location.href='event_details.php?id=<?php echo $event['id']; ?>'">
                                        <td>
                                            <div class="event-item">
                                                <div class="event-avatar">
                                                    <?php
                                                    $eventIcons = [
                                                        'Meeting' => 'fa-users',
                                                        'Presentation' => 'fa-chart-line',
                                                        'Conference' => 'fa-microphone',
                                                        'Activity' => 'fa-running',
                                                        'Training' => 'fa-graduation-cap',
                                                        'Other' => 'fa-calendar'
                                                    ];
                                                    $icon = $eventIcons[$event['event_type']] ?? 'fa-calendar';
                                                    ?>
                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                </div>
                                                <div class="event-info">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['event_name']); ?></h6>
                                                    <div class="event-meta">
                                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location'] ?? 'No location'); ?></span>
                                                        <?php if ($event['attendee_count'] > 0): ?>
                                                        <span><i class="fas fa-user"></i> <?php echo $event['attendee_count']; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-600"><?php echo date('M j, Y', strtotime($event['start_datetime'])); ?></div>
                                            <small class="text-secondary"><?php echo date('g:i A', strtotime($event['start_datetime'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge-modern <?php echo getStatusBadge($event['status']); ?>">
                                                <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                                <?php echo $event['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-modern">
                                                <a href="event_details.php?id=<?php echo $event['id']; ?>" 
                                                   class="btn-icon" title="View Details" onclick="event.stopPropagation()">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (function_exists('hasRole') && (hasRole('super_admin') || hasRole('admin') || hasRole('pm_manager') || $_SESSION['user_id'] == $event['organizer_id'])): ?>
                                                <a href="edit_event.php?id=<?php echo $event['id']; ?>" 
                                                   class="btn-icon" title="Edit" onclick="event.stopPropagation()">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state-modern">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-calendar-times"></i>
                                                </div>
                                                <h5 class="empty-state-title">No Events Found</h5>
                                                <p class="empty-state-text">Get started by creating your first event</p>
                                                <a href="events.php?action=add" class="btn-create">
                                                    <i class="fas fa-plus-circle"></i>
                                                    Create Event
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Tasks -->
                <div class="col-lg-6 mb-4">
                    <div class="table-modern-container" data-aos="fade-up" data-aos-delay="100">
                        <div class="section-header p-4 pb-0">
                            <h4 class="section-title-modern">Recent Tasks</h4>
                            <a href="tasks.php" class="view-all-link">
                                View All
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="table-responsive">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentTasks)): ?>
                                    <?php foreach ($recentTasks as $task): ?>
                                    <tr onclick="window.location.href='task_details.php?id=<?php echo $task['id']; ?>'">
                                        <td>
                                            <div>
                                                <h6 class="mb-1 fw-600"><?php echo htmlspecialchars($task['task_name']); ?></h6>
                                                <small class="text-secondary">
                                                    <i class="fas fa-calendar-alt me-1"></i>
                                                    <?php echo htmlspecialchars($task['event_name']); ?>
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($task['due_date']): ?>
                                                <?php 
                                                $dueDate = new DateTime($task['due_date']);
                                                $today = new DateTime();
                                                $isOverdue = $dueDate < $today && $task['status'] != 'Completed';
                                                $isDueSoon = !$isOverdue && $today->diff($dueDate)->days <= 3;
                                                ?>
                                                <div class="fw-600 <?php echo $isOverdue ? 'text-danger' : ($isDueSoon ? 'text-warning' : ''); ?>">
                                                    <?php echo date('M j, Y', strtotime($task['due_date'])); ?>
                                                </div>
                                                <?php if ($isOverdue): ?>
                                                <small class="text-danger">
                                                    <i class="fas fa-exclamation-circle"></i> Overdue
                                                </small>
                                                <?php elseif ($isDueSoon): ?>
                                                <small class="text-warning">
                                                    <i class="fas fa-clock"></i> Due soon
                                                </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-secondary">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = [
                                                'Not Started' => 'badge-secondary',
                                                'In Progress' => 'badge-upcoming',
                                                'Completed' => 'badge-completed',
                                                'Cancelled' => 'badge-cancelled'
                                            ];
                                            $statusClass = $statusClass[$task['status']] ?? 'badge-secondary';
                                            ?>
                                            <span class="badge-modern <?php echo $statusClass; ?>">
                                                <i class="fas fa-circle me-1" style="font-size: 8px;"></i>
                                                <?php echo $task['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons-modern">
                                                <a href="task_details.php?id=<?php echo $task['id']; ?>" 
                                                   class="btn-icon" title="View Details" onclick="event.stopPropagation()">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit_task.php?id=<?php echo $task['id']; ?>" 
                                                   class="btn-icon" title="Edit" onclick="event.stopPropagation()">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="4">
                                            <div class="empty-state-modern">
                                                <div class="empty-state-icon">
                                                    <i class="fas fa-tasks"></i>
                                                </div>
                                                <h5 class="empty-state-title">No Tasks Found</h5>
                                                <p class="empty-state-text">Create tasks to keep track of your event preparations</p>
                                                <a href="tasks.php?action=add" class="btn-create">
                                                    <i class="fas fa-plus-circle"></i>
                                                    Create Task
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Calendar Preview -->
            <div class="chart-container-modern" data-aos="fade-up">
                <div class="section-header mb-4">
                    <h4 class="section-title-modern">Event Calendar</h4>
                    <a href="calendar.php" class="view-all-link">
                        Full Calendar
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <div id="calendar"></div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
    // Initialize AOS
    AOS.init({
        duration: 800,
        once: true,
        offset: 50
    });

    document.addEventListener('DOMContentLoaded', function() {
        // Chart.js - Event Status Distribution
        const ctx = document.getElementById('eventStatusChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($statusLabels ?: ['Planning', 'Upcoming', 'Ongoing', 'Completed', 'Cancelled']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($statusCounts ?: [4, 6, 3, 8, 2]); ?>,
                        backgroundColor: [
                            'rgba(255, 183, 77, 0.9)',
                            'rgba(79, 195, 247, 0.9)',
                            'rgba(39, 50, 116, 0.9)',
                            'rgba(102, 187, 106, 0.9)',
                            'rgba(239, 83, 80, 0.9)'
                        ],
                        borderColor: 'transparent',
                        borderWidth: 0,
                        borderRadius: 8,
                        spacing: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                font: {
                                    family: "'Plus Jakarta Sans', sans-serif",
                                    size: 12,
                                    weight: 500
                                },
                                color: 'var(--text-primary)'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'var(--bg-card)',
                            titleColor: 'var(--text-primary)',
                            bodyColor: 'var(--text-secondary)',
                            borderColor: 'var(--border-color)',
                            borderWidth: 1,
                            padding: 12,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // FullCalendar
        const calendarEl = document.getElementById('calendar');
        if (calendarEl) {
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: [
                    {
                        title: 'Board Meeting',
                        start: new Date(),
                        backgroundColor: 'rgba(39, 50, 116, 0.9)',
                        borderColor: 'transparent',
                        id: 1
                    },
                    {
                        title: 'Team Training',
                        start: new Date(new Date().getTime() + 86400000),
                        backgroundColor: 'rgba(102, 187, 106, 0.9)',
                        borderColor: 'transparent',
                        id: 2
                    },
                    {
                        title: 'Client Presentation',
                        start: new Date(new Date().getTime() + 172800000),
                        backgroundColor: 'rgba(79, 195, 247, 0.9)',
                        borderColor: 'transparent',
                        id: 3
                    }
                ],
                eventClick: function(info) {
                    window.location.href = 'event_details.php?id=' + info.event.id;
                },
                height: 'auto',
                aspectRatio: 1.8,
                buttonText: {
                    today: 'Today',
                    month: 'Month',
                    week: 'Week',
                    day: 'Day'
                }
            });
            calendar.render();
        }

        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl, {
                trigger: 'hover'
            });
        });

        // Listen for sidebar toggle to adjust main content
        const sidebarToggle = document.getElementById('sidebarToggleBtn');
        const mainContent = document.getElementById('mainContent');
        const mainHeader = document.getElementById('mainHeader');
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                setTimeout(() => {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar.classList.contains('collapsed')) {
                        mainContent.classList.add('expanded');
                        if (mainHeader) mainHeader.classList.add('expanded');
                    } else {
                        mainContent.classList.remove('expanded');
                        if (mainHeader) mainHeader.classList.remove('expanded');
                    }
                    // Refresh AOS
                    AOS.refresh();
                }, 50);
            });
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const mainHeader = document.getElementById('mainHeader');
            if (window.innerWidth <= 992) {
                if (mainHeader) mainHeader.classList.remove('expanded');
            } else {
                const sidebar = document.getElementById('sidebar');
                if (sidebar && sidebar.classList.contains('collapsed')) {
                    if (mainHeader) mainHeader.classList.add('expanded');
                } else {
                    if (mainHeader) mainHeader.classList.remove('expanded');
                }
            }
            // Refresh AOS
            AOS.refresh();
        });

        // Add hover animations for stat cards
        document.querySelectorAll('.stat-card-modern').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Add click animation for quick actions
        document.querySelectorAll('.quick-action-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (!e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    
                    // Add loading animation
                    this.style.transform = 'scale(0.95)';
                    
                    setTimeout(() => {
                        window.location.href = href;
                    }, 150);
                }
            });
        });
    });

    // Navigation function with animation
    function navigateTo(url) {
        document.body.style.opacity = '0.8';
        setTimeout(() => {
            window.location.href = url;
        }, 200);
    }

    // Dashboard functions
    function refreshDashboard() {
        // Show loading state
        Swal.fire({
            title: 'Refreshing...',
            html: 'Fetching latest data',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        setTimeout(() => {
            location.reload();
        }, 1000);
    }

    function downloadReport() {
        Swal.fire({
            title: 'Download Report',
            text: 'Select report format',
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '<?php echo $custom_colors['primary']; ?>',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'PDF',
            cancelButtonText: 'Excel',
            showDenyButton: true,
            denyButtonText: 'CSV',
            denyButtonColor: '#4FC3F7'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'export_report.php?format=pdf';
            } else if (result.isDenied) {
                window.location.href = 'export_report.php?format=csv';
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                window.location.href = 'export_report.php?format=excel';
            }
        });
    }

    function shareDashboard() {
        Swal.fire({
            title: 'Share Dashboard',
            html: `
                <div class="mb-3">
                    <input type="email" class="form-control" placeholder="Enter email address" id="shareEmail">
                </div>
                <div class="mb-3">
                    <select class="form-control" id="sharePermission">
                        <option value="view">View Only</option>
                        <option value="edit">Can Edit</option>
                        <option value="full">Full Access</option>
                    </select>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '<?php echo $custom_colors['primary']; ?>',
            confirmButtonText: 'Share',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const email = document.getElementById('shareEmail').value;
                const permission = document.getElementById('sharePermission').value;
                
                if (!email) {
                    Swal.showValidationMessage('Please enter an email address');
                    return false;
                }
                
                return { email, permission };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Shared!',
                    text: `Dashboard shared with ${result.value.email}`,
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + N for new event
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            window.location.href = 'events.php?action=add';
        }
        
        // Ctrl + R for refresh
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            refreshDashboard();
        }
        
        // Esc to clear selections
        if (e.key === 'Escape') {
            // Clear any active selections
            document.querySelectorAll('.stat-card-modern.selected').forEach(el => {
                el.classList.remove('selected');
            });
        }
    });

    // Handle theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        const darkMode = e.matches;
        document.body.classList.toggle('dark-mode', darkMode);
        
        // Update charts with new colors
        location.reload(); // Simple approach, could be improved
    });
    </script>
</body>
</html>