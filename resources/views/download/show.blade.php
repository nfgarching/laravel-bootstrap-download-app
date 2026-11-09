@extends('layouts.portal')

@section('heading', $shipment->subject)

@section('content')
    <dl class="portal-meta">
        <dt>{{ __('portal.from') }}</dt>
        <dd>{{ $sender }}</dd>
        <dt>{{ __('portal.expires') }}</dt>
        <dd>{{ $shipment->expires_at->timezone(config('portal.display_timezone', 'Europe/Berlin'))->format('d.m.Y H:i') }} Uhr</dd>
    </dl>

    @if ($shipment->message)
        <p class="portal-message">{!! nl2br(e($shipment->message)) !!}</p>
    @endif

    <h2>{{ __('portal.files') }}</h2>

    @forelse ($files as $file)
        <form method="POST" action="{{ route('download.file', ['token' => $token, 'file' => $file['id']]) }}" class="portal-file">
            @csrf
            <div>
                <p class="portal-file-name">{{ $file['name'] }}</p>
                <p class="portal-hint">
                    {{ \Illuminate\Support\Number::fileSize($file['size'], precision: 1) }}
                    · {{ trans_choice('portal.remaining', $file['remaining'], ['count' => $file['remaining']]) }}
                </p>
            </div>
            <button type="submit" @disabled($file['remaining'] === 0)>{{ __('portal.download') }}</button>
        </form>
    @empty
        <p>{{ __('portal.no_files') }}</p>
    @endforelse
@endsection
