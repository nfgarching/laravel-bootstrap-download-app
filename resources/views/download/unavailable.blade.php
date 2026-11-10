@extends('layouts.portal')

@section('title', __('portal.unavailable_title'))
@section('heading', __('portal.unavailable_title'))
@section('icon', 'bi-link-45deg')

@section('content')
    <p class="mb-0">{{ __('portal.unavailable_text') }}</p>
@endsection
