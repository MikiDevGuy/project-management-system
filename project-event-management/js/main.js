// Main JavaScript for Project Event Management System
document.addEventListener('DOMContentLoaded', function() {
    // Event listeners for action buttons
    document.querySelectorAll('.view-btn').forEach(button => {
        button.addEventListener('click', function() {
            const eventId = this.getAttribute('data-id');
            viewEvent(eventId);
        });
    });

    document.querySelectorAll('.edit-btn').forEach(button => {
        button.addEventListener('click', function() {
            const eventId = this.getAttribute('data-id');
            editEvent(eventId);
        });
    });

    document.querySelectorAll('.delete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const eventId = this.getAttribute('data-id');
            deleteEvent(eventId);
        });
    });

    // Event form submission
    const eventForm = document.getElementById('eventForm');
    if (eventForm) {
        eventForm.addEventListener('submit', function(e) {
            e.preventDefault();
            saveEvent(this);
        });
    }
});

function viewEvent(id) {
    fetch(`api/events.php?id=${id}`)
        .then(response => response.json())
        .then(event => {
            // Create modal with event details
            const modalContent = `
                <div class="modal fade" id="viewEventModal" tabindex="-1" aria-labelledby="viewEventModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="viewEventModalLabel">${event.event_name}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Project:</strong> ${event.project_name || 'N/A'}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Type:</strong> ${event.event_type}
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Start:</strong> ${new Date(event.start_datetime).toLocaleString()}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>End:</strong> ${event.end_datetime ? new Date(event.end_datetime).toLocaleString() : 'N/A'}
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Location:</strong> ${event.location}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Organizer:</strong> ${event.organizer_name}
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>Status:</strong> <span class="badge bg-primary">${event.status}</span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Priority:</strong> <span class="badge bg-warning">${event.priority}</span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <strong>Description:</strong>
                                    <p>${event.description || 'No description provided.'}</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add modal to DOM and show it
            document.body.insertAdjacentHTML('beforeend', modalContent);
            const modal = new bootstrap.Modal(document.getElementById('viewEventModal'));
            modal.show();
            
            // Remove modal from DOM after it's hidden
            document.getElementById('viewEventModal').addEventListener('hidden.bs.modal', function() {
                this.remove();
            });
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading event details');
        });
}

function editEvent(id) {
    fetch(`api/events.php?id=${id}`)
        .then(response => response.json())
        .then(event => {
            // Populate the form with event data
            document.getElementById('eventName').value = event.event_name;
            document.getElementById('projectId').value = event.project_id;
            document.getElementById('eventType').value = event.event_type;
            document.getElementById('organizerId').value = event.organizer_id;
            
            // Format datetime for input fields
            const startDatetime = event.start_datetime.replace(' ', 'T').substring(0, 16);
            document.getElementById('startDatetime').value = startDatetime;
            
            if (event.end_datetime) {
                const endDatetime = event.end_datetime.replace(' ', 'T').substring(0, 16);
                document.getElementById('endDatetime').value = endDatetime;
            }
            
            document.getElementById('eventLocation').value = event.location;
            document.getElementById('eventStatus').value = event.status;
            document.getElementById('eventPriority').value = event.priority;
            document.getElementById('eventDescription').value = event.description || '';
            
            // Change form action to update instead of create
            const form = document.getElementById('eventForm');
            form.action = `api/events.php?id=${id}`;
            form.method = 'PUT';
            
            // Change modal title
            document.getElementById('addEventModalLabel').textContent = 'Edit Event';
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('addEventModal'));
            modal.show();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading event data');
        });
}

function deleteEvent(id) {
    if (confirm('Are you sure you want to delete this event?')) {
        fetch(`api/events.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
            } else {
                alert('Event deleted successfully');
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting event');
        });
    }
}

function saveEvent(form) {
    const formData = new FormData(form);
    const url = form.action;
    const method = form.method;
    
    // Convert FormData to URL-encoded string for PUT requests
    let body;
    if (method === 'PUT') {
        const params = new URLSearchParams();
        for (const pair of formData) {
            params.append(pair[0], pair[1]);
        }
        body = params.toString();
    } else {
        body = formData;
    }
    
    fetch(url, {
        method: method,
        body: method === 'PUT' ? body : formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
        } else {
            alert(data.message);
            // Close modal and reload page
            const modal = bootstrap.Modal.getInstance(document.getElementById('addEventModal'));
            modal.hide();
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving event');
    });
}