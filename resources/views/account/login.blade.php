@extends('layouts.portal')

@section('icon', 'bi-person-lock')
@section('heading', __('Anmelden'))

@section('content')
    <div data-passkey-box>
        <button type="button" class="btn btn-primary btn-lg w-100"
                data-passkey="login"
                data-options="{{ route('account.login.passkey.options') }}"
                data-submit="{{ route('account.login.passkey') }}">
            <span class="spinner-border spinner-border-sm d-none" data-passkey-spinner aria-hidden="true"></span>
            <i class="bi bi-fingerprint me-1" aria-hidden="true"></i> {{ __('Mit Passkey anmelden') }}
        </button>
        <div class="alert alert-danger small mt-2 mb-0 d-none" data-passkey-error role="alert"></div>
    </div>

    <details class="mt-4" @if (old('email')) open @endif>
        <summary>{{ __('Mit Passwort und Authenticator-App anmelden') }}</summary>

        <form method="POST" action="{{ route('account.login.password') }}" class="mt-3">
            @csrf
            <div class="mb-3">
                <label for="email" class="form-label">{{ __('E-Mail-Adresse') }}</label>
                <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">{{ __('Passwort') }}</label>
                <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label for="code" class="form-label">{{ __('Code aus der App') }}</label>
                <input id="code" name="code" type="text" inputmode="numeric" maxlength="12"
                       class="form-control portal-password" autocomplete="one-time-code" required>
                <div class="form-text">{{ __('Statt des Codes geht auch ein Wiederherstellungscode.') }}</div>
            </div>
            <button type="submit" class="btn btn-outline-primary w-100">{{ __('Anmelden') }}</button>
        </form>
    </details>

    <p class="small text-body-secondary mt-4 mb-0">
        {{ __('Kein Zugriff mehr auf Ihren Passkey?') }}
        <a href="{{ route('account.recovery') }}">{{ __('Mit Wiederherstellungscode anmelden') }}</a><br>
        {{ __('Noch keinen Zugang? Sie können ihn einrichten, nachdem Sie Dokumente über einen Link von uns abgerufen haben.') }}
    </p>
@endsection

@push('scripts')
    <script src="{{ asset('js/passkey.js') }}"></script>
@endpush
