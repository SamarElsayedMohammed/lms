@extends('layouts.app')

@section('title'), 'Manage Document Lecture')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>Manage Documents: {{ $lecture->title }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#"> {{ __('Dashboard') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.index') }}"> {{ __('Courses') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.show', $course->id) }}">{{ $course->name }}</a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.chapters.index', $course->id) }}"> {{ __('Chapters') }} </a></div>
                <div class="breadcrumb-item"><a
                        href="{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}"> {{ __('Lectures') }} </a>
                </div>
                <div class="breadcrumb-item"> {{ __('Documents') }} </div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title"> {{ __('Lecture Documents') }} </h2>
            <p class="section-lead"> {{ __('Add and manage documents for this lecture.') }} </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Document Management') }} </h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addDocumentModal">
                                    <i class="fas fa-plus"></i> {{ __('Add Documents') }} </button>
                            </div>
                        </div>
                        <div class="card-body"> @if (count($documents) > 0) <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th width="5%"> {{ __('Order') }} </th>
                                                <th> {{ __('Title') }} </th>
                                                <th> {{ __('Files') }} </th>
                                                <th> {{ __('Status') }} </th>
                                                <th width="15%"> {{ __('Action') }} </th>
                                            </tr>
                                        </thead>
                                        <tbody id="sortable-documents"> @foreach ($documents as $document) <tr data-id="{{ $document->id }}">
                                                    <td>
                                                        <div class="sort-handle">
                                                            <i class="fas fa-grip-vertical"></i>
                                                            {{ $document->order }}
                                                        </div>
                                                    </td>
                                                    <td>{{ $document->title }}</td>
                                                    <td>
                                                        <div class="badge badge-primary">{{ $document->file_count }} files
                                                        </div>
                                                        <button class="btn btn-sm btn-info ml-2"
                                                            onclick="viewFiles('{{ $document->id }}')">
                                                            <i class="fas fa-eye"></i> {{ __('View Files') }} </button>
                                                    </td>
                                                    <td> @if ($document->is_active) <div class="badge badge-success"> {{ __('Active') }} </div> @else <div class="badge badge-warning"> {{ __('Inactive') }} </div> @endif </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary"
                                                            onclick="editDocument('{{ $document->id }}')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form
                                                            action="{{ route('courses.chapters.lectures.documents.destroy', ['course' => $course->id, 'chapter' => $chapter->id, 'lecture' => $lecture->id, 'document' => $document->id]) }}"
                                                            method="POST" class="d-inline"> @csrf
                                                            @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger"
                                                                onclick="return confirm('Are you sure you want to delete this document set?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr> @endforeach </tbody>
                                    </table>
                                </div> @else <div class="empty-state" data-height="400">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <h2> {{ __('No Documents Added Yet') }} </h2>
                                    <p class="lead"> {{ __('Start by adding documents to this lecture.') }} </p>
                                    <button class="btn btn-primary mt-4" data-toggle="modal"
                                        data-target="#addDocumentModal">
                                        <i class="fas fa-plus"></i> {{ __('Add Documents') }} </button>
                                </div> @endif </div>
                        <div class="card-footer bg-whitesmoke">
                            <div class="row">
                                <div class="col-md-6">
                                    <a href="{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}"
                                        class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> {{ __('Back to Lectures') }} </a>
                                </div>
                                <div class="col-md-6 text-right"> @if (count($documents) > 1) <button class="btn btn-primary" id="save-order">
                                            <i class="fas fa-save"></i> {{ __('Save Order') }} </button> @endif </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Add Document Modal -->
    <div class="modal fade" id="addDocumentModal" tabindex="-1" role="dialog" aria-labelledby="addDocumentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addDocumentModalLabel"> {{ __('Add New Documents') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form
                    action="{{ route('courses.chapters.lectures.documents.store', ['course' => $course->id, 'chapter' => $chapter->id, 'lecture' => $lecture->id]) }}"
                    method="POST" enctype="multipart/form-data"> @csrf <div class="modal-body">
                        <div class="form-group">
                            <label for="title"> {{ __('Document Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label> {{ __('Upload Files') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <div class="custom-file">
                                <input type="file" name="files[]" class="custom-file-input" id="customFile" multiple
                                    required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt">
                                <label class="custom-file-label" for="customFile"> {{ __('Choose files') }} </label>
                            </div>
                            <small class="form-text text-muted"> {{ __('Allowed file types: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT. Maximum size: 10MB per file.') }} </small>
                        </div>

                        <div class="form-group">
                            <label for="description"> {{ __('Description') }} </label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="order"> {{ __('Display Order') }} </label>
                            <input type="number" name="order" id="order" class="form-control"
                                value="{{ count($documents) }}">
                            <small class="form-text text-muted"> {{ __('Leave as is to add to the end.') }} </small>
                        </div>

                        <div class="form-group">
                            <label class="custom-switch">
                                <input type="checkbox" name="is_active" value="1" class="custom-switch-input"
                                    checked>
                                <span class="custom-switch-indicator"></span>
                                <span class="custom-switch-description"> {{ __('Active') }} </span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"> {{ __('Cancel') }} </button>
                        <button type="submit" class="btn btn-primary"> {{ __('Upload Documents') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Document Modal -->
    <div class="modal fade" id="editDocumentModal" tabindex="-1" role="dialog"
        aria-labelledby="editDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editDocumentModalLabel"> {{ __('Edit Document') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form id="editDocumentForm" method="POST" enctype="multipart/form-data"> @csrf
                    @method('PUT')
        <div class="modal-body">
                        <div class="form-group">
                            <label for="edit_title"> {{ __('Document Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label> {{ __('Add More Files') }} </label>
                            <div class="custom-file">
                                <input type="file" name="files[]" class="custom-file-input" id="edit_files" multiple
                                    accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt">
                                <label class="custom-file-label" for="edit_files"> {{ __('Choose files') }} </label>
                            </div>
                            <small class="form-text text-muted"> {{ __('Optional. Allowed file types: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT. Maximum size: 10MB
                                per file.') }} </small>
                        </div>

                        <div class="form-group">
                            <label for="edit_description"> {{ __('Description') }} </label>
                            <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="edit_order"> {{ __('Display Order') }} </label>
                            <input type="number" name="order" id="edit_order" class="form-control">
                        </div>

                        <div class="form-group">
                            <label class="custom-switch">
                                <input type="checkbox" name="is_active" id="edit_is_active" value="1"
                                    class="custom-switch-input">
                                <span class="custom-switch-indicator"></span>
                                <span class="custom-switch-description"> {{ __('Active') }} </span>
                            </label>
                        </div>

                        <div id="existing_files_container" class="mt-4">
                            <!-- Existing files will be populated here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"> {{ __('Cancel') }} </button>
                        <button type="submit" class="btn btn-primary"> {{ __('Update Document') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Files Modal -->
    <div class="modal fade" id="viewFilesModal" tabindex="-1" role="dialog" aria-labelledby="viewFilesModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewFilesModalLabel"> {{ __('Document Files') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="files_list">
                        <!-- Files will be populated here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"> {{ __('Close') }} </button>
                </div>
            </div>
        </div>
    </div> @endsection

@push(\'scripts\' <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
        $(document).ready(function() {
            // Show filenames in file input
            $('.custom-file-input').on('change', function() {
                var fileNames = Array.from(this.files).map(file => file.name).join(', ');
                $(this).next('.custom-file-label').html(fileNames || 'Choose files');
            });

            // Make table sortable
            $("#sortable-documents").sortable({
                handle: '.sort-handle',
                update: function(event, ui) {
                    // Update order numbers after drag
                    $('#sortable-documents tr').each(function(index) {
                        $(this).find('td:first-child div').text(index + 1);
                    });
                }
            });

            // Save the new order
            $('#save-order').click(function() {
                var documents = [];
                $('#sortable-documents tr').each(function(index) {
                    documents.push({
                        id: $(this).data('id'),
                        order: index
                    });
                });

                $.ajax({
                    url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/documents/reorder') }}",
                    method: 'POST',
                    data: {
                        _token: "{{ csrf_token() }}",
                        documents: documents
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            iziToast.success({
                                title: 'Success!',
                                message: 'Document order updated successfully',
                                position: 'topRight'
                            });
                        }
                    },
                    error: function(xhr) {
                        iziToast.error({
                            title: 'Error!',
                            message: 'Failed to update document order',
                            position: 'topRight'
                        });
                    }
                });
            });
        });

        // Function to edit document
        function editDocument(documentId) {
            // Fetch document data
            $.ajax({
                url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/documents') }}" +
                    '/' + documentId,
                method: 'GET',
                success: function(response) {
                    if (response.status === 'success') {
                        const document = response.data;

                        // Set form action URL
                        $('#editDocumentForm').attr('action',
                            "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/documents') }}" +
                            '/' + documentId);

                        // Populate form fields
                        $('#edit_title').val(document.title);
                        $('#edit_description').val(document.description);
                        $('#edit_order').val(document.order);
                        $('#edit_is_active').prop('checked', document.is_active);

                        // Reset file input
                        $('#edit_files').val('');
                        $('#edit_files').next('.custom-file-label').html('Choose files');

                        // Populate existing files
                        let filesHtml = '';
                        if (document.url && document.url.length > 0) {
                            filesHtml = '<h6> {{ __('Existing Files:') }} </h6><div class="list-group">';
                            document.url.forEach(function(file, index) {
                                let fileIcon = getFileIcon(file.name);
                                filesHtml += `
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="${fileIcon} mr-2"></i> ${file.name}
                                    <small class="text-muted ml-2">${formatFileSize(file.size)}</small>
                                </div>
                                <div>
                                    <a href="${getFileUrl(file.path)}" target="_blank" class="btn btn-sm btn-info mr-1">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeFile('${documentId}', '${file.path}')">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                            });
                            filesHtml += '</div>';
                        } else {
                            filesHtml =
                                '<div class="alert alert-info"> {{ __('No files attached to this document.') }} </div>';
                        }

                        $('#existing_files_container').html(filesHtml);

                        // Show the modal
                        $('#editDocumentModal').modal('show');
                    }
                },
                error: function(xhr) {
                    iziToast.error({
                        title: 'Error!',
                        message: 'Failed to load document data',
                        position: 'topRight'
                    });
                }
            });
        }

        // Function to view files
        function viewFiles(documentId) {
            // Fetch document data
            $.ajax({
                url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/documents') }}" +
                    '/' + documentId,
                method: 'GET',
                success: function(response) {
                    if (response.status === 'success') {
                        const document = response.data;

                        // Populate file list
                        let filesHtml = '';
                        if (document.url && document.url.length > 0) {
                            filesHtml = '<div class="list-group">';
                            document.url.forEach(function(file) {
                                let fileIcon = getFileIcon(file.name);
                                filesHtml += `
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="${fileIcon} mr-2"></i> ${file.name}
                                    <small class="text-muted ml-2">${formatFileSize(file.size)}</small>
                                </div>
                                <a href="${getFileUrl(file.path)}" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fas fa-download"></i> {{ __('Download') }} </a>
                            </div>
                        `;
                            });
                            filesHtml += '</div>';
                        } else {
                            filesHtml = '<div class="alert alert-info"> {{ __('No files available.') }} </div>';
                        }

                        $('#files_list').html(filesHtml);
                        $('#viewFilesModalLabel').text('Files: ' + document.title);

                        // Show the modal
                        $('#viewFilesModal').modal('show');
                    }
                },
                error: function(xhr) {
                    iziToast.error({
                        title: 'Error!',
                        message: 'Failed to load document files',
                        position: 'topRight'
                    });
                }
            });
        }

        // Function to remove a file
        function removeFile(documentId, filePath) {
            if (confirm('Are you sure you want to remove this file?')) {
                $.ajax({
                    url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/documents') }}" +
                        '/' + documentId + '/files',
                    method: 'DELETE',
                    data: {
                        _token: "{{ csrf_token() }}",
                        file_path: filePath
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            iziToast.success({
                                title: 'Success!',
                                message: 'File removed successfully',
                                position: 'topRight'
                            });

                            // Refresh document data
                            editDocument(documentId);
                        }
                    },
                    error: function(xhr) {
                        iziToast.error({
                            title: 'Error!',
                            message: 'Failed to remove file',
                            position: 'topRight'
                        });
                    }
                });
            }
        }

        // Helper function to get file icon based on extension
        function getFileIcon(filename) {
            const extension = filename.split('.').pop().toLowerCase();

            switch (extension) {
                case 'pdf':
                    return 'fas fa-file-pdf text-danger';
                case 'doc':
                case 'docx':
                    return 'fas fa-file-word text-primary';
                case 'xls':
                case 'xlsx':
                    return 'fas fa-file-excel text-success';
                case 'ppt':
                case 'pptx':
                    return 'fas fa-file-powerpoint text-warning';
                case 'txt':
                    return 'fas fa-file-alt text-secondary';
                default:
                    return 'fas fa-file text-info';
            }
        }

        // Helper function to format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Helper function to get file URL
        function getFileUrl(path) {
            return "{{ asset('storage') }}/" + path;
        }
    </script>
@endpush
