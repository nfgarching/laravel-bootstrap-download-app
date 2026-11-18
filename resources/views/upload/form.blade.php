@extends('layouts.portal')

@section('title', $uploadRequest->subject)
@section('heading', $uploadRequest->subject)
@section('icon', 'bi-cloud-arrow-up')
@if (auth()->check())
    @section('nav')
        @include('account._nav')
    @endsection
@endif

@section('content')
    @include('upload._receipt')

    <p>{{ __(':sender bittet Sie, Unterlagen hochzuladen.', ['sender' => $sender]) }}</p>

    @if ($uploadRequest->message)
        <div class="alert alert-secondary portal-message">{{ $uploadRequest->message }}</div>
    @endif

    <p class="small text-body-secondary">
        {{ __('Möglich bis :date', ['date' => $uploadRequest->expires_at->timezone(config('portal.display_timezone'))->format('d.m.Y H:i')]) }} Uhr
        · {{ trans_choice('{0} keine weiteren Dateien möglich|{1} noch 1 Datei möglich|[2,*] noch :count Dateien möglich', $remaining, ['count' => $remaining]) }}
    </p>

    @if ($received->isNotEmpty())
        <h2 class="h6 text-body-secondary text-uppercase mt-3 mb-2">{{ __('Bereits eingegangen') }}</h2>
        <ul class="list-unstyled small mb-3">
            @foreach ($received as $item)
                <li class="text-break"><i class="bi bi-check2 text-success" aria-hidden="true"></i> {{ $item->name }}
                    <span class="text-body-secondary">· {{ $item->created_at->timezone(config('portal.display_timezone'))->format('d.m.Y H:i') }}</span></li>
            @endforeach
        </ul>
    @endif

    @if ($remaining > 0)
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" data-upload-form>
            @csrf
            @include('upload._fields')
        </form>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/upload.js') }}"></script>
@endpush
