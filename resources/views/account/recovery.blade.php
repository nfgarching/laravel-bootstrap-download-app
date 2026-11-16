@extends('layouts.portal')

@section('icon', 'bi-life-preserver')
@section('heading', __('Mit Wiederherstellungscode anmelden'))

@section('content')
    <form method="POST" action="{{ route('account.recovery.login') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">{{ __('E-Mail-Adresse') }}</label>
            <input id="email" name="email" type="email" class="form-control" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <div class="mb-3">
            <label for="code" class="form-label">{{ __('Wiederherstellungscode') }}</label>
            <input id="code" name="code" type="text" maxlength="20" class="form-control portal-password"
                   autocomplete="off" placeholder="XXXXX-XXXXX" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">{{ __('Anmelden') }}</button>
    </form>

    <p class="small text-body-secondary mt-4 mb-0">
        {{ __('Auch keine Codes mehr? Bitte rufen Sie uns an. Nach einer Prüfung Ihrer Identität setzen wir den Zugang zurück; danach können Sie ihn über den nächsten Link neu einrichten.') }}
    </p>
@endsection
