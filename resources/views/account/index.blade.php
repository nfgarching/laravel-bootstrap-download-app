@extends('layouts.portal')

@section('icon', 'bi-inbox')
@section('heading', __('Ihre Dokumente'))
@section('nav')
    @include('account._nav')
@endsection

@section('content')
    <p class="small text-body-secondary">{{ __('Angemeldet als :email', ['email' => $account->email]) }}</p>

    @forelse ($recipients as $recipient)
        @php($shipment = $recipient->shipment)
        <a href="{{ route('account.shipment', ['recipient' => $recipient->uuid]) }}"
           class="d-flex align-items-center gap-3 py-3 border-top text-decoration-none text-reset">
            <i class="bi bi-folder2-open portal-file-icon" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold portal-file-name">{{ $shipment->subject }}</div>
                <div class="small text-body-secondary">
                    {{ trans_choice('{1} 1 Datei|[2,*] :count Dateien', $shipment->files->count(), ['count' => $shipment->files->count()]) }}
                    · {{ __('bis :date', ['date' => $shipment->expires_at->timezone(config('portal.display_timezone'))->format('d.m.Y')]) }}
                    @if ($recipient->first_opened_at === null)
                        · <span class="badge text-bg-primary">{{ __('neu') }}</span>
                    @endif
                </div>
            </div>
            <i class="bi bi-chevron-right text-body-secondary" aria-hidden="true"></i>
        </a>
    @empty
        <p class="mb-0">{{ __('Derzeit liegen keine Dokumente für Sie bereit.') }}</p>
    @endforelse
@endsection
