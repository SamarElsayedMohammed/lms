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
                    <input type="text" name="assignment_title" class="form-control assignment-title-input" placeholder="{{ __('Assignment Title') }}" value="{{ $curriculum->title }}" required >
                </div>

                {{-- Points --}}
                <div class="form-group mandatory col-sm-12 col-md-6">
                    <label class="form-label d-block" for="assignment-points">{{ __('Points') }} </label>
                    <input type="number" name="assignment_points" id="assignment-points" class="form-control assignment-points-input" placeholder="{{ __('Points') }}" value="{{ $curriculum->points }}" required>
                </div>

                {{-- Assignment Description --}}
                <div class="form-group col-12">
                    <label class="form-label d-block" for="assignment-description">{{ __('Assignment Description') }} </label>
                    <textarea name="assignment_description" id="assignment-description" class="form-control assignment-description-input" placeholder="{{ __('Assignment Description') }}">{{ $curriculum->description }}</textarea>
                </div>
                
                {{-- Assignment Instructions --}}
                <div class="form-group mandatory col-12">
                    <label class="form-label d-block" for="assignment-instructions">{{ __('Assignment Instructions') }} </label>
                    <textarea name="assignment_instructions" id="assignment-instructions" class="form-control assignment-instructions-input" placeholder="{{ __('Assignment Instructions') }}" required>{{ $curriculum->instructions }}</textarea>
                </div>

                {{-- Allowed File Types --}}
                <div class="form-group mandatory col-sm-12 col-md-6 col-lg-4">
                    <label for="allowed-file-types" class="form-label">{{ __('Allowed File Types') }}</label>
                    @php
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
                    
               
                    
                    <select name="assignment_allowed_file_types[]" class="form-control tags-without-new-tag" multiple="multiple" required>
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

                {{-- Assignment Media --}}
                <div class="form-group col-sm-12 col-md-6 col-lg-4">
                    <label for="assignment-media" class="form-label">{{ __('Assignment Media') }}</label>
                    <input type="file" name="assignment_media" id="assignment-media" class="form-control assignment-media-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.md,.zip,.rar,.7z,.tar,.gz,.bz2,.xz,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps">
                    @if($curriculum->media)
                        <div class="mt-2">
                            <small class="text-muted">{{ __('Current Media:') }}</small>
                            <a href="{{ asset('storage/' . $curriculum->media) }}" target="_blank" class="btn btn-sm btn-info ml-2">
                                <i class="fa fa-eye"></i> {{ __('View Current Media') }}
                            </a>
                        </div>
                    @endif
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
            </div>
            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('Update Assignment') }}">
        </form>
    </div>
</div>

@push('scripts')
<script>
        $(document).ready(function() {});
        function formSuccessFunction(response){
            setTimeout(function(){
                window.location.reload();
            }, 1500);
        }
    </script>
@endpush
