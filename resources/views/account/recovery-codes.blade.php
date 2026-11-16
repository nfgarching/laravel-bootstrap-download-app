@extends('layouts.portal')

@section('icon', 'bi-key')
@section('heading', __('Wiederherstellungscodes'))

@section('content')
    @if ($first)
        <p class="fw-semibold">{{ __('Ihr Zugang ist eingerichtet.') }}</p>
    @endif

    <p>{{ __('Mit diesen Codes können Sie sich anmelden, wenn Ihr Passkey oder Ihre Authenticator-App nicht mehr verfügbar ist. Jeder Code gilt einmal.') }}</p>

    <div class="row row-cols-2 g-2 mb-3 font-monospace text-center fs-5 user-select-all">
        @foreach ($codes as $code)
            <div class="col"><div class="border rounded py-1">{{ $code }}</div></div>
        @endforeach
    </div>

    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
        {{ __('Bitte drucken Sie die Codes aus oder notieren Sie sie und bewahren Sie sie sicher auf. Sie werden nur jetzt angezeigt.') }}
    </div>

    <a href="{{ route('account.index') }}" class="btn btn-primary w-100">{{ __('Ich habe die Codes gesichert') }}</a>
@endsection
