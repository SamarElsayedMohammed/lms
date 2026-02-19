<div class="card">
    <div class="card-body">
        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('course-chapters.curriculum.resource.update', $curriculum->course_chapter_id) }}" data-parsley-validate enctype="multipart/form-data" data-success-function="formSuccessFunction">
            <div class="row">

                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="document_type_id" value="{{ $curriculum->id }}">

                {{-- Document Title --}}
                <div class="form-group mandatory col-12">
                    <label class="form-label d-block" for="document-title">{{ __('Document Title') }} </label>
                    <input type="text" name="document_title" id="document-title" class="form-control" placeholder="{{ __('Document Title') }}" value="{{ $curriculum->title }}">
                </div>

                {{-- Document Description --}}
                <div class="form-group col-12">
                    <label class="form-label d-block" for="document-description">{{ __('Document Description') }} </label>
                    <textarea name="document_description" id="document-description" class="form-control" placeholder="{{ __('Document Description') }}">{{ $curriculum->description }}</textarea>
                </div>

                {{-- Document File Input --}}
                <div class="form-group mandatory document-file col-sm-12 col-md-6">
                    <label class="form-label d-block" for="document_file">{{ __('Document File') }} </label>
                    
                    <input type="file" name="document_file" class="form-control document-file-input" placeholder="{{ __('Document File') }}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.rtf,.odt,.ods,.odp,.md,.zip,.rar,.7z,.tar,.gz,.bz2,.xz,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.svg,.webp,.ico,.psd,.ai,.eps,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.m4v,.3gp,.3g2,.asf,.rm,.rmvb,.vob,.ogv,.mts,.m2ts,.mp3,.wav,.ogg,.m4a,.m4b,.m4p,.aac,.flac,.wma,.aiff,.au,.ra,.amr,.opus"> @if(!empty($curriculum->file)) <div class="mb-2">
                            <a href="{{ asset('storage/' . $curriculum->file) }}" target="_blank" class="btn btn-primary mt-2 resource-file-preview">{{ __('File Preview') }}</a>
                        </div>
                        <input type="hidden" name="old_document_file" value="{{ $curriculum->file }}"> @endif </div>

                {{-- Duration --}}
                <div class="form-group col-sm-12 col-md-6">
                    <label class="form-label d-block" for="duration">{{ __('Duration (in seconds)') }} </label>
                    <input type="number" name="duration" id="duration" class="form-control" placeholder="{{ __('Duration (in seconds)') }}" min="0" value="{{ $curriculum->duration }}">
                    <small class="text-muted">{{ __('Estimated time for this resource in seconds') }}</small>
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
            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('Update Resourse') }}">
        </form>
    </div>
</div> @push('scripts')
<script>
    function formSuccessFunction(response){
        // Reload the same edit page after successful update
        setTimeout(function(){
            window.location.reload();
        }, 1500);
    }
</script>
@endpush
