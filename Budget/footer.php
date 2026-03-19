<?php
// Footer content
?>
    </div>
</div>

<!-- Footer -->
<footer class="footer mt-auto py-3 bg-light border-top">
    <div class="container-fluid px-4">
        <div class="row align-items-center">
            <div class="col-md-6">
                <span class="text-muted">&copy; <?= date('Y') ?> Dashen Bank. All rights reserved.</span>
            </div>
            <div class="col-md-6 text-end">
                <span class="text-muted">Budget Management System v1.0</span>
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap JS Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Custom JS -->
<script>
    $(document).ready(function() {
        // Initialize DataTables with Dashen brand styling
        $('.data-table').DataTable({
            responsive: true,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                infoEmpty: "Showing 0 to 0 of 0 entries",
                infoFiltered: "(filtered from _MAX_ total entries)",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            },
            dom: '<"row"<"col-md-6"l><"col-md-6"f>>rt<"row"<"col-md-6"i><"col-md-6"p>>',
            drawCallback: function() {
                // Update pagination styling
                $('.pagination .page-item').removeClass('active');
                $('.pagination .page-item').each(function() {
                    if ($(this).find('.page-link').text() == $(this).closest('.dataTables_wrapper').find('.dataTables_info').text().split(' ')[3]) {
                        $(this).addClass('active');
                    }
                });
            }
        });
        
        // Enable tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();
        
        // Calculate contingency amount when percentage changes
        $('.contingency-percentage').on('change', function() {
            const percentage = parseFloat($(this).val()) || 0;
            const estimatedAmount = parseFloat($(this).closest('form').find('.estimated-amount').val()) || 0;
            const contingencyAmount = (estimatedAmount * percentage / 100).toFixed(2);
            $(this).closest('form').find('.contingency-amount').val(contingencyAmount);
            $(this).closest('form').find('.total-budget-amount').val((estimatedAmount + parseFloat(contingencyAmount)).toFixed(2));
        });
        
        // Calculate total when estimated amount changes
        $('.estimated-amount').on('change', function() {
            const percentage = parseFloat($(this).closest('form').find('.contingency-percentage').val()) || 0;
            const estimatedAmount = parseFloat($(this).val()) || 0;
            const contingencyAmount = (estimatedAmount * percentage / 100).toFixed(2);
            $(this).closest('form').find('.contingency-amount').val(contingencyAmount);
            $(this).closest('form').find('.total-budget-amount').val((estimatedAmount + parseFloat(contingencyAmount)).toFixed(2));
        });
        
        // Custom styling for DataTables pagination
        $('.data-table').on('draw.dt', function() {
            $('.paginate_button').addClass('page-link');
            $('.paginate_button').parent().addClass('page-item');
            
            // Apply Dashen brand color to active pagination button
            $('.paginate_button.current').css({
                'background-color': '#273274',
                'border-color': '#273274',
                'color': 'white'
            });
        });
        
        // Sidebar toggle functionality
        $('#sidebarToggle').on('click', function() {
            $('#sidebar').toggleClass('collapsed');
            $('#mainContent').toggleClass('expanded');
            
            // Change icon
            const icon = $(this).find('i');
            if ($('#sidebar').hasClass('collapsed')) {
                icon.removeClass('fa-bars').addClass('fa-chevron-right');
            } else {
                icon.removeClass('fa-chevron-right').addClass('fa-bars');
            }
            
            // Store sidebar state in localStorage
            localStorage.setItem('sidebarCollapsed', $('#sidebar').hasClass('collapsed'));
        });
        
        // Check and apply saved sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            $('#sidebar').addClass('collapsed');
            $('#mainContent').addClass('expanded');
            $('#sidebarToggle i').removeClass('fa-bars').addClass('fa-chevron-right');
        }
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            $('.alert').alert('close');
        }, 5000);
    });
    
    // Custom function to format currency
    function formatCurrency(amount, currency = 'USD') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(amount);
    }
    
    // Custom function to show confirmation dialog
    function confirmAction(message, callback) {
        if (confirm(message)) {
            callback();
        }
    }
</script>

<!-- Custom Styles for Footer and DataTables -->
<style>
    :root {
        --dashen-primary: #273274;
        --dashen-secondary: #4a5cb6;
        --dashen-light: #f0f2ff;
    }
    
    /* Footer styling */
    .footer {
        background-color: var(--dashen-light) !important;
        border-top: 1px solid rgba(39, 50, 116, 0.1) !important;
    }
    
    /* DataTables customization */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        color: var(--dashen-primary) !important;
        border: 1px solid var(--dashen-primary) !important;
        margin: 0 2px;
        border-radius: 0.25rem;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background-color: var(--dashen-primary) !important;
        border-color: var(--dashen-primary) !important;
        color: white !important;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background-color: var(--dashen-primary) !important;
        border-color: var(--dashen-primary) !important;
        color: white !important;
    }
    
    .dataTables_wrapper .dataTables_length select,
    .dataTables_wrapper .dataTables_filter input {
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        padding: 0.375rem 0.75rem;
    }
    
    .dataTables_wrapper .dataTables_length select:focus,
    .dataTables_wrapper .dataTables_filter input:focus {
        border-color: var(--dashen-secondary);
        box-shadow: 0 0 0 0.25rem rgba(39, 50, 116, 0.25);
    }
    
    /* Custom scrollbar styling */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb {
        background: var(--dashen-secondary);
        border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: var(--dashen-primary);
    }
    .text-muted {
        color: #6c757d !important;
        margin-left: 300px;
    }
    
    /* Print styles */
    @media print {
        .sidebar, .navbar, .footer, .btn {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }
        
        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
        }
    }
    
    /* Loading spinner */
    .loading-spinner {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        border: 2px solid #f3f3f3;
        border-top: 2px solid var(--dashen-primary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>
</body>
</html>