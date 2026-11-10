@extends('layouts.portal')

@section('icon', 'bi-key')

@section('content')
    <p>{{ __('portal.password_intro', ['sender' => $sender]) }}</p>

    <form method="POST" action="{{ route('download.unlock', ['token' => $token]) }}">
        @csrf
        <div class="mb-3">
            <label for="password" class="form-label">{{ __('portal.password') }}</label>
            <input id="password" name="password" type="password" class="form-control form-control-lg portal-password"
                   autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus
                   aria-describedby="password-hint">
            <div id="password-hint" class="form-text">{{ __('portal.password_hint') }}</div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-100">{{ __('portal.unlock') }}</button>
    </form>
@endsection
