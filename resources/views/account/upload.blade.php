@extends('layouts.portal')

@section('icon', 'bi-cloud-arrow-up')
@section('heading', __('Unterlagen hochladen'))
@section('nav')
    @include('account._nav')
@endsection

@section('content')
    @include('upload._receipt')

    @if (! $available)
        <p class="mb-0">{{ __('Das Hochladen ist derzeit nicht möglich. Bitte wenden Sie sich an uns.') }}</p>
    @else
        <p>{{ __('Hier können Sie uns Unterlagen schicken. Bitte geben Sie an, worum es geht – am besten mit unserem Aktenzeichen.') }}</p>

        <form method="POST" action="{{ route('account.upload.store') }}" enctype="multipart/form-data" data-upload-form>
            @csrf
            <div class="mb-3">
                <label for="subject" class="form-label">{{ __('Betreff') }}</label>
                <input id="subject" name="subject" type="text" class="form-control @error('subject') is-invalid @enderror"
                       value="{{ old('subject') }}" maxlength="200" required>
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @include('upload._fields')
        </form>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/upload.js') }}"></script>
@endpush
