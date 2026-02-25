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
    {{ __('View Course') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('courses.index') }}">← {{ __('Back to All Courses') }}</a>
        <a class="btn btn-info ml-2" href="{{ route('courses.edit', $course->id) }}">{{ __('Edit Course') }}</a>
    </div> 
@endsection

@section('main')
    @php
        // Convert all course fields to strings to prevent htmlspecialchars errors
        $courseId = safeString($course->id);
        $courseTitle = safeString($course->title);
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
        
        // Handle missing category gracefully
        $courseCategoryName = 'Uncategorized';
        if ($course->category_id) {
            try {
                $category = \App\Models\Category::withTrashed()->find($course->category_id);
                if ($category && $category->name) {
                    $courseCategoryName = safeString($category->name);
                }
            } catch (\Exception $e) {
                $courseCategoryName = 'Uncategorized';
            }
        }
        
        // Convert boolean fields safely
        $courseSequentialAccess = (bool)($course->sequential_access ?? false);
        $courseCertificateEnabled = (bool)($course->certificate_enabled ?? false);
        $courseIsActive = (bool)($course->is_active ?? false);
        
        // Get language name
        $courseLanguageName = 'N/A';
        if ($course->language) {
            $courseLanguageName = safeString($course->language->name);
        }
        
        // Get instructor names
        $instructorNames = 'N/A';
        if ($course->instructors && $course->instructors->count() > 0) {
            $instructorNames = $course->instructors->pluck('name')->implode(', ');
        } elseif ($course->user) {
            $instructorNames = safeString($course->user->name);
        }
        
        // Get tags
        $tagNames = 'None';
        if ($course->tags && $course->tags->count() > 0) {
            $tagNames = $course->tags->pluck('name')->implode(', ');
        }
    @endphp
    
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Course Details') }}
                        </h4>
                        
                        <div class="row">
                            {{-- Title --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Title') }}</label>
                                <div class="form-control-plaintext">{!! e($courseTitle) !!}</div>
                            </div>

                            {{-- Short Description --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Short Description') }}</label>
                                <div class="form-control-plaintext">{!! nl2br(e($courseShortDescription ?: 'N/A')) !!}</div>
                            </div>

                            {{-- Thumbnail --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Thumbnail') }}</label>
                                @if(!empty($courseThumbnail))
                                    <div class="mt-2">
                                        <img class="img-thumbnail" src="{{ $courseThumbnail }}" alt="Thumbnail" style="max-height: 200px;">
                                    </div>
                                @else
                                    <div class="form-control-plaintext">N/A</div>
                                @endif
                            </div>

                            {{-- Intro Video --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Intro Video') }}</label>
                                @if(!empty($courseIntroVideo))
                                    <div class="mt-2">
                                        <a href="{{ $courseIntroVideo }}" target="_blank" class="btn btn-sm btn-info">{{ __('View Video') }}</a>
                                    </div>
                                @else
                                    <div class="form-control-plaintext">N/A</div>
                                @endif
                            </div>

                            {{-- Level --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Level') }}</label>
                                <div class="form-control-plaintext">{{ ucfirst($courseLevel) }}</div>
                            </div>

                            {{-- Course Type --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Course Type') }}</label>
                                <div class="form-control-plaintext">
                                    <span class="badge badge-{{ $courseType == 'free' ? 'success' : 'primary' }}">
                                        {{ ucfirst($courseType) }}
                                    </span>
                                </div>
                            </div>

                            {{-- Sequential Chapter Access --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Sequential Chapter Access') }}</label>
                                <div class="form-control-plaintext">
                                    <span class="badge badge-{{ $courseSequentialAccess ? 'info' : 'secondary' }}">
                                        {{ $courseSequentialAccess ? __('Sequential (Step by step)') : __('Any Order (Free access)') }}
                                    </span>
                                </div>
                            </div>

                            {{-- Certificate Available (Only for Free Courses) --}}
                            @if($courseType == 'free')
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Certificate Available') }}</label>
                                <div class="form-control-plaintext">
                                    <span class="badge badge-{{ $courseCertificateEnabled ? 'success' : 'secondary' }}">
                                        {{ $courseCertificateEnabled ? __('Yes') : __('No') }}
                                    </span>
                                </div>
                            </div>

                            {{-- Certificate Fee --}}
                            @if($courseCertificateEnabled)
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Certificate Fee') }}</label>
                                <div class="form-control-plaintext">{{ $courseCertificateFee ? number_format($courseCertificateFee, 2) : '0.00' }}</div>
                            </div>
                            @endif
                            @endif

                            {{-- Price --}}
                            @if($courseType == 'paid')
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Price') }}</label>
                                <div class="form-control-plaintext">{{ $coursePrice ? number_format($coursePrice, 2) : '0.00' }}</div>
                            </div>

                            {{-- Discount Price --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Discount Price') }}</label>
                                <div class="form-control-plaintext">{{ $courseDiscountPrice ? number_format($courseDiscountPrice, 2) : 'N/A' }}</div>
                            </div>
                            @endif

                            {{-- Category --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Category') }}</label>
                                <div class="form-control-plaintext">{!! e($courseCategoryName) !!}</div>
                            </div>

                            {{-- Instructors --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Instructors') }}</label>
                                <div class="form-control-plaintext">{!! e($instructorNames) !!}</div>
                            </div>

                            {{-- Tags --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Tags') }}</label>
                                <div class="form-control-plaintext">{!! e($tagNames) !!}</div>
                            </div>

                            {{-- Status --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Status') }}</label>
                                <div class="form-control-plaintext">
                                    <span class="badge badge-{{ $courseIsActive ? 'success' : 'warning' }}">
                                        {{ $courseIsActive ? __('Active') : __('Inactive') }}
                                    </span>
                                </div>
                            </div>

                            {{-- Approval Status --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Approval Status') }}</label>
                                <div class="form-control-plaintext">
                                    @if($course->approval_status == 'approved')
                                        <span class="badge badge-success">{{ __('Approved') }}</span>
                                    @elseif($course->approval_status == 'pending')
                                        <span class="badge badge-warning">{{ __('Pending') }}</span>
                                    @elseif($course->approval_status == 'rejected')
                                        <span class="badge badge-danger">{{ __('Rejected') }}</span>
                                    @else
                                        <span class="badge badge-secondary">{{ ucfirst($course->approval_status ?? 'N/A') }}</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Learnings --}}
                            @if($course->learnings && $course->learnings->count() > 0)
                            <div class="form-group col-sm-12">
                                <label class="font-weight-bold">{{ __('What You Will Learn') }}</label>
                                <ul class="list-group">
                                    @foreach($course->learnings as $learning)
                                        <li class="list-group-item">{!! e($learning->title ?? 'N/A') !!}</li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif

                            {{-- Requirements --}}
                            @if($course->requirements && $course->requirements->count() > 0)
                            <div class="form-group col-sm-12">
                                <label class="font-weight-bold">{{ __('Requirements') }}</label>
                                <ul class="list-group">
                                    @foreach($course->requirements as $requirement)
                                        <li class="list-group-item">{!! e($requirement->requirement ?? 'N/A') !!}</li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif

                            {{-- Meta Title --}}
                            @if($courseMetaTitle)
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Meta Title') }}</label>
                                <div class="form-control-plaintext">{{ $courseMetaTitle }}</div>
                            </div>
                            @endif

                            {{-- Meta Description --}}
                            @if($courseMetaDescription)
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Meta Description') }}</label>
                                <div class="form-control-plaintext">{{ $courseMetaDescription }}</div>
                            </div>
                            @endif

                            {{-- Meta Keywords --}}
                            @if($courseMetaKeywords)
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Meta Keywords') }}</label>
                                <div class="form-control-plaintext">{{ $courseMetaKeywords }}</div>
                            </div>
                            @endif

                            {{-- Meta Image --}}
                            @if($courseMetaImage)
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Meta Image') }}</label>
                                <div class="mt-2">
                                    <img class="img-thumbnail" src="{{ $courseMetaImage }}" alt="Meta Image" style="max-height: 200px;">
                                </div>
                            </div>
                            @endif

                            {{-- Created At --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Created At') }}</label>
                                <div class="form-control-plaintext">{{ $course->created_at ? $course->created_at->format('Y-m-d H:i:s') : 'N/A' }}</div>
                            </div>

                            {{-- Updated At --}}
                            <div class="form-group col-sm-12 col-md-6">
                                <label class="font-weight-bold">{{ __('Updated At') }}</label>
                                <div class="form-control-plaintext">{{ $course->updated_at ? $course->updated_at->format('Y-m-d H:i:s') : 'N/A' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Course Curriculum: حسب الهيكلة إما "دروس فقط" أو "فصول ودروس" --}}
        @php
            $contentStructure = $course->content_structure ?? 'chapters';
            $isDirectLessons = ($contentStructure === 'lessons');
            $chaptersForDisplay = $course->chapters && $course->chapters->count() > 0 ? $course->chapters : collect();
            $defaultChapterForLessons = $isDirectLessons && $chaptersForDisplay->isNotEmpty() ? $chaptersForDisplay->first() : null;
        @endphp
        @if($chaptersForDisplay->isNotEmpty())
        <div class="row mt-4">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Course Curriculum') }}
                        </h4>

                        @if($isDirectLessons && $defaultChapterForLessons)
                            {{-- وضع الدروس المباشرة: قائمة مرتبة من الدروس مع روابط فقط (بدون عرض الفصل) --}}
                            @php
                                $ch = $defaultChapterForLessons;
                                $lectures = $ch->lectures ?? collect();
                                $quizzes = $ch->quizzes ?? collect();
                                $assignments = $ch->assignments ?? collect();
                                $resources = $ch->resources ?? collect();
                                $hasContent = $lectures->count() > 0 || $quizzes->count() > 0 || $assignments->count() > 0 || $resources->count() > 0;
                            @endphp
                            @if($hasContent)
                                <h5 class="mb-3 text-primary"><i class="fas fa-list-ol mr-2"></i>{{ __('Lessons') }} ({{ __('in order') }})</h5>
                                <ul class="list-group list-group-flush">
                                    @foreach($lectures as $order => $lecture)
                                        <li class="list-group-item {{ !$lecture->is_active ? 'bg-light' : '' }}">
                                            <div class="d-flex justify-content-between align-items-center flex-wrap">
                                                <div class="mb-1">
                                                    <span class="badge badge-primary mr-2">{{ $order + 1 }}</span>
                                                    <i class="fas fa-play-circle text-primary mr-2"></i>
                                                    <strong>{{ $lecture->title ?? __('Untitled Lecture') }}</strong>
                                                    @if($lecture->duration)
                                                        <small class="text-muted ml-2"><i class="fas fa-clock mr-1"></i>{{ \App\Services\HelperService::getFormattedDuration($lecture->duration) }}</small>
                                                    @endif
                                                </div>
                                                <div>
                                                    @php
                                                        $lectureRawFile = $lecture->getRawOriginal('file');
                                                        $lectureRawUrl = $lecture->getRawOriginal('url');
                                                        $lectureRawYoutubeUrl = $lecture->getRawOriginal('youtube_url');
                                                    @endphp
                                                    @if($lecture->type == 'file' && $lectureRawFile)
                                                        <a href="{{ $lecture->file }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-external-link-alt mr-1"></i>{{ __('View') }}</a>
                                                    @elseif($lecture->type == 'url' && $lectureRawUrl)
                                                        <a href="{{ $lectureRawUrl }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-link mr-1"></i>{{ __('View') }}</a>
                                                    @elseif($lecture->type == 'youtube_url' && $lectureRawYoutubeUrl)
                                                        <a href="{{ $lectureRawYoutubeUrl }}" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fab fa-youtube mr-1"></i>{{ __('Watch') }}</a>
                                                    @endif
                                                    @can('course-chapters-list')
                                                        <a href="{{ route('course-chapters.curriculum.edit', ['id' => $lecture->id, 'type' => 'lecture']) }}" class="btn btn-sm btn-outline-secondary ml-1"><i class="fas fa-cog mr-1"></i>{{ __('Manage') }}</a>
                                                    @endcan
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                    @foreach($quizzes as $order => $quiz)
                                        <li class="list-group-item {{ !$quiz->is_active ? 'bg-light' : '' }}">
                                            <span class="badge badge-primary mr-2">{{ $lectures->count() + $order + 1 }}</span>
                                            <i class="fas fa-question-circle text-warning mr-2"></i>
                                            <strong>{{ $quiz->title ?? __('Untitled Quiz') }}</strong>
                                            @can('course-chapters-list')
                                                <a href="{{ route('course-chapters.curriculum.edit', ['id' => $quiz->id, 'type' => 'quiz']) }}" class="btn btn-sm btn-outline-secondary ml-2"><i class="fas fa-cog mr-1"></i>{{ __('Manage') }}</a>
                                            @endcan
                                        </li>
                                    @endforeach
                                    @foreach($assignments as $order => $assignment)
                                        <li class="list-group-item {{ !$assignment->is_active ? 'bg-light' : '' }}">
                                            <span class="badge badge-primary mr-2">{{ $lectures->count() + $quizzes->count() + $order + 1 }}</span>
                                            <i class="fas fa-tasks text-info mr-2"></i>
                                            <strong>{{ $assignment->title ?? __('Untitled Assignment') }}</strong>
                                            @can('course-chapters-list')
                                                <a href="{{ route('course-chapters.curriculum.edit', ['id' => $assignment->id, 'type' => 'assignment']) }}" class="btn btn-sm btn-outline-secondary ml-2"><i class="fas fa-cog mr-1"></i>{{ __('Manage') }}</a>
                                            @endcan
                                        </li>
                                    @endforeach
                                    @foreach($resources as $order => $resource)
                                        <li class="list-group-item {{ !$resource->is_active ? 'bg-light' : '' }}">
                                            <span class="badge badge-primary mr-2">{{ $lectures->count() + $quizzes->count() + $assignments->count() + $order + 1 }}</span>
                                            <i class="fas fa-file text-secondary mr-2"></i>
                                            <strong>{{ $resource->title ?? __('Untitled Resource') }}</strong>
                                            @if($resource->type == 'file' && $resource->file)
                                                <a href="{{ $resource->file }}" target="_blank" class="btn btn-sm btn-outline-primary ml-2"><i class="fas fa-download mr-1"></i>{{ __('Download') }}</a>
                                            @elseif($resource->type == 'url' && $resource->url)
                                                <a href="{{ $resource->url }}" target="_blank" class="btn btn-sm btn-outline-primary ml-2"><i class="fas fa-link mr-1"></i>{{ __('View') }}</a>
                                            @endif
                                            @can('course-chapters-list')
                                                <a href="{{ route('course-chapters.curriculum.edit', ['id' => $resource->id, 'type' => 'resource']) }}" class="btn btn-sm btn-outline-secondary ml-1"><i class="fas fa-cog mr-1"></i>{{ __('Manage') }}</a>
                                            @endcan
                                        </li>
                                    @endforeach
                                </ul>
                                @can('course-chapters-list')
                                    <p class="mt-3 mb-0">
                                        <a href="{{ route('course-chapters.curriculum.index', $ch->id) }}" class="btn btn-sm btn-primary"><i class="fas fa-plus mr-1"></i>{{ __('Add lesson or content') }}</a>
                                    </p>
                                @endcan
                            @else
                                <p class="text-muted mb-0"><i class="fas fa-info-circle mr-2"></i>{{ __('No lessons yet.') }}</p>
                                @can('course-chapters-list')
                                    <a href="{{ route('course-chapters.curriculum.index', $defaultChapterForLessons->id) }}" class="btn btn-sm btn-primary mt-2"><i class="fas fa-plus mr-1"></i>{{ __('Add lesson or content') }}</a>
                                @endcan
                            @endif
                        @else
                            {{-- وضع الفصول والدروس: فصل وجوا كل فصل دروس مع روابط --}}
                        <div class="accordion" id="courseChaptersAccordion">
                            @foreach($chaptersForDisplay as $chapterIndex => $chapter)
                                @php
                                    $chapterId = 'chapter-' . $chapter->id;
                                    $isActive = $chapterIndex == 0 ? 'show' : '';
                                @endphp
                                
                                <div class="card mb-2">
                                    <div class="card-header" id="heading{{ $chapter->id }}">
                                        <h5 class="mb-0">
                                            <button class="btn btn-link w-100 text-left" type="button" data-toggle="collapse" data-target="#{{ $chapterId }}" aria-expanded="{{ $chapterIndex == 0 ? 'true' : 'false' }}" aria-controls="{{ $chapterId }}">
                                                <i class="fas fa-chevron-{{ $chapterIndex == 0 ? 'down' : 'right' }} mr-2"></i>
                                                <strong>{{ __('Chapter') }} {{ $chapter->chapter_order ?? ($chapterIndex + 1) }}: {{ $chapter->title ?? __('Untitled Chapter') }}</strong>
                                                @if($chapter->description)
                                                    <small class="text-muted d-block mt-1">{{ \Illuminate\Support\Str::limit($chapter->description, 100) }}</small>
                                                @endif
                                            </button>
                                            @can('course-chapters-list')
                                                <a href="{{ route('course-chapters.curriculum.index', $chapter->id) }}" class="btn btn-sm btn-outline-primary ml-2" onclick="event.stopPropagation();">{{ __('Manage lessons') }}</a>
                                            @endcan
                                        </h5>
                        </div>
                                    
                                    <div id="{{ $chapterId }}" class="collapse {{ $isActive }}" aria-labelledby="heading{{ $chapter->id }}" data-parent="#courseChaptersAccordion">
                        <div class="card-body">
                                            @if($chapter->description)
                                                <div class="mb-3">
                                                    <strong>{{ __('Description') }}:</strong>
                                                    <p class="mb-0">{!! nl2br(e($chapter->description)) !!}</p>
                                                </div>
                                            @endif
                                            
                                            @php
                                                $hasContent = false;
                                                $lectures = $chapter->lectures ?? collect();
                                                $quizzes = $chapter->quizzes ?? collect();
                                                $assignments = $chapter->assignments ?? collect();
                                                $resources = $chapter->resources ?? collect();
                                                
                                                if($lectures->count() > 0 || $quizzes->count() > 0 || $assignments->count() > 0 || $resources->count() > 0) {
                                                    $hasContent = true;
                                                }
                                            @endphp
                                            
                                            @if($hasContent)
                                                <div class="curriculum-items">
                                                    {{-- Lectures --}}
                                                    @if($lectures->count() > 0)
                                                        <div class="mb-3">
                                                            <h6 class="text-primary">
                                                                <i class="fas fa-video mr-2"></i>{{ __('Lectures') }} ({{ $lectures->count() }})
                                                            </h6>
                                                            <ul class="list-group">
                                                                @foreach($lectures as $lecture)
                                                                    <li class="list-group-item {{ !$lecture->is_active ? 'bg-light' : '' }}">
                                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                                            <div class="flex-grow-1">
                                                                                <i class="fas fa-play-circle text-primary mr-2"></i>
                                                                                <strong>{{ $lecture->title ?? __('Untitled Lecture') }}</strong>
                                                                                @if($lecture->description)
                                                                                    <br><small class="text-muted">{!! nl2br(e(\Illuminate\Support\Str::limit($lecture->description, 150))) !!}</small>
                                                                                @endif
                                                                                @if($lecture->duration)
                                                                                    <br><small class="text-info"><i class="fas fa-clock mr-1"></i>{{ \App\Services\HelperService::getFormattedDuration($lecture->duration) }}</small>
                                                                                @endif
                                                                                @if($lecture->chapter_order)
                                                                                    <br><small class="text-muted"><i class="fas fa-sort-numeric-up mr-1"></i>{{ __('Order') }}: {{ $lecture->chapter_order }}</small>
                                                                                @endif
                                                                                @if($lecture->type)
                                                                                    <br><small class="text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('Type') }}: {{ ucfirst(str_replace('_', ' ', $lecture->type)) }}</small>
                                                                                @endif
                                                                            </div>
                                                                            <div>
                                                                                <span class="badge badge-{{ $lecture->is_active ? 'success' : 'secondary' }}">
                                                                                    {{ $lecture->is_active ? __('Active') : __('Inactive') }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        {{-- Lecture File/URL --}}
                                                                        @php
                                                                            // Get raw values to avoid accessor issues
                                                                            $lectureRawFile = $lecture->getRawOriginal('file');
                                                                            $lectureRawUrl = $lecture->getRawOriginal('url');
                                                                            $lectureRawYoutubeUrl = $lecture->getRawOriginal('youtube_url');
                                                                        @endphp
                                                                        
                                                                        @if($lecture->type == 'file' && $lectureRawFile)
                                                                            <div class="mt-2">
                                                                                <small class="text-primary">
                                                                                    <i class="fas fa-file-video mr-1"></i>{{ __('File') }}: 
                                                                                    <a href="{{ $lecture->file }}" target="_blank" class="text-primary">{{ __('View File') }}</a>
                                                                                    @if($lecture->file_extension)
                                                                                        <span class="badge badge-info ml-1">{{ strtoupper($lecture->file_extension) }}</span>
                                                                                    @endif
                                                                                </small>
                                                                            </div>
                                                                        @elseif($lecture->type == 'url' && $lectureRawUrl)
                                                                            <div class="mt-2">
                                                                                <small class="text-primary">
                                                                                    <i class="fas fa-link mr-1"></i>{{ __('URL') }}: 
                                                                                    <a href="{{ $lectureRawUrl }}" target="_blank" class="text-primary">{{ $lectureRawUrl }}</a>
                                                                                </small>
                                                                            </div>
                                                                        @elseif($lecture->type == 'youtube_url' && $lectureRawYoutubeUrl)
                                                                            <div class="mt-2">
                                                                                <small class="text-primary">
                                                                                    <i class="fab fa-youtube mr-1"></i>{{ __('YouTube URL') }}: 
                                                                                    <a href="{{ $lectureRawYoutubeUrl }}" target="_blank" class="text-primary">{{ $lectureRawYoutubeUrl }}</a>
                                                                                </small>
                                                                            </div>
                                                                        @endif
                                                                        
                                                                        {{-- Lecture Resources --}}
                                                                        @if($lecture->resources && $lecture->resources->count() > 0)
                                                                            <div class="mt-2">
                                                                                <small class="font-weight-bold d-block mb-1">{{ __('Lecture Resources') }}:</small>
                                                                                <ul class="list-unstyled ml-3">
                                                                                    @foreach($lecture->resources as $resource)
                                                                                        <li>
                                                                                            <small>
                                                                                                @if($resource->type == 'file' && $resource->file)
                                                                                                    <i class="fas fa-file mr-1"></i>
                                                                                                    <a href="{{ $resource->file }}" target="_blank">{{ $resource->file }}</a>
                                                                                                @elseif($resource->type == 'url' && $resource->url)
                                                                                                    <i class="fas fa-link mr-1"></i>
                                                                                                    <a href="{{ $resource->url }}" target="_blank">{{ $resource->url }}</a>
                                                                                                @endif
                                                                                            </small>
                                </li>
                                                                                    @endforeach
                                                                                </ul>
                                                                            </div>
                                                                        @endif
                                </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                    
                                                    {{-- Quizzes --}}
                                                    @if($quizzes->count() > 0)
                                                        <div class="mb-3">
                                                            <h6 class="text-warning">
                                                                <i class="fas fa-question-circle mr-2"></i>{{ __('Quizzes') }} ({{ $quizzes->count() }})
                                                            </h6>
                                                            <ul class="list-group">
                                                                @foreach($quizzes as $quiz)
                                                                    <li class="list-group-item {{ !$quiz->is_active ? 'bg-light' : '' }}">
                                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                                            <div class="flex-grow-1">
                                                                                <i class="fas fa-clipboard-list text-warning mr-2"></i>
                                                                                <strong>{{ $quiz->title ?? __('Untitled Quiz') }}</strong>
                                                                                @if($quiz->description)
                                                                                    <br><small class="text-muted">{!! nl2br(e(\Illuminate\Support\Str::limit($quiz->description, 150))) !!}</small>
                                                                                @endif
                                                                                @if($quiz->time_limit)
                                                                                    <br><small class="text-info"><i class="fas fa-clock mr-1"></i>{{ __('Time Limit') }}: {{ $quiz->time_limit }} {{ __('minutes') }}</small>
                                                                                @endif
                                                                                @if($quiz->total_points)
                                                                                    <br><small class="text-info"><i class="fas fa-star mr-1"></i>{{ __('Points') }}: {{ $quiz->total_points }}</small>
                                                                                @endif
                                                                                @if($quiz->passing_score)
                                                                                    <br><small class="text-info"><i class="fas fa-check-circle mr-1"></i>{{ __('Passing Score') }}: {{ $quiz->passing_score }}%</small>
                                                                                @endif
                                                                                @if($quiz->chapter_order)
                                                                                    <br><small class="text-muted"><i class="fas fa-sort-numeric-up mr-1"></i>{{ __('Order') }}: {{ $quiz->chapter_order }}</small>
                                                                                @endif
                                                                            </div>
                                                                            <div>
                                                                                <span class="badge badge-{{ $quiz->is_active ? 'success' : 'secondary' }}">
                                                                                    {{ $quiz->is_active ? __('Active') : __('Inactive') }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        {{-- Quiz Questions and Answers --}}
                                                                        @if($quiz->questions && $quiz->questions->count() > 0)
                                                                            <div class="mt-3">
                                                                                <small class="font-weight-bold d-block mb-2">{{ __('Questions') }} ({{ $quiz->questions->count() }}):</small>
                                                                                <div class="ml-3">
                                                                                    @foreach($quiz->questions as $questionIndex => $question)
                                                                                        <div class="mb-3 p-2 border rounded">
                                                                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                                                                <div class="flex-grow-1">
                                                                                                    <strong class="text-primary">Q{{ $questionIndex + 1 }}: {{ $question->question ?? __('Untitled Question') }}</strong>
                                                                                                    @if($question->points)
                                                                                                        <br><small class="text-info"><i class="fas fa-star mr-1"></i>{{ __('Points') }}: {{ $question->points }}</small>
                                                                                                    @endif
                                                                                                </div>
                                                                                                <span class="badge badge-{{ $question->is_active ? 'success' : 'secondary' }} badge-sm">
                                                                                                    {{ $question->is_active ? __('Active') : __('Inactive') }}
                                                                                                </span>
                                                                                            </div>
                                                                                            
                                                                                            {{-- Question Options (Answers) --}}
                                                                                            @if($question->options && $question->options->count() > 0)
                                                                                                <div class="ml-3 mt-2">
                                                                                                    <small class="font-weight-bold d-block mb-1">{{ __('Options') }}:</small>
                                                                                                    <ul class="list-unstyled">
                                                                                                        @foreach($question->options as $optionIndex => $option)
                                                                                                            <li class="mb-1">
                                                                                                                <small>
                                                                                                                    <span class="badge badge-{{ $option->is_correct ? 'success' : 'secondary' }} mr-1">
                                                                                                                        {{ chr(65 + $optionIndex) }}
                                                                                                                    </span>
                                                                                                                    {{ $option->option ?? __('Untitled Option') }}
                                                                                                                    @if($option->is_correct)
                                                                                                                        <i class="fas fa-check-circle text-success ml-1" title="{{ __('Correct Answer') }}"></i>
                                                                                                                    @endif
                                                                                                                    <span class="badge badge-{{ $option->is_active ? 'info' : 'secondary' }} badge-sm ml-1">
                                                                                                                        {{ $option->is_active ? __('Active') : __('Inactive') }}
                                                                                                                    </span>
                                                                                                                </small>
                                                                                                            </li>
                                                                                                        @endforeach
                                                                                                    </ul>
                                                                                                </div>
                                                                                            @else
                                                                                                <div class="ml-3 mt-2">
                                                                                                    <small class="text-muted">{{ __('No options available') }}</small>
                                                                                                </div>
                                                                                            @endif
                                                                                        </div>
                                                                                    @endforeach
                                                                                </div>
                                                                            </div>
                                                                        @else
                                                                            <div class="mt-2">
                                                                                <small class="text-muted">{{ __('No questions available') }}</small>
                                                                            </div>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                    
                                                    {{-- Assignments --}}
                                                    @if($assignments->count() > 0)
                                                        <div class="mb-3">
                                                            <h6 class="text-info">
                                                                <i class="fas fa-tasks mr-2"></i>{{ __('Assignments') }} ({{ $assignments->count() }})
                                                            </h6>
                                                            <ul class="list-group">
                                                                @foreach($assignments as $assignment)
                                                                    <li class="list-group-item d-flex justify-content-between align-items-center {{ !$assignment->is_active ? 'bg-light' : '' }}">
                                                                        <div class="flex-grow-1">
                                                                            <i class="fas fa-file-alt text-info mr-2"></i>
                                                                            <strong>{{ $assignment->title ?? 'Untitled Assignment' }}</strong>
                                                                            @if($assignment->description)
                                                                                <br><small class="text-muted">{!! nl2br(e(\Illuminate\Support\Str::limit($assignment->description, 150))) !!}</small>
                                                                            @endif
                                                                            @if($assignment->points)
                                                                                <br><small class="text-info"><i class="fas fa-star mr-1"></i>{{ __('Points') }}: {{ $assignment->points }}</small>
                                                                            @endif
                                                                            @if($assignment->chapter_order)
                                                                                <br><small class="text-muted"><i class="fas fa-sort-numeric-up mr-1"></i>{{ __('Order') }}: {{ $assignment->chapter_order }}</small>
                                                                            @endif
                                                                        </div>
                                                                        <div>
                                                                            <span class="badge badge-{{ $assignment->is_active ? 'success' : 'secondary' }}">
                                                                                {{ $assignment->is_active ? __('Active') : __('Inactive') }}
                                                                            </span>
                                                                        </div>
                                </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                    
                                                    {{-- Resources --}}
                                                    @if($resources->count() > 0)
                                                        <div class="mb-3">
                                                            <h6 class="text-secondary">
                                                                <i class="fas fa-file-download mr-2"></i>{{ __('Resources') }} ({{ $resources->count() }})
                                                            </h6>
                                                            <ul class="list-group">
                                                                @foreach($resources as $resource)
                                                                    <li class="list-group-item {{ !$resource->is_active ? 'bg-light' : '' }}">
                                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                                            <div class="flex-grow-1">
                                                                                <i class="fas fa-file text-secondary mr-2"></i>
                                                                                <strong>{{ $resource->title ?? 'Untitled Resource' }}</strong>
                                                                                @if($resource->description)
                                                                                    <br><small class="text-muted">{!! nl2br(e(\Illuminate\Support\Str::limit($resource->description, 150))) !!}</small>
                                                                                @endif
                                                                                @if($resource->type)
                                                                                    <br><small class="text-muted"><i class="fas fa-info-circle mr-1"></i>{{ __('Type') }}: {{ ucfirst($resource->type) }}</small>
                                                                                @endif
                                                                                @if($resource->chapter_order)
                                                                                    <br><small class="text-muted"><i class="fas fa-sort-numeric-up mr-1"></i>{{ __('Order') }}: {{ $resource->chapter_order }}</small>
                                                                                @endif
                                                                            </div>
                                                                            <div>
                                                                                <span class="badge badge-{{ $resource->is_active ? 'success' : 'secondary' }}">
                                                                                    {{ $resource->is_active ? __('Active') : __('Inactive') }}
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        {{-- Resource File/URL --}}
                                                                        @if($resource->type == 'file' && $resource->file)
                                                                            <div class="mt-2">
                                                                                <small class="text-primary">
                                                                                    <i class="fas fa-file-download mr-1"></i>{{ __('Document File') }}: 
                                                                                    <a href="{{ $resource->file }}" target="_blank" class="text-primary">{{ __('View/Download File') }}</a>
                                                                                    @if($resource->file_extension)
                                                                                        <span class="badge badge-info ml-1">{{ strtoupper($resource->file_extension) }}</span>
                                                                                    @endif
                                                                                </small>
                                                                            </div>
                                                                        @elseif($resource->type == 'url' && $resource->url)
                                                                            <div class="mt-2">
                                                                                <small class="text-primary">
                                                                                    <i class="fas fa-link mr-1"></i>{{ __('Document URL') }}: 
                                                                                    <a href="{{ $resource->url }}" target="_blank" class="text-primary">{{ $resource->url }}</a>
                                                                                </small>
                                                                            </div>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                            </ul>
                        </div>
                                                    @endif
                    </div>
                                            @else
                                                <div class="">
                                                    <i class="fas fa-info-circle mr-2"></i>{{ __('No curriculum content available for this chapter.') }}
                        </div>
                                            @endif
                                </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @else
        <div class="row mt-4">
            <div class="col-md-12 grid-margin stretch-card search-container">
                    <div class="card">
                        <div class="card-body">
                        <div class="">
                            <i class="fas fa-info-circle mr-2"></i>{{ __('No chapters available for this course.') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        // Update chevron icon on accordion toggle
        $('#courseChaptersAccordion').on('show.bs.collapse', function(e) {
            $(e.target).prev().find('.fa-chevron-right').removeClass('fa-chevron-right').addClass('fa-chevron-down');
        });
        
        $('#courseChaptersAccordion').on('hide.bs.collapse', function(e) {
            $(e.target).prev().find('.fa-chevron-down').removeClass('fa-chevron-down').addClass('fa-chevron-right');
        });
    });
    </script>
@endpush
