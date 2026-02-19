@extends('layouts.app')

@section('title')
    {{ __('manage') . ' ' . __('sliders') }}
@endsection


@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
    </div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('sliders-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('create') . ' ' . __('sliders') }}

                        </h4>
                        <form class="pt-3 mt-6 create-form" method="POST"
      action="{{ route('sliders.store') }}" data-success-function="formSuccessFunction"
      data-parsley-validate enctype="multipart/form-data"> @csrf <div class="row">
        {{-- Image --}}
        {{-- Image --}}
        <div class="form-group col-sm-12 col-md-4">
            <label>{{ __('Image') }} <span class="text-danger">*</span></label>
            <input type="file" name="image" class="form-control" accept="image/*" required>
        </div>


        {{-- Model Type --}}
        <div class="form-group col-sm-12 col-md-4">
            <label>{{ __('Select Type') }}</label>
            <select name="model_type" id="model_type" class="form-control" required>
                <option value="">{{ __('Select Type') }}</option>
                <option value="default">{{ __('Default') }}</option>
                <option value="custom_link">{{ __('Custom Link') }}</option>
                <option value="course">{{ __('Course') }}</option>
                <option value="instructor">{{ __('Instructor') }}</option>
            </select>
        </div>

        {{-- Third Party Link - only visible if model_type is custom_link --}}
        <div class="form-group col-sm-12 col-md-4" id="third-party-link-section" style="display: none;">
            <label>{{ __('Third Party Link') }} <span class="text-danger">*</span></label>
            <input type="url" name="third_party_link" id="third_party_link" class="form-control" placeholder="{{ __('Enter link') }}">
        </div>

        {{-- Model ID Dropdown - only visible if model_type is Course or Instructor --}}
        <div class="form-group col-sm-12 col-md-4" id="model-id-section" style="display: none;">
            <label>{{ __('Select Option') }} <span class="text-danger">*</span></label>
            <select name="model_id" id="model_id" class="form-control">
                <option value="">{{ __('Select Option') }}</option>
            </select>
        </div>
        <label></label>
    <input class="btn btn-primary float-right ml-3" type="submit" value="{{ __('Submit') }}" style="width: auto; min-width: 120px; height: 38px; line-height: 1.5;">
</form>

                    </div>
                </div>
            </div>
        </div>
        @endcan
        <!-- Table List -->
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">
                            {{ __('list') . ' ' . __('sliders') }}
                        </h4>
                        <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                            data-table="sliders" data-toggle="table" data-status-column="is_required"
                            data-url="{{ route('sliders.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                            data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true"
                            data-reorderable-rows="true" data-maintain-selected="true" data-export-data-type="all"
                            data-export-options='{ "fileName": "{{ __('custom-fields') }}-{{ date('d-m-y') }}"
                            ,"ignoreColumn":["operate"]}'
                            data-show-export="true" data-query-params="customFieldsQueryParams">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true">
                                        {{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th scope="col" data-field="image" data-sortable="false" data-formatter="imageFormatter" data-escape="false">{{ __('image') }}</th>
                                    <th scope="col" data-field="type" data-sortable="true" data-escape="true">{{ __('type') }}</th>
                                    <th scope="col" data-field="value" data-sortable="true" data-escape="true">{{ __('value') }}</th>
                                    <th scope="col" data-field="order" data-sortable="false" data-escape="true">{{ __('order') }}
                                    </th>
                                    <th scope="col" data-field="operate" data-sortable="false"
                                        data-escape="false">{{ __('action') }}</th>
                                </tr>
                            </thead>
                        </table>
                        <span
                            class="d-block mb-4 mt-2 text-danger small">{{ __('Note :- you can change the rank of rows by dragging rows') }}</span>
                        <div class="mt-1 d-md-block">
                            <button id="change-order-form-field" class="btn btn-primary">{{ __('update_rank') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div> @endsection

@section('script')
    <script>
        let courses = {!! json_encode($courses ?? []) !!};
        let instructors = {!! json_encode($instructors ?? []) !!};

        // Ensure we have valid arrays
        if (typeof courses !== 'object' || !Array.isArray(courses)) {
            console.error('Courses is not a valid array:', courses);
            window.courses = [];
        } else {
            window.courses = courses;
        }

        if (typeof instructors !== 'object' || !Array.isArray(instructors)) {
            console.error('Instructors is not a valid array:', instructors);
            window.instructors = [];
        } else {
            window.instructors = instructors;
        }

        document.getElementById('model_type').addEventListener('change', function () {
            const modelType = this.value;
            const thirdPartyLinkSection = document.getElementById('third-party-link-section');
            const modelIdSection = document.getElementById('model-id-section');
            const modelIdSelect = document.getElementById('model_id');
            const thirdPartyLinkInput = document.getElementById('third_party_link');

            // Reset fields
            modelIdSelect.innerHTML = '<option value="">{{ __("Select Option") }}</option>';
            if (thirdPartyLinkInput) {
                thirdPartyLinkInput.value = '';
            }

            // Remove required attributes
            modelIdSelect.removeAttribute('required');
            if (thirdPartyLinkInput) {
                thirdPartyLinkInput.removeAttribute('required');
            }

            if (modelType === 'default') {
                // Default: Hide both sections, no redirect
                thirdPartyLinkSection.style.display = 'none';
                modelIdSection.style.display = 'none';
            } else if (modelType === 'custom_link') {
                // Custom Link: Show third party link, hide model id
                thirdPartyLinkSection.style.display = 'block';
                modelIdSection.style.display = 'none';
                if (thirdPartyLinkInput) {
                    thirdPartyLinkInput.setAttribute('required', 'required');
                }
            } else if (modelType === 'course') {
                // Course: Show model id, hide third party link
                thirdPartyLinkSection.style.display = 'none';
                modelIdSection.style.display = 'block';
                modelIdSelect.setAttribute('required', 'required');
                if (window.courses && Array.isArray(window.courses)) {
                    window.courses.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.text = item.title || 'Untitled Course';
                        modelIdSelect.appendChild(option);
                    });
                }
            } else if (modelType === 'instructor') {
                // Instructor: Show model id, hide third party link
                thirdPartyLinkSection.style.display = 'none';
                modelIdSection.style.display = 'block';
                modelIdSelect.setAttribute('required', 'required');
                if (window.instructors && Array.isArray(window.instructors)) {
                    window.instructors.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.text = item.user ? item.user.name : 'Unknown Instructor';
                        modelIdSelect.appendChild(option);
                    });
                }
            } else {
                // Empty selection: Hide both
                thirdPartyLinkSection.style.display = 'none';
                modelIdSection.style.display = 'none';
            }
        });

        // Attach filters to table query params
        function customFieldsQueryParams(params) {
            params.show_deleted = 0;
            return params;
        }

        // Hide Action column if no rows have any actions (all operate fields are empty)
        $('#table_list').on('load-success.bs.table', function (e, data) {
            if (data && data.rows) {
                const hasAnyActions = data.rows.some(row => row.operate && row.operate.trim() !== '');
                if (!hasAnyActions) {
                    $('#table_list').bootstrapTable('hideColumn', 'operate');
                } else {
                    $('#table_list').bootstrapTable('showColumn', 'operate');
                }
            }
        });
    </script>
@endsection
