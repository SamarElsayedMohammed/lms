<div class="card">
    <div class="card-body">
        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('course-chapters.curriculum.lecture.update', $curriculum->course_chapter_id) }}" data-parsley-validate enctype="multipart/form-data" data-success-function="formSuccessFunction">
            <div class="row">
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="lecture_type_id" value="{{ $curriculum->id }}">
                {{-- Lecture Title --}}
                <div class="form-group mandatory col-12">
                    <label class="form-label d-block" for="lecture-title">{{ __('Lecture Title') }} </label>
                    <input type="text" name="lecture_title" id="lecture-title" class="form-control" placeholder="{{ __('Video Title') }}" value="{{ $curriculum->title }}">
                </div>

                {{-- Lecture Description --}}
                <div class="form-group col-12">
                    <label class="form-label d-block" for="lecture-description">{{ __('Lecture Description') }} </label>
                    <textarea name="lecture_description" id="lecture-description" class="form-control" placeholder="{{ __('Lecture Description') }}">{{ $curriculum->description }}</textarea>
                </div>

                {{-- Lecture Type --}}
                <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                    <label class="form-label d-block" for="lecture_type">{{ __('Lecture Type') }} </label>
                    {{-- File --}}
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="lecture-type-file" name="lecture_type" value="file" class="custom-control-input lecture-type lecture-type-file" required>
                        <label class="custom-control-label" for="lecture-type-file">{{ __('File') }}</label>
                    </div>
                    {{-- Youtube URL --}}
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" id="lecture-type-youtube-url" name="lecture_type" value="youtube_url" class="custom-control-input lecture-type lecture-type-youtube-url" required>
                        <label class="custom-control-label" for="lecture-type-youtube-url">{{ __('Youtube URL') }}</label>
                    </div>
                </div>

                {{-- Lecture File Input --}}
                <div class="form-group lecture-file col-sm-12 col-md-6 col-xl-4">
                    <label class="form-label d-block" for="lecture_file">{{ __('Lecture File') }} </label>
                    <input type="file" name="lecture_file" class="form-control lecture-file-input" placeholder="{{ __('Lecture File') }}" accept="video/*,application/pdf,text/plain,text/markdown,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation">
                    @php
                        $curriculumType = is_array($curriculum) ? ($curriculum['type'] ?? null) : ($curriculum->type ?? null);
                    @endphp
                    @if($curriculumType == 'file')
                        @php
                            $curriculumFile = is_array($curriculum) ? ($curriculum['file'] ?? null) : ($curriculum->file ?? null);
                        @endphp
                        @if($curriculumFile)
                            <a href="{{ $curriculumFile }}" target="_blank" class="btn btn-primary mt-2">{{ __('File Preview') }}</a>
                        @endif
                    @endif
                </div>

                {{-- Lecture Youtube URL Input --}}
                <div class="form-group mandatory lecture-youtube-url col-sm-12 col-md-6 col-xl-4">
                    <label class="form-label d-block" for="lecture_youtube_url">{{ __('Lecture Youtube URL') }} </label>
                    <input type="text" name="lecture_youtube_url" class="form-control lecture-youtube-url-input" placeholder="{{ __('Lecture Youtube URL') }}" value="{{ old('lecture_youtube_url', $curriculum->youtube_url) }}">
                </div>

                <div class="row">
                    {{-- Lecture Hours --}}
                    <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                        <label class="form-label d-block" for="lecture-hours">{{ __('Lecture Hours') }} </label>
                        <input type="number" name="lecture_hours" id="lecture-hours" class="form-control" placeholder="{{ __('LectureHours') }}" min="0" value="{{ $curriculum->hours }}">
                    </div>

                    {{-- Lecture Minutes --}}
                    <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                        <label class="form-label d-block" for="lecture-minutes">{{ __('Lecture Minutes') }} </label>
                        <input type="number" name="lecture_minutes" id="lecture-minutes" class="form-control" placeholder="{{ __('Lecture Minutes') }}" min="0" max="59" value="{{ $curriculum->minutes }}">
                    </div>
                    {{-- Lecture Seconds --}}
                    <div class="form-group mandatory col-sm-12 col-md-6 col-xl-4">
                        <label class="form-label d-block" for="lecture-seconds">{{ __('Lecture Seconds') }} </label>
                        <input type="number" name="lecture_seconds" id="lecture-seconds" class="form-control" placeholder="{{ __('Lecture Seconds') }}" min="0" max="59" value="{{ $curriculum->seconds }}">
                    </div>
                </div>

                {{-- Is Active --}}
                <div class="form-group col-sm-12 col-md-6 col-xl-3">
                    <div class="control-label">{{ __('Status') }}</div>
                    <div class="custom-switches-stacked mt-2">
                        <label class="custom-switch">
                            <input type="checkbox" class="custom-switch-input custom-toggle-switch" {{ $curriculum->is_active == 1 ? 'checked' : '' }}>
                            <input type="hidden" name="is_active" class="custom-toggle-switch-value" value="{{ $curriculum->is_active ?? 0 }}">
                            <span class="custom-switch-indicator"></span>
                            <span class="custom-switch-description">{{ __('Active') }}</span>
                        </label>
                    </div>
                </div>

                {{-- Free Preview --}}
                <div class="form-group col-sm-12 col-md-6 col-xl-3">
                    <div class="control-label">{{ __('Free Preview') }}</div>
                    <div class="custom-switches-stacked mt-2">
                        <label class="custom-switch">
                            <input type="checkbox" class="custom-switch-input custom-toggle-switch" {{ $curriculum->free_preview == 1 ? 'checked' : '' }}>
                            <input type="hidden" name="lecture_free_preview" class="custom-toggle-switch-value" value="{{ $curriculum->free_preview ?? 0 }}">
                            <span class="custom-switch-indicator"></span>
                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                        </label>
                    </div>
                </div>

                {{-- Free Lesson (No Subscription Required) --}}
                <div class="form-group col-sm-12 col-md-6 col-xl-3">
                    <div class="control-label">{{ __('Free Lesson') }}</div>
                    <div class="custom-switches-stacked mt-2">
                        <label class="custom-switch">
                            <input type="checkbox" class="custom-switch-input custom-toggle-switch" {{ ($curriculum->is_free ?? 0) == 1 ? 'checked' : '' }}>
                            <input type="hidden" name="lecture_is_free" class="custom-toggle-switch-value" value="{{ $curriculum->is_free ?? 0 }}">
                            <span class="custom-switch-indicator"></span>
                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                        </label>
                    </div>
                </div>
                
                <div><hr></div>
                {{-- Resource Toggle Section --}}
                <div class="form-group col-12 resource-toggle-section">
                    <div class="control-label">{{ __('Resource') }}</div>
                    <div class="custom-switches-stacked mt-2">
                        <label class="custom-switch">
                            <input type="checkbox" class="custom-switch-input custom-toggle-switch" id="resource-toggle">
                            <input type="hidden" name="resource_status" class="custom-toggle-switch-value" value="0" id="resource-status">
                            <span class="custom-switch-indicator"></span>
                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                        </label>
                    </div>
                </div>
                <div class="resource-container" style="display: none;">
                    {{-- Resource Repeater Section --}}
                    <div class="resource-section">
                        <div data-repeater-list="resource_data">
                            <div class="row resource-input-section d-flex align-items-center mb-2 bg-light p-3 rounded mt-2" data-repeater-item>
                                {{-- Remove Resource --}}
                                <div class="form-group col-12">
                                    <button data-repeater-delete type="button" class="btn btn-danger remove-resource" title="{{ __('remove') }}">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </div>
                                {{-- Resource Type --}}
                                <div class="form-group mandatory col-sm-12 col-lg-6">
                                    <label class="form-label d-block">{{ __('Resource Type') }} </label>
                                    <select name="resource_type" class="form-control course-chapter-resource-type">
                                        <option value="">{{ __('Select Resource Type') }}</option>
                                        <option value="url">{{ __('External URL') }}</option>
                                        <option value="file">{{ __('File') }}</option>
                                    </select>
                                </div>

                                {{-- Resource Title --}}
                                <div class="form-group mandatory col-sm-12 col-lg-6 resource-title-field" style="display: none;">
                                    <label class="form-label d-block">{{ __('Resource Title') }} </label>
                                    <input type="text" name="resource_title" class="form-control resource-title-input" placeholder="{{ __('Resource Title') }}">
                                </div>

                                {{-- Lecture Resource Id --}}
                                <input type="hidden" name="id" class="lecture-resource-id">
                                {{-- Resource URL Input --}}
                                <div class="form-group mandatory resource-url col-sm-12 col-lg-6" style="display: none;">
                                    <label class="form-label d-block">{{ __('Resource URL') }} </label>
                                    <input type="text" name="resource_url" class="form-control resource-url-input" placeholder="{{ __('Resource URL') }}">
                                </div>

                                {{-- Resource File Input --}}
                                <div class="form-group mandatory resource-file col-sm-12 col-lg-6" style="display: none;">
                                    <label class="form-label d-block">{{ __('Resource File') }} </label>
                                    <input type="file" name="resource_file" class="form-control resource-file-input" placeholder="{{ __('Resource File') }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.m4v,.3gp,.3g2,.asf,.rm,.rmvb,.vob,.ogv,.mts,.m2ts,.mp3,.wav,.ogg,.m4a,.m4b,.m4p,.aac,.flac,.wma,.aiff,.au,.ra,.amr,.opus,.zip,.rar,.7z,.tar,.gz,.bz2,.xz">
                                    <input type="hidden" name="resource_file_url" class="resource-file-url">
                                    <a target="_blank" class="btn btn-primary mt-2 resource-file-preview" style="display: none;">{{ __('File Preview') }}</a>
                                </div>
                            </div>
                        </div>
                        {{-- Add New Resource --}}
                        <button type="button" class="btn btn-success mt-1" data-repeater-create title="{{ __('Add New Resource') }}">
                            <i class="fa fa-plus"></i> {{ __('Add New Resource') }}
                        </button>
                    </div>
                    <div><hr></div>
                </div>
                {{-- End Resource Container --}}
            </div>
            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('Update Lecture') }}">
        </form>
    </div>
