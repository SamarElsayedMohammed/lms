@php
use Illuminate\Support\Facades\Storage;
@endphp

@extends('layouts.app')
@section('title')
    {{__("Change Sub Categories Order")}}
@endsection

@section('page-title')
    <h1 class="mb-0">@yield('title')</h1>
    <div class="section-header-button ml-auto">
        <a class="btn btn-primary" href="{{ route('categories.index') }}">← {{__("Back to All Categories")}}</a>
    </div> @endsection

@section('main')
    <section class="section">
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        @can('categories-reorder')
                        <form class="pt-3" id="update-team-member-rank-form" action="{{ route('categories.order.update') }}" novalidate="novalidate">
                            <ul class="sortable list-unstyled row col-12 d-flex justify-content-center">
                                <div class="row bg-light pt-2 rounded mb-2 col-12 d-flex justify-content-center"> @foreach( $categories as $row) <li id="{{$row->id}}" class="ui-state-default draggable col-md-12 col-lg-5  mr-2 col-xl-3" style="cursor:grab">
                                            <div class="bg-light pt-2 p-3 rounded mt-2 mb-2 col-12 d-flex justify-content-center">
                                                 <div class="row">
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
                                                        <strong> {{$row->name}} </strong>
                                                        <div>
                                                            <span style="font-size: 12px;">{{ $row->designation }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </li> @endforeach </div>
                            </ul>
                            <input class="btn btn-primary" type="submit" value="Update"/>
                        </form>
                        @else
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            {{ __('You do not have permission to reorder subcategories.') }}
                        </div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
