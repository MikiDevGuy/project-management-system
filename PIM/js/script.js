// script.js - JavaScript for Issue Tracker

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            var bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Confirm before deleting
    var deleteButtons = document.querySelectorAll('.btn-delete');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });

    // Status update handling
    var statusSelects = document.querySelectorAll('.status-select');
    statusSelects.forEach(function(select) {
        select.addEventListener('change', function() {
            var form = this.closest('form');
            if (form) {
                form.submit();
            }
        });
    });

    // File input preview
    var fileInputs = document.querySelectorAll('.file-input');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            var fileName = this.files[0]?.name || 'No file chosen';
            var label = this.nextElementSibling;
            if (label && label.classList.contains('file-label')) {
                label.textContent = fileName;
            }
        });
    });

    // Toggle sidebar on mobile
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
        });
    }

    // Real-time notification check
    function checkNotifications() {
        fetch('check_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.unread > 0) {
                    updateNotificationBadge(data.unread);
                }
            })
            .catch(error => console.error('Error checking notifications:', error));
    }

    // Check notifications every 30 seconds
    setInterval(checkNotifications, 30000);

    function updateNotificationBadge(count) {
        var badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.textContent = count;
            badge.classList.remove('d-none');
        }
    }
});

// Utility function for API calls
function apiCall(url, options = {}) {
    return fetch(url, {
        headers: {
            'Content-Type': 'application/json',
            ...options.headers
        },
        ...options
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('API request failed');
        }
        return response.json();
    });
}

// Format date for display
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}

// Debounce function for search inputs
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}