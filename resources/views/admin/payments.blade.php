@extends('admin.layouts.app')

@section('title', 'Payments')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2>Payments Management</h2>
            <p class="text-muted">Manage and update payment statuses for all orders</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" id="search" class="form-control" placeholder="Order #, Reference, User...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Status</label>
                    <select id="payment_status" class="form-select">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                        <option value="refunded">Refunded</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select id="payment_method" class="form-select">
                        <option value="">All</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="wallet">Wallet Balance</option>
                        <option value="wise">Wise Transfer</option>
                        <option value="crypto">Cryptocurrency</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Order Status</label>
                    <select id="status" class="form-select">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date Range</label>
                    <div class="input-group">
                        <input type="date" id="date_from" class="form-control" placeholder="From">
                        <input type="date" id="date_to" class="form-control" placeholder="To">
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-search"></i> Filter
                    </button>
                    <button type="reset" id="resetFilters" class="btn btn-secondary">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th width="5%">#</th>
                            <th width="10%">Order #</th>
                            <th width="15%">User</th>
                            <th width="8%">Reference</th>
                            <th width="8%">Amount</th>
                            <th width="10%">Payment Method</th>
                            <th width="12%">Payment Status</th>
                            <th width="10%">Order Status</th>
                            <th width="12%">Paid At</th>
                            <th width="10%">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2 text-muted">Loading payments...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination" class="mt-4 d-flex justify-content-between align-items-center">
                <div id="paginationInfo" class="text-muted small"></div>
                <nav>
                    <ul class="pagination mb-0" id="paginationLinks"></ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Update Payment Status Modal - Centered -->
