@extends('layouts.app')

@section('title')
    {{ __('HLS Video Management') }}
@endsection

@push('style')
    <link rel="stylesheet" href="{{ asset('library/bootstrap-table/bootstrap-table.min.css') }}">
@endpush

@section('main')
    <section class="section">
        <div class="section-header">
            <h1><i class="fas fa-video"></i> {{ __('HLS Video Management') }}</h1>
            <div class="section-header-button">
                <button class="btn btn-primary" onclick="refreshStatus()">
                    <i class="fas fa-sync-alt"></i> {{ __('Refresh Status') }}
                </button>
            </div>
        </div>

        <!-- System Status -->
        @if($ffmpegStatus['available'])
            <div class="alert alert-success d-flex align-items-center justify-content-between mb-4">
                <div>
                    <i class="fas fa-check-circle mr-2"></i>
                    <strong>{{ __('HLS Encoding Ready') }}</strong>
                    <span class="mx-2">|</span>
                    <span class="text-muted">FFmpeg {{ $ffmpegStatus['version'] ?? '' }}</span>
                </div>
                <div>
                    <span class="badge badge-success mr-2"><i class="fas fa-check"></i> proc_open</span>
                    <span class="badge badge-success"><i class="fas fa-check"></i> FFmpeg</span>
                </div>
            </div>
        @else
            <div class="alert alert-danger mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>{{ __('HLS Encoding Not Available') }}</strong>
                    </div>
                    <div>
                        @if($ffmpegStatus['proc_open_available'])
                            <span class="badge badge-success mr-2"><i class="fas fa-check"></i> proc_open</span>
                        @else
                            <span class="badge badge-danger mr-2"><i class="fas fa-times"></i> proc_open</span>
                        @endif
                        @if($ffmpegStatus['path'])
                            <span class="badge badge-success"><i class="fas fa-check"></i> FFmpeg</span>
                        @else
                            <span class="badge badge-danger"><i class="fas fa-times"></i> FFmpeg</span>
                        @endif
                    </div>
                </div>
                <hr class="my-2">
                <small>
                    @if(!$ffmpegStatus['proc_open_available'])
                        <strong>proc_open:</strong> {{ __('Enable in php.ini by removing from disable_functions') }}<br>
                    @endif
                    @if(!$ffmpegStatus['path'])
                        <strong>FFmpeg:</strong> {{ __('Install via') }} <code>apt install ffmpeg</code> (Ubuntu) {{ __('or') }} <code>yum install ffmpeg</code> (CentOS)
                    @endif
                </small>
            </div>
        @endif

        @if($ffmpegStatus['available'])
            <!-- Statistics Cards -->
            <div class="row">
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-primary">
                            <i class="fas fa-video"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Total Videos') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['total']) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-warning">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('In Queue') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['pending']) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-info">
                            <i class="fas fa-cog fa-spin"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Processing') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['processing']) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Completed') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['completed']) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Failed') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['failed']) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 col-12">
                    <div class="card card-statistic-1">
                        <div class="card-icon bg-secondary">
                            <i class="fas fa-minus-circle"></i>
                        </div>
                        <div class="card-wrap">
                            <div class="card-header">
                                <h4>{{ __('Not Encoded') }}</h4>
                            </div>
                            <div class="card-body">
                                {{ number_format($stats['not_encoded']) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- HLS Settings Card -->
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-cog"></i> {{ __('HLS Settings') }}</h4>
                </div>
                <div class="card-body py-3">
                    <form id="hlsSettingsForm">
                        <div class="d-flex align-items-center flex-wrap">
                                <label for="hls_max_file_size_mb" class="mb-0 mr-2 text-nowrap">{{ __('Max File Size:') }}</label>
                                <input type="number" class="form-control mr-2" id="hls_max_file_size_mb" name="hls_max_file_size_mb" value="{{ $settings['hls_max_file_size_mb'] ?? '500' }}" min="1" max="10000" style="width: 100px;">
                                <span class="mr-4 text-muted">MB</span>
                            <div class="custom-control custom-switch ml-4 mr-4">
                                <input type="checkbox" class="custom-control-input" id="hls_auto_encode" name="hls_auto_encode" value="1" {{ ($settings['hls_auto_encode'] ?? '1') == '1' ? 'checked' : '' }}>
                                <label class="custom-control-label" for="hls_auto_encode">{{ __('Auto-encode on upload') }}</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-save"></i> {{ __('Save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Videos Table Card -->
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-list"></i> {{ __('Video Lectures') }}</h4>
                    <div class="card-header-action">
                        @if($stats['failed'] > 0)
                            <button class="btn btn-warning" onclick="bulkRetryFailed()">
                                <i class="fas fa-redo"></i> {{ __('Retry All Failed') }} ({{ $stats['failed'] }})
                            </button>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <select class="form-control" id="statusFilter" onchange="refreshTable()">
                                <option value="all">{{ __('All Status') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="processing">{{ __('Processing') }}</option>
                                <option value="completed">{{ __('Completed') }}</option>
                                <option value="failed">{{ __('Failed') }}</option>
                                <option value="not_encoded">{{ __('Not Encoded') }}</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <input type="text" class="form-control" id="searchFilter" placeholder="{{ __('Search by course, chapter, or lecture name...') }}" onkeyup="debounceSearch()">
                        </div>
                        <div class="col-md-4 text-right">
                            <button class="btn btn-secondary" onclick="refreshTable()">
                                <i class="fas fa-sync-alt"></i> {{ __('Refresh') }}
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <table id="videosTable"
                           data-toggle="table"
                           data-url="{{ route('settings.hls.videos') }}"
                           data-pagination="true"
                           data-side-pagination="server"
                           data-page-size="20"
                           data-page-list="[10, 20, 50, 100]"
                           data-show-columns="true"
                           data-show-refresh="true"
                           data-query-params="queryParams"
                           data-response-handler="responseHandler"
                           class="table table-striped">
                        <thead>
                            <tr>
                                <th data-field="id" data-sortable="true">{{ __('ID') }}</th>
                                <th data-field="course_name">{{ __('Course') }}</th>
                                <th data-field="chapter_name">{{ __('Chapter') }}</th>
                                <th data-field="title" data-sortable="true">{{ __('Lecture') }}</th>
                                <th data-field="hls_status_badge" data-sortable="true" data-sort-name="hls_status">{{ __('Status') }}</th>
                                <th data-field="hls_error_message" data-formatter="errorFormatter">{{ __('Error') }}</th>
                                <th data-field="hls_encoded_at" data-sortable="true">{{ __('Encoded At') }}</th>
                                <th data-field="id" data-formatter="actionFormatter">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('library/bootstrap-table/bootstrap-table.min.js') }}"></script>
    <script>
        let searchTimeout;
        let videosTableData = [];

        function refreshStatus() {
            Swal.fire({
                title: '{{ __("Refreshing...") }}',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: '{{ route("settings.hls.refresh-status") }}',
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                success: function(response) {
                    Swal.fire({
                        icon: 'success',
                        title: '{{ __("Success") }}',
                        text: response.message,
                        timer: 1500
                    }).then(() => {
                        location.reload();
                    });
                },
                error: function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: '{{ __("Error") }}',
                        text: xhr.responseJSON?.message || '{{ __("Something went wrong") }}'
                    });
                }
            });
        }

        function queryParams(params) {
            params.status = $('#statusFilter').val();
            params.search = $('#searchFilter').val();
            return params;
        }

        function responseHandler(res) {
            videosTableData = res.rows || [];
            return res;
        }

        function refreshTable() {
            $('#videosTable').bootstrapTable('refresh');
        }

        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(refreshTable, 500);
        }

        function errorFormatter(value, row) {
            if (!value) return '-';
            const truncated = value.length > 50 ? value.substring(0, 50) + '...' : value;
            return `<span title="${value}" class="text-danger">${truncated}</span>`;
        }

        function actionFormatter(value, row) {
            const status = row.hls_status;
            let buttons = '';

            if (status === null || status === 'not_encoded') {
                buttons = `<button class="btn btn-sm btn-primary" onclick="encodeVideo(${value})" title="{{ __('Encode') }}">
                    <i class="fas fa-play"></i>
                </button>`;
            } else if (status === 'failed') {
                buttons = `<button class="btn btn-sm btn-warning" onclick="retryVideo(${value})" title="{{ __('Retry') }}">
                    <i class="fas fa-redo"></i>
                </button>`;
            } else if (status === 'completed') {
                buttons = `<button class="btn btn-sm btn-info" onclick="reencodeVideo(${value})" title="{{ __('Re-encode') }}">
                    <i class="fas fa-sync"></i>
                </button>`;
            } else if (status === 'pending' || status === 'processing') {
                buttons = `<span class="text-muted"><i class="fas fa-hourglass-half"></i></span>`;
            }

            return buttons;
        }

        function encodeVideo(id) {
            performAction('{{ route("settings.hls.encode", ":id") }}'.replace(':id', id), '{{ __("Encode this video?") }}');
        }

        function retryVideo(id) {
            performAction('{{ route("settings.hls.retry", ":id") }}'.replace(':id', id), '{{ __("Retry encoding this video?") }}');
        }

        function reencodeVideo(id) {
            Swal.fire({
                title: '{{ __("Re-encode Video") }}',
                text: '{{ __("This will delete the existing HLS files and re-encode the video. Continue?") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: '{{ __("Yes, re-encode") }}',
                cancelButtonText: '{{ __("Cancel") }}'
            }).then((result) => {
                if (result.isConfirmed) {
                    performAction('{{ route("settings.hls.reencode", ":id") }}'.replace(':id', id), null, true);
                }
            });
        }

        function performAction(url, confirmText, skipConfirm = false) {
            const doAction = function() {
                $.ajax({
                    url: url,
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        Swal.fire({
                            icon: response.error ? 'error' : 'success',
                            title: response.error ? '{{ __("Error") }}' : '{{ __("Success") }}',
                            text: response.message,
                            timer: 2000
                        });
                        refreshTable();
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: '{{ __("Error") }}',
                            text: xhr.responseJSON?.message || '{{ __("Something went wrong") }}'
                        });
                    }
                });
            };

            if (skipConfirm) {
                doAction();
            } else {
                Swal.fire({
                    title: '{{ __("Confirm") }}',
                    text: confirmText,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '{{ __("Yes") }}',
                    cancelButtonText: '{{ __("Cancel") }}'
                }).then((result) => {
                    if (result.isConfirmed) {
                        doAction();
                    }
                });
            }
        }

        function bulkRetryFailed() {
            Swal.fire({
                title: '{{ __("Retry All Failed Videos") }}',
                text: '{{ __("This will queue all failed videos for re-encoding. Continue?") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: '{{ __("Yes, retry all") }}',
                cancelButtonText: '{{ __("Cancel") }}'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '{{ route("settings.hls.bulk-retry") }}',
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            Swal.fire({
                                icon: response.error ? 'error' : 'success',
                                title: response.error ? '{{ __("Error") }}' : '{{ __("Success") }}',
                                text: response.message
                            }).then(() => {
                                location.reload();
                            });
                        },
                        error: function(xhr) {
                            Swal.fire({
                                icon: 'error',
                                title: '{{ __("Error") }}',
                                text: xhr.responseJSON?.message || '{{ __("Something went wrong") }}'
                            });
                        }
                    });
                }
            });
        }

        // HLS Settings Form
        $('#hlsSettingsForm').on('submit', function(e) {
            e.preventDefault();

            $.ajax({
                url: '{{ route("settings.hls.update-settings") }}',
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                data: {
                    hls_auto_encode: $('#hls_auto_encode').is(':checked') ? 1 : 0,
                    hls_max_file_size_mb: $('#hls_max_file_size_mb').val()
                },
                success: function(response) {
                    Swal.fire({
                        icon: response.error ? 'error' : 'success',
                        title: response.error ? '{{ __("Error") }}' : '{{ __("Success") }}',
                        text: response.message,
                        timer: 2000
                    });
                },
                error: function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: '{{ __("Error") }}',
                        text: xhr.responseJSON?.message || '{{ __("Something went wrong") }}'
                    });
                }
            });
        });
    </script>
@endpush
