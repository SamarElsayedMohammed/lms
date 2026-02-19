@extends('layouts.app')

@section('title'), 'Manage Video Lecture')

@section('content')
    <section class="section">
        <div class="section-header">
            <h1>Manage Video: {{ $lecture->title }}</h1>
            <div class="section-header-breadcrumb">
                <div class="breadcrumb-item active"><a href="#"> {{ __('Dashboard') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.index') }}"> {{ __('Courses') }} </a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.show', $course->id) }}">{{ $course->name }}</a></div>
                <div class="breadcrumb-item"><a href="{{ route('courses.chapters.index', $course->id) }}"> {{ __('Chapters') }} </a></div>
                <div class="breadcrumb-item"><a
                        href="{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}"> {{ __('Lectures') }} </a>
                </div>
                <div class="breadcrumb-item"> {{ __('Video') }} </div>
            </div>
        </div>

        <div class="section-body">
            <h2 class="section-title"> {{ __('Lecture Videos') }} </h2>
            <p class="section-lead"> {{ __('Add and manage videos for this lecture.') }} </p>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h4> {{ __('Video Management') }} </h4>
                            <div class="card-header-action">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addVideoModal">
                                    <i class="fas fa-plus"></i> {{ __('Add Video') }} </button>
                            </div>
                        </div>
                        <div class="card-body"> @if (count($videos) > 0) <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th width="5%"> {{ __('Order') }} </th>
                                                <th> {{ __('Title') }} </th>
                                                <th> {{ __('URL') }} </th>
                                                <th> {{ __('Duration') }} </th>
                                                <th> {{ __('Status') }} </th>
                                                <th width="15%"> {{ __('Action') }} </th>
                                            </tr>
                                        </thead>
                                        <tbody id="sortable-videos"> @foreach ($videos as $video) <tr data-id="{{ $video->id }}">
                                                    <td>
                                                        <div class="sort-handle">
                                                            <i class="fas fa-grip-vertical"></i>
                                                            {{ $video->order }}
                                                        </div>
                                                    </td>
                                                    <td>{{ $video->title }}</td>
                                                    <td>
                                                        <a href="{{ $video->url }}" target="_blank"
                                                            class="btn btn-sm btn-icon btn-info">
                                                            <i class="fas fa-external-link-alt"></i> {{ __('View') }} </a>
                                                    </td>
                                                    <td>{{ $video->formatted_duration ?? '--' }}</td>
                                                    <td> @if ($video->is_active) <div class="badge badge-success"> {{ __('Active') }} </div> @else <div class="badge badge-warning"> {{ __('Inactive') }} </div> @endif </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-primary"
                                                            onclick="editVideo('{{ $video->id }}')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <form
                                                            action="{{ route('courses.chapters.lectures.videos.destroy', ['course' => $course->id, 'chapter' => $chapter->id, 'lecture' => $lecture->id, 'video' => $video->id]) }}"
                                                            method="POST" class="d-inline"> @csrf
                                                            @method('DELETE')
        <button type="submit" class="btn btn-sm btn-danger"
                                                                onclick="return confirm('Are you sure you want to delete this video?')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr> @endforeach </tbody>
                                    </table>
                                </div> @else <div class="empty-state" data-height="400">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-film"></i>
                                    </div>
                                    <h2> {{ __('No Videos Added Yet') }} </h2>
                                    <p class="lead"> {{ __('Start by adding a video to this lecture.') }} </p>
                                    <button class="btn btn-primary mt-4" data-toggle="modal" data-target="#addVideoModal">
                                        <i class="fas fa-plus"></i> {{ __('Add Video') }} </button>
                                </div> @endif </div>
                        <div class="card-footer bg-whitesmoke">
                            <div class="row">
                                <div class="col-md-6">
                                    <a href="{{ route('courses.chapters.lectures.index', ['course' => $course->id, 'chapter' => $chapter->id]) }}"
                                        class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> {{ __('Back to Lectures') }} </a>
                                </div>
                                <div class="col-md-6 text-right"> @if (count($videos) > 1) <button class="btn btn-primary" id="save-order">
                                            <i class="fas fa-save"></i> {{ __('Save Order') }} </button> @endif </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Video Preview Card --> @if (count($videos) > 0) <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4> {{ __('Preview First Video') }} </h4>
                            </div>
                            <div class="card-body">
                                <div class="embed-responsive embed-responsive-16by9">
                                    @php
                                        $videoUrl = $videos->first()->url;
                                        $videoId = null;

                                        // Extract YouTube video ID
                                        if (strpos($videoUrl, 'youtube.com') !== false) {
                                            parse_str(parse_url($videoUrl, PHP_URL_QUERY), $params);
                                            $videoId = isset($params['v']) ? $params['v'] : null;
                                        } elseif (strpos($videoUrl, 'youtu.be') !== false) {
                                            $videoId = basename(parse_url($videoUrl, PHP_URL_PATH));
                                        }

                                        // Extract Vimeo video ID
                                        if (strpos($videoUrl, 'vimeo.com') !== false) {
                                            $vimeoId = basename(parse_url($videoUrl, PHP_URL_PATH));
                                        }
                                    @endphp

                                    @if (isset($videoId))
                                        <iframe class="embed-responsive-item"
                                            src="https://www.youtube.com/embed/{{ $videoId }}"
                                            allowfullscreen></iframe> @elseif(isset($vimeoId)) <iframe class="embed-responsive-item"
                                            src="https://player.vimeo.com/video/{{ $vimeoId }}"
                                            allowfullscreen></iframe> @else <div class="alert alert-info"> {{ __('Video URL format not recognized or supported for
                                            preview.') }} </div> @endif </div>
                            </div>
                        </div>
                    </div>
                </div> @endif </div>
    </section>

    <!-- Add Video Modal -->
    <div class="modal fade" id="addVideoModal" tabindex="-1" role="dialog" aria-labelledby="addVideoModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addVideoModalLabel"> {{ __('Add New Video') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form
                    action="{{ route('courses.chapters.lectures.videos.store', ['course' => $course->id, 'chapter' => $chapter->id, 'lecture' => $lecture->id]) }}"
                    method="POST"> @csrf <div class="modal-body">
                        <div class="form-group">
                            <label for="title"> {{ __('Video Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="url"> {{ __('Video URL') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="url" id="url" class="form-control" required
                                placeholder="YouTube or Vimeo URL">
                            <small class="form-text text-muted"> {{ __('Enter YouTube or Vimeo video URL.') }} </small>
                        </div>

                        <div class="form-group">
                            <label for="duration"> {{ __('Duration (in seconds)') }} </label>
                            <input type="number" name="duration" id="duration" class="form-control" min="0">
                            <small class="form-text text-muted"> {{ __('Optional. Enter the video duration in seconds.') }} </small>
                        </div>

                        <div class="form-group">
                            <label for="description"> {{ __('Description') }} </label>
                            <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="order"> {{ __('Display Order') }} </label>
                            <input type="number" name="order" id="order" class="form-control"
                                value="{{ count($videos) }}">
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
                        <button type="submit" class="btn btn-primary"> {{ __('Add Video') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Video Modal -->
    <div class="modal fade" id="editVideoModal" tabindex="-1" role="dialog" aria-labelledby="editVideoModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editVideoModalLabel"> {{ __('Edit Video') }} </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true"> {{ __('&times;') }} </span>
                    </button>
                </div>
                <form id="editVideoForm" method="POST"> @csrf
                    @method('PUT')
        <div class="modal-body">
                        <div class="form-group">
                            <label for="edit_title"> {{ __('Video Title') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="edit_url"> {{ __('Video URL') }} <span class="text-danger"> {{ __('*') }} </span></label>
                            <input type="text" name="url" id="edit_url" class="form-control" required
                                placeholder="YouTube or Vimeo URL">
                            <small class="form-text text-muted"> {{ __('Enter YouTube or Vimeo video URL.') }} </small>
                        </div>

                        <div class="form-group">
                            <label for="edit_duration"> {{ __('Duration (in seconds)') }} </label>
                            <input type="number" name="duration" id="edit_duration" class="form-control"
                                min="0">
                            <small class="form-text text-muted"> {{ __('Optional. Enter the video duration in seconds.') }} </small>
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
                        <button type="submit" class="btn btn-primary"> {{ __('Update Video') }} </button>
                    </div>
                </form>
            </div>
        </div>
    </div> @endsection

@push(\'scripts\' <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
        $(document).ready(function() {
            // Make table sortable
            $("#sortable-videos").sortable({
                handle: '.sort-handle',
                update: function(event, ui) {
                    // Update order numbers after drag
                    $('#sortable-videos tr').each(function(index) {
                        $(this).find('td:first-child div').text(index + 1);
                    });
                }
            });

            // Save the new order
            $('#save-order').click(function() {
                var videos = [];
                $('#sortable-videos tr').each(function(index) {
                    videos.push({
                        id: $(this).data('id'),
                        order: index
                    });
                });

                $.ajax({
                    url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/videos/reorder') }}",
                    method: 'POST',
                    data: {
                        _token: "{{ csrf_token() }}",
                        videos: videos
                    },
                    success: function(response) {
                        if (response.status === 'success') {
                            iziToast.success({
                                title: 'Success!',
                                message: 'Video order updated successfully',
                                position: 'topRight'
                            });
                        }
                    },
                    error: function(xhr) {
                        iziToast.error({
                            title: 'Error!',
                            message: 'Failed to update video order',
                            position: 'topRight'
                        });
                    }
                });
            });
        });

        // Function to edit video
        function editVideo(videoId) {
            // Fetch video data
            $.ajax({
                url: "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/videos') }}" +
                    '/' + videoId,
                method: 'GET',
                success: function(response) {
                    if (response.status === 'success') {
                        const video = response.data;

                        // Set form action URL
                        $('#editVideoForm').attr('action',
                            "{{ url('api/courses/' . $course->id . '/chapters/' . $chapter->id . '/lectures/' . $lecture->id . '/videos') }}" +
                            '/' + videoId);

                        // Populate form fields
                        $('#edit_title').val(video.title);
                        $('#edit_url').val(video.url);
                        $('#edit_duration').val(video.duration);
                        $('#edit_description').val(video.description);
                        $('#edit_order').val(video.order);
                        $('#edit_is_active').prop('checked', video.is_active);

                        // Show the modal
                        $('#editVideoModal').modal('show');
                    }
                },
                error: function(xhr) {
                    iziToast.error({
                        title: 'Error!',
                        message: 'Failed to load video data',
                        position: 'topRight'
                    });
                }
            });
        }
    </script>
@endpush
