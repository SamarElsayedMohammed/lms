@extends('layouts.app')

@section('title')
    {{ __('Supervisor Form Details') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('instructor.index') }}">← {{ __('Back to All Supervisors') }}</a>
    </div>
@endsection

@section('main')
    <div class="content-wrapper">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                {{-- User Details Card--}}
                <div class="card">
                    <div class="card-header">
                        <div class="divider">
                            <div class="divider-text">
                                {{-- Title --}}
                                <h4 class="card-title">{{ __('Supervisor User Details') }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="card-body mt-4 plr-50">
                        <div class="row">
                            {{-- ID --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('ID') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->id }}" disabled>
                            </div>

                            {{-- User ID --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('User ID') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->user_id }}" disabled>
                            </div>

                            {{-- Name --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Name') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->user->name }}" disabled>
                            </div>

                            {{-- Email --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Email') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->user->email }}" disabled>
                            </div>

                            {{-- Type --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Type') }}</label>
                                <input type="text" class="form-control" value="@if(isset($instructor->type) && $instructor->type == 'individual'){{ __('Individual') }}@elseif(isset($instructor->type) && $instructor->type == 'team'){{ __('Team') }}@else{{ __('N/A') }}@endif" disabled>
                            </div>

                            {{-- Status --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Status') }}</label>
                                <input type="text" class="form-control" value="@if($instructor->status == 'approved'){{ __('Approved') }}@elseif($instructor->status == 'rejected'){{ __('Rejected') }}@elseif($instructor->status == 'suspended'){{ __('Suspended') }}@else{{ __('Pending') }}@endif" disabled>
                            </div>

                            {{-- Reason --}}
                            @if($instructor->reason)
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Reason') }}</label>
                                <textarea class="form-control" disabled>{{ $instructor->reason }}</textarea>
                            </div>
                            @endif

                            {{-- Created At --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Created At') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->created_at ? $instructor->created_at->format('F d, Y \a\t g:i A') : __('N/A') }}" disabled>
                            </div>

                            {{-- Updated At --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Updated At') }}</label>
                                <input type="text" class="form-control" value="{{ $instructor->updated_at ? $instructor->updated_at->format('F d, Y \a\t g:i A') : __('N/A') }}" disabled>
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Personal Details Card--}}
                <div class="card">
                    <div class="card-header">
                        <div class="divider">
                            <div class="divider-text">
                                {{-- Title --}}
                                <h4 class="card-title">{{ __('Supervisor Personal Details') }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="card-body mt-4 plr-50">
                        <div class="row">
                            {{-- Qualification --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Qualification') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->qualification)
                                    <textarea class="form-control" disabled>{{ $instructor->personal_details->qualification }}</textarea>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Experience --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Years of Experience') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->years_of_experience)
                                    <input type="number" class="form-control" value="{{ $instructor->personal_details->years_of_experience }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Skills --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Skills') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->skills)
                                    <textarea class="form-control" disabled>{{ $instructor->personal_details->skills }}</textarea>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Bank Account Number --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Bank Account Number') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->bank_account_number)
                                    <input type="text" class="form-control" value="{{ $instructor->personal_details->bank_account_number }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Bank Name --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Bank Name') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->bank_name)
                                    <input type="text" class="form-control" value="{{ $instructor->personal_details->bank_name }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Bank Account Holder Name --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Bank Account Holder Name') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->bank_account_holder_name)
                                    <input type="text" class="form-control" value="{{ $instructor->personal_details->bank_account_holder_name }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Bank IFSC Code --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Bank IFSC Code') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->bank_ifsc_code)
                                    <input type="text" class="form-control" value="{{ $instructor->personal_details->bank_ifsc_code }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Team Name --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Team Name') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->team_name)
                                    <input type="text" class="form-control" value="{{ $instructor->personal_details->team_name }}" disabled>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Team Logo --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Team Logo') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->getRawOriginal('team_logo'))
                                    <div>
                                        <a href="{{ $instructor->personal_details->team_logo }}" target="_blank" class="btn btn-primary btn-sm">{{ __('View Team Logo') }}</a>
                                    </div>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- About Me --}}
                            <div class="form-group col-md-12">
                                <label>{{ __('About Me') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->about_me)
                                    <div class="form-control-plaintext" style="min-height: 150px; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 0.25rem;">
                                        {!! $instructor->personal_details->about_me !!}
                                    </div>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- ID Proof --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('ID Proof') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->getRawOriginal('id_proof'))
                                    <div>
                                        <a href="{{ $instructor->personal_details->id_proof }}" target="_blank" class="btn btn-primary btn-sm">{{ __('View ID Proof') }}</a>
                                    </div>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>

                            {{-- Preview Video --}}
                            <div class="form-group col-md-6 col-lg-4">
                                <label>{{ __('Preview Video') }}</label>
                                @if($instructor->personal_details && $instructor->personal_details->getRawOriginal('preview_video'))
                                    <div>
                                        <a href="{{ $instructor->personal_details->preview_video }}" target="_blank" class="btn btn-primary btn-sm">{{ __('View Video') }}</a>
                                    </div>
                                @else
                                    <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                @endif
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Social Media Card--}}
                <div class="card">
                    <div class="card-header">
                        <div class="divider">
                            <div class="divider-text">
                                {{-- Title --}}
                                <h4 class="card-title">{{ __('Supervisor Social Media') }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="card-body mt-4 plr-50">
                        @if($instructor->social_medias && $instructor->social_medias->count() > 0)
                            <div class="row">
                                @foreach($instructor->social_medias as $key => $socialMedia)
                                    <div class="form-group col-md-6 col-lg-4">
                                        <label class="form-label">{{ $key + 1 }}. {{ ($socialMedia->social_media && $socialMedia->social_media->name) ? $socialMedia->social_media->name : ($socialMedia->title ?? __('N/A')) }}</label>
                                        @if($socialMedia->url)
                                            <input type="text" class="form-control mb-2" value="{{ $socialMedia->url }}" disabled>
                                            <a href="{{ $socialMedia->url }}" target="_blank" class="btn btn-primary btn-sm">{{ __('View') }}</a>
                                        @else
                                            <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="">
                                <p class="mb-0">{{ __('No data available') }}</p>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Other Details Card--}}
                <div class="card">
                    <div class="card-header">
                        <div class="divider">
                            <div class="divider-text">
                                {{-- Title --}}
                                <h4 class="card-title">{{ __('Supervisor Other Details') }}</h4>
                            </div>
                        </div>
                    </div>
                    <div class="card-body mt-4 plr-50 plr-50">
                        @if($instructor->other_details && $instructor->other_details->count() > 0)
                            <div class="row">
                                @php
                                    // Group other_details by custom_form_field_id to handle checkbox duplicates
                                    $groupedDetails = $instructor->other_details->groupBy('custom_form_field_id');
                                @endphp
                                @foreach ($groupedDetails as $fieldId => $details)
                                    @php
                                        $otherDetail = $details->first();
                                    @endphp
                                    @if($otherDetail && $otherDetail->custom_form_field)
                                    <div class="form-group col-md-6 col-lg-4">
                                        @switch($otherDetail->custom_form_field->type)
                                            @case('text')
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                <input type="text" class="form-control" value="{{ $otherDetail->value }}" disabled>
                                                @break

                                            @case('textarea')
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                <textarea class="form-control" disabled>{{ $otherDetail->value }}</textarea>
                                                @break

                                            @case('number')
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                <input type="number" class="form-control" value="{{ $otherDetail->value }}" disabled>
                                                @break

                                            @case('checkbox')
                                                @php
                                                    // Get all other details for this custom form field to find all selected options
                                                    $selectedOptionIds = $details->pluck('custom_form_field_option_id')->filter()->toArray();
                                                    $selectedOptions = $details->pluck('value')->filter()->toArray();
                                                @endphp
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                @foreach ($otherDetail->custom_form_field->options as $option)
                                                    @php
                                                        $isChecked = false;
                                                        if ($option->id && in_array($option->id, $selectedOptionIds)) {
                                                            $isChecked = true;
                                                        } elseif (!empty($selectedOptions)) {
                                                            // Check if option text is in selected values (for backward compatibility)
                                                            $valueArray = is_string($selectedOptions[0] ?? null) ? json_decode($selectedOptions[0] ?? '[]', true) : $selectedOptions;
                                                            if (is_array($valueArray) && in_array($option->option, $valueArray)) {
                                                                $isChecked = true;
                                                            } elseif (in_array($option->option, $selectedOptions)) {
                                                                $isChecked = true;
                                                            }
                                                        }
                                                    @endphp
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input" {{ $isChecked ? 'checked' : '' }} disabled>
                                                        <label class="form-check-label">{{ $option->option }}</label>
                                                    </div>
                                                @endforeach
                                                @break

                                            @case('dropdown')
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                <select class="form-control" disabled>
                                                    @if($otherDetail->custom_form_field_option_id)
                                                        @foreach ($otherDetail->custom_form_field->options as $option)
                                                            <option value="{{ $option->id }}" {{ $otherDetail->custom_form_field_option_id == $option->id ? 'selected' : '' }}>{{ $option->option }}</option>
                                                        @endforeach
                                                    @else
                                                        @foreach ($otherDetail->custom_form_field->options as $option)
                                                            <option value="{{ $option->option }}" {{ $otherDetail->value == $option->option ? 'selected' : '' }}>{{ $option->option }}</option>
                                                        @endforeach
                                                    @endif
                                                </select>
                                                @break

                                            @case('radio')
                                                <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                @foreach ($otherDetail->custom_form_field->options as $option)
                                                    <div class="form-check">
                                                        <input type="radio" class="form-check-input" name="{{ $otherDetail->custom_form_field->name }}" value="{{ $option->option }}" {{ $otherDetail->custom_form_field_option_id == $option->id ? 'checked' : '' }} disabled>
                                                        <label class="form-check-label">{{ $option->option }}</label>
                                                    </div>
                                                @endforeach
                                                @break

                                            @case('file')
                                                @switch($otherDetail->extension)
                                                    @case('jpg')
                                                    @case('jpeg')
                                                    @case('png')
                                                    @case('gif')
                                                    @case('svg')
                                                        <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                        @if(!empty($otherDetail->value))
                                                            <div>
                                                                <a href="{{ $otherDetail->value }}" target="_blank" class="btn btn-primary btn-sm">{{ __('View File') }}</a>
                                                            </div>
                                                        @else
                                                            <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                                        @endif
                                                        @break

                                                    @case('pdf')
                                                    @case('txt')
                                                    @case('doc')
                                                    @case('docx')
                                                        <label>{{ $otherDetail->custom_form_field->name }}</label>
                                                        @if(!empty($otherDetail->value))
                                                            <div>
                                                                <a href="{{ $otherDetail->value }}" target="_blank" download class="btn btn-primary btn-sm">{{ __('Download File') }}</a>
                                                            </div>
                                                        @else
                                                            <p class="text-muted mb-0">{{ __('N/A') }}</p>
                                                        @endif
                                                        @break
                                                @endswitch
                                                @break
                                        @default
                                            @break
                                    @endswitch
                                </div>
                                    @endif
                            @endforeach
                        </div>
                        @else
                            <div class="">
                                <p class="mb-0">{{ __('No data available') }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
