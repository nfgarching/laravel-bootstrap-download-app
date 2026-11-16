@extends('layouts.portal')

@section('icon', 'bi-shield-lock')
@section('heading', __('Sicherheit'))
@section('nav')
    @include('account._nav')
@endsection

@section('content')
    {{-- Passkeys --}}
    <h2 class="h6 text-body-secondary text-uppercase mb-2">{{ __('Passkeys') }}</h2>

    @forelse ($passkeys as $passkey)
        <div class="d-flex align-items-center gap-3 py-2 border-top">
            <i class="bi bi-fingerprint portal-file-icon" aria-hidden="true"></i>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold portal-file-name">{{ $passkey->name }}</div>
                <div class="small text-body-secondary">
                    {{ __('eingerichtet :date', ['date' => $passkey->created_at->timezone(config('portal.display_timezone'))->format('d.m.Y')]) }}
                    @if ($passkey->last_used_at)
                        · {{ __('zuletzt :date', ['date' => $passkey->last_used_at->timezone(config('portal.display_timezone'))->format('d.m.Y H:i')]) }}
                    @endif
                </div>
            </div>
            <form method="POST" action="{{ route('account.passkey.destroy', ['passkey' => $passkey->id]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('Entfernen') }}: {{ $passkey->name }}">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    @empty
        <p class="small text-body-secondary">{{ __('Noch kein Passkey eingerichtet.') }}</p>
    @endforelse

    <div class="border-top pt-3" data-passkey-box>
        <div class="input-group">
            <input id="passkey-name" type="text" class="form-control" maxlength="100" placeholder="{{ __('Name des Geräts, z. B. iPhone') }}"
                   aria-label="{{ __('Name des Geräts') }}">
            <button type="button" class="btn btn-outline-primary"
                    data-passkey="register" data-name="#passkey-name"
                    data-options="{{ route('account.passkey.options') }}"
                    data-submit="{{ route('account.passkey.store') }}">
                <span class="spinner-border spinner-border-sm d-none" data-passkey-spinner aria-hidden="true"></span>
                {{ __('Passkey hinzufügen') }}
            </button>
        </div>
        <div class="alert alert-danger small mt-2 mb-0 d-none" data-passkey-error role="alert"></div>
    </div>

    @if ($account->hasPasswordLogin())
        <p class="small text-body-secondary mt-3 mb-0">
            <i class="bi bi-phone me-1" aria-hidden="true"></i> {{ __('Anmeldung mit Passwort und Authenticator-App ist eingerichtet.') }}
        </p>
    @endif

    {{-- Wiederherstellungscodes --}}
    <h2 class="h6 text-body-secondary text-uppercase mt-4 mb-2">{{ __('Wiederherstellungscodes') }}</h2>
    <p class="small">{{ trans_choice('{0} Sie haben keine unverbrauchten Codes mehr.|{1} Sie haben noch einen unverbrauchten Code.|[2,*] Sie haben noch :count unverbrauchte Codes.', $account->remainingRecoveryCodes(), ['count' => $account->remainingRecoveryCodes()]) }}</p>
    <form method="POST" action="{{ route('account.recovery-codes.regenerate') }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Neue Codes erzeugen') }}</button>
        <div class="form-text">{{ __('Die bisherigen Codes werden damit ungültig.') }}</div>
    </form>

    {{-- Benachrichtigung --}}
    <h2 class="h6 text-body-secondary text-uppercase mt-4 mb-2">{{ __('Benachrichtigung') }}</h2>
    <form method="POST" action="{{ route('account.notifications') }}" class="d-flex align-items-center gap-2">
        @csrf
        <input type="hidden" name="notify" value="{{ $account->notify_new_shipments ? 0 : 1 }}">
        <span class="small flex-grow-1">
            {{ $account->notify_new_shipments
                ? __('Sie erhalten eine E-Mail (ohne Link auf Dokumente), wenn neue Unterlagen bereitliegen.')
                : __('Sie erhalten keine E-Mail bei neuen Unterlagen.') }}
        </span>
        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $account->notify_new_shipments ? __('Abschalten') : __('Einschalten') }}</button>
    </form>

    {{-- Löschen --}}
    <details class="mt-4">
        <summary class="small text-danger">{{ __('Zugang löschen') }}</summary>
        <form method="POST" action="{{ route('account.destroy') }}" class="mt-2">
            @csrf
            <div class="form-check mb-2">
                <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="confirm-delete">
                <label class="form-check-label small" for="confirm-delete">
                    {{ __('Ich möchte meinen Zugang löschen. Dokumente erhalte ich dann wieder nur per Link.') }}
                </label>
                @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-sm btn-danger">{{ __('Zugang löschen') }}</button>
        </form>
    </details>
@endsection

@push('scripts')
    <script src="{{ asset('js/passkey.js') }}"></script>
@endpush
