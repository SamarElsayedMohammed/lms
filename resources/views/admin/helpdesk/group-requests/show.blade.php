@extends('layouts.app')

@section('title', 'Group Request Details')

@section('main')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header space-between section-header">
                    <h3 class="card-title">
                        <i class="fas fa-eye"></i> Group Request Details
                    </h3>
                    <div class="card-tools">
                        <a href="{{ route('admin.helpdesk.group-requests.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Request Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">Request ID:</th>
                                    <td>{{ $request->id }}</td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        @if($request->status == 'pending')
                                            <span class="badge badge-warning">Pending</span>
                                        @elseif($request->status == 'approved')
                                            <span class="badge badge-success">Approved</span>
                                        @elseif($request->status == 'rejected')
                                            <span class="badge badge-danger">Rejected</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Requested At:</th>
                                    <td>{{ $request->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>Last Updated:</th>
                                    <td>{{ $request->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Group Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">Group ID:</th>
                                    <td>{{ $request->group->id }}</td>
                                </tr>
                                <tr>
                                    <th>Group Name:</th>
                                    <td>{{ $request->group->name }}</td>
                                </tr>
                                <tr>
                                    <th>Group Slug:</th>
                                    <td>{{ $request->group->slug }}</td>
                                </tr>
                                <tr>
                                    <th>Description:</th>
                                    <td>{{ $request->group->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Is Private:</th>
                                    <td>
                                        @if($request->group->is_private)
                                            <span class="badge badge-success">Yes</span>
                                        @else
                                            <span class="badge badge-danger">No</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Is Active:</th>
                                    <td>
                                        @if($request->group->is_active)
                                            <span class="badge badge-success">Yes</span>
                                        @else
                                            <span class="badge badge-danger">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>User Information</h5>
                            <table class="table table-bordered">
                                <tr>
                                    <th width="30%">User ID:</th>
                                    <td>{{ $request->user->id }}</td>
                                </tr>
                                <tr>
                                    <th>Name:</th>
                                    <td>{{ $request->user->name }}</td>
                                </tr>
                                <tr>
                                    <th>Email:</th>
                                    <td>{{ $request->user->email }}</td>
                                </tr>
                                <tr>
                                    <th>Profile:</th>
                                    <td>
                                        @if($request->user->profile)
                                            <img src="{{ $request->user->profile }}" alt="Profile" class="img-thumbnail" style="width: 50px; height: 50px;">
                                        @else
                                            <span class="text-muted">No profile image</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>Registered At:</th>
                                    <td>{{ $request->user->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    @if($request->status == 'pending')
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>Actions</h5>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-success" onclick="updateStatus('approved')">
                                    <i class="fas fa-check"></i> Approve Request
                                </button>
                                <button type="button" class="btn btn-danger" onclick="updateStatus('rejected')">
                                    <i class="fas fa-times"></i> Reject Request
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusUpdateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Request Status</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form id="status-update-form">
                @csrf
                <div class="modal-body">
                    <input type="hidden" id="request-id" name="request_id" value="{{ $request->id }}">
                    <div class="form-group">
                        <label for="status-select">Status:</label>
                        <select class="form-control" id="status-select" name="status" required>
                            <option value="pending" {{ $request->status == 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="approved" {{ $request->status == 'approved' ? 'selected' : '' }}>Approved</option>
                            <option value="rejected" {{ $request->status == 'rejected' ? 'selected' : '' }}>Rejected</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateStatus()">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function updateStatus(status = null) {
    if (status) {
        $('#status-select').val(status);
    }
    
    // Open modal directly without confirmation
    $('#statusUpdateModal').modal('show');
}

$(document).ready(function() {
    $('#status-update-form').on('submit', function(e) {
        e.preventDefault();
        updateStatus();
    });
});

function updateStatus() {
    let formData = $('#status-update-form').serialize();
    let requestId = $('#request-id').val();

    $.ajax({
        url: `{{ url('admin/helpdesk/group-requests') }}/${requestId}/status`,
        type: 'POST',
        data: formData,
        success: function(response) {
            if (response.error === false || response.success === true || response.success === 'true' || response.status === 'success' || (response.message && response.message.includes('successfully'))) {
                // Auto-hide modal
                $('#statusUpdateModal').modal('hide');
                
                // Show success SweetAlert toast
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                Toast.fire({
                    icon: 'success',
                    title: response.message || 'Status updated successfully'
                }).then(() => {
                    // Reload the page to show updated status
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: response.message || 'An error occurred',
                    confirmButtonText: 'OK'
                });
            }
        },
        error: function(xhr) {
            let errorMessage = 'An error occurred while updating status';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMessage = xhr.responseJSON.message;
            }
            
            showSwalErrorToast(errorMessage, 'Error', 4000);
        }
    });
}

function showAlert(type, message) {
    let alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    let alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
    `;
    
    // Remove existing alerts
    $('.alert').remove();
    
    // Add new alert at the top of the content
    $('.card-body').prepend(alertHtml);
    
    // Auto-hide after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
}
</script>
@endsection
