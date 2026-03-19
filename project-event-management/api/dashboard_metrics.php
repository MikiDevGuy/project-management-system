<?php
header('Content-Type: application/json; charset=utf-8');

// Attempt to load shared config/functions
$config_path = file_exists(__DIR__ . '/../config/database.php') ? __DIR__ . '/../config/database.php' : __DIR__ . '/../../config/database.php';
if (file_exists($config_path)) require_once $config_path;
$functions_path = file_exists(__DIR__ . '/../config/functions.php') ? __DIR__ . '/../config/functions.php' : __DIR__ . '/../../config/functions.php';
if (file_exists($functions_path)) require_once $functions_path;

// Minimal DB connection fallback
if (!function_exists('getDBConnection')) {
    function getDBConnection() {
        $c = mysqli_connect('localhost', 'root', '', 'project_manager');
        if (!$c) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed']);
            exit;
        }
        mysqli_set_charset($c, 'utf8mb4');
        return $c;
    }
}

$conn = getDBConnection();

$out = [
    'status' => 'ok',
    'data' => []
];

// Event status distribution
$rows = [];
$res = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM events GROUP BY status");
if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[$r['status']] = (int)$r['cnt'];
$out['data']['event_status'] = $rows;

// Recent events
$recent = [];
$res = mysqli_query($conn, "SELECT id,event_name,start_datetime,location,status FROM events ORDER BY start_datetime DESC LIMIT 10");
if ($res) while ($r = mysqli_fetch_assoc($res)) $recent[] = $r;
$out['data']['recent_events'] = $recent;

// Recent tasks
$recentTasks = [];
$res = mysqli_query($conn, "SELECT id,task_name,due_date,status,event_id FROM event_tasks ORDER BY created_at DESC LIMIT 10");
if ($res) while ($r = mysqli_fetch_assoc($res)) $recentTasks[] = $r;
$out['data']['recent_tasks'] = $recentTasks;

// Summary counts
$counts = [];
$q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM events");
$counts['total_events'] = $q ? (int)mysqli_fetch_assoc($q)['total'] : 0;
$q = mysqli_query($conn, "SELECT COUNT(*) AS upcoming FROM events WHERE status='Upcoming' AND start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
$counts['upcoming_events'] = $q ? (int)mysqli_fetch_assoc($q)['upcoming'] : 0;
$q = mysqli_query($conn, "SELECT COUNT(*) AS ongoing FROM events WHERE status='Ongoing'");
$counts['ongoing_events'] = $q ? (int)mysqli_fetch_assoc($q)['ongoing'] : 0;
$q = mysqli_query($conn, "SELECT COUNT(*) AS pending FROM event_tasks WHERE status!='Completed'");
$counts['pending_tasks'] = $q ? (int)mysqli_fetch_assoc($q)['pending'] : 0;
$q = mysqli_query($conn, "SELECT COUNT(*) AS resources FROM event_resources WHERE status!='Delivered'");
$counts['resources_needed'] = $q ? (int)mysqli_fetch_assoc($q)['resources'] : 0;
$q = mysqli_query($conn, "SELECT COUNT(DISTINCT user_id) AS attendees FROM event_attendees");
$counts['total_attendees'] = $q ? (int)mysqli_fetch_assoc($q)['attendees'] : 0;
$out['data']['counts'] = $counts;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
