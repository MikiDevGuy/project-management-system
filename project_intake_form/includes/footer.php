<?php
// includes/footer.php
?>
            </div> <!-- End of content-wrapper -->
        </main>
    </div> <!-- End of app-container -->

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.35.0/dist/apexcharts.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // ==================== SIDEBAR FUNCTIONALITY ====================
        
        // Check sidebar state from localStorage
        let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        // Initialize sidebar state
        function initSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarCollapsed) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('collapsed');
            }
            
            // Update tooltips for collapsed sidebar
            updateSidebarTooltips();
        }
        
        // Toggle sidebar collapse
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('collapsed');
            sidebarCollapsed = sidebar.classList.contains('collapsed');
            
            // Save state to localStorage
            localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
            
            // Update tooltips
            updateSidebarTooltips();
            
            // Trigger resize event for charts if any
            window.dispatchEvent(new Event('resize'));
        }
        
        // Update tooltips for collapsed sidebar
        function updateSidebarTooltips() {
            const navLinks = document.querySelectorAll('.sidebar .nav-link');
            navLinks.forEach(link => {
                const textSpan = link.querySelector('.nav-text');
                if (textSpan) {
                    link.setAttribute('data-title', textSpan.textContent);
                }
            });
        }
        
        // Mobile sidebar toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
            
            // Prevent body scroll when sidebar is open
            document.body.style.overflow = sidebar.classList.contains('show') ? 'hidden' : '';
        }
        
        // ==================== EVENT LISTENERS ====================
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize sidebar
            initSidebar();
            
            // Desktop sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            
            // Mobile sidebar toggle
            const mobileToggle = document.getElementById('mobileToggle');
            if (mobileToggle) {
                mobileToggle.addEventListener('click', toggleMobileSidebar);
            }
            
            // Close sidebar when clicking on overlay
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) {
                overlay.addEventListener('click', toggleMobileSidebar);
            }
            
            // Close mobile sidebar when clicking a link
            const navLinks = document.querySelectorAll('.sidebar .nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        toggleMobileSidebar();
                    }
                });
            });
            
            // Auto-close submenus on mobile when clicking outside
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 992) {
                    const sidebar = document.getElementById('sidebar');
                    const target = event.target;
                    
                    if (!sidebar.contains(target) && !target.closest('.mobile-toggle')) {
                        sidebar.classList.remove('show');
                        document.getElementById('sidebarOverlay').classList.remove('show');
                        document.body.style.overflow = '';
                    }
                }
            });
            
            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth > 992) {
                        // On desktop, ensure sidebar overlay is hidden
                        document.getElementById('sidebarOverlay').classList.remove('show');
                        document.body.style.overflow = '';
                    } else {
                        // On mobile, collapse sidebar if it's expanded
                        const sidebar = document.getElementById('sidebar');
                        if (!sidebar.classList.contains('collapsed')) {
                            sidebar.classList.add('collapsed');
                            mainContent.classList.add('collapsed');
                        }
                    }
                }, 250);
            });
        });
        
        // ==================== NOTIFICATION FUNCTIONS ====================
        
        function markAllAsRead() {
            fetch('mark_notifications_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ 
                    user_id: <?php echo $user_id; ?>,
                    csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    const notificationBadges = document.querySelectorAll('.notification-badge, .nav-badge');
                    notificationBadges.forEach(badge => {
                        badge.style.display = 'none';
                    });
                    
                    // Show success message
                    showToast('success', 'All notifications marked as read');
                } else {
                    showToast('error', 'Failed to mark notifications as read');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('error', 'An error occurred');
            });
        }
        
        // ==================== UTILITY FUNCTIONS ====================
        
        // Toast notification system
        function showToast(type, message) {
            // Create toast container if it doesn't exist
            let toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
                document.body.appendChild(toastContainer);
            }
            
            // Create toast element
            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="toast-header bg-${type} text-white">
                        <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                        <small>Just now</small>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            
            // Show toast
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement, {
                autohide: true,
                delay: 5000
            });
            toast.show();
            
            // Remove toast from DOM after it's hidden
            toastElement.addEventListener('hidden.bs.toast', function () {
                toastElement.remove();
            });
        }
        
        // Form validation helper
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return true;
            
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                    
                    // Add error message if not present
                    if (!field.nextElementSibling || !field.nextElementSibling.classList.contains('invalid-feedback')) {
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'invalid-feedback';
                        errorDiv.textContent = 'This field is required';
                        field.parentNode.appendChild(errorDiv);
                    }
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            return isValid;
        }
        
        // Auto-save functionality
        let autoSaveTimeout;
        function setupAutoSave(formId, saveUrl) {
            const form = document.getElementById(formId);
            if (!form) return;
            
            const saveButton = form.querySelector('.save-draft');
            if (saveButton) {
                saveButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    saveFormAsDraft(form, saveUrl);
                });
            }
            
            // Auto-save on input change
            const inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    clearTimeout(autoSaveTimeout);
                    autoSaveTimeout = setTimeout(() => {
                        saveFormAsDraft(form, saveUrl);
                    }, 3000); // Save after 3 seconds of inactivity
                });
            });
        }
        
        function saveFormAsDraft(form, saveUrl) {
            const formData = new FormData(form);
            formData.append('save_draft', '1');
            
            fetch(saveUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', 'Draft saved successfully');
                }
            })
            .catch(error => {
                console.error('Auto-save error:', error);
            });
        }
        
        // Print functionality
        function printPage() {
            window.print();
        }
        
        // Export to Excel/PDF
        function exportTable(tableId, format = 'excel') {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            if (format === 'excel') {
                // Simple CSV export
                let csv = [];
                const rows = table.querySelectorAll('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    const row = [], cols = rows[i].querySelectorAll('td, th');
                    
                    for (let j = 0; j < cols.length; j++) {
                        const text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s)/gm, " ");
                        row.push('"' + text + '"');
                    }
                    
                    csv.push(row.join(","));
                }
                
                const csvString = csv.join("\n");
                const filename = 'export_' + new Date().toISOString().slice(0,10) + '.csv';
                
                const blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                showToast('success', 'Export completed successfully');
            }
        }
        
        // Initialize DataTables
        $(document).ready(function() {
            $('.table-dashen').DataTable({
                "pageLength": 25,
                "language": {
                    "search": "Search:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                    "infoEmpty": "Showing 0 to 0 of 0 entries",
                    "infoFiltered": "(filtered from _MAX_ total entries)",
                    "paginate": {
                        "first": "First",
                        "last": "Last",
                        "next": "Next",
                        "previous": "Previous"
                    }
                },
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                       '<"row"<"col-sm-12"tr>>' +
                       '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                "initComplete": function(settings, json) {
                    $('.dataTables_length select').addClass('form-select form-select-sm');
                    $('.dataTables_filter input').addClass('form-control form-control-sm');
                }
            });
        });
        
        // Loading state management
        function setLoading(element, isLoading) {
            if (isLoading) {
                element.classList.add('loading');
                element.disabled = true;
                const originalText = element.innerHTML;
                element.setAttribute('data-original-text', originalText);
                element.innerHTML = '<span class="loading-spinner"></span> Loading...';
            } else {
                element.classList.remove('loading');
                element.disabled = false;
                const originalText = element.getAttribute('data-original-text');
                if (originalText) {
                    element.innerHTML = originalText;
                }
            }
        }
        
        // Page load indicator
        document.addEventListener('DOMContentLoaded', function() {
            // Remove initial loading state
            document.body.classList.remove('page-loading');
        });
        
        // Handle page transitions
        document.addEventListener('click', function(e) {
            if (e.target.tagName === 'A' && e.target.href && !e.target.href.includes('#') && !e.target.target) {
                // Add loading state for page transitions
                document.body.classList.add('page-loading');
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const saveButton = document.querySelector('.btn-dashen[type="submit"]');
                if (saveButton) saveButton.click();
            }
            
            // Ctrl + / to toggle sidebar
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                toggleSidebar();
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    const modal = bootstrap.Modal.getInstance(openModal);
                    if (modal) modal.hide();
                }
            }
        });
    </script>
    
    <!-- Page-specific JavaScript -->
    <?php if (isset($page_js)): ?>
    <script><?php echo $page_js; ?></script>
    <?php endif; ?>
    
    <!-- Additional styles for loading states -->
    <style>
        .page-loading::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .page-loading::after {
            content: '';
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            border: 4px solid rgba(39, 50, 116, 0.1);
            border-top: 4px solid var(--dashen-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 10000;
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        /* Toast container */
        .toast-container {
            z-index: 1060;
        }
        
        .toast {
            min-width: 300px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }
        
        /* Modal customizations */
        .modal-dialog {
            max-width: 800px;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--dashen-primary), var(--dashen-secondary));
            color: white;
        }
        
        .modal-footer {
            background: #f8f9fa;
        }
    </style>
</body>
</html>