<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

include 'db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'];
$role = $_SESSION['system_role'];

// Handle preflight request
if ($method == 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    switch ($method) {
        case 'GET':
            getMilestones();
            break;
        case 'POST':
            createMilestone();
            break;
        case 'PUT':
            updateMilestone();
            break;
        case 'DELETE':
            deleteMilestone();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

function getMilestones() {
    global $conn, $user_id, $role;
    
    $milestone_id = $_GET['id'] ?? null;
    $project_id = $_GET['project_id'] ?? null;
    $phase_id = $_GET['phase_id'] ?? null;
    $activity_id = $_GET['activity_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    $query = "SELECT m.*, p.name as project_name, ph.name as phase_name, a.name as activity_name 
              FROM milestones m 
              LEFT JOIN projects p ON m.project_id = p.id 
              LEFT JOIN phases ph ON m.phase_id = ph.id 
              LEFT JOIN activities a ON m.activity_id = a.id 
              WHERE 1=1";
    
    $params = [];
    $types = "";
    
    if ($milestone_id) {
        $query .= " AND m.id = ?";
        $params[] = $milestone_id;
        $types .= "i";
    }
    
    if ($project_id) {
        $query .= " AND m.project_id = ?";
        $params[] = $project_id;
        $types .= "i";
    }
    
    if ($phase_id) {
        $query .= " AND m.phase_id = ?";
        $params[] = $phase_id;
        $types .= "i";
    }
    
    if ($activity_id) {
        $query .= " AND m.activity_id = ?";
        $params[] = $activity_id;
        $types .= "i";
    }
    
    if ($status) {
        $query .= " AND m.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    
    // Add permission check for non-super admins
    if ($role !== 'super_admin') {
        $query .= " AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = m.project_id AND pu.user_id = ?)";
        $params[] = $user_id;
        $types .= "i";
    }
    
    $query .= " ORDER BY m.target_date ASC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $milestones = $result->fetch_all(MYSQLI_ASSOC);
    
    // If requesting a specific milestone by ID, return it directly
    if ($milestone_id && count($milestones) > 0) {
        echo json_encode(['success' => true, 'data' => $milestones[0]]);
    } else {
        echo json_encode(['success' => true, 'data' => $milestones]);
    }
    $stmt->close();
}
function createMilestone() {
    global $conn, $user_id, $role;
    
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    // Validate required fields
    if (!isset($input['project_id']) || !isset($input['name']) || !isset($input['target_date'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: project_id, name, and target_date are required']);
        return;
    }
    
    $project_id = $input['project_id'];
    $phase_id = $input['phase_id'] ?? null;
    $activity_id = $input['activity_id'] ?? null;
    $name = trim($input['name']);
    $description = trim($input['description'] ?? '');
    $target_date = $input['target_date'];
    
    // Validate target date
    if (!strtotime($target_date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid target date format']);
        return;
    }
    
    // Check permissions
    if ($role !== 'super_admin') {
        $check_stmt = $conn->prepare("SELECT 1 FROM project_users WHERE project_id = ? AND user_id = ?");
        $check_stmt->bind_param("ii", $project_id, $user_id);
        $check_stmt->execute();
        $has_access = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$has_access) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this project']);
            return;
        }
    }
    
    // Validate phase_id and activity_id belong to the project
    if ($phase_id) {
        $check_stmt = $conn->prepare("SELECT 1 FROM phases WHERE id = ? AND project_id = ?");
        $check_stmt->bind_param("ii", $phase_id, $project_id);
        $check_stmt->execute();
        $valid_phase = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$valid_phase) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid phase_id for this project']);
            return;
        }
    }
    
    if ($activity_id) {
        $check_stmt = $conn->prepare("SELECT 1 FROM activities a JOIN phases p ON a.phase_id = p.id WHERE a.id = ? AND p.project_id = ?");
        $check_stmt->bind_param("ii", $activity_id, $project_id);
        $check_stmt->execute();
        $valid_activity = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        
        if (!$valid_activity) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid activity_id for this project']);
            return;
        }
    }
    
    // Determine initial status based on target date
    $status = 'pending';
    $today = date('Y-m-d');
    if ($target_date < $today) {
        $status = 'delayed';
    }
    
    $stmt = $conn->prepare("INSERT INTO milestones (project_id, phase_id, activity_id, name, description, target_date, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("iiissss", 
        $project_id,
        $phase_id,
        $activity_id,
        $name,
        $description,
        $target_date,
        $status
    );
    
    if ($stmt->execute()) {
        $milestone_id = $stmt->insert_id;
        
        // Fetch the created milestone with related data
        $fetch_stmt = $conn->prepare("SELECT m.*, p.name as project_name, ph.name as phase_name, a.name as activity_name 
                                     FROM milestones m 
                                     LEFT JOIN projects p ON m.project_id = p.id 
                                     LEFT JOIN phases ph ON m.phase_id = ph.id 
                                     LEFT JOIN activities a ON m.activity_id = a.id 
                                     WHERE m.id = ?");
        $fetch_stmt->bind_param("i", $milestone_id);
        $fetch_stmt->execute();
        $result = $fetch_stmt->get_result();
        $milestone = $result->fetch_assoc();
        $fetch_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Milestone created successfully', 'data' => $milestone]);
    } else {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();
}

function updateMilestone() {
    global $conn, $user_id, $role;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    $milestone_id = $input['id'] ?? null;
    
    if (!$milestone_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Milestone ID required']);
        return;
    }
    
    // Check permissions
    $check_stmt = $conn->prepare("SELECT m.project_id FROM milestones m 
                                 WHERE m.id = ? AND (? = 'super_admin' OR 
                                 EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = m.project_id AND pu.user_id = ?))");
    $check_stmt->bind_param("isi", $milestone_id, $role, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $updates = [];
    $params = [];
    $types = "";
    
    $allowed_fields = ['name', 'description', 'target_date', 'status', 'achieved_date'];
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
            $types .= "s";
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }
    
    $params[] = $milestone_id;
    $types .= "i";
    
    $query = "UPDATE milestones SET " . implode(", ", $updates) . ", updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Milestone updated successfully']);
    } else {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();
}

function deleteMilestone() {
    global $conn, $user_id, $role;
    
    $milestone_id = $_GET['id'] ?? null;
    
    if (!$milestone_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Milestone ID required']);
        return;
    }
    
    // Check permissions
    $check_stmt = $conn->prepare("SELECT m.project_id FROM milestones m 
                                 WHERE m.id = ? AND (? = 'super_admin' OR 
                                 EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = m.project_id AND pu.user_id = ?))");
    $check_stmt->bind_param("isi", $milestone_id, $role, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM milestones WHERE id = ?");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $milestone_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Milestone deleted successfully']);
    } else {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $stmt->close();
}
?>