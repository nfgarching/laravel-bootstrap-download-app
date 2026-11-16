@extends('layouts.portal')

@section('icon', 'bi-person-lock')
@section('heading', __('Anmeldung erforderlich'))

@section('content')
    <p>{{ __(':sender hat Dokumente für Sie bereitgestellt, die nur nach Anmeldung abgerufen werden können.', ['sender' => $sender]) }}</p>
    <a href="{{ route('account.login') }}" class="btn btn-primary btn-lg w-100">{{ __('Anmelden') }}</a>
    <p class="small text-body-secondary mt-3 mb-0">
        {{ __('Kein Zugriff mehr auf Ihren Passkey oder Ihre Authenticator-App?') }}
        <a href="{{ route('account.recovery') }}">{{ __('Mit Wiederherstellungscode anmelden') }}</a>
    </p>
@endsection
