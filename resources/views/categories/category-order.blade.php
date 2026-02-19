@php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
@endphp

@extends('layouts.app')
@section('title')
    {{__("Change Categories Order")}}
@endsection


@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('categories.index') }}">← {{__("Back to All Categories")}}</a>
    </div>
@endsection

@section('main')
    <section class="section">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        @can('categories-reorder')
                        <form class="pt-3" id="update-team-member-rank-form" action="{{ route('categories.order.update') }}" method="POST" novalidate="novalidate">
                            @csrf
                            <ul class="sortable list-unstyled row col-12 d-flex justify-content-center">
                                @foreach($categories as $row)
                                <li id="{{$row->id}}" class="ui-state-default draggable col-md-12 col-lg-5 mr-2 col-xl-3" style="cursor:grab; list-style: none;">
                                    <div class="bg-light pt-2 p-3 rounded mt-2 mb-2 col-12 d-flex justify-content-center">
                                        <div class="row w-100">
                                            <div class="col-6" style="padding-left: 15px; padding-right:5px;">
                                                @php
                                                    // Get raw image path (before accessor conversion)
                                                    $imagePath = $row->getRawOriginal('image');
                                                    // Use Storage::url() only if we have a path, otherwise use the accessor value
                                                    $imageUrl = $imagePath ? Storage::url($imagePath) : ($row->image ?? asset('assets/img_placeholder.jpeg'));
                                                @endphp
                                                <img src="{{ $imageUrl }}" alt="{{ $row->name }}" class="order-change" style="max-width: 100%; height: auto;"/>
                                            </div>
                                            <div class="col-6 d-flex flex-column justify-content-center align-items-center" style="padding-left: 5px; padding-right:5px;">
                                                <strong>{{ $row->name }}</strong>
                                                @if($row->description)
                                                <div>
                                                    <span style="font-size: 12px;">{{ Str::limit($row->description, 50) }}</span>
                                                </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </li>
                                @endforeach
                            </ul>
                            <div class="text-center mt-4">
                                <input class="btn btn-primary" type="submit" value="{{ __('Update') }}"/>
                            </div>
                        </form>
                        @else
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            {{ __('You do not have permission to reorder categories.') }}
                        </div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    // Ensure sortable is initialized
    let sortableElement = $(".sortable");
    if (sortableElement.length > 0 && typeof $.fn.sortable !== 'undefined') {
        sortableElement.sortable({
            revert: true,
            items: "li",
            tolerance: "pointer",
            cursor: "move",
            cursorAt: { top: 20, left: 20 },
            start: function(event, ui) {
                ui.placeholder.height(ui.item.height());
                ui.placeholder.css('visibility', 'visible');
            }
        });
        console.log("Sortable initialized successfully");
    } else {
        console.error("jQuery UI Sortable is not available");
    }
    
    // Form submit handler
    $("#update-team-member-rank-form").on("submit", function (e) {
        e.preventDefault();
        
        let sortableElement = $(".sortable");
        let userOrder = [];
        
        // Always get order from DOM to ensure correct order (more reliable than toArray)
        // This ensures we get the actual visual order after drag & drop
        sortableElement.find('li').each(function() {
            let id = $(this).attr('id');
            if (id) {
                userOrder.push(id);
            }
        });
        
        // Also try toArray for comparison/debugging
        let sortableArray = [];
        if (sortableElement.length && sortableElement.data('ui-sortable')) {
            sortableArray = sortableElement.sortable("toArray");
        }
        
        // Ensure IDs are integers (convert string IDs to integers)
        userOrder = userOrder.map(function(id) {
            return parseInt(id) || id;
        });
        sortableArray = sortableArray.map(function(id) {
            return parseInt(id) || id;
        });
        
        // Debug: Show order with sequence numbers
        console.log("Category order from DOM:", userOrder);
        console.log("Category order from sortable.toArray():", sortableArray);
        console.log("Order match:", JSON.stringify(userOrder) === JSON.stringify(sortableArray));
        console.log("Category order JSON:", JSON.stringify(userOrder));
        console.log("Order with sequence mapping:");
        userOrder.forEach(function(id, index) {
            console.log("  Position " + (index + 1) + ": Category ID " + id);
        });
        
        if (!userOrder || userOrder.length === 0) {
            showErrorToast("Unable to get category order. Please refresh the page and try again.");
            return;
        }
        
        let submitButton = $(this).find(":submit");
        let url = $(this).attr("action");
        let token = $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val();
        
        submitButton.prop('disabled', true).val('Updating...');
        
        $.ajax({
            url: url,
            type: 'POST',
            data: {
                _token: token,
                order: JSON.stringify(userOrder)
            },
            dataType: 'json',
            success: function(response) {
                console.log("Response:", response);
                if (!response.error || response.success === true) {
                    showSuccessToast(response.message || "Order Updated Successfully");
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    showErrorToast(response.message || "Failed to update order");
                    submitButton.prop('disabled', false).val('{{ __("Update") }}');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", xhr.responseJSON || error);
                let errorMessage = "Failed to update order";
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
                showErrorToast(errorMessage);
                submitButton.prop('disabled', false).val('{{ __("Update") }}');
            }
        });
    });
});
</script>
@endpush
