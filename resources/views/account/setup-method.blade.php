@extends('layouts.portal')

@section('icon', 'bi-person-lock')
@section('heading', __('Anmeldung festlegen'))

@section('content')
    <p>{{ __('Ihre Adresse :email ist bestätigt. Wie möchten Sie sich künftig anmelden?', ['email' => $email]) }}</p>

    {{-- Passkey --}}
    <div class="border rounded-3 p-3 mb-3" data-passkey-box>
        <h2 class="h6"><i class="bi bi-fingerprint me-1" aria-hidden="true"></i> {{ __('Passkey (empfohlen)') }}</h2>
        <p class="small text-body-secondary">
            {{ __('Anmeldung mit Fingerabdruck, Gesichtserkennung oder der PIN Ihres Geräts. Kein Passwort, das gestohlen werden kann.') }}
        </p>
        <div class="mb-2">
            <label for="passkey-name" class="form-label small">{{ __('Name des Geräts (optional)') }}</label>
            <input id="passkey-name" type="text" class="form-control form-control-sm" maxlength="100" placeholder="{{ __('z. B. iPhone, Laptop') }}">
        </div>
        <button type="button" class="btn btn-primary w-100"
                data-passkey="register" data-name="#passkey-name"
                data-options="{{ route('account.setup.passkey.options') }}"
                data-submit="{{ route('account.setup.passkey') }}">
            <span class="spinner-border spinner-border-sm d-none" data-passkey-spinner aria-hidden="true"></span>
            {{ __('Passkey einrichten') }}
        </button>
        <div class="alert alert-danger small mt-2 mb-0 d-none" data-passkey-error role="alert"></div>
    </div>

    {{-- Passwort + Authenticator-App --}}
    <details class="border rounded-3 p-3" @if ($errors->any()) open @endif>
        <summary class="h6 mb-0"><i class="bi bi-phone me-1" aria-hidden="true"></i> {{ __('Passwort und Authenticator-App') }}</summary>

        <form method="POST" action="{{ route('account.setup.password') }}" class="mt-3">
            @csrf
            <div class="mb-3">
                <label for="password" class="form-label">{{ __('Passwort (mindestens :min Zeichen)', ['min' => $minLength]) }}</label>
                <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror"
                       autocomplete="new-password" minlength="{{ $minLength }}" required>
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">{{ __('Passwort wiederholen') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="form-control"
                       autocomplete="new-password" required>
            </div>

            <p class="small mb-2">{{ __('Scannen Sie den QR-Code mit einer Authenticator-App (z. B. Microsoft Authenticator, Google Authenticator, 1Password) und geben Sie den angezeigten Code ein.') }}</p>
            <div class="text-center mb-2 portal-qr">{!! $qrCode !!}</div>
            <p class="small text-body-secondary text-center mb-3">
                {{ __('Schlüssel zum Abtippen:') }}<br>
                <span class="font-monospace user-select-all">{{ $totpSecret }}</span>
            </p>

            <div class="mb-3">
                <label for="code" class="form-label">{{ __('Code aus der App') }}</label>
                <input id="code" name="code" type="text" inputmode="numeric" maxlength="7"
                       class="form-control portal-password @error('code') is-invalid @enderror" autocomplete="one-time-code" required>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-outline-primary w-100">{{ __('Zugang einrichten') }}</button>
        </form>
    </details>
@endsection

@push('scripts')
    <script src="{{ asset('js/passkey.js') }}"></script>
@endpush
