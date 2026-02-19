@extends('layouts.app')

@section('title')
    {{ __('Manage Faqs') }}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto"></div> @endsection

@section('main')
    <div class="content-wrapper">
        <!-- Create Form -->
        @can('faqs-create')
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title mb-4">
                            {{ __('Create Faq') }}
                        </h4>

                        {{-- Form start --}}
                        <form class="pt-3 mt-6 create-form" method="POST" action="{{ route('faqs.store') }}"
                            data-success-function="formSuccessFunction" data-parsley-validate> @csrf <div class="row">
                                {{-- Question --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="question" class="form-label">{{ __('Question') }}</label>
                                    <input type="text" name="question" id="question" placeholder="{{ __('Question') }}" class="form-control" data-parsley-required="true">
                                </div>
                                {{-- Answer --}}
                                <div class="form-group mandatory col-sm-12 col-md-6">
                                    <label for="answer" class="form-label">{{ __('Answer') }}</label>
                                    <textarea name="answer" id="answer" class="form-control" placeholder="{{ __('Answer') }}" rows="4"
                                        data-parsley-required="true"></textarea>
                                </div>
                                {{-- Is Active --}}
                                <div class="form-group col-sm-12">
                                    <div class="control-label">{{ __('Is Active') }}</div>
                                    <div class="custom-switches-stacked mt-2">
                                        <label class="custom-switch">
                                            <input type="checkbox" name="is_active" value="1"
                                                class="custom-switch-input">
                                            <span class="custom-switch-indicator"></span>
                                            <span class="custom-switch-description">{{ __('Yes') }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <input class="btn btn-primary float-right ml-3" id="create-btn" type="submit" value="{{ __('submit') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endcan

        <!-- Table List -->
        <div class="row mt-4">
            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('List FAQs') }}</h4>

                        {{-- Show Trash Button --}}
                        <div class="col-12 mt-4 text-right">
                            <b><a href="#" class="table-list-type active mr-2" data-id="0">{{ __('all') }}</a></b> {{ __('|') }} <a href="#" class="ml-2 table-list-type" data-id="1">{{ __('Trashed') }}</a>
                        </div>
                        <table aria-describedby="mydesc" class="table" id="table_list" data-table="faqs" data-toggle="table" data-url="{{ route('faqs.show', 0) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100]" data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true" data-trim-on-search="false" data-mobile-responsive="true" data-maintain-selected="true"
                            data-export-data-type="all"data-export-options='{ "fileName": "{{ __('faqs') }}-<?=
    date('d-m-y')
?>
                            ","ignoreColumn":["operate", "is_active"]}' data-show-export="true" data-query-params="faqQueryParams" data-status-column="is_active" data-table="faqs">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-escape="true">
                                        {{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-escape="true">{{ __('no.') }}</th>
                                    <th scope="col" data-field="question" data-sortable="true" data-escape="true">{{ __('Question') }}</th>
                                    <th scope="col" data-field="answer" data-sortable="false" data-formatter="answerFormatter" data-escape="false">{{ __('Answer') }}</th>
                                    <th scope="col" data-field="is_active" data-formatter="statusFormatter" data-export="false" data-escape="false" id="is-active-column">{{ __('Is Active') }}</th>
                                    <th scope="col" data-field="is_active_export" data-visible="true" data-export="true" class="d-none">{{ __('Is Active (Export)') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-formatter="actionColumnFormatter" data-events="faqEvents" data-escape="false">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Edit FAQ Modal -->
    <div class="modal fade" id="edit-faq-modal" tabindex="-1" aria-labelledby="editFaqModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="edit-form" method="POST" class="edit-form" data-parsley-validate> @csrf
            @method('PUT')
        <input type="hidden" name="faq_id" id="faq-id">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editFaqModalLabel">{{ __('Edit FAQ') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                        <span aria-hidden="true" style="font-size: 1.5rem; font-weight: 700; line-height: 1; color: #000; text-shadow: 0 1px 0 #fff;">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="faq-question">{{ __('Question') }}</label>
                        <input type="text" class="form-control" id="faq-question" name="question"
                            required data-parsley-required-message="{{ __('Question is required') }}">
                    </div>

                    <div class="form-group mt-3">
                        <label for="faq-answer">{{ __('Answer') }}</label>
                        <textarea class="form-control" id="faq-answer" name="answer" rows="4"
                            required data-parsley-required-message="{{ __('Answer is required') }}"></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Update') }}</button>
                </div>
            </div>
        </form>
    </div>
</div> @endsection

@section('style')
    <style>
        #table_list th[data-field="is_active_export"],
        #table_list td[data-field="is_active_export"] {
            display: none;
        }
    </style>
@endsection

@section('script')
    <script>
        function formSuccessFunction(response) {
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }

        // Formatter for Answer column with "View More" functionality
        function answerFormatter(value, row, index) {
            if (!value) return '';

            const maxLength = 150; // Maximum characters to show before truncating
            const answerId = 'answer-' + row.id;

            // If answer is shorter than maxLength, show it fully
            if (value.length <= maxLength) {
                return '<div class="faq-answer-text">' + escapeHtml(value) + '</div>';
            }

            // Truncate and add "View More" functionality
            const truncatedText = escapeHtml(value.substring(0, maxLength));
            const fullText = escapeHtml(value);

            return `
                <div class="faq-answer-container">
                    <div class="faq-answer-text" id="${answerId}-short">
                        ${truncatedText}...
                        <a href="javascript:void(0)" class="view-more-link" onclick="toggleAnswer('${answerId}')" data-id="${answerId}">
                            <span class="view-more-text">View More</span>
                        </a>
                    </div>
                    <div class="faq-answer-text" id="${answerId}-full" style="display: none;">
                        ${fullText}
                        <a href="javascript:void(0)" class="view-less-link" onclick="toggleAnswer('${answerId}')" data-id="${answerId}">
                            <span class="view-less-text">View Less</span>
                        </a>
                    </div>
                </div>
            `;
        }

        // Toggle between truncated and full answer
        function toggleAnswer(answerId) {
            const shortDiv = document.getElementById(answerId + '-short');
            const fullDiv = document.getElementById(answerId + '-full');

            if (shortDiv && fullDiv) {
                if (shortDiv.style.display === 'none') {
                    shortDiv.style.display = 'block';
                    fullDiv.style.display = 'none';
                } else {
                    shortDiv.style.display = 'none';
                    fullDiv.style.display = 'block';
                }
            }
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Hide is_active column when viewing trashed items
        $(document).ready(function() {
            // Listen for table refresh/load events
            $('#table_list').on('load-success.bs.table', function() {
                toggleIsActiveColumn();
            });

            // Listen for tab changes (All/Trashed)
            $('.table-list-type').on('click', function() {
                setTimeout(function() {
                    toggleIsActiveColumn();
                }, 100);
            });
        });

        function toggleIsActiveColumn() {
            const isTrashed = $('.table-list-type.active').data('id') == 1;
            const $table = $('#table_list');

            if (isTrashed) {
                // Hide is_active column when viewing trashed items
                $table.bootstrapTable('hideColumn', 'is_active');
            } else {
                // Show is_active column when viewing all items
                $table.bootstrapTable('showColumn', 'is_active');
            }
        }

        // Hide Action column if no rows have any actions (all operate fields are empty)
        $(document).ready(function() {
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
