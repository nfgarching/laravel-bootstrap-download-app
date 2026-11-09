@extends('layouts.portal')

@section('content')
    <p>{{ __('portal.password_intro', ['sender' => $sender]) }}</p>

    <form method="POST" action="{{ route('download.unlock', ['token' => $token]) }}" class="portal-form">
        @csrf
        <label for="password">{{ __('portal.password') }}</label>
        <input id="password" name="password" type="password" autocomplete="off" autocapitalize="characters" required autofocus>
        <p class="portal-hint">{{ __('portal.password_hint') }}</p>
        <button type="submit">{{ __('portal.unlock') }}</button>
    </form>
@endsection
