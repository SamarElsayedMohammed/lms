@extends('layouts.app')

@section('title'), 'Chapter Lectures')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>Lectures for: {{ $chapter->title }}</h1>
            <div class="section-header-button">
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addLectureModal"> {{ __('Add Lecture') }} </button>
            </div>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#"> {{ __('Dashboard') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.index') }}"> {{ __('Courses') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.show', $course->id) }}">{{ $course->name }}</a>
                </div>
                <div class="breadcrumb-item"><a href="{{ route('courses.chapters.index', $course->id) }}"> {{ __('Chapters') }} </a></div>
                <div class="breadcrumb-item"> {{ __('Lectures') }} </div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title"> {{ __('Manage Lectures') }} </h2>
            <p class="section-lead"> {{ __('Add and organize lectures for this chapter.') }} </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4>Chapter: {{ $chapter->title }}</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th width="5%"> {{ __('Order') }} </th>
                                            <th> {{ __('Title') }} </th>
                                            <th> {{ __('Type') }} </th>
                                            <th> {{ __('Duration') }} </th>
                                            <th> {{ __('Status') }} </th>
                                            <th width="15%"> {{ __('Action') }} </th>
                                        </tr>
                                    </thead>
                                    <tbody id="sortable-lectures"> @forelse($lectures as $lecture) <tr data-id="{{ $lecture->id }}">
                                                <td>
                                                    <div class="sort-handle">
                                                        <i class="fas fa-grip-vertical"></i>
                                                        {{ $lecture->order }}
                                                    </div>
                                                </td>
                                                <td>{{ $lecture->title }}</td>
                                                <td> @if ($lecture->type == \'video\' <span class="badge badge-primary"> {{ __('Video') }} </span> @elseif($lecture->type == \'document\') <span class="badge badge-info"> {{ __('Document') }} </span> @elseif($lecture->type == \'quiz\') <span class="badge badge-warning"> {{ __('Quiz') }} </span> @elseif($lecture->type == \'assignment\') <span class="badge badge-success"> {{ __('Assignment') }} </span> @endif </td>
                                                <td>
                                                    @if ($lecture->duration)
                                                        {{ gmdate('H:i:s', $lecture->duration) }}
                                                    @else
                                                        --
                                                    @endif
                                                </td>
                                                <td> @if ($lecture->is_active) <div class="badge badge-success"> {{ __('Active') }} </div> @else <div class="badge badge-warning"> {{ __('Inactive') }} </div> @endif </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-primary dropdown-toggle"
                                                            type="button" id="dropdownMenuButton" data-toggle="dropdown"
                                                            aria-haspopup="true" aria-expanded="false"> {{ __('Actions') }} </button>
                                                        <div class="dropdown-menu"> @if ($lecture->type == \'video\' <a class="dropdown-item" href="javascript:void(0)"
                                                                    onclick="manageLectureContent('{{ $lecture->id }}', 'video')">
                                                                    <i class="fas fa-film text-primary"></i> {{ __('Manage Video') }} </a> @elseif($lecture->type == \'document\') <a class="dropdown-item" href="javascript:void(0)"
                                                                    onclick="manageLectureContent('{{ $lecture->id }}', 'document')">
                                                                    <i class="fas fa-file-alt text-info"></i> {{ __('Manage
                                                                    Documents') }} </a> @elseif($lecture->type == \'quiz\') <a class="dropdown-item" href="javascript:void(0)"
                                                                    onclick="manageLectureContent('{{ $lecture->id }}', 'quiz')">
                                                                    <i class="fas fa-question-circle text-warning"></i> {{ __('Manage Quiz') }} </a> @elseif($lecture->type == \'assignment\') <a class="dropdown-item" href="javascript:void(0)"
                                                                    onclick="manageLectureContent('{{ $lecture->id }}', 'assignment')">
                                                                    <i class="fas fa-clipboard-list text-success"></i> {{ __('Manage Assignment') }} </a> @endif <a class="dropdown-item" href="javascript:void(0)"
                                                                onclick="editLecture('{{ $lecture->id }}')">
                                                                <i class="fas fa-edit text-primary"></i> {{ __('Edit') }} </a>

                                                            <div class="dropdown-divider"></div>

                                                            <form method="POST"
                                                                action="{{ route('courses.chapters.lectures.destroy', ['course' => $course->id, 'chapter' => $chapter->id, 'lecture' => $lecture->id]) }}"
                                                                class="d-inline lecture-delete-form"> @csrf
                                                                @method('DELETE')
        <button type="submit" class="dropdown-item">
                                                                    <i class="fas fa-trash text-danger"></i> {{ __('Delete') }} </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr> @empty <tr>
                                                <td colspan="6" class="text-center"> {{ __('No lectures found') }} </td>
                                            </tr> @endforelse </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer bg-whitesmoke">
                            <div class="row">
                                <div class="col-md-6">
                                    <a href="{{ route('courses.chapters.index', $course->id) }}" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> {{ __('Back to Chapters') }} </a>
                                </div>
                                <div class="col-md-6 text-right"> @if (count($lectures) > 1) <button class="btn btn-primary" id="save-order">
                                            <i class="fas fa-save"></i> {{ __('Save Order') }} </button> @endif </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Add Lecture Modal -->
    <div class="modal fade" id="addLectureModal" tabindex="-1" role="dialog" aria-labelledby="addLectureModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLectureModalLabel"> {{ __('Add New Lecture') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form id="lectureForm"
                    action="{{ route('courses.chapters.lectures.store', ['course' => $course->id, 'chapter' => $chapter->id]) }}"
                    method="POST"> @csrf <div class="modal-body">
                        <div class="form-group">
                            <label for="title"> {{ __('Lecture Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="type"> {{ __('Lecture Type') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <select name="type" id="type" class="form-control" required>
                                <option value="video"> {{ __('Video') }} </option>
                                <option value="document"> {{ __('Document') }} </option>
                                <option value="quiz"> {{ __('Quiz') }} </option>
                                <option value="assignment"> {{ __('Assignment') }} </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="description"> {{ __('Description') }} </label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="order"> {{ __('Display Order') }} </label>
                            <input type="number" name="order" id="order" class="form-control"
                                value="{{ count($lectures) }}">
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
                        <button type="submit" class="btn btn-primary"> {{ __('Create Lecture') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Lecture Modal -->
    <div class="modal fade" id="editLectureModal" tabindex="-1" role="dialog" aria-labelledby="editLectureModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editLectureModalLabel"> {{ __('Edit Lecture') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form id="editLectureForm" method="POST"> @csrf
                    @method('PUT')
        <div class="modal-body">
                        <div class="form-group">
                            <label for="edit_title"> {{ __('Lecture Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_type"> {{ __('Lecture Type') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <select name="type" id="edit_type" class="form-control" required>
                                <option value="video"> {{ __('Video') }} </option>
                                <option value="document"> {{ __('Document') }} </option>
                                <option value="quiz"> {{ __('Quiz') }} </option>
                                <option value="assignment"> {{ __('Assignment') }} </option>
                            </select>
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
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"> {{ __('Cancel') }} </button>
                        <button type="submit" class="btn btn-primary"> {{ __('Update Lecture') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div> @endsection

@push(\'scripts\' <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
        $(document).ready(function() {
            // Make table sortable
            $("#sortable-lectures").sortable({
                handle: '.sort-handle',
                update: function(event, ui) {
                    // Update order numbers after drag
                    $('#sortable-lectures tr').each(function(index) {
                        $(this).find('td:first-child div').text(index + 1);
                    });
                }
            });

            // Save the new order
            $('#save-order').click(function() {
                var lectures = [];
                $('#sortable-lectures tr').each(function(index) {
                    lectures.push({
                        id: $(this).data('id'),
                        order: index
                    });
                });

                $.ajax({
                    url: "{{ route('courses.chapters.lectures.reorder', ['course' => $course->id, 'chapter' => $chapter->id]) }}",
                    method: 'POST',
                    data: {
                        _token: "{{ csrf_token() }}",
                        lectures: lectures
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            iziToast.success({
                                title: 'Success!',
                                message: 'Lecture order updated successfully',
                                position: 'topRight'
                            });
                        }
                    },
                    error: function(xhr) {
                        iziToast.error({
                            title: 'Error!',
                            message: 'Failed to update lecture order',
                            position: 'topRight'
                        });
                    }
                });
            });

            // Confirm before deleting lecture
            $('.lecture-delete-form').on('submit', function(e) {
                e.preventDefault();
                if (confirm(
                        'Are you sure you want to delete this lecture? This will also delete all associated content.'
                    )) {
                    this.submit();
                }
            });
        });

        // Function to edit lecture
        function editLecture(lectureId) {
            // Fetch lecture data
            $.ajax({
                url: "{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}" +
                    '/' + lectureId,
                method: 'GET',
                success: function(response) {
                    if (response.status === 'success') {
                        const lecture = response.data;

                        // Set form action URL
                        $('#editLectureForm').attr('action',
                            "{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}" +
                            '/' + lectureId);

                        // Populate form fields
                        $('#edit_title').val(lecture.title);
                        $('#edit_type').val(lecture.type);
                        $('#edit_description').val(lecture.description);
                        $('#edit_order').val(lecture.order);
                        $('#edit_is_active').prop('checked', lecture.is_active);

                        // Show the modal
                        $('#editLectureModal').modal('show');
                    }
                },
                error: function(xhr) {
                    iziToast.error({
                        title: 'Error!',
                        message: 'Failed to load lecture data',
                        position: 'topRight'
                    });
                }
            });
        }

        // Function to manage lecture content based on type
        function manageLectureContent(lectureId, type) {
            let url;

            if (type === 'video') {
                // Redirect to video management
                url = "{{ route('courses.index') }}/{{ $course->id }}/chapters/{{ $chapter->id }}/lectures/" +
                    lectureId + "/videos";
            } else if (type === 'document') {
                // Redirect to document management
                url = "{{ route('courses.index') }}/{{ $course->id }}/chapters/{{ $chapter->id }}/lectures/" +
                    lectureId + "/documents";
            } else if (type === 'quiz') {
                // Redirect to quiz management
                url = "{{ route('courses.index') }}/{{ $course->id }}/chapters/{{ $chapter->id }}/lectures/" +
                    lectureId + "/quiz";
            } else if (type === 'assignment') {
                // Redirect to assignment management
                url = "{{ route('courses.index') }}/{{ $course->id }}/chapters/{{ $chapter->id }}/lectures/" +
                    lectureId + "/assignment";
            }

            if (url) {
                window.location.href = url;
            }
        }
    </script>
@endpush
