@extends('layouts.portal')

@section('icon', 'bi-envelope-check')
@section('heading', __('Adresse bestätigen'))

@section('content')
    <p>{{ __('Bitte geben Sie den sechsstelligen Code ein, den wir an :email geschickt haben.', ['email' => $email]) }}</p>

    <form method="POST" action="{{ route('account.setup.code') }}">
        @csrf
        <div class="mb-3">
            <label for="code" class="form-label">{{ __('Code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                   class="form-control form-control-lg portal-password" autocomplete="one-time-code" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">{{ __('Bestätigen') }}</button>
    </form>

    <form method="POST" action="{{ route('account.setup.resend') }}" class="mt-3 text-center">
        @csrf
        <button type="submit" class="btn btn-link btn-sm">{{ __('Neuen Code anfordern') }}</button>
    </form>
@endsection
