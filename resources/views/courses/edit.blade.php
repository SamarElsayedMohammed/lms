@extends('layouts.app')

@php
// Helper function to safely convert any value to string
if (!function_exists('safeString')) {
function safeString($value) {
if (is_array($value)) {
return implode(', ', array_filter($value, function($item) {
return !is_array($item) && !is_object($item);
}));
}
if (is_object($value)) {
return method_exists($value, '__toString') ? (string)$value : '';
}
return (string)($value ?? '');
}
}
@endphp

@section('title')
{{ __('Edit Course') }}
@endsection

@section('page-title')
<h1 class="mb-0">@yield('title')</h1>
<div class="section-header-button ml-auto">
    <a class="btn btn-primary" href="{{ route('courses.index') }}">← {{ __('Back to All Courses') }}</a>
</div> @endsection

@section('main')
@php
// Convert all course fields to strings to prevent htmlspecialchars errors
$courseId = safeString($course->id);
$courseTitle = safeString($course->title);
$courseSlug = safeString($course->slug);
$courseShortDescription = safeString($course->short_description);
$courseThumbnail = safeString($course->thumbnail);
$courseIntroVideo = safeString($course->intro_video);
$courseLevel = safeString($course->level);
$courseType = safeString($course->course_type);
$coursePrice = safeString($course->price);
$courseDiscountPrice = safeString($course->discount_price);
$courseCertificateFee = safeString($course->certificate_fee);
$courseMetaTitle = safeString($course->meta_title);
$courseMetaImage = safeString($course->meta_image);
$courseMetaDescription = safeString($course->meta_description);
$courseMetaKeywords = safeString($course->meta_keywords);
$courseLanguageId = safeString($course->language_id);
// Handle missing category gracefully - show "Uncategorized" if category is missing
$courseCategoryName = 'Uncategorized';
if ($course->category_id) {
try {
$category = \App\Models\Category::withTrashed()->find($course->category_id);
if ($category && $category->name) {
$courseCategoryName = safeString($category->name);
}
} catch (\Exception $e) {
// Category doesn't exist, keep as "Uncategorized"
$courseCategoryName = 'Uncategorized';
}
}

