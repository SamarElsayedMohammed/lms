<div class="card">
    <h4 class="card-title mb-4">

    </h4>
    <div class="card-body">
        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('course-chapters.curriculum.assignment.update', $curriculum->course_chapter_id) }}" data-parsley-validate enctype="multipart/form-data" data-success-function="formSuccessFunction">
            <div class="row">
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="assignment_type_id" value="{{ $curriculum->id }}">
                    {{-- Assignment Title --}}
                <div class="form-group mandatory col-sm-12 col-md-6">
                    <label class="form-label d-block" for="assignment_title">{{ __('Assignment Title') }} </label>
                    <input type="text" name="assignment_title" class="form-control assignment-title-input" placeholder="{{ __('Assignment Title') }}" value="{{ $curriculum->title }}">
                </div>

                {{-- Points --}}
                <div class="form-group mandatory col-sm-12 col-md-6">
                    <label class="form-label d-block" for="assignment-points">{{ __('Points') }} </label>
                    <input type="number" name="assignment_points" id="assignment-points" class="form-control assignment-points-input" placeholder="{{ __('Points') }}" value="{{ $curriculum->points }}">
                </div>

                {{-- Assignment Description --}}
                <div class="form-group mandatory col-12">
                    <label class="form-label d-block" for="assignment-description">{{ __('Assignment Description') }} </label>
                    <textarea name="assignment_description" id="assignment-description" class="form-control assignment-description-input" placeholder="{{ __('Assignment Description') }}">{{ $curriculum->description }}</textarea>
                </div>
                
                {{-- Assignment Instructions --}}
                <div class="form-group mandatory col-12">
                    <label class="form-label d-block" for="assignment-instructions">{{ __('Assignment Instructions') }} </label>
                    <textarea name="assignment_instructions" id="assignment-instructions" class="form-control assignment-instructions-input" placeholder="{{ __('Assignment Instructions') }}">{{ $curriculum->instructions }}</textarea>
                </div>

                {{-- Allowed File Types --}}
                <div class="form-group mandatory col-sm-12 col-md-6 col-lg-4">
                    <label for="allowed-file-types" class="form-label">{{ __('Allowed File Types') }}</label>                     @php
                        // The model already returns an array due to the accessor
                        $fileTypes = $curriculum->allowed_file_types ?? [];
                        
                        // Define valid categories
                        $validCategories = ['audio', 'video', 'document', 'image'];
                        
                        // Check if the stored values are already categories or file extensions
                        $selectedTypes = [];
                        
                        // If the stored values are already categories, use them directly
                        foreach ($fileTypes as $fileType) {
                            if (in_array($fileType, $validCategories)) {
                                $selectedTypes[] = $fileType;
                            }
                        }
                        
                        // If no categories found, try to map file extensions to categories
                        if (empty($selectedTypes)) {
                            $fileTypeMap = [
                                'audio' => ['mp3', 'wav', 'ogg', 'm4a', 'm4b', 'm4p', 'aac', 'flac', 'wma', 'aiff', 'au', 'ra', 'amr', 'opus'],
                                'video' => ['mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'm4v', '3gp', '3g2', 'asf', 'rm', 'rmvb', 'vob', 'ogv', 'mts', 'm2ts'],
                                'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp', 'md', 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'],
                                'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'tif', 'svg', 'webp', 'ico', 'psd', 'ai', 'eps']
                            ];
                            
                            foreach ($fileTypeMap as $category => $types) {
                                if (array_intersect($fileTypes, $types)) {
                                    $selectedTypes[] = $category;
                                }
                            }
                        }
                    @endphp
                    
                  
                    
                    <select name="assignment_allowed_file_types[]" class="form-control tags-without-new-tag" multiple="multiple">
                        @if(isset($allowedFileTypes) && is_array($allowedFileTypes))
                            @foreach ($allowedFileTypes as $key => $value)
                                <option value="{{ $key }}"
                                    @if(in_array($key, $selectedTypes)) selected @endif>
                                    {{ $value }}
                                </option>
                            @endforeach
                        @else
                            <option value="">No file types available</option>
                        @endif
                    </select>
                </div>

                {{-- Can Skip --}}
                <div class="form-group col-sm-12 col-lg-2">
                    <label class="control-label">{{ __('Can Skip ?') }}</label>
                    <div class="custom-switches-stacked mt-2">
                        <label class="custom-switch">
                            <input type="checkbox" class="custom-switch-input custom-toggle-switch can-skip-switch"  {{ $curriculum->can_skip == 1 ? 'checked' : '' }}>
                            <input type="hidden" name="assignment_can_skip" class="custom-toggle-switch-value" value="{{ $curriculum->can_skip ?? 0 }}">
                            <span class="custom-switch-indicator"></span>
                        </label>
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
                                        <option value="document">{{ __('Document') }}</option>
                                        <option value="video">{{ __('Video') }}</option>
                                        <option value="audio">{{ __('Audio') }}</option>
                                        <option value="image">{{ __('Image') }}</option>
                                    </select>
                                </div>
                                {{-- Assignment Resource Id --}}
                                <input type="hidden" name="id" class="assignment-resource-id">
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
            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('Update Assignment') }}">
        </form>
    </div>
</div> @push(\'scripts\') <script>
        $(document).ready(function() {

            // Resources
            let resourcesExists = '{{ $curriculum->resources->count() }}';
            if (resourcesExists > 0) {
                $('#resource-toggle').prop('checked', true);
                $('#resource-status').val(1);
                $('.resource-container').show();

                resourceSectionRepeater.setList([
                    @foreach($curriculum->resources as $resource)
                        @if($resource->type == 'url')
                        {
                            'id': '{{ $resource->id }}',
                            'resource_type': '{{ $resource->type }}',
                            'resource_url': '{{ $resource->url }}',
                        },
                        @elseif($resource->type == 'file')
                        {
                            'id': '{{ $resource->id }}',
                            'resource_type': '{{ $resource->type }}',
                            'resource_file_url': '{{ $resource->file }}',
                        }
                        @else
                        {
                            'id': '{{ $resource->id }}',
                            'resource_type': '{{ $resource->type }}',
                            'resource_url': '{{ $resource->url }}',
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
                        $row.find('.resource-url-field, .resource-file-field').hide();

                        if (selectedType === 'url') {
                            $row.find('.resource-url-field').show();
                        } else if (selectedType === 'file') {
                            $row.find('.resource-file-field').show();
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

        });
        function formSuccessFunction(response){
            setTimeout(function(){
                window.location.reload();
            }, 1500);
        }
    </script>
@endpush