</div>

@push('scripts')
<script>
        $(document).ready(function() {
            // Lecture Type
            @php
                $curriculumType = is_array($curriculum) ? ($curriculum['type'] ?? '') : ($curriculum->type ?? '');
            @endphp
            let lectureType = '{{ $curriculumType }}';
            if(lectureType == 'file') {
                $('.lecture-file').show();
                $('.lecture-youtube-url').hide();
                $('.lecture-type-file').prop('checked', true);
                $('.lecture-type-youtube-url').prop('checked', false);
            }else if(lectureType == 'youtube_url') {
                $('.lecture-youtube-url').show();
                $('.lecture-file').hide();
                $('.lecture-type-file').prop('checked', false);
                $('.lecture-type-youtube-url').prop('checked', true);
            }

            // Resources
            @php
                $resources = is_array($curriculum) ? ($curriculum['resources'] ?? collect()) : ($curriculum->resources ?? collect());
                if (is_array($resources)) {
                    $resources = collect($resources);
                }
            @endphp
            let resourcesExists = '{{ $resources->count() }}';
            if (resourcesExists > 0) {
                $('#resource-toggle').prop('checked', true);
                $('#resource-status').val(1);
                $('.resource-container').show();

                resourceSectionRepeater.setList([
                    @foreach($resources as $resource)
                        @php
                            $resourceType = is_array($resource) ? ($resource['type'] ?? '') : ($resource->type ?? '');
                            $resourceId = is_array($resource) ? ($resource['id'] ?? '') : ($resource->id ?? '');
                            $resourceTitle = is_array($resource) ? ($resource['title'] ?? '') : ($resource->title ?? '');
                            $resourceUrl = is_array($resource) ? ($resource['url'] ?? '') : ($resource->url ?? '');
                            $resourceFile = is_array($resource) ? ($resource['file'] ?? '') : ($resource->file ?? '');
                        @endphp
                        @if($resourceType == 'url')
                        {
                            'id': '{{ $resourceId }}',
                            'resource_type': '{{ $resourceType }}',
                            'resource_title': '{{ $resourceTitle }}',
                            'resource_url': '{{ $resourceUrl }}',
                        },
                        @elseif($resourceType == 'file')
                        {
                            'id': '{{ $resourceId }}',
                            'resource_type': '{{ $resourceType }}',
                            'resource_title': '{{ $resourceTitle }}',
                            'resource_file_url': '{{ $resourceFile }}',
                        }
                        @else
                        {
                            'id': '{{ $resourceId }}',
                            'resource_type': '{{ $resourceType }}',
                            'resource_title': '{{ $resourceTitle }}',
                            'resource_url': '{{ $resourceUrl }}',
                        }
                        @endif
                    @endforeach
                ]);

                // Bind change event to each resource_type after repeater sets the list
                setTimeout(function () {
                    $('.resource-container').find('.course-chapter-resource-type').off('change').on('change', function () {
                        let $row = $(this).closest('[data-repeater-item]');
                        let selectedType = $(this).val();

                        // Hide all resource-specific fields
                        $row.find('.resource-url, .resource-file, .resource-title-field').hide();

                        if (selectedType && selectedType !== '') {
                            $row.find('.resource-title-field').show();
                        }

                        if (selectedType === 'url') {
                            $row.find('.resource-url').show();
                        } else if (selectedType === 'file') {
                            $row.find('.resource-file').show();
                            let fileUrl = $row.find('.resource-file-url').val();
                            if (fileUrl) {
                                $row.find('.resource-file-preview').attr('href', '{{ asset("storage") }}/' + fileUrl).show();
                            }
                        }
                    }).trigger('change'); // trigger once to reflect current state
                }, 100); // Delay ensures DOM is ready after `setList`

            } else {
                $('#resource-toggle').prop('checked', false);
                $('#resource-status').val(0);
                $('.resource-container').hide();
            }

            // Handle resource toggle change
            $('#resource-toggle').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#resource-status').val(1);
                    $('.resource-container').show();
                    // Re-enable validation for resource fields
                    $('.resource-container').find('[name^="resource_data"]').attr('data-parsley-required', 'true');
                } else {
                    $('#resource-status').val(0);
                    $('.resource-container').hide();
                    // Clear all resource data when toggle is off
                    if (typeof resourceSectionRepeater !== 'undefined') {
                        resourceSectionRepeater.setList([]);
                    }
                    // Remove validation attributes from resource fields
                    $('.resource-container').find('[name^="resource_data"]').removeAttr('data-parsley-required').removeAttr('required');
                }
            });

            // Before form submission, remove resource_data if resource_status is 0
            $('.create-form').on('submit', function(e) {
                var resourceStatus = $('#resource-status').val();
                if (resourceStatus == '0' || resourceStatus == 0 || !resourceStatus) {
                    // Remove all resource_data inputs before submission
                    $(this).find('[name^="resource_data"]').remove();
                    // Also clear any Parsley validation errors for resource fields
                    if (typeof window.Parsley !== 'undefined') {
                        $(this).parsley().reset();
                    }
                }
            });

        });

        // Handle resource type change to show/hide resource title field for new resources
        $(document).on('change', '.course-chapter-resource-type', function() {
            var resourceType = $(this).val();
            var resourceTitleField = $(this).closest('.resource-input-section').find('.resource-title-field');
            
            if (resourceType && resourceType !== '') {
                resourceTitleField.show();
            } else {
                resourceTitleField.hide();
            }
        });

        function formSuccessFunction(response){
            setTimeout(function(){
                window.location.reload();
            }, 1500);
        }
    </script>
@endpush
