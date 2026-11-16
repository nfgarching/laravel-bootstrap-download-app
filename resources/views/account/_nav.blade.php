<ul class="nav nav-underline small">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('account.index', 'account.shipment') ? 'active' : '' }}" href="{{ route('account.index') }}">
            <i class="bi bi-inbox" aria-hidden="true"></i> {{ __('Dokumente') }}
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('account.security') ? 'active' : '' }}" href="{{ route('account.security') }}">
            <i class="bi bi-shield-lock" aria-hidden="true"></i> {{ __('Sicherheit') }}
        </a>
    </li>
    <li class="nav-item ms-auto">
        <form method="POST" action="{{ route('account.logout') }}">
            @csrf
            <button type="submit" class="nav-link"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> {{ __('Abmelden') }}</button>
        </form>
    </li>
</ul>
