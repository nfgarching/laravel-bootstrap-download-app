<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#115e59">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('portal.title')) – {{ config('portal.sender_name') }}</title>
    <script src="{{ asset('js/theme.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
</head>
<body>
    <main class="portal-wrap">
        <div class="portal-logo" aria-hidden="true"><i class="bi @yield('icon', 'bi-shield-lock')"></i></div>
        <p class="text-center text-body-secondary mb-1">{{ config('portal.sender_name') }}</p>
        <h1 class="h4 text-center mb-4">@yield('heading', __('portal.title'))</h1>

        @if (session('portal_notice'))
            <div class="alert alert-success d-flex gap-2" role="status">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <div>{{ session('portal_notice') }}</div>
            </div>
        @endif

        @if (session('portal_error'))
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>{{ session('portal_error') }}</div>
            </div>
        @endif

        <div class="card shadow-sm">
            @hasSection('nav')
                <div class="card-header bg-transparent border-0 pt-3 px-4">@yield('nav')</div>
            @endif
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>

        <footer class="text-center text-body-secondary small mt-4">
            <p class="mb-2"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('portal.footer') }}</p>
            @if (config('portal.accounts.enabled'))
                <p class="mb-2">
                    @auth
                        <a href="{{ route('account.index') }}">{{ __('Mein Zugang') }}</a>
                    @else
                        <a href="{{ route('account.login') }}">{{ __('Anmelden') }}</a>
                    @endauth
                </p>
            @endif
            @if (config('portal.imprint_url') || config('portal.privacy_url'))
                <p class="mb-0">
                    @if (config('portal.imprint_url'))
                        <a href="{{ config('portal.imprint_url') }}" rel="noopener noreferrer">{{ __('portal.imprint') }}</a>
                    @endif
                    @if (config('portal.imprint_url') && config('portal.privacy_url'))
                        ·
                    @endif
                    @if (config('portal.privacy_url'))
                        <a href="{{ config('portal.privacy_url') }}" rel="noopener noreferrer">{{ __('portal.privacy') }}</a>
                    @endif
                </p>
            @endif
        </footer>
    </main>
    @stack('scripts')
</body>
</html>
