@extends('layouts.app')

@section('title')
    {{ __('View Certificate') }}
@endsection

@section('page-title')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
        <h1 class="mb-2 mb-md-0 flex-shrink-0">@yield('title'): <span class="d-block d-md-inline">{{ $certificate->name }}</span></h1>
        <div class="section-header-button w-100 w-md-auto" style="margin-left: auto;">
            <div class="d-flex flex-column flex-md-row gap-2 w-100 w-md-auto">
                <a href="{{ route('admin.certificates.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">{{ __('Back to Certificates') }}</span>
                    <span class="d-sm-none">{{ __('Back') }}</span>
                </a>
                <a href="{{ route('admin.certificates.edit', $certificate) }}" class="btn btn-warning">
                    <i class="fas fa-edit"></i> <span class="d-none d-md-inline">{{ __('Edit') }}</span>
                </a>
                <a href="{{ route('admin.certificates.preview', $certificate) }}" class="btn btn-info" target="_blank">
                    <i class="fas fa-search"></i> <span class="d-none d-md-inline">{{ __('Preview') }}</span>
                </a>
                <a href="{{ route('admin.certificates.editor', $certificate) }}" class="btn btn-primary">
                    <i class="fas fa-edit"></i> <span class="d-none d-md-inline">{{ __('Edit Design') }}</span>
                    <span class="d-md-none">{{ __('Design') }}</span>
                </a>
            </div>
        </div>
    </div>
@endsection

@section('main')
<div class="section">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4>{{ __('Certificate Details') }}: {{ $certificate->name }}</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <table class="table table-bordered table-sm">
                                <tr>
                                    <th width="30%">ID</th>
                                    <td>{{ $certificate->id }}</td>
                                </tr>
                                <tr>
                                    <th>Name</th>
                                    <td>{{ $certificate->name }}</td>
                                </tr>
                                <tr>
                                    <th>Type</th>
                                    <td>
                                        <span class="badge badge-{{ $certificate->type === 'course_completion' ? 'success' : ($certificate->type === 'exam_completion' ? 'info' : 'warning') }}">
                                            {{ ucwords(str_replace('_', ' ', $certificate->type)) }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Description</th>
                                    <td>{{ $certificate->description ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Title</th>
                                    <td>{{ $certificate->title ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Subtitle</th>
                                    <td>{{ $certificate->subtitle ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Signature Text</th>
                                    <td>{{ $certificate->signature_text ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <th>Status</th>
                                    <td>
                                        <span class="badge badge-{{ $certificate->is_active ? 'success' : 'danger' }}">
                                            {{ $certificate->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created At</th>
                                    <td>{{ $certificate->created_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                                <tr>
                                    <th>Updated At</th>
                                    <td>{{ $certificate->updated_at->format('M d, Y H:i:s') }}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-12 col-md-6">
                            <h5>Background Image</h5>
                            @if($certificate->background_image)
                                <img src="{{ $certificate->background_image_url }}" alt="Background Image" 
                                     class="img-fluid img-thumbnail" style="max-height: 300px;">
                            @else
                                <p class="text-muted">No background image uploaded</p>
                            @endif

                            <h5 class="mt-4">Signature Image</h5>
                            @if($certificate->signature_image)
                                <img src="{{ $certificate->signature_image_url }}" alt="Signature Image" 
                                     class="img-thumbnail" style="max-height: 150px;">
                            @else
                                <p class="text-muted">No signature image uploaded</p>
                            @endif
                        </div>
                    </div>

                    @if($certificate->template_settings)
                    <div class="row mt-4">
                        <div class="col-12">
                            <h5>Template Settings</h5>
                            <pre class="bg-light p-3">{{ json_encode($certificate->template_settings, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
@endpush
