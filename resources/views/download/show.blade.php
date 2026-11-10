@extends('layouts.portal')

@section('title', $shipment->subject)
@section('heading', $shipment->subject)
@section('icon', 'bi-file-earmark-arrow-down')

@section('content')
    <dl class="row mb-3">
        <dt class="col-sm-4 text-body-secondary fw-normal">{{ __('portal.from') }}</dt>
        <dd class="col-sm-8">{{ $sender }}</dd>
        <dt class="col-sm-4 text-body-secondary fw-normal">{{ __('portal.expires') }}</dt>
        <dd class="col-sm-8 mb-0">{{ $shipment->expires_at->timezone(config('portal.display_timezone', 'Europe/Berlin'))->format('d.m.Y H:i') }} Uhr</dd>
    </dl>

    @if ($shipment->message)
        <div class="alert alert-secondary portal-message">{{ $shipment->message }}</div>
    @endif

    <h2 class="h6 text-body-secondary text-uppercase mt-4 mb-2">{{ __('portal.files') }}</h2>

    @forelse ($files as $file)
        <form method="POST" action="{{ route('download.file', ['token' => $token, 'file' => $file['id']]) }}"
              class="d-flex align-items-center gap-3 py-3 border-top">
            @csrf
            <i class="bi bi-file-earmark-text portal-file-icon" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold portal-file-name">{{ $file['name'] }}</div>
                <div class="small text-body-secondary">
                    {{ \Illuminate\Support\Number::fileSize($file['size'], precision: 1) }}
                    · {{ trans_choice('portal.remaining', $file['remaining'], ['count' => $file['remaining']]) }}
                </div>
            </div>
            <button type="submit" class="btn btn-outline-primary" @disabled($file['remaining'] === 0)
                    aria-label="{{ __('portal.download') }}: {{ $file['name'] }}">
                <i class="bi bi-download" aria-hidden="true"></i>
                <span class="d-none d-sm-inline ms-1">{{ __('portal.download') }}</span>
            </button>
        </form>
    @empty
        <p class="mb-0">{{ __('portal.no_files') }}</p>
    @endforelse

    @if (count($files) > 1)
        <form method="POST" action="{{ route('download.zip', ['token' => $token]) }}" class="border-top pt-3">
            @csrf
            <button type="submit" class="btn btn-primary w-100" @disabled($files->contains(fn ($file) => $file['remaining'] === 0))>
                <i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i> {{ __('portal.download_all') }}
            </button>
            <div class="form-text text-center">{{ __('portal.download_all_hint') }}</div>
        </form>
    @endif

    @if (count($files) > 0)
        <p class="small text-body-secondary mt-3 mb-0">{{ __('portal.download_hint') }}</p>
    @endif
@endsection
