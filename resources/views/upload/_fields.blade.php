{{-- Dateiauswahl, Nachricht und Hinweise – gemeinsam für Link und Konto --}}
<div class="mb-3">
    <label for="files" class="form-label">{{ __('Dateien') }}</label>
    <input id="files" name="files[]" type="file" class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror"
           multiple required accept="{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}">
    @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
    @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
    <div class="form-text">
        {{ __('Bis :mb MB je Datei. Erlaubt: PDF, Bilder, Office-Dokumente, E-Mails, ZIP.', ['mb' => $maxMb]) }}
    </div>
</div>

<div class="mb-3">
    <label for="message" class="form-label">{{ __('Nachricht (optional)') }}</label>
    <textarea id="message" name="message" rows="2" class="form-control" maxlength="2000">{{ old('message') }}</textarea>
</div>

<div class="alert alert-secondary small">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    {{ __('Fristgebundene Erklärungen gelten erst mit Kenntnisnahme in der Kanzlei als zugegangen. Bei Eiligem rufen Sie uns bitte zusätzlich an.') }}
</div>

<button type="submit" class="btn btn-primary btn-lg w-100" data-upload-button>
    <span class="spinner-border spinner-border-sm d-none" data-upload-spinner aria-hidden="true"></span>
    <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i> {{ __('Hochladen') }}
</button>
<div class="form-text text-center d-none" data-upload-wait>{{ __('Die Dateien werden übertragen. Bitte schließen Sie die Seite nicht.') }}</div>
