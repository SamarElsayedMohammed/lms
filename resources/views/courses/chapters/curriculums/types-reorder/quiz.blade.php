<div class="row">
    <div class="col-md-12 grid-margin stretch-card search-container">
        <div class="card">
            <div class="card-body">
                <h4 class="card-title">
                    {{ __('Reorder Questions') }}
                </h4>
                <form id="reorder-form">
                    <ul id="sortable-questions" class="list-group"> @foreach($curriculum->questions as $question) <li class="list-group-item" data-id="{{ $question->id }}">
                                {{ $question->question }}
                            </li> @endforeach </ul>
                    <button type="button" class="btn btn-primary mt-3" id="save-reorder">{{ __('Save Order') }}</button>
                </form>
            </div>
        </div>
    </div>
</div> @push(\'scripts\') <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(function() {
    $("#sortable-questions").sortable();
    $("#sortable-questions").disableSelection();

    $('#save-reorder').on('click', function() {
        var orderedIds = [];
        $('#sortable-questions li').each(function() {
            orderedIds.push($(this).data('id'));
        });

        $.ajax({
            url: "{{ route('course-chapters.curriculum.reorder-update', ['id' => $curriculum->id, 'type' => 'questions']) }}",
            type: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                order: orderedIds
            },
            dataType: "json",
            success: function (data) {
                if (!data.error) {
                    showSuccessToast(data.message);
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showErrorToast(data.message);
                }
            }
        });
    });
});
</script>
@endpush
