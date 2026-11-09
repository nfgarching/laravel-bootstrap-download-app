<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', __('portal.title')) – {{ config('portal.sender_name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
</head>
<body>
    <main class="portal">
        <header class="portal-header">
            <p class="portal-sender">{{ config('portal.sender_name') }}</p>
            <h1>@yield('heading', __('portal.title'))</h1>
        </header>

        @if (session('portal_error'))
            <p class="portal-alert" role="alert">{{ session('portal_error') }}</p>
        @endif

        @yield('content')
    </main>
</body>
</html>
