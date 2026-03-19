<?php
// api/get_change_requests.php
require_once '../db.php';

// Get parameters for pagination and filtering
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : '';
$priority = isset($_GET['priority']) ? $_GET['priority'] : '';
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : '';

// Build the query with filters
$query = "SELECT cr.*, p.name as project_name, u.username as requester_name
          FROM change_requests cr
          JOIN projects p ON cr.project_id = p.id
          JOIN users u ON cr.requester_id = u.id
          WHERE 1=1";

$params = [];
$types = '';

// Add role-based filtering
if (!empty($user_id)) {
    $query .= " AND cr.requester_id = ?";
    $params[] = $user_id;
    $types .= 'i';
}

if (!empty($status)) {
    $query .= " AND cr.status = ?";
    $params[] = $status;
    $types .= 's';
}

if (!empty($priority)) {
    $query .= " AND cr.priority = ?";
    $params[] = $priority;
    $types .= 's';
}

if (!empty($project_id)) {
    $query .= " AND cr.project_id = ?";
    $params[] = $project_id;
    $types .= 'i';
}

if (!empty($search)) {
    $query .= " AND (cr.change_title LIKE ? OR cr.change_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$query .= " ORDER BY cr.request_date DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// Execute the query
$stmt = $conn->prepare($query);

// Bind parameters
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$countQuery = "SELECT COUNT(*) as total FROM change_requests cr WHERE 1=1";
$countParams = [];
$countTypes = '';

// Add role-based filtering
if (!empty($user_id)) {
    $countQuery .= " AND cr.requester_id = ?";
    $countParams[] = $user_id;
    $countTypes .= 'i';
}

if (!empty($status)) {
    $countQuery .= " AND cr.status = ?";
    $countParams[] = $status;
    $countTypes .= 's';
}

if (!empty($priority)) {
    $countQuery .= " AND cr.priority = ?";
    $countParams[] = $priority;
    $countTypes .= 's';
}

if (!empty($project_id)) {
    $countQuery .= " AND cr.project_id = ?";
    $countParams[] = $project_id;
    $countTypes .= 'i';
}

if (!empty($search)) {
    $countQuery .= " AND (cr.change_title LIKE ? OR cr.change_description LIKE ?)";
    $countParams[] = "%$search%";
    $countParams[] = "%$search%";
    $countTypes .= 'ss';
}

$countStmt = $conn->prepare($countQuery);

// Bind count parameters
if (!empty($countParams)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$total = $countResult->fetch_assoc()['total'];

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'requests' => $requests,
    'total_requests' => $total
]);
?>