<div class="modal fade" id="updatePaymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fa fa-credit-card me-2"></i> Update Payment Status
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="update_order_id">
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Order Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fa fa-hashtag"></i>
                        </span>
                        <input type="text" id="update_order_number" class="form-control bg-light" readonly>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Current Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fa fa-info-circle"></i>
                        </span>
                        <input type="text" id="update_current_status" class="form-control bg-light" readonly>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Payment Status</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="fa fa-exchange-alt"></i>
                        </span>
                        <select id="update_payment_status" class="form-select">
                            <option value="pending">⏳ Pending</option>
                            <option value="paid">✅ Paid</option>
                            <option value="failed">❌ Failed</option>
                            <option value="refunded">🔄 Refunded</option>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes (Optional)</label>
                    <textarea id="update_notes" class="form-control" rows="4" placeholder="Add any notes about this payment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fa fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="savePaymentUpdate">
                    <i class="fa fa-save"></i> Update Payment
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    loadPayments();

    // Filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadPayments();
    });

    // Reset filters
    $('#resetFilters').on('click', function() {
        $('#search').val('');
        $('#payment_status').val('');
        $('#payment_method').val('');
        $('#status').val('');
        $('#date_from').val('');
        $('#date_to').val('');
        loadPayments();
    });

    // Update button click
    $(document).on('click', '.update-payment-btn', function() {
        var orderId = $(this).data('id');
        var orderNumber = $(this).data('order');
        var currentStatus = $(this).data('status');
        
        $('#update_order_id').val(orderId);
        $('#update_order_number').val(orderNumber);
        $('#update_current_status').val(currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1));
        $('#update_payment_status').val(currentStatus);
        $('#update_notes').val('');
        
        var modal = new bootstrap.Modal(document.getElementById('updatePaymentModal'));
        modal.show();
    });

    // Save payment update
    $('#savePaymentUpdate').on('click', function() {
        var orderId = $('#update_order_id').val();
        var newStatus = $('#update_payment_status').val();
        var notes = $('#update_notes').val();
        
        // Show loading state
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
        
        $.ajax({
            url: '/admin/payments/' + orderId + '/update-status',
            method: 'POST',
            data: {
                payment_status: newStatus,
                notes: notes,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonText: 'OK',
                        timer: 2000
                    });
                    
                    // Close modal
                    var modal = bootstrap.Modal.getInstance(document.getElementById('updatePaymentModal'));
                    modal.hide();
                    
                    // Reload payments
                    loadPayments();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function(xhr) {
                var errorMsg = xhr.responseJSON?.message || 'Failed to update payment status';
                Swal.fire('Error', errorMsg, 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Update Payment');
            }
        });
    });

    function loadPayments(page = 1) {
        // Show loading state
        $('#paymentsTableBody').html('\
            <tr>\
                <td colspan="10" class="text-center py-5">\
                    <div class="spinner-border text-primary" role="status">\
                        <span class="visually-hidden">Loading...</span>\
                    </div>\
                    <p class="mt-2 text-muted">Loading payments...</p>\
                </td>\
            </tr>\
        ');
        
        $.ajax({
            url: '/admin/payments/data',
            method: 'GET',
            data: {
                page: page,
                search: $('#search').val(),
                payment_status: $('#payment_status').val(),
                payment_method: $('#payment_method').val(),
                status: $('#status').val(),
                date_from: $('#date_from').val(),
                date_to: $('#date_to').val()
            },
            success: function(response) {
                if (response.success) {
                    renderPaymentsTable(response.data);
                    renderPagination(response.pagination);
                } else {
                    $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center text-danger py-5">' + (response.message || 'Failed to load payments') + '</td></tr>');
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = 'Error loading payments';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                } else if (xhr.status === 404) {
                    errorMessage = 'API endpoint not found. Please check the route.';
                } else if (xhr.status === 500) {
                    errorMessage = 'Server error. Please check the logs.';
                }
                $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center text-danger py-5">' + errorMessage + '</td></tr>');
            }
        });
    }

    function renderPaymentsTable(orders) {
        if (!orders || orders.length === 0) {
            $('#paymentsTableBody').html('<tr><td colspan="10" class="text-center py-5"><i class="fa fa-inbox fa-3x text-muted"></i><p class="mt-2 text-muted">No payments found</p></td></tr>');
            return;
        }
        
        var html = '';
        orders.forEach(function(order, index) {
            // Payment Status Badge
            var paymentStatusBadge = '';
            switch(order.payment_status) {
                case 'paid':
                    paymentStatusBadge = '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i> Paid</span>';
                    break;
                case 'pending':
                    paymentStatusBadge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-clock me-1"></i> Pending</span>';
                    break;
                case 'failed':
                    paymentStatusBadge = '<span class="badge bg-danger px-3 py-2"><i class="fa fa-exclamation-circle me-1"></i> Failed</span>';
                    break;
                case 'refunded':
                    paymentStatusBadge = '<span class="badge bg-info px-3 py-2"><i class="fa fa-undo me-1"></i> Refunded</span>';
                    break;
                default:
                    paymentStatusBadge = '<span class="badge bg-secondary px-3 py-2">' + order.payment_status + '</span>';
            }
            
            // Order Status Badge
            var orderStatusBadge = '';
            switch(order.status) {
                case 'completed':
                    orderStatusBadge = '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i> Completed</span>';
                    break;
                case 'processing':
                    orderStatusBadge = '<span class="badge bg-primary px-3 py-2"><i class="fa fa-spinner fa-spin me-1"></i> Processing</span>';
                    break;
                case 'pending':
                    orderStatusBadge = '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-hourglass-half me-1"></i> Pending</span>';
                    break;
                case 'cancelled':
                    orderStatusBadge = '<span class="badge bg-danger px-3 py-2"><i class="fa fa-ban me-1"></i> Cancelled</span>';
                    break;
                default:
                    orderStatusBadge = '<span class="badge bg-secondary px-3 py-2">' + order.status + '</span>';
            }
            
            // Payment Method Badge
            var paymentMethodBadge = '';
            switch(order.payment_method) {
                case 'card':
                    paymentMethodBadge = '<span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2"><i class="fab fa-cc-visa me-1"></i> Card</span>';
                    break;
                case 'wallet':
                    paymentMethodBadge = '<span class="badge bg-success bg-opacity-10 text-success px-3 py-2"><i class="fa fa-wallet me-1"></i> Wallet</span>';
                    break;
                case 'wise':
                    paymentMethodBadge = '<span class="badge bg-info bg-opacity-10 text-info px-3 py-2"><i class="fa fa-university me-1"></i> Wise</span>';
                    break;
                case 'crypto':
                    paymentMethodBadge = '<span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2"><i class="fab fa-bitcoin me-1"></i> Crypto</span>';
                    break;
                case 'bank':
                    paymentMethodBadge = '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2"><i class="fa fa-building me-1"></i> Bank</span>';
                    break;
                default:
                    paymentMethodBadge = '<span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2">' + order.payment_method + '</span>';
            }
            
            // Format date without time
            var paidAt = '-';
            if (order.paid_at) {
                var date = new Date(order.paid_at);
                paidAt = date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }
            
            html += '<tr>';
            html += '<td class="text-center">#' + (index + 1) + '</td>';
            html += '<td><strong>' + order.order_number + '</strong></td>';
            html += '<td>';
            html += '<div class="d-flex flex-column">';
            html += '<span class="fw-semibold">' + (order.user ? order.user.name : 'N/A') + '</span>';
            html += '<small class="text-muted">' + (order.user ? order.user.email : 'No email') + '</small>';
            html += '</div>';
            html += '</td>';
            html += '<td><code class="small">' + order.reference_code + '</code></td>';
            html += '<td class="fw-semibold text-primary">€' + parseFloat(order.total_amount).toFixed(2) + '</td>';
            html += '<td>' + paymentMethodBadge + '</td>';
            html += '<td>' + paymentStatusBadge + '</td>';
            html += '<td>' + orderStatusBadge + '</td>';
            html += '<td>' + paidAt + '</td>';
            html += '<td>';
            
            if (order.payment_status !== 'paid') {
                html += '<button class="btn btn-sm btn-primary update-payment-btn" ';
                html += 'data-id="' + order.id + '" ';
                html += 'data-order="' + order.order_number + '" ';
                html += 'data-status="' + order.payment_status + '">';
                html += '<i class="fa fa-edit"></i> Update';
                html += '</button>';
            } else {
                html += '<span class="badge bg-success px-3 py-2">Completed</span>';
            }
            
            html += '</td>';
            html += '</tr>';
        });
        
        $('#paymentsTableBody').html(html);
    }

    function renderPagination(pagination) {
        $('#paginationInfo').html('Showing <strong>' + pagination.from + '</strong> to <strong>' + pagination.to + '</strong> of <strong>' + pagination.total + '</strong> entries');
        
        var paginationHtml = '';
        
        if (pagination.current_page > 1) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (pagination.current_page - 1) + '"><i class="fa fa-chevron-left"></i> Previous</a></li>';
        }
        
        for (var i = 1; i <= pagination.last_page; i++) {
            if (i >= pagination.current_page - 2 && i <= pagination.current_page + 2) {
                var activeClass = i === pagination.current_page ? 'active' : '';
                paginationHtml += '<li class="page-item ' + activeClass + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
            }
        }
        
        if (pagination.current_page < pagination.last_page) {
            paginationHtml += '<li class="page-item"><a class="page-link" href="#" data-page="' + (pagination.current_page + 1) + '">Next <i class="fa fa-chevron-right"></i></a></li>';
        }
        
        $('#paginationLinks').html(paginationHtml);
        
        $('.page-link').on('click', function(e) {
            e.preventDefault();
            var page = $(this).data('page');
            if (page) {
                loadPayments(page);
                $('html, body').animate({ scrollTop: 0 }, 'fast');
            }
        });
    }
});
</script>

<style>
.table > :not(caption) > * > * {
    padding: 12px 8px;
    vertical-align: middle;
}

.badge {
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 6px;
}

.update-payment-btn {
    white-space: nowrap;
    padding: 4px 12px;
}

.modal-dialog-centered {
    display: flex;
    align-items: center;
    min-height: calc(100% - 1rem);
}

.table-bordered {
    border: 1px solid #dee2e6;
}

.table-dark {
    background-color: #212529;
}

code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 12px;
}

.pagination .page-link {
    color: #0d6efd;
    cursor: pointer;
}

.pagination .active .page-link {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}
</style>
@endsection