@if (session('portal_receipt'))
    <div class="alert alert-success" role="status">
        <div class="fw-semibold mb-1"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
            {{ __('Eingegangen am :time Uhr', ['time' => session('portal_receipt_time')]) }}</div>
        <ul class="small mb-1 ps-3">
            @foreach (session('portal_receipt') as $item)
                <li class="text-break">
                    {{ $item['name'] }} ({{ \Illuminate\Support\Number::fileSize($item['size'], precision: 1) }})
                    <div class="font-monospace text-body-secondary portal-hash">SHA-256 {{ $item['sha256'] }}</div>
                </li>
            @endforeach
        </ul>
        <div class="small">{{ __('Eine Bestätigung mit diesen Angaben erhalten Sie zusätzlich per E-Mail.') }}</div>
    </div>
@endif
