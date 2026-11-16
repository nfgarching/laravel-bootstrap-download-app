<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Anmelden und Abmelden eines Kontos (Sitzung ohne "Angemeldet bleiben").
 */
class AccountLogin
{
    public function login(Request $request, Account $account, string $method): void
    {
        Auth::login($account);
        $request->session()->regenerate();
        $request->session()->put('portal.login_method', $method);

        $account->forceFill([
            'last_login_at' => now(),
            'inactivity_notice_at' => null,
        ])->save();
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