// Convert boolean fields safely
$courseSequentialAccess = (bool)($course->sequential_access ?? false);
$courseCertificateEnabled = (bool)($course->certificate_enabled ?? false);
$courseIsActive = (bool)($course->is_active ?? false);
@endphp
<div class="content-wrapper">
    <div class="row">
        <div class="col-md-12 grid-margin stretch-card search-container">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">
                        {{ __('Edit Course') }}
                    </h4>
                    {{-- Start Form --}}
                    <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('courses.update', $courseId) }}"
                        data-success-function="formSuccessFunction" data-pre-submit-function="validateVideoFileSize"
                        data-parsley-validate enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="id" value="{{ $courseId }}" required>

                        <div class="row">

                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Title') }} <span class="text-danger"> * </span></label>
                                <input type="text" name="title" id="title" placeholder="{{ __('Title') }}"
                                    class="form-control" value="{{ $courseTitle }}" required>
                            </div>


                            {{-- Short Description --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Short Description') }}</label>
                                <textarea name="short_description" id="short_description" class="form-control"
                                    placeholder="{{ __('Short Description') }}">{{ $courseShortDescription }}</textarea>
                            </div>

                            {{-- Thumbnail --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Thumbnail') }} <span class="text-danger">*</span></label>
                                <input type="file" name="thumbnail" id="thumbnail" class="form-control" accept="image/*"
                                    onchange="previewThumbnail(this)"> @if(!empty($courseThumbnail)) <div class="mt-2">
                                    <small>{{ __('Current Thumbnail') }}</small><br>
                                    <img id="thumbnail_preview" class="edit-image-preview" src="{{ $courseThumbnail }}"
                                        alt="Thumbnail Image">
                                </div> @endif
                            </div>

                            {{-- Intro Video (currentIntroType and contentStructureForView passed from controller for backward compatibility) --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Intro Video') }}</label>
                                @php
                                $currentIntroVideo = $course->getRawOriginal('intro_video') ?? null;
                                @endphp
                                <div class="mb-2">
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_none" name="intro_video_type" value=""
                                            class="custom-control-input" {{ !$currentIntroType ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="intro_type_none">{{ __('None')
                                            }}</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_file" name="intro_video_type" value="file"
                                            class="custom-control-input" {{ $currentIntroType==='file' ? 'checked' : ''
                                            }}>
                                        <label class="custom-control-label" for="intro_type_file">{{ __('Upload File')
                                            }}</label>
                                    </div>
                                    <div class="custom-control custom-radio custom-control-inline">
                                        <input type="radio" id="intro_type_url" name="intro_video_type" value="url"
                                            class="custom-control-input" {{ $currentIntroType==='url' ? 'checked' : ''
                                            }}>
                                        <label class="custom-control-label" for="intro_type_url">{{ __('Video URL')
                                            }}</label>
                                    </div>
                                </div>
                                {{-- File Upload --}}
                                <div id="intro_video_file_wrapper"
                                    style="{{ $currentIntroType === 'file' ? '' : 'display:none;' }}">
                                    <input type="file" name="intro_video" id="intro_video" class="form-control"
                                        accept="video/*">
                                    <small class="form-text text-muted">{{ __('Maximum file size:') }} <span
                                            id="max-video-size">{{ $maxVideoSizeMB ?? 100 }}</span> MB</small>
                                    @if($currentIntroType === 'file' && $currentIntroVideo)
                                    <div class="mt-1">
                                        <small class="text-muted">{{ __('Current:') }}</small>
                                        <a href="{{ $courseIntroVideo }}" target="_blank" class="ml-1"><i
                                                class="fas fa-play-circle"></i> {{ __('View Current Video') }}</a>
                                        <small class="text-muted d-block">{{ __('Upload a new file to replace the
                                            current video') }}</small>
                                    </div>
                                    @endif
                                </div>
                                {{-- URL Input --}}
                                <div id="intro_video_url_wrapper"
                                    style="{{ $currentIntroType === 'url' ? '' : 'display:none;' }}">
                                    <input type="url" name="intro_video_url" id="intro_video_url" class="form-control"
                                        value="{{ $currentIntroType === 'url' ? $currentIntroVideo : '' }}"
                                        placeholder="{{ __('Enter video URL (YouTube, Vimeo, etc.)') }}">
                                    <small class="form-text text-muted">{{ __('Paste a valid video link') }}</small>
                                </div>
                                <div id="intro_video_error" class="alert alert-danger mt-2" role="alert"
                                    style="display:none;">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    <span id="intro_video_error_text"></span>
                                </div>
                            </div>

                            {{-- Content Structure --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Content Structure') }} <span class="text-danger">*</span>
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        title="{{ __('Choose how to organize your course content') }}"></i>
                                </label>
                                <div class="mt-1">
                                    <div class="custom-control custom-radio mb-2">
                                        <input type="radio" id="structure_chapters" name="content_structure"
                                            value="chapters" class="custom-control-input" {{ ($contentStructureForView ?? $course->content_structure ?? 'chapters') === 'chapters' ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="structure_chapters">
                                            <i class="fas fa-layer-group mr-1 text-primary"></i> <strong>{{ __('Chapters & Lessons') }}</strong>
                                            <br><small class="text-muted">{{ __('Group lessons inside chapters') }}</small>
                                        </label>
                                    </div>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" id="structure_lessons" name="content_structure"
                                            value="lessons" class="custom-control-input" {{ ($contentStructureForView ?? $course->content_structure ?? 'chapters') === 'lessons' ? 'checked' : '' }}>
                                        <label class="custom-control-label" for="structure_lessons">
                                            <i class="fas fa-list mr-1 text-success"></i> <strong>{{ __('Direct Lessons') }}</strong>
                                            <br><small class="text-muted">{{ __('Add lessons directly without chapters') }}</small>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {{-- Level --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label>{{ __('Level') }} <span class="text-danger"> * </span></label>
                                <select name="level" id="level" class="form-control" required>
                                    <option value="beginner" {{ $courseLevel=='beginner' ? 'selected' : '' }}>{{
                                        __('Beginner') }}</option>
                                    <option value="intermediate" {{ $courseLevel=='intermediate' ? 'selected' : '' }}>{{
                                        __('Intermediate') }}</option>
                                    <option value="advanced" {{ $courseLevel=='advanced' ? 'selected' : '' }}>{{
                                        __('Advanced') }}</option>
                                </select>
                            </div>

                            {{-- Course Type --}}
                            <div class="form-group mandatory col-sm-12 col-md-2">
                                <label class="d-block form-label">{{ __('Course Type') }}</label>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="course_type_free" name="course_type" value="free"
                                        class="custom-control-input" {{ $courseType=='free' ? 'checked' : '' }}
                                        disabled>
                                    <label class="custom-control-label" for="course_type_free">{{ __('Free') }}</label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="course_type_paid" name="course_type" value="paid"
                                        class="custom-control-input" {{ $courseType=='paid' ? 'checked' : '' }}
                                        disabled>
                                    <label class="custom-control-label" for="course_type_paid">{{ __('Paid') }}</label>
                                </div>
                            </div>

                            {{-- Free Course (Subscription Bypass) --}}
                            @php $courseIsFree = (bool)($course->is_free ?? false); $courseIsFreeUntil =
                            $course->is_free_until ?? null; @endphp
                            <div class="form-group col-sm-12 col-md-6">
                                <div class="control-label">
                                    {{ __('Free Course (No Subscription Required)') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('If enabled, entire course is accessible without subscription.') }}"></i>
                                </div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="checkbox" class="custom-switch-input" id="is_free" name="is_free"
                                            value="1" {{ $courseIsFree ? 'checked' : '' }}>
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description">{{ $courseIsFree ? __('Yes') : __('No')
                                            }}</span>
                                    </label>
                                </div>
                            </div>
                            <div class="form-group col-sm-12 col-md-6 is-free-until-field"
                                style="{{ !$courseIsFree ? 'display: none;' : '' }}">
                                <label>{{ __('Free Until (Date)') }}</label>
                                <input type="datetime-local" name="is_free_until" id="is_free_until"
                                    class="form-control"
                                    value="{{ $courseIsFreeUntil ? \Carbon\Carbon::parse($courseIsFreeUntil)->format('Y-m-d\TH:i') : '' }}"
                                    placeholder="{{ __('Leave empty for permanently free') }}">
                                <small class="form-text text-muted">{{ __('Optional: Set date when free access ends.
                                    Leave empty if permanently free.') }}</small>
                            </div>

                            {{-- Sequential Chapter Access --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <div class="control-label">
                                    {{ __('Sequential Chapter Access') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('If enabled, students must complete chapters in order. If disabled, they can access any chapter freely.') }}"></i>
                                </div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="checkbox" class="custom-switch-input" id="sequential_access"
                                            name="sequential_access" value="1" {{ $courseSequentialAccess ? 'checked'
                                            : '' }}>
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description sequential-access-text">{{
                                            $courseSequentialAccess ? __('Sequential (Step by step)') : __('Any Order
                                            (Free access)') }}</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Certificate Toggle (Only for Free Courses) --}}
                            @if($courseType == 'free')
                            <div class="form-group col-sm-12 col-md-6">
                                <div class="control-label">
                                    {{ __('Certificate Available') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('Enable certificate generation for this free course. Students can get a certificate by paying the specified fee.') }}"></i>
                                </div>
                                <div class="custom-switches-stacked mt-2">
                                    <label class="custom-switch">
                                        <input type="checkbox" class="custom-switch-input" id="certificate_enabled"
                                            name="certificate_enabled" value="1" {{ $courseCertificateEnabled
                                            ? 'checked' : '' }}>
                                        <span class="custom-switch-indicator"></span>
                                        <span class="custom-switch-description certificate-enabled-text">{{
                                            $courseCertificateEnabled ? __('Yes') : __('No') }}</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Certificate Fee (Only if Certificate is Enabled) --}}
                            <div class="form-group col-sm-12 col-md-6 certificate-fee-field"
                                style="{{ $courseCertificateEnabled ? '' : 'display: none;' }}">
                                <label class="form-label">
                                    {{ __('Certificate Fee') }}
                                    <i class="fas fa-info-circle text-info ml-1" data-toggle="tooltip"
                                        data-placement="top"
                                        title="{{ __('This fee will be charged to students who want to get a certificate for completing this free course') }}"></i>
                                </label>
                                <input type="number" name="certificate_fee" id="certificate_fee" step="0.01" min="0"
                                    placeholder="{{ __('Certificate Fee') }}" class="form-control"
                                    value="{{ $courseCertificateFee }}">
                            </div>
                            @endif


                            {{-- Category --}}
                            <div class="col-md-6 form-group mandatory">
                                <label for="category_id" class="form-label">{{ __('Category') }}</label>
                                <input type="text" class="form-control" value="{{ $courseCategoryName }}" disabled>
                            </div>


                            {{-- Course Tags --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label for="course_tags" class="form-label">{{ __('Course Tags') }}</label>
                                <select name="course_tags[]" id="course_tags" class="form-control select2-tags"
                                    multiple="multiple"> @if ($tags->count() > 0) <option value="">{{ __('Select a Tag')
                                        }}</option> @foreach ($tags as $tag) <option value="{{ $tag->id }}" {{ $course->
                                        tags->contains($tag->id) ? 'selected' : '' }}>{{ is_array($tag->tag) ?
                                        implode(', ', $tag->tag) : (string)($tag->tag ?? '') }}</option> @endforeach
                                    @else <option value="">{{ __('No tags found') }}</option> @endif </select>
                                <small class="form-text text-muted">{{ __('Type and hit enter to add new tags or select
                                    from the list.') }}</small>
                            </div>
                            <input type="hidden" name="language_id" value="{{ $courseLanguageId ?: ($course_languages->first()->id ?? '') }}">
                            <div>
                                <hr>
                            </div>

                            {{-- Course Learnings --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Learnings') }}</label>
                                <div class="course-learnings-section">
                                    <div data-repeater-list="learnings_data">
                                        <div class="row learning-section d-flex align-items-center mb-2"
                                            data-repeater-item>
                                            <input type="hidden" name="id" class="id">
                                            {{-- Learning --}}
                                            <div class="form-group mandatory col-md-11">
                                                <label class="form-label">{{ __('Learning') }} - <span
                                                        class="learning-number"> {{ __('0') }} </span></label>
                                                <input type="text" name="learning" class="form-control"
                                                    placeholder="{{ __('Enter a learning outcome') }}" required
                                                    data-parsley-required="true">
                                            </div>
                                            {{-- Remove Learning --}}
                                            <div class="form-group col-md-1 mt-4">
                                                <button data-repeater-delete type="button"
                                                    class="btn btn-danger remove-learning" title="{{ __('remove') }}">
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Add New Learning --}}
                                    <button type="button" class="btn btn-success mt-1 add-new-learning"
                                        data-repeater-create title="{{ __('Add New Learning') }}">
                                        <i class="fa fa-plus"></i> {{ __('Add New Learning') }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <hr>
                            </div>
                            {{-- Course Requirements --}}
                            <div class="form-group col-12">
                                <label class="form-label">{{ __('Course Requirements') }}</label>
                                <div class="course-requirements-section">
                                    <div data-repeater-list="requirements_data">
                                        <div class="row learning-section d-flex align-items-center mb-2"
                                            data-repeater-item>
                                            <input type="hidden" name="id" class="id">
                                            {{-- Requirement --}}
                                            <div class="form-group mandatory col-md-11">
                                                <label class="form-label">{{ __('Requirement') }} - <span
                                                        class="requirement-number"> {{ __('0') }} </span></label>
                                                <input type="text" name="requirement" class="form-control"
                                                    placeholder="{{ __('Enter a requirement') }}" required
                                                    data-parsley-required="true">
                                            </div>
                                            {{-- Remove Requirement --}}
                                            <div class="form-group col-md-1 mt-4">
                                                <button data-repeater-delete type="button"
                                                    class="btn btn-danger remove-requirement"
                                                    title="{{ __('remove') }}">
                                                    <i class="fa fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    {{-- Add New Requirement --}}
                                    <button type="button" class="btn btn-success mt-1 add-new-requirement"
                                        data-repeater-create title="{{ __('Add New Requirement') }}">
                                        <i class="fa fa-plus"></i> {{ __('Add New Requirement') }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <hr>
                            </div>

                            {{-- SEO Meta Tags Section --}}
                            <div class="form-group col-12">
                                <h5 class="mb-3 text-primary"><i class="fas fa-tags mr-2"></i>{{ __('SEO Meta Tags') }}
                                </h5>
                            </div>

                            {{-- Meta Title --}}
                            <div class="form-group col-12">
                                <label for="meta_title" class="form-label">{{ __('Meta Title') }}</label>
                                <input type="text" name="meta_title" value="{{ $courseMetaTitle }}" id="meta_title"
                                    class="form-control" placeholder="{{ __('Enter meta title for SEO') }}"
                                    maxlength="60">
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO
                                    title for search engines (recommended: 50-60 characters)') }}</small>
                            </div>

                            {{-- Meta Description --}}
                            <div class="form-group col-12">
                                <label for="meta_description" class="form-label">{{ __('Meta Description') }}</label>
                                <textarea name="meta_description" id="meta_description" class="form-control"
                                    placeholder="{{ __('Enter meta description for SEO') }}" rows="3"
                                    maxlength="160">{{ $courseMetaDescription }}</textarea>
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('SEO
                                    description for search engines (recommended: 150-160 characters)') }}</small>
                            </div>

                            {{-- Meta Keywords --}}
                            <div class="form-group col-12">
                                <label for="meta_keywords" class="form-label">{{ __('Meta Keywords') }}</label>
                                <textarea name="meta_keywords" id="meta_keywords" class="form-control"
                                    placeholder="{{ __('Enter keywords separated by commas (e.g., course, online, learning)') }}"
                                    rows="2">{{ $courseMetaKeywords ?? '' }}</textarea>
                                <small class="form-text text-muted"><i class="fas fa-info-circle mr-1"></i>{{
                                    __('Keywords for SEO (separate multiple keywords with commas)') }}</small>
                            </div>

                            <div>
                                <hr>
                            </div>

                        </div>
                        <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit"
                            value="{{ __('Update') }}">
                    </form>
                </div>
            </div>
        </div>

        {{-- الفصول والمنهج (Chapters & Curriculum) - إنشاء الشباتر وإضافة الدروس في نفس الصفحة --}}
        @can('course-chapters-list')
        <div class="row mt-4">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            <i class="fas fa-list-ol mr-2"></i>{{ __('Chapters & Curriculum') }}
                        </h4>
                        <p class="text-muted mb-4">{{ __('Add chapters and manage lessons (lectures, quizzes, assignments) for this course here.') }}</p>

                        @if($contentStructureForView === 'chapters')
                            {{-- إنشاء فصل جديد --}}
                            @can('course-chapters-create')
                            <div class="border rounded p-3 mb-4 bg-light">
                                <h5 class="mb-3">{{ __('Add Chapter') }}</h5>
                                <form id="add-chapter-form" method="POST" action="{{ route('course-chapters.store') }}" class="row">
                                    @csrf
                                    <input type="hidden" name="course_id" value="{{ $course->id }}">
                                    <div class="form-group col-md-4">
                                        <label for="chapter_title" class="form-label">{{ __('Title') }}</label>
                                        <input type="text" name="title" id="chapter_title" class="form-control" placeholder="{{ __('Chapter title') }}" required>
                                    </div>
                                    <div class="form-group col-md-4">
                                        <label for="chapter_description" class="form-label">{{ __('Description') }}</label>
                                        <input type="text" name="description" id="chapter_description" class="form-control" placeholder="{{ __('Optional description') }}">
                                    </div>
                                    <div class="form-group col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary">{{ __('Add Chapter') }}</button>
                                    </div>
                                </form>
                            </div>
                            @endcan

                            {{-- قائمة الفصول مع روابط إدارة الدروس --}}
                            <h5 class="mb-3">{{ __('Chapters and lessons') }}</h5>
                            @if($chapters->isEmpty())
                                <p class="text-muted">{{ __('No chapters yet. Add a chapter above, then add lessons for each chapter.') }}</p>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach($chapters as $ch)
                                        @if(!($ch->type === 'default' && $isDirectLessonsMode))
                                        <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                                            <div>
                                                <strong>{{ $ch->title ?? __('Untitled Chapter') }}</strong>
                                                @if(!empty($ch->description))
                                                    <span class="text-muted d-block small">{{ \Illuminate\Support\Str::limit($ch->description, 80) }}</span>
                                                @endif
                                            </div>
                                            <a href="{{ route('course-chapters.curriculum.index', $ch->id) }}" class="btn btn-sm btn-outline-primary mt-2 mt-md-0">
                                                <i class="fas fa-plus mr-1"></i>{{ __('Add / Manage lessons') }}
                                            </a>
                                        </li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                        @else
                            {{-- وضع الدروس فقط (بدون فصول ظاهرة) --}}
                            @php
                                $defaultChapter = $chapters->firstWhere('type', 'default') ?? $chapters->first();
                            @endphp
                            @if($defaultChapter)
                                <p class="mb-2">{{ __('This course uses a single list of lessons (no separate chapters).') }}</p>
                                <a href="{{ route('course-chapters.curriculum.index', $defaultChapter->id) }}" class="btn btn-primary">
                                    <i class="fas fa-plus mr-1"></i>{{ __('Add / Manage lessons') }}
                                </a>
                            @else
                                <p class="text-muted">{{ __('No curriculum container yet. Save the course and refresh to add lessons.') }}</p>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endcan
    </div>
</div> @endsection

@section('script')
<script>
    $(document).ready(function () {
        const $form = $('.create-form');

        // Add Chapter form: AJAX submit then reload so new chapter appears
        $('#add-chapter-form').on('submit', function (e) {
            e.preventDefault();
            var $f = $(this);
            var $btn = $f.find('button[type="submit"]');
            $btn.prop('disabled', true);
            $.ajax({
                url: $f.attr('action'),
                type: 'POST',
                data: $f.serialize(),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                success: function (res) {
                    if (res && res.message) {
                        window.location.reload();
                    } else {
                        $btn.prop('disabled', false);
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : (xhr.responseText || '{{ __("Error adding chapter.") }}');
                    alert(msg);
                }
            });
        });

        // Sequential Access Toggle
        const $sequentialAccessToggle = $('#sequential_access');
        const $sequentialAccessText = $('.sequential-access-text');

        function updateSequentialAccessText() {
            if ($sequentialAccessToggle.is(':checked')) {
                $sequentialAccessText.text('{{ __("Sequential (Step by step)") }}');
            } else {
                $sequentialAccessText.text('{{ __("Any Order (Free access)") }}');
            }
        }

        $sequentialAccessToggle.on('change', updateSequentialAccessText);

        // Certificate Toggle
        const $certificateToggle = $('#certificate_enabled');
        const $certificateText = $('.certificate-enabled-text');
        const $certificateFeeField = $('.certificate-fee-field');
        const $certificateFeeInput = $('#certificate_fee');

        function updateCertificateToggle() {
            if ($certificateToggle.is(':checked')) {
                $certificateText.text('{{ __("Yes") }}');
                $certificateFeeField.show();
                $certificateFeeInput.attr('required', 'required');
            } else {
                $certificateText.text('{{ __("No") }}');
                $certificateFeeField.hide();
                $certificateFeeInput.removeAttr('required').val('');
            }
        }

        $certificateToggle.on('change', updateCertificateToggle);

        // Initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();

        // Video file size validation
        const $introVideoInput = $('#intro_video');
        const $introVideoError = $('#intro_video_error');
        const maxVideoSizeMB = parseFloat('{{ $maxVideoSizeMB ?? 100 }}');
        const maxVideoSizeBytes = maxVideoSizeMB * 1024 * 1024; // Convert MB to bytes

        // Validate on file selection
        $introVideoInput.on('change', function () {
            const file = this.files[0];
            if (file) {
                if (file.size > maxVideoSizeBytes) {
                    const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
                    const errorMessage = '{{ __("Please upload a video file smaller than") }} ' + maxVideoSizeMB + ' MB. {{ __("Your file size is") }} ' + fileSizeMB + ' MB.';
                    $('#intro_video_error_text').text(errorMessage);
                    $introVideoError.css('display', 'block').show();
                    $introVideoInput.addClass('is-invalid').css('border-color', '#dc3545');
                    // Don't clear - let user see what they selected
                    // $introVideoInput.val('');
                    return false;
                } else {
                    $introVideoError.hide();
                    $introVideoInput.removeClass('is-invalid').css('border-color', '');
                }
            }
        });
    });

    // Pre-submit validation function
    function validateVideoFileSize() {
        const selectedType = $('input[name="intro_video_type"]:checked').val();
        const maxVideoSizeMB = parseFloat('{{ $maxVideoSizeMB ?? 100 }}');
        const maxVideoSizeBytes = maxVideoSizeMB * 1024 * 1024;

        if (selectedType === 'file') {
            const file = $('#intro_video').length && $('#intro_video')[0].files[0];
            const hasExistingFile = {{ ($currentIntroType === 'file' && $currentIntroVideo) ? 'true' : 'false' }};
            if (!file && !hasExistingFile) {
                $('#intro_video_error_text').text('{{ __("Please select a video file to upload.") }}');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video').offset().top - 150 }, 500);
                return false;
            }
            if (file && file.size > maxVideoSizeBytes) {
                const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
                $('#intro_video_error_text').text('{{ __("File too large:") }} ' + sizeMB + ' MB. {{ __("Max:") }} ' + maxVideoSizeMB + ' MB');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video').offset().top - 150 }, 500);
                return false;
            }
        } else if (selectedType === 'url') {
            const url = $('#intro_video_url').val().trim();
            if (!url) {
                $('#intro_video_error_text').text('{{ __("Please enter a video URL.") }}');
                $('#intro_video_error').show();
                $('html, body').animate({ scrollTop: $('#intro_video_url').offset().top - 150 }, 500);
                return false;
            }
        }
        $('#intro_video_error').hide();
        return true;
    }