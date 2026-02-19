@extends('layouts.app')

@section('title', __('Pending Approvals'))

@section('page-title')
    <h1 class="mb-0">{{ __('Pending Approvals') }}</h1>
@endsection

@section('main')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <ul class="nav nav-tabs" id="approvalTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="ratings-tab" data-toggle="tab" href="#ratings" role="tab">{{ __('Pending Ratings') }}</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="comments-tab" data-toggle="tab" href="#comments" role="tab">{{ __('Pending Comments') }}</a>
                        </li>
                    </ul>
                    <div class="tab-content mt-4" id="approvalTabContent">
                        <div class="tab-pane fade show active" id="ratings" role="tabpanel">
                            <div id="ratings-list"></div>
                        </div>
                        <div class="tab-pane fade" id="comments" role="tabpanel">
                            <div id="comments-list"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function() {
    function loadRatings() {
        $.get('{{ url("/admin/reviews/pending") }}', function(data) {
            var html = data.ratings && data.ratings.length
                ? data.ratings.map(function(r) {
                    return '<div class="card mb-2"><div class="card-body d-flex justify-content-between align-items-center"><div><strong>' + (r.user ? r.user.name : '') + '</strong> - ' + r.rating + ' ' + (r.rateable ? (r.rateable.name || r.rateable_type) : '') + '<br><small>' + (r.review || '') + '</small></div><div><button class="btn btn-success btn-sm approve-rating" data-id="' + r.id + '">{{ __("Approve") }}</button> <button class="btn btn-danger btn-sm reject-rating" data-id="' + r.id + '">{{ __("Reject") }}</button></div></div></div>';
                }).join('')
                : '<p>{{ __("No pending ratings") }}</p>';
            $('#ratings-list').html(html);
        });
    }
    function loadComments() {
        $.get('{{ url("/admin/comments/pending") }}', function(data) {
            var html = data.comments && data.comments.length
                ? data.comments.map(function(c) {
                    return '<div class="card mb-2"><div class="card-body d-flex justify-content-between align-items-center"><div><strong>' + (c.user ? c.user.name : '') + '</strong> - ' + (c.course ? c.course.name : '') + '<br><small>' + (c.message || '') + '</small></div><div><button class="btn btn-success btn-sm approve-comment" data-id="' + c.id + '">{{ __("Approve") }}</button> <button class="btn btn-danger btn-sm reject-comment" data-id="' + c.id + '">{{ __("Reject") }}</button></div></div></div>';
                }).join('')
                : '<p>{{ __("No pending comments") }}</p>';
            $('#comments-list').html(html);
        });
    }
    loadRatings();
    $('#comments-tab').on('shown.bs.tab', loadComments);
    $(document).on('click', '.approve-rating', function() {
        var id = $(this).data('id');
        $.post('{{ url("/admin/reviews") }}/' + id + '/approve', { _token: '{{ csrf_token() }}' }, function() { loadRatings(); });
    });
    $(document).on('click', '.reject-rating', function() {
        var id = $(this).data('id');
        $.post('{{ url("/admin/reviews") }}/' + id + '/reject', { _token: '{{ csrf_token() }}' }, function() { loadRatings(); });
    });
    $(document).on('click', '.approve-comment', function() {
        var id = $(this).data('id');
        $.post('{{ url("/admin/comments") }}/' + id + '/approve', { _token: '{{ csrf_token() }}' }, function() { loadComments(); });
    });
    $(document).on('click', '.reject-comment', function() {
        var id = $(this).data('id');
        $.post('{{ url("/admin/comments") }}/' + id + '/reject', { _token: '{{ csrf_token() }}' }, function() { loadComments(); });
    });
});
</script>
@endpush
