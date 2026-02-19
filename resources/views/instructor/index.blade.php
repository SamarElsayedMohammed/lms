@extends('layouts.app')

@section('title')
    {{ __('Manage Supervisors') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto"></div> @endsection

@section('main')
    <div class="content-wrapper">
        @if(isset($isSingleInstructorMode) && $isSingleInstructorMode)
            <!-- Single Instructor Mode Message -->
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card">
                    <div class="card">
                        <div class="card-body">
                            <div class="alert alert-info text-center">
                                <h4 class="alert-heading">{{ __('Supervisor List Disabled') }}</h4>
                                <p class="mb-0">{{ __('Supervisor list is disabled in single supervisor mode.') }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- Table List -->
            <div class="row">
                <div class="col-md-12 grid-margin stretch-card search-container">
                    <div class="card">
                        <div class="card-body">
                            {{-- Title --}}
                            <h4 class="card-title">
                                {{ __('List Supervisors') }}
                            </h4>

                            <div id="toolbar"></div>

                            <table aria-describedby="mydesc" class="table" id="table_list" data-table="instructors" data-toggle="table" data-url="{{ route('instructor.show', 0) }}" data-click-to-select="true" data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-use-row-attr-func="true" data-maintain-selected="true" data-export-data-type="all" data-export-options='{ "fileName": "{{ __('Supervisors') }}-<?=
    date('d-m-y')
?>","ignoreColumn":["operate"]}' data-show-export="true" data-query-params="instructorQueryParams" data-status-column="is_active">
                                <thead>
                                    <tr>
                                        <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true"> {{ __('id') }}</th>
                                        <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                        <th scope="col" data-field="user.name" data-sortable="false" data-formatter="capitalizeNameFormatter" data-escape="false">{{ __('Name') }}</th>
                                        <th scope="col" data-field="type" data-sortable="true"  data-escape="false">{{ __('Type') }}</th>
                                        <th scope="col" data-field="status" data-sortable="true" data-escape="false">{{ __('Status') }}</th>
                                        <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="instructorEvents" data-escape="false">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif


        <!-- Edit Modal -->
        <div class="modal fade" id="instructorEditModal" tabindex="-1" role="dialog"
            aria-labelledby="instructorEditModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content rounded shadow-lg">
                    <div class="modal-header">
                        <h5 class="modal-title" id="instructorEditModalLabel">{{ __('Change Status')}}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                            <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                        </button>
                    </div>
                    <form class="pt-3 mt-6 edit-form" method="POST" data-parsley-validate id="instructorEditForm">
                         @method('PUT')
                          <div class="modal-body">
                            <input type="hidden" name="edit_id" id="edit_instructor_id">

                            <div class="form-group mandatory col-sm-12 col-md-6">
                                <label class="form-label d-block" for="status">{{ __('Status') }} </label>
                                {{-- Approved --}}
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="status-approved" name="status" value="approved" class="custom-control-input" required data-parsley-required="true">
                                    <label class="custom-control-label" for="status-approved">{{ __('Approve') }}</label>
                                </div>
                                {{-- Rejected --}}
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="status-rejected" name="status" value="rejected" class="custom-control-input" data-parsley-required="true">
                                    <label class="custom-control-label" for="status-rejected">{{ __('Reject') }}</label>
                                </div>
                                {{-- Suspended --}}
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input type="radio" id="status-suspended" name="status" value="suspended" class="custom-control-input" data-parsley-required="true">
                                    <label class="custom-control-label" for="status-suspended">{{ __('Suspend') }}</label>
                                </div>
                            </div>
                            {{-- Reason --}}
                            <div class="form-group mandatory" id="reason-field" style="display: none;">
                                <label class="form-label" for="reason">{{ __('Reason') }}</label>
                                <textarea name="reason" id="reason" class="form-control" rows="3" placeholder="{{ __('Reason') }}"></textarea>
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
        // Define instructorQueryParams function for pagination
        function instructorQueryParams(params) {
            return {
                offset: params.offset,
                limit: params.limit,
                sort: params.sort,
                order: params.order,
                search: params.search,
            };
        }

        $(document).ready(function() {
            const $priceFields = $('.price-field');
            const $discountPriceFields = $('.discount-price-field');
            const $priceInput = $('#price');
            const $discountPriceInput = $('#discount-price');
            const $form = $('.create-form');

            function togglePriceFields() {
                if ($('#course-type-free').is(':checked')) {
                    $priceFields.hide();
                    $priceInput.removeAttr('required');
                    $discountPriceFields.hide();
                } else if ($('#course-type-paid').is(':checked')) {
                    $priceFields.show().addClass('mandatory');
                    $priceInput.attr('required', true);
                    $discountPriceFields.show();
                }
            }
            // Initial state
            togglePriceFields();
            // On change
            $('input[name="course_type"]').change(togglePriceFields);

            // Toggle Reason Field
            $('input[name="status"]').change(function() {
                if ($('#status-rejected').is(':checked') || $('#status-suspended').is(':checked')) {
                    $('#reason-field').show();
                    $('#reason').attr('required', true);
                } else {
                    $('#reason-field').hide();
                    $('#reason').removeAttr('required');
                }
            });

            // Reset modal when closed
            $('#instructorEditModal').on('hidden.bs.modal', function() {
                $('#instructorEditForm')[0].reset();
                $('#edit_instructor_id').val('');
                $('#reason-field').hide();
                $('#reason').removeAttr('required');
                $('input[name="status"]').prop('checked', false);
            });

        });

        // Hide Action column if no rows have any actions
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
