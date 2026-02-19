    @extends('layouts.app')

    @section('title')
        {{ __('Manage Feature Sections') }}
    @endsection

    @push('style')
    @endpush

    @section('page-title')
        <h1 class="mb-0">@yield('title')</h1>
        <div class="section-header-button ml-auto">
        </div> @endsection

    @section('main')
        <div class="content-wrapper">

            <!-- Create Form -->
            @can('feature-sections-create')
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card search-container">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">
                                {{ __('Create Feature Section') }}
                            </h4>
                            <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('feature-sections.store') }}" data-parsley-validate enctype="multipart/form-data"> @csrf <div class="row">
                                    <!-- Type Dropdown -->
                                    <div class="form-group col-sm-12 col-md-6">
                                        <label>{{ __('Type') }} <span class="text-danger"> * </span></label>
                                        <select name="type" id="type" class="form-control" required>
                                            <option value="">{{ __('Select Type') }}</option>
                                            <option value="top_rated_courses"> {{ __('Top Rated Courses') }} </option>
                                            <option value="newly_added_courses"> {{ __('Newly Added Courses') }} </option>
                                            <option value="most_viewed_courses"> {{ __('Most Viewed Courses') }} </option>
                                            <option value="offer"> {{ __('Offer') }} </option>
                                            <option value="why_choose_us"> {{ __('Why choose us') }} </option>
                                            <option value="free_courses"> {{ __('Free Courses') }} </option>
                                            <option value="become_instructor"> {{ __('Become Instructor') }} </option>
                                            <option value="top_rated_instructors"> {{ __('Top rated Instructors') }} </option>
                                            <option value="wishlist"> {{ __('Wishlist') }} </option>
                                            <option value="searching_based"> {{ __('Based on Searching') }} </option>
                                            <option value="recommend_for_you"> {{ __('Recommend for you') }} </option>
                                            <option value="my_learning"> {{ __('My Learning') }} </option>
                                        </select>
                                    </div>

                                    <!-- Title -->
                                    <div class="form-group col-sm-12 col-md-6">
                                        <label>{{ __('Title') }} <span class="text-danger">* </span></label>
                                        <input type="text" name="title" placeholder="{{ __('Section title') }}" class="form-control" required>
                                    </div>

                                    <!-- Limit (shown conditionally) -->
                                    <div class="form-group col-sm-12 col-md-6 limit-field d-none">
                                        <label>{{ __('Limit') }} <span class="text-danger">* </span></label>
                                        <input type="number" step="1" name="limit" placeholder="e.g. 2" class="form-control" data-parsley-excluded="true">
                                    </div>

                                    <!-- Offer Image (shown conditionally) -->
                                    <div class="form-group col-sm-12 col-md-6 offer-image-field d-none">
                                        <label>{{ __('Offer Image') }} <span class="text-danger">*</span></label>
                                        <input type="file" name="offer_image" class="form-control" accept="image/*" id="offer_image">
                                    </div>
                                </div>

                                <input class="btn btn-primary float-right ml-3" type="submit" value="{{ __('Submit') }}">
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
                                {{ __('List Feature Sections') }}
                            </h4>
                            <div class="col-12 mt-4 text-right">
                                <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                            </div>
                            <div id="toolbar"></div>
                            <table aria-describedby="mydesc" class="table reorder-table-row" id="table_list"
                                data-table="feature_sections" data-toggle="table" data-status-column="is_active"
                                data-url="{{ route('feature-sections.show', 0) }}" data-click-to-select="true"
                                data-side-pagination="server" data-pagination="true"
                                data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar"
                                data-show-columns="true" data-show-refresh="true" data-trim-on-search="false"
                                data-mobile-responsive="true" data-use-row-attr-func="true" data-reorderable-rows="true"
                                data-maintain-selected="true" data-export-data-type="all"
                                data-export-options='{ "fileName": "{{ __('feature-sections') }}-<?= date('d-m-y') ?>","ignoreColumn":["operate"]}'
                                data-show-export="true" data-export-types='["csv", "excel", "pdf"]' data-query-params="queryParams">
                                <thead>
                                    <tr>
                                        <th data-field="id" data-visible="false" data-escape="true">{{ __('id') }}</th>
                                        <th data-field="no" data-escape="true">{{ __('no.') }}</th>
                                        <th data-field="type" data-formatter="sentenceCaseFormatter" data-escape="true">{{ __('Type') }}</th>
                                        <th data-field="title" data-escape="true">{{ __('Title') }}</th>
                                        <th data-field="limit" data-escape="true">{{ __('Limit') }}</th>
                                        <th data-field="images" data-formatter="imageFormatter" data-escape="false">{{ __('Image') }}</th>
                                        <th data-field="row_order" data-escape="true">{{ __('Row Order') }}</th>
                                        <th data-field="is_active" data-formatter="statusFormatter" data-escape="false">{{ __('Status') }}</th>
                                        <th data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="featureSectionAction" data-escape="false">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                            </table>
                            <span
                                class="d-block mb-4 mt-2 text-danger small" id="rank-note">{{ __('Note :- you can change the rank of rows by dragging rows') }}</span>
                            <div class="mt-1 d-none d-md-block">
                                <button id="change-order-feature-section" class="btn btn-primary">{{ __('update_rank') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal fade" id="featureSectionEditModal" tabindex="-1" role="dialog"
                aria-labelledby="featureSectionEditModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-md" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="featureSectionEditModalLabel">{{ __('Edit Feature Section') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                                <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                            </button>
                        </div>
                        <form class="pt-3 mt-6 edit-form" method="POST" data-parsley-validate id="featureSectionEditForm"> @csrf
                            @method('PUT')
            <div class="modal-body">
                                <input type="hidden" name="id" id="edit_feature_section_id">
                                <div class="form-group">
                                    <label>{{ __('Type') }} <span class="text-danger">* </span></label>
                                    <select name="type" id="edit_type" class="form-control" required>
                                        <option value="top_rated_courses"> {{ __('Top Rated Courses') }} </option>
                                        <option value="newly_added_courses"> {{ __('Newly Added Courses') }} </option>
                                        <option value="most_viewed_courses"> {{ __('Most Viewed Courses') }} </option>
                                        <option value="offer"> {{ __('Offer') }} </option>
                                        <option value="why_choose_us"> {{ __('Why choose us') }} </option>
                                        <option value="free_courses"> {{ __('Free Courses') }} </option>
                                        <option value="become_instructor"> {{ __('Become Instructor') }} </option>
                                        <option value="top_rated_instructors"> {{ __('Top rated Instructors') }} </option>
                                        <option value="wishlist"> {{ __('Wishlist') }} </option>
                                        <option value="searching_based"> {{ __('Based on Searching') }} </option>
                                        <option value="recommend_for_you"> {{ __('Recommend for you') }} </option>
                                        <option value="my_learning"> {{ __('My Learning') }} </option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>{{ __('Title') }} <span class="text-danger">* </span></label>
                                    <input type="text" name="title" id="edit_title" class="form-control" required>
                                </div>
                                <div class="form-group limit-field d-none">
                                    <label>{{ __('Limit') }} <span class="text-danger">* </span></label>
                                    <input type="number" name="limit" id="edit_limit" class="form-control" required>
                                </div>
                                <!-- Offer Image Upload -->
                                <div class="form-group  offer-image-field d-none">
                                    <label>{{ __('Offer Image') }}</label>
                                    <input type="file" name="offer_image" class="form-control" accept="image/*" id="edit_offer_image">
                                    <img id="existing_offer_image" src="" alt="Offer Image" class="img-thumbnail mt-2" style="max-height: 100px; display: none;">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <input class="btn btn-primary" type="submit" value="{{ __('Update') }}">
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div> @endsection
    @section('script')
        <script>
            $(document).ready(function () {
                function toggleFields() {
                    var selectedType = $('#type').val();
                    var editSelectedType = $('#edit_type').val();
                    var limitTypes = [
                        'top_rated_courses',
                        'newly_added_courses',
                        'most_viewed_courses',
                        'free_courses',
                        'wishlist',
                        'searching_based',
                        'recommend_for_you',
                        'my_learning'
                    ];
                    var offerTypes = [
                        'offer'
                    ];

                    // Types that should hide both image and limit fields
                    var hideImageAndLimitTypes = [
                        'why_choose_us',
                        'become_instructor'
                    ];

                    // Show/hide limit field - only show for types that need it
                    if (limitTypes.includes(selectedType) || limitTypes.includes(editSelectedType)) {
                        $('.limit-field').removeClass('d-none');
                        $('#limit, #edit_limit').attr('required', 'required');
                        // Include in parsley validation when visible
                        $('#limit, #edit_limit').removeAttr('data-parsley-excluded');
                    } else {
                        $('.limit-field').addClass('d-none');
                        $('#limit, #edit_limit').val('').removeAttr('required');
                        // Exclude from parsley validation when hidden
                        $('#limit, #edit_limit').attr('data-parsley-excluded', 'true');
                    }

                    // Show/hide offer image field
                    if (offerTypes.includes(selectedType) || offerTypes.includes(editSelectedType)) {
                        $('.offer-image-field').removeClass('d-none');
                        // Make offer_image required when offer type is selected (only for create form, not edit)
                        if (selectedType === 'offer') {
                            $('#offer_image').attr('required', 'required');
                            $('#offer_image').removeAttr('data-parsley-excluded');
                        }
                        // For edit form, image is optional (can keep existing or update)
                        $('#edit_offer_image').removeAttr('required');
                        $('#edit_offer_image').removeAttr('data-parsley-excluded');
                        // Hide limit field when offer type is selected
                        $('.limit-field').addClass('d-none');
                        $('#limit, #edit_limit').val('').removeAttr('required');
                        // Exclude from parsley validation when hidden
                        $('#limit, #edit_limit').attr('data-parsley-excluded', 'true');
                    } else {
                        $('.offer-image-field').addClass('d-none');
                        $('.offer-image-field input').val('').removeAttr('required');
                        // Exclude from parsley validation when hidden
                        $('.offer-image-field input').attr('data-parsley-excluded', 'true');
                    }

                    // Explicitly hide image and limit for specific types
                    if (hideImageAndLimitTypes.includes(selectedType) || hideImageAndLimitTypes.includes(editSelectedType)) {
                        $('.limit-field').addClass('d-none');
                        $('#limit, #edit_limit').val('').removeAttr('required');
                        $('#limit, #edit_limit').attr('data-parsley-excluded', 'true');
                        $('.offer-image-field').addClass('d-none');
                        $('.offer-image-field input').val('').removeAttr('required');
                        $('.offer-image-field input').attr('data-parsley-excluded', 'true');
                    }

                    // Reset parsley validation after field changes to apply new exclusions
                    if ($('.create-form').length && $('.create-form').parsley) {
                        $('.create-form').parsley().reset();
                    }
                // When modal is shown, check which type is selected and display fields accordingly
                $('#featureSectionEditModal').on('shown.bs.modal', function () {
                    toggleFields();
                });
                }

                // Bind change event
                $('#type').on('change', toggleFields);
                $('#edit_type').on('change', toggleFields);

                // Trigger once on page load
                toggleFields();

                // Handle All/Trashed tab switching
                let isTrashedView = false;

                // Function to completely disable drag and drop
                function disableDragDrop() {
                    $('#table_list').addClass('disable-drag-drop');
                    $('#table_list').attr('data-reorderable-rows', 'false');

                    // Disable jQuery UI sortable if available
                    if ($.fn.sortable && $('#table_list tbody').hasClass('ui-sortable')) {
                        $('#table_list tbody').sortable('destroy');
                    }

                    // Disable tableDnD if it's being used
                    if ($.fn.tableDnD && $('#table_list tbody').data('tableDnD')) {
                        $('#table_list tbody').tableDnD({ onDragStyle: null, onDropStyle: null });
                        $('#table_list tbody').unbind('mousedown');
                    }

                    // Remove all drag-related event handlers
                    $('#table_list tbody tr').off('mousedown dragstart drag');
                    $('#table_list tbody tr').css('cursor', 'default');

                    // Prevent all drag events at table level
                    $('#table_list tbody').off('mousedown');
                    $('#table_list tbody').css('cursor', 'default');
                }

                // Function to enable drag and drop
                function enableDragDrop() {
                    $('#table_list').removeClass('disable-drag-drop');
                    $('#table_list').attr('data-reorderable-rows', 'true');
                }

                $('.table-list-type').on('click', function(e){
                    e.preventDefault();
                    $('.table-list-type').removeClass('active');
                    $(this).addClass('active');

                    isTrashedView = $(this).data('id') === 1;

                    // Hide/Show Status column based on tab
                    if (isTrashedView) {
                        $('#table_list').bootstrapTable('hideColumn', 'is_active');
                        $('#table_list').bootstrapTable('hideColumn', 'row_order');
                        // Hide update rank button and note immediately
                        $('#rank-note').css('display', 'none').addClass('hidden');
                        $('#change-order-feature-section').css('display', 'none');
                        // Completely disable drag and drop
                        disableDragDrop();
                    } else {
                        $('#table_list').bootstrapTable('showColumn', 'is_active');
                        $('#table_list').bootstrapTable('showColumn', 'row_order');
                        // Show update rank button and note
                        $('#rank-note').css('display', 'block').removeClass('hidden');
                        $('#change-order-feature-section').css('display', 'block');
                        // Enable drag and drop
                        enableDragDrop();
                    }

                    // Refresh table
                    $('#table_list').bootstrapTable('refresh');
                });

                // After table refresh, disable drag and drop if in trashed view
                $('#table_list').on('refresh.bs.table', function() {
                    if (isTrashedView) {
                        $('#rank-note').css('display', 'none').addClass('hidden');
                        disableDragDrop();
                    } else {
                        $('#rank-note').css('display', 'block').removeClass('hidden');
                        enableDragDrop();
                    }
                });

                // After table body is loaded, disable drag and drop if in trashed view
                $('#table_list').on('post-body.bs.table', function() {
                    if (isTrashedView) {
                        $('#rank-note').css('display', 'none').addClass('hidden');
                        disableDragDrop();
                    }
                });

                // Check on page load if trashed tab is active
                setTimeout(function() {
                    // Check if URL has show_deleted parameter or if trashed tab is active by default
                    const urlParams = new URLSearchParams(window.location.search);
                    const showDeleted = urlParams.get('show_deleted');
                    const trashedTabActive = $('.table-list-type[data-id="1"]').hasClass('active');

                    if (showDeleted == 1 || showDeleted === '1' || trashedTabActive) {
                        isTrashedView = true;
                        $('#rank-note').css('display', 'none').addClass('hidden');
                        $('#change-order-feature-section').css('display', 'none');
                    }
                }, 100);

                // Define queryParams function to handle show_deleted parameter
                window.queryParams = function(params) {
                    params.show_deleted = isTrashedView ? 1 : 0;
                    return params;
                };

                // Completely prevent drag and drop when in trashed view
                $(document).on('mousedown', '#table_list.disable-drag-drop tbody tr, #table_list.disable-drag-drop tbody tr td', function(e) {
                    if (isTrashedView) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        return false;
                    }
                });

                $(document).on('dragstart drag dragend', '#table_list.disable-drag-drop tbody tr, #table_list.disable-drag-drop tbody', function(e) {
                    if (isTrashedView) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        return false;
                    }
                });

                // Prevent selection and context menu
                $(document).on('selectstart contextmenu', '#table_list.disable-drag-drop tbody tr', function(e) {
                    if (isTrashedView) {
                        e.preventDefault();
                        return false;
                    }
                });

                // Prevent touch events for mobile
                $(document).on('touchstart touchmove', '#table_list.disable-drag-drop tbody tr', function(e) {
                    if (isTrashedView) {
                        e.preventDefault();
                        return false;
                    }
                });

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

            });
        </script>
    @endsection
