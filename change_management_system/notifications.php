<?php
// notifications.php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['system_role'] ?? '';

// Function to get notifications with detailed information
function getNotifications($conn, $user_id) {
    $notifications = [];
    
    // Query to get all notifications from the notifications table
    $sql = "SELECT n.*, 
                   u.username as sender_name,
                   u.system_role as sender_role,
                   cr.change_title,
                   cr.priority,
                   cr.status as current_status
            FROM notifications n
            LEFT JOIN users u ON u.id = n.related_user_id
            LEFT JOIN change_requests cr ON cr.change_request_id = n.related_id AND n.related_module = 'change_request'
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 50";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Parse metadata JSON if it exists
            $metadata = [];
            if (!empty($row['metadata'])) {
                $metadata = json_decode($row['metadata'], true);
            }
            
            $notifications[] = [
                'id' => $row['id'],
                'title' => $row['title'] ?: 'Notification',
                'message' => $row['message'],
                'type' => $row['type'] ?: 'info',
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'related_module' => $row['related_module'],
                'related_id' => $row['related_id'],
                'sender_name' => $row['sender_name'],
                'sender_role' => $row['sender_role'],
                'change_title' => $row['change_title'],
                'priority' => $row['priority'],
                'current_status' => $row['current_status'],
                'metadata' => $metadata
            ];
        }
        $stmt->close();
    }
    
    return $notifications;
}

// Function to mark notification as read
function markNotificationAsRead($conn, $notification_id, $user_id) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $notification_id, $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    return false;
}

// Function to mark all notifications as read
function markAllNotificationsAsRead($conn, $user_id) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }
    return false;
}

// Function to get unread notification count
function getUnreadCount($conn, $user_id) {
    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $count = 0;
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $count = $row['count'];
        }
        $stmt->close();
    }
    return $count;
}

