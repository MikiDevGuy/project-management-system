// js/change_management.js
document.addEventListener('DOMContentLoaded', () => {
    let currentPage = 1;
    const rowsPerPage = 10;
    const viewModal = new bootstrap.Modal(document.getElementById('viewChangeRequestModal'));
    const editModal = new bootstrap.Modal(document.getElementById('editChangeRequestModal'));
    const notificationsModal = new bootstrap.Modal(document.getElementById('notificationsModal'));

    // Load change requests with role-based filtering
    async function loadChangeRequests(page) {
        const offset = (page - 1) * rowsPerPage;
        const statusFilter = document.getElementById('statusFilter').value;
        const priorityFilter = document.getElementById('priorityFilter').value;
        const projectFilter = document.getElementById('projectFilter').value;
        const searchInput = document.getElementById('searchInput').value;

        let url = `api/get_change_requests.php?limit=${rowsPerPage}&offset=${offset}`;

        // Add role-based filtering
        if (userRole !== 'super_admin' && userRole !== 'pm_manager') {
            url += `&user_id=${userId}`;
        }

        if (statusFilter) url += `&status=${statusFilter}`;
        if (priorityFilter) url += `&priority=${priorityFilter}`;
        if (projectFilter) url += `&project_id=${projectFilter}`;
        if (searchInput) url += `&search=${encodeURIComponent(searchInput)}`;

        // Show loading state
        const tableBody = document.getElementById('changeRequestsTableBody');
        tableBody.innerHTML = '<tr><td colspan="8" class="text-center">Loading...</td></tr>';

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (data.status === 'success') {
                tableBody.innerHTML = data.requests.map(request => `
                    <tr>
                        <td>${request.change_request_id}</td>
                        <td>${request.project_name}</td>
                        <td>${request.change_title}</td>
                        <td>${request.requester_name}</td>
                        <td><span class="badge ${getStatusClass(request.status)}">${request.status}</span></td>
                        <td><span class="badge ${getPriorityClass(request.priority)}">${request.priority}</span></td>
                        <td>${new Date(request.request_date).toLocaleDateString()}</td>
                        <td>
                            <button class="btn btn-sm btn-info view-btn" data-id="${request.change_request_id}">View</button>
                            ${(userRole === 'Admin' || userRole === 'Project Manager' || userId == request.requester_id) ?
                                `<button class="btn btn-sm btn-warning edit-btn" data-id="${request.change_request_id}">Edit</button>` : ''}
                            ${(userRole === 'Admin' || userRole === 'Project Manager') ?
                                `<button class="btn btn-sm btn-danger delete-btn" data-id="${request.change_request_id}">Delete</button>` : ''}
                            ${(userRole === 'Admin' || userRole === 'Project Manager') && (request.status === 'In Progress' || request.status === 'Approved') ?
                             `<button class="btn btn-sm btn-success complete-btn" data-id="${request.change_request_id}">Complete</button>` : ''}
                        </td>
                    </tr>
                `).join('');

                renderPagination(data.total_requests, page);
                updateDashboardStats(data.requests);
            } else {
                tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Error loading data</td></tr>';
                alert('Error loading change requests: ' + data.message);
            }
        } catch (error) {
            console.error('Error loading change requests:', error);
            tableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Failed to load data</td></tr>';
            alert('Failed to load change requests. Please check your connection and try again.');
        }
    }


// Add completeChangeRequest function
async function completeChangeRequest(id) {
    if (confirm('Mark this change request as completed?')) {
        try {
            const formData = new FormData();
            formData.append('change_request_id', id);
            formData.append('status', 'Implemented');
            
            const response = await fetch('api/update_change_request_status.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                alert('Change request marked as completed!');
                loadChangeRequests(currentPage);
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error completing change request:', error);
            alert('Error completing change request');
        }
    }
}

    function updateDashboardStats(requests) {
        const total = requests.length;
        const approved = requests.filter(r => r.status === 'Approved').length;
        const inProgress = requests.filter(r => r.status === 'In Progress').length;
        const pending = requests.filter(r => r.status === 'Open').length;
        
        document.querySelectorAll('.stat-number')[0].textContent = total;
        document.querySelectorAll('.stat-number')[1].textContent = approved;
        document.querySelectorAll('.stat-number')[2].textContent = inProgress;
        document.querySelectorAll('.stat-number')[3].textContent = pending;
    }

    function getStatusClass(status) {
        switch (status) {
            case 'Open': return 'bg-secondary';
            case 'In Progress': return 'bg-primary';
            case 'Approved': return 'bg-success';
            case 'Rejected': return 'bg-danger';
            case 'Implemented': return 'bg-info';
            case 'Terminated': return 'bg-dark';
            default: return 'bg-secondary';
        }
    }

    function getPriorityClass(priority) {
        switch (priority) {
            case 'Low': return 'bg-secondary';
            case 'Medium': return 'bg-info';
            case 'High': return 'bg-warning text-dark';
            case 'Urgent': return 'bg-danger';
            default: return 'bg-secondary';
        }
    }

    function renderPagination(totalItems, currentPage) {
        const totalPages = Math.ceil(totalItems / rowsPerPage);
        const paginationContainer = document.getElementById('pagination-controls');
        
        let html = '';
        
        if (currentPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a></li>`;
        }
        
        for (let i = 1; i <= totalPages; i++) {
            html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                        <a class="page-link" href="#" data-page="${i}">${i}</a>
                     </li>`;
        }
        
        if (currentPage < totalPages) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage + 1}">Next</a></li>`;
        }
        
        paginationContainer.innerHTML = html;
        
        // Add event listeners to pagination links
        paginationContainer.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = parseInt(e.target.dataset.page);
                currentPage = page;
                loadChangeRequests(page);
            });
        });
    }

    async function viewChangeRequest(id) {
        try {
            const response = await fetch(`api/get_change_request_details.php?id=${id}`);
            const data = await response.json();

            if (data.status === 'success') {
                const request = data.request;
                // Fetch comments for this request
            const commentsResponse = await fetch(`api/get_comments.php?change_request_id=${id}`);
            const commentsData = await commentsResponse.json();
            
            let commentsHTML = '';
            if (commentsData.status === 'success' && commentsData.comments.length > 0) {
                commentsHTML = `
                    <div class="card mt-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Comments & Discussion</h6>
                        </div>
                        <div class="card-body">
                            ${commentsData.comments.map(comment => `
                                <div class="comment mb-3 ${comment.is_internal ? 'internal-comment' : ''}">
                                    <div class="comment-header">
                                        <strong>${comment.username}</strong> 
                                        on ${new Date(comment.comment_date).toLocaleString()}
                                        ${comment.is_internal ? '<span class="badge bg-warning float-end">Internal</span>' : ''}
                                    </div>
                                    <div class="comment-text">${comment.comment_text}</div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            } else {
                commentsHTML = '<div class="alert alert-info mt-3">No comments yet.</div>';
            }

                const body = document.getElementById('viewChangeRequestBody');
                body.innerHTML = `
                    <div class="row mb-4">
                        <div class="col-md-8">
                            <h4>${request.change_title}</h4>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span class="badge ${getStatusClass(request.status)}">${request.status}</span>
                                <span class="badge ${getPriorityClass(request.priority)}">${request.priority}</span>
                                <span class="badge bg-secondary">${request.change_type || 'N/A'}</span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <p class="mb-0"><strong>Request ID:</strong> ${request.change_request_id}</p>
                            <p class="mb-0"><strong>Date Submitted:</strong> ${new Date(request.request_date).toLocaleDateString()}</p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Basic Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Project:</strong> ${request.project_name}</p>
                                    <p><strong>Requester:</strong> ${request.requester_name}</p>
                                    <p><strong>Area of Impact:</strong> ${request.area_of_impact || 'N/A'}</p>
                                    <p><strong>Escalation Required:</strong> ${request.escalation_required == 1 ? 'Yes' : 'No'}</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">Resolution Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Resolution Expected:</strong> ${request.resolution_expected || 'N/A'}</p>
                                    <p><strong>Date Resolved:</strong> ${request.date_resolved || 'N/A'}</p>
                                    <p><strong>Assigned To:</strong> ${request.assigned_to_name || 'Unassigned'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Description</h6>
                        </div>
                        <div class="card-body">
                            <p>${request.change_description}</p>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Justification</h6>
                        </div>
                        <div class="card-body">
                            <p>${request.justification}</p>
                        </div>
                    </div>
                    
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Impact Analysis</h6>
                        </div>
                        <div class="card-body">
                            <p>${request.impact_analysis}</p>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Action Plan</h6>
                        </div>
                        <div class="card-body">
                            <p>${request.action || 'N/A'}</p>
                        </div>
                    </div>
                 ${commentsHTML}
            `;
            viewModal.show();
            } else {
                alert('Error fetching details: ' + data.message);
            }
        } catch (error) {
            console.error('Error viewing change request:', error);
            alert('Error fetching change request details');
        }
    }

    async function loadProjects(selectId) {
        try {
            const response = await fetch('api/get_projects.php');
            const projects = await response.json();

            const select = document.getElementById(selectId);
            select.innerHTML = '<option value="">Select Project</option>';

            projects.forEach(project => {
                const option = document.createElement('option');
                option.value = project.project_id;
                option.textContent = project.project_name;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading projects:', error);
            alert('Error loading projects list');
        }
    }

    async function editChangeRequest(id) {
        try {
            await loadProjects('edit_project_id');
            // Load users for assignment dropdown
        const usersResponse = await fetch('api/get_users.php');
        const users = await usersResponse.json();
        
        const assignedToSelect = document.getElementById('edit_assigned_to_id');
        assignedToSelect.innerHTML = '<option value="">Unassigned</option>' + 
            users.map(user => `<option value="${user.user_id}">${user.username}</option>`).join('');


            const response = await fetch(`api/get_change_request_details.php?id=${id}`);
            const data = await response.json();

            if (data.status === 'success') {
                const request = data.request;
                document.getElementById('edit_change_request_id').value = request.change_request_id;
                document.getElementById('edit_project_id').value = request.project_id;
                document.getElementById('edit_change_title').value = request.change_title;
                document.getElementById('edit_change_type').value = request.change_type;
                document.getElementById('edit_change_description').value = request.change_description;
                document.getElementById('edit_justification').value = request.justification;
                document.getElementById('edit_impact_analysis').value = request.impact_analysis;
                document.getElementById('edit_area_of_impact').value = request.area_of_impact;
                document.getElementById('edit_resolution_expected').value = request.resolution_expected;
                document.getElementById('edit_date_resolved').value = request.date_resolved;
                document.getElementById('edit_action').value = request.action;
                document.getElementById('edit_priority').value = request.priority;
                document.getElementById('edit_assigned_to_id').value = request.assigned_to_id || '';
                document.getElementById('edit_escalation_required').value = request.escalation_required;

                editModal.show();
            } else {
                alert('Error fetching details for edit: ' + data.message);
            }
        } catch (error) {
            console.error('Error editing change request:', error);
            alert('Error loading change request for editing');
        }
    }

    async function deleteChangeRequest(id) {
        if (confirm('Are you sure you want to delete this change request?')) {
            try {
                const response = await fetch('api/delete_change_request.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const result = await response.json();
                if (result.status === 'success') {
                    alert('Request deleted successfully.');
                    loadChangeRequests(currentPage);
                } else {
                    alert('Error deleting request: ' + result.message);
                }
            } catch (error) {
                console.error('Error deleting change request:', error);
                alert('Error deleting change request');
            }
        }
    }

    async function fetchNotificationCount() {
        try {
            const response = await fetch('api/get_notifications.php');
            const data = await response.json();
            if (data.status === 'success') {
                document.getElementById('notification-count').textContent = data.count;
            }
        } catch (error) {
            console.error('Error fetching notifications:', error);
        }
    }

    async function loadNotifications() {
        try {
            const response = await fetch('api/get_notifications.php?details=true');
            const data = await response.json();
            
            if (data.status === 'success') {
                const notificationsBody = document.getElementById('notificationsBody');
                
                if (data.notifications && data.notifications.length > 0) {
                    notificationsBody.innerHTML = data.notifications.map(notification => `
                        <div class="alert ${notification.type === 'alert' ? 'alert-warning' : 'alert-info'}">
                            <h6>${notification.title}</h6>
                            <p class="mb-1">${notification.message}</p>
                            <small class="text-muted">${new Date(notification.date).toLocaleString()}</small>
                        </div>
                    `).join('');
                } else {
                    notificationsBody.innerHTML = '<div class="alert alert-info">No notifications at this time.</div>';
                }
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }

    // Event listeners
    document.getElementById('changeRequestsTableBody').addEventListener('click', (e) => {
        const id = e.target.dataset.id;
        if (e.target.classList.contains('view-btn')) {
            viewChangeRequest(id);
        } else if (e.target.classList.contains('edit-btn')) {
            editChangeRequest(id);
        } else if (e.target.classList.contains('delete-btn')) {
            deleteChangeRequest(id);
        } else if (e.target.classList.contains('complete-btn')) {
            completeChangeRequest(id);
        }
    });

    document.getElementById('changeRequestForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        try {
            const response = await fetch('api/add_change_request.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                alert('Change request submitted successfully!');
                const modal = bootstrap.Modal.getInstance(document.getElementById('addChangeRequestModal'));
                modal.hide();
                document.getElementById('changeRequestForm').reset();
                loadChangeRequests(currentPage);
                fetchNotificationCount();
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error submitting change request:', error);
            alert('Error submitting change request');
        }
    });

    document.getElementById('editChangeRequestForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        try {
            const response = await fetch('api/update_change_request.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                alert('Change request updated successfully!');
                editModal.hide();
                loadChangeRequests(currentPage);
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            console.error('Error updating change request:', error);
            alert('Error updating change request');
        }
    });

    // Filter event listeners
    document.getElementById('statusFilter').addEventListener('change', () => {
        currentPage = 1;
        loadChangeRequests(currentPage);
    });

    document.getElementById('priorityFilter').addEventListener('change', () => {
        currentPage = 1;
        loadChangeRequests(currentPage);
    });

    document.getElementById('projectFilter').addEventListener('change', () => {
        currentPage = 1;
        loadChangeRequests(currentPage);
    });

    document.getElementById('searchInput').addEventListener('input', () => {
        currentPage = 1;
        loadChangeRequests(currentPage);
    });

    // Notifications modal event listener
    document.getElementById('notificationsModal').addEventListener('show.bs.modal', () => {
        loadNotifications();
    });

    // Initial load
    loadChangeRequests(currentPage);
    fetchNotificationCount();
});