// Function to format time
function timeAgo($datetime) {
    if (empty($datetime)) return 'Recently';
    
    $time = strtotime($datetime);
    if ($time === false) return 'Recently';
    
    $time_difference = time() - $time;

    if ($time_difference < 1) { return 'just now'; }
    $condition = array( 
        12 * 30 * 24 * 60 * 60 =>  'year',
        30 * 24 * 60 * 60       =>  'month',
        24 * 60 * 60            =>  'day',
        60 * 60                 =>  'hour',
        60                      =>  'minute',
        1                       =>  'second'
    );

    foreach ($condition as $secs => $str) {
        $d = $time_difference / $secs;

        if ($d >= 1) {
            $t = round($d);
            return $t . ' ' . $str . ($t > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

// Function to format change details
function formatChangeDetails($notification) {
    $html = '';
    
    if (!empty($notification['metadata'])) {
        $metadata = $notification['metadata'];
        
        $html .= '<div class="change-details mt-3 p-3 bg-light rounded">';
        $html .= '<h6 class="mb-2"><i class="fas fa-history me-2"></i>Change Details:</h6>';
        
        // Old status to New status
        if (isset($metadata['old_status']) && isset($metadata['new_status'])) {
            $html .= '<div class="status-change mb-2">';
            $html .= '<div class="d-flex align-items-center">';
            $html .= '<span class="badge bg-secondary me-2">' . htmlspecialchars($metadata['old_status']) . '</span>';
            $html .= '<i class="fas fa-arrow-right text-muted mx-2"></i>';
            $html .= '<span class="badge ' . getStatusBadgeClass($metadata['new_status']) . '">' . htmlspecialchars($metadata['new_status']) . '</span>';
            $html .= '</div></div>';
        }
        
        // Action performed
        if (isset($metadata['action'])) {
            $html .= '<div class="action-info mb-2">';
            $html .= '<strong>Action:</strong> ';
            $html .= '<span class="badge ' . getActionBadgeClass($metadata['action']) . '">';
            $html .= htmlspecialchars($metadata['action']);
            $html .= '</span></div>';
        }
        
        // Performed by user
        if (isset($metadata['performed_by']) || isset($notification['sender_name'])) {
            $html .= '<div class="performer-info mb-2">';
            $html .= '<strong>Performed by:</strong> ';
            $userName = $metadata['performed_by'] ?? $notification['sender_name'] ?? 'System';
            $userRole = $metadata['performer_role'] ?? $notification['sender_role'] ?? '';
            
            $html .= '<span class="fw-medium">' . htmlspecialchars($userName) . '</span>';
            if ($userRole) {
                $html .= ' <span class="badge bg-info">' . htmlspecialchars($userRole) . '</span>';
            }
            $html .= '</div>';
        }
        
        // Additional details
        if (isset($metadata['change_request_id'])) {
            $html .= '<div class="request-info mb-2">';
            $html .= '<strong>Change Request:</strong> #' . htmlspecialchars($metadata['change_request_id']);
            if (!empty($notification['change_title'])) {
                $html .= ' - ' . htmlspecialchars($notification['change_title']);
            }
            $html .= '</div>';
        }
        
        // Priority if available
        if (!empty($notification['priority'])) {
            $html .= '<div class="priority-info mb-2">';
            $html .= '<strong>Priority:</strong> ';
            $html .= '<span class="badge ' . getPriorityBadgeClass($notification['priority']) . '">';
            $html .= htmlspecialchars($notification['priority']);
            $html .= '</span></div>';
        }
        
        // Comments if available
        if (isset($metadata['comments']) && !empty($metadata['comments'])) {
            $html .= '<div class="comments-info">';
            $html .= '<strong>Comments:</strong>';
            $html .= '<div class="mt-1 p-2 bg-white rounded border">';
            $html .= htmlspecialchars($metadata['comments']);
            $html .= '</div></div>';
        }
        
        $html .= '</div>';
    }
    
    return $html;
}

// Helper function for status badge classes
function getStatusBadgeClass($status) {
    switch(strtolower($status)) {
        case 'open': return 'bg-warning text-dark';
        case 'approved': return 'bg-success';
        case 'in progress': return 'bg-info';
        case 'implemented': return 'bg-primary';
        case 'rejected': return 'bg-danger';
        case 'terminated': return 'bg-dark';
        default: return 'bg-secondary';
    }
}

// Helper function for action badge classes
function getActionBadgeClass($action) {
    switch(strtolower($action)) {
        case 'approved': return 'bg-success';
        case 'rejected': return 'bg-danger';
        case 'implementation started': 
        case 'started implementation': 
            return 'bg-info';
        case 'implemented': 
        case 'completed': 
            return 'bg-primary';
        case 'terminated': return 'bg-dark';
        default: return 'bg-secondary';
    }
}

// Helper function for priority badge classes
function getPriorityBadgeClass($priority) {
    switch(strtolower($priority)) {
        case 'high': 
        case 'urgent': 
            return 'bg-danger';
        case 'medium': return 'bg-warning text-dark';
        case 'low': return 'bg-success';
        default: return 'bg-secondary';
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'get_notifications') {
        $notifications = getNotifications($conn, $user_id);
        // Format notifications for display
        $formatted = [];
        foreach ($notifications as $notification) {
            $formatted[] = [
                'id' => $notification['id'],
                'title' => $notification['title'],
                'message' => $notification['message'],
                'time' => timeAgo($notification['created_at']),
                'type' => $notification['type'],
                'read' => $notification['is_read'],
                'related_id' => $notification['related_id'],
                'sender_name' => $notification['sender_name'],
                'sender_role' => $notification['sender_role'],
                'change_title' => $notification['change_title'],
                'priority' => $notification['priority'],
                'current_status' => $notification['current_status'],
                'metadata' => $notification['metadata'],
                'change_details_html' => formatChangeDetails($notification)
            ];
        }
        echo json_encode($formatted);
        exit();
        
    } elseif ($action === 'mark_as_read' && isset($_POST['notification_id'])) {
        $success = markNotificationAsRead($conn, $_POST['notification_id'], $user_id);
        echo json_encode(['success' => $success]);
        exit();
        
    } elseif ($action === 'mark_all_read') {
        $success = markAllNotificationsAsRead($conn, $user_id);
        echo json_encode(['success' => $success]);
        exit();
        
    } elseif ($action === 'get_count') {
        $count = getUnreadCount($conn, $user_id);
        echo json_encode(['count' => $count]);
        exit();
        
    } else {
        echo json_encode(['error' => 'Invalid action']);
        exit();
    }
}

// For debugging - check if we can connect to database
if (!$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --dashen-primary: #273274;
            --dashen-accent: #f58220;
            --dashen-success: #28a745;
            --dashen-warning: #ffc107;
            --dashen-danger: #dc3545;
            --dashen-info: #17a2b8;
        }
        
        .notification-item {
            transition: all 0.3s ease;
            border-radius: 8px;
            margin-bottom: 10px;
            padding: 15px;
            background: #fff;
            border-left: 4px solid transparent;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .notification-item.unread {
            background: #f8f9fa;
            border-left-color: var(--dashen-accent);
        }
        
        .notification-item:hover {
            background: #e9ecef;
            transform: translateX(2px);
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 1.2rem;
        }
        
        .notification-icon.success {
            background: rgba(40, 167, 69, 0.1);
            color: var(--dashen-success);
        }
        
        .notification-icon.warning {
            background: rgba(255, 193, 7, 0.1);
            color: var(--dashen-warning);
        }
        
        .notification-icon.danger {
            background: rgba(220, 53, 69, 0.1);
            color: var(--dashen-danger);
        }
        
        .notification-icon.info {
            background: rgba(23, 162, 184, 0.1);
            color: var(--dashen-info);
        }
        
        .notification-icon.primary {
            background: rgba(39, 50, 116, 0.1);
            color: var(--dashen-primary);
        }
        
        .btn-mark-read {
            padding: 4px 8px;
            font-size: 0.8rem;
        }
        
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .change-details {
            border: 1px solid #dee2e6;
            font-size: 0.9rem;
        }
        
        .change-details h6 {
            font-size: 0.95rem;
            color: #495057;
        }
        
        .toggle-details {
            font-size: 0.8rem;
            padding: 2px 8px;
        }
        
        .badge {
            font-size: 0.75em;
            font-weight: 500;
        }
        
        .status-badge {
            min-width: 80px;
            text-align: center;
        }
        
        .modal-backdrop {
            z-index: 1040 !important;
        }
        
        .modal {
            z-index: 1050 !important;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-bell text-primary me-2"></i>
                Notifications
            </h1>
            <div>
                <button class="btn btn-outline-primary me-2" onclick="refreshNotifications()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
                <button class="btn btn-primary" onclick="markAllAsRead()">
                    <i class="fas fa-check-double me-1"></i> Mark All Read
                </button>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="card">
                    <div class="card-body p-0">
                        <div id="notificationsList">
                            <div class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading notifications...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading your notifications...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detail View Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailModalTitle">
                        <i class="fas fa-info-circle me-2"></i>Change Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="detailModalBody">
                    Loading details...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="markCurrentAsRead()">
                        <i class="fas fa-check me-1"></i> Mark as Read
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentNotificationId = null;
    
    document.addEventListener('DOMContentLoaded', function() {
        loadNotifications();
        
        // Close modal when clicking outside
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                const modal = bootstrap.Modal.getInstance(this);
                if (modal) modal.hide();
            }
        });
    });
    
    function loadNotifications() {
        fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_notifications'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(notifications => {
            if (notifications.error) {
                showError(notifications.error);
            } else {
                displayNotifications(notifications);
            }
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
            showError('Failed to load notifications: ' + error.message);
        });
    }
    
    function displayNotifications(notifications) {
        const container = document.getElementById('notificationsList');
        
        if (!notifications || notifications.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h4>No Notifications</h4>
                    <p>You're all caught up! No new notifications.</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        notifications.forEach(notification => {
            const iconClass = getIconClass(notification.type);
            const iconBgClass = notification.type || 'primary';
            const hasDetails = notification.metadata && Object.keys(notification.metadata).length > 0;
            
            html += `
                <div class="notification-item ${notification.read ? '' : 'unread'}" id="notification-${notification.id}">
                    <div class="d-flex align-items-start">
                        <div class="notification-icon ${iconBgClass} me-3 position-relative">
                            <i class="fas ${iconClass}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="mb-1 ${notification.read ? 'text-muted' : 'fw-bold'}">${notification.title}</h6>
                                <small class="text-muted"><i class="far fa-clock me-1"></i>${notification.time}</small>
                            </div>
                            <p class="mb-2">${notification.message}</p>
                            
                            ${notification.sender_name ? `
                                <div class="sender-info mb-2">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        From: ${notification.sender_name}
                                        ${notification.sender_role ? `<span class="badge bg-info ms-1">${notification.sender_role}</span>` : ''}
                                    </small>
                                </div>
                            ` : ''}
                            
                            ${notification.change_title ? `
                                <div class="request-info mb-2">
                                    <small class="text-muted">
                                        <i class="fas fa-file-alt me-1"></i>
                                        Request: ${notification.change_title}
                                        ${notification.related_id ? `<span class="badge bg-secondary ms-1">#${notification.related_id}</span>` : ''}
                                    </small>
                                </div>
                            ` : ''}
                            
                            ${notification.current_status ? `
                                <div class="status-info mb-2">
                                    <small class="text-muted">
                                        <i class="fas fa-flag me-1"></i>
                                        Current Status: 
                                        <span class="badge ${getStatusBadgeClass(notification.current_status)} ms-1">
                                            ${notification.current_status}
                                        </span>
                                    </small>
                                </div>
                            ` : ''}
                            
                            ${hasDetails ? `
                                <div class="action-buttons mt-2">
                                    <button class="btn btn-sm btn-outline-primary toggle-details me-2" onclick="toggleDetails(${notification.id})">
                                        <i class="fas fa-chevron-down me-1"></i> Show Details
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="viewDetails(${notification.id})" data-bs-toggle="modal" data-bs-target="#detailModal">
                                        <i class="fas fa-expand me-1"></i> Full View
                                    </button>
                                </div>
                                
                                <div class="change-details mt-3" id="details-${notification.id}" style="display: none;">
                                    ${notification.change_details_html}
                                </div>
                            ` : ''}
                        </div>
                        ${!notification.read ? `
                        <div class="flex-shrink-0 ms-2">
                            <button class="btn btn-sm btn-outline-primary btn-mark-read" onclick="markAsRead(${notification.id})" title="Mark as read">
                                <i class="fas fa-check"></i>
                            </button>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }
    
    function getIconClass(type) {
        switch(type) {
            case 'success': return 'fa-check-circle';
            case 'warning': return 'fa-exclamation-triangle';
            case 'danger': return 'fa-times-circle';
            case 'info': return 'fa-info-circle';
            default: return 'fa-bell';
        }
    }
    
    function getStatusBadgeClass(status) {
        switch(status.toLowerCase()) {
            case 'open': return 'bg-warning text-dark';
            case 'approved': return 'bg-success';
            case 'in progress': return 'bg-info';
            case 'implemented': return 'bg-primary';
            case 'rejected': return 'bg-danger';
            case 'terminated': return 'bg-dark';
            default: return 'bg-secondary';
        }
    }
    
    function toggleDetails(notificationId) {
        const detailsDiv = document.getElementById(`details-${notificationId}`);
        const toggleBtn = detailsDiv.previousElementSibling.querySelector('.toggle-details');
        
        if (detailsDiv.style.display === 'none' || !detailsDiv.style.display) {
            detailsDiv.style.display = 'block';
            toggleBtn.innerHTML = '<i class="fas fa-chevron-up me-1"></i> Hide Details';
        } else {
            detailsDiv.style.display = 'none';
            toggleBtn.innerHTML = '<i class="fas fa-chevron-down me-1"></i> Show Details';
        }
    }
    
    function viewDetails(notificationId) {
        currentNotificationId = notificationId;
        
        // Find the notification data
        const notificationItem = document.getElementById(`notification-${notificationId}`);
        if (!notificationItem) return;
        
        // Extract data from the notification item
        const title = notificationItem.querySelector('h6').textContent;
        const message = notificationItem.querySelector('p').textContent;
        const time = notificationItem.querySelector('small.text-muted').textContent;
        
        // Get change details HTML
        const detailsDiv = document.getElementById(`details-${notificationId}`);
        const changeDetails = detailsDiv ? detailsDiv.innerHTML : '';
        
        // Build modal content
        const modalContent = `
            <div class="notification-details">
                <div class="alert alert-info">
                    <h5 class="alert-heading">${title}</h5>
                    <p class="mb-0">${message}</p>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6><i class="fas fa-calendar me-2"></i>Timeline</h6>
                                <p class="mb-0"><strong>Time:</strong> ${time}</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-body">
                                <h6><i class="fas fa-tag me-2"></i>Status</h6>
                                ${notificationItem.querySelector('.status-info')?.outerHTML || '<p class="mb-0 text-muted">No status available</p>'}
                            </div>
                        </div>
                    </div>
                </div>
                
                ${changeDetails || '<div class="alert alert-warning">No detailed change information available.</div>'}
            </div>
        `;
        
        document.getElementById('detailModalTitle').innerHTML = `<i class="fas fa-info-circle me-2"></i>${title}`;
        document.getElementById('detailModalBody').innerHTML = modalContent;
    }
    
    function markAsRead(notificationId) {
        fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=mark_as_read&notification_id=${notificationId}`
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                const item = document.getElementById(`notification-${notificationId}`);
                if (item) {
                    item.classList.remove('unread');
                    item.querySelector('h6').classList.remove('fw-bold');
                    item.querySelector('h6').classList.add('text-muted');
                    item.querySelector('.btn-mark-read')?.remove();
                    
                    // Update notification count in parent window
                    if (parent.updateNotificationBadge) {
                        parent.updateNotificationBadge();
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error marking notification as read:', error);
            alert('Failed to mark notification as read');
        });
    }
    
    function markCurrentAsRead() {
        if (currentNotificationId) {
            markAsRead(currentNotificationId);
            const modal = bootstrap.Modal.getInstance(document.getElementById('detailModal'));
            if (modal) modal.hide();
        }
    }
    
    function markAllAsRead() {
        if (!confirm('Mark all notifications as read? This will close all notification modals.')) return;
        
        fetch('notifications.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=mark_all_read'
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                // Update all UI notifications
                document.querySelectorAll('.notification-item.unread').forEach(item => {
                    item.classList.remove('unread');
                    const heading = item.querySelector('h6');
                    heading.classList.remove('fw-bold');
                    heading.classList.add('text-muted');
                    item.querySelector('.btn-mark-read')?.remove();
                });
                
                // Close all modals
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                });
                
                // Remove modal backdrops
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                    backdrop.remove();
                });
                
                // Update notification count in parent window
                if (parent.updateNotificationBadge) {
                    parent.updateNotificationBadge();
                }
                
                // Remove body modal-open class
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
                
                alert('All notifications marked as read and modals closed.');
            }
        })
        .catch(error => {
            console.error('Error marking all as read:', error);
            alert('Failed to mark all notifications as read');
        });
    }
    
    function refreshNotifications() {
        loadNotifications();
    }
    
    function showError(message) {
        const container = document.getElementById('notificationsList');
        container.innerHTML = `
            <div class="alert alert-danger m-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${message}
                <button class="btn btn-sm btn-outline-danger ms-2" onclick="loadNotifications()">Retry</button>
            </div>
        `;
    }
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>