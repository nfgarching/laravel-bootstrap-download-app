<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-Mails des Portals an Empfänger. Bewusst reiner Text und ohne Links auf
 * Dokumente: Wer die E-Mail mitliest, erfährt nur, dass etwas bereitliegt.
 */
class AccountMailer
{
    public function code(string $email, string $code): void
    {
        $minutes = (int) config('portal.accounts.code_ttl_minutes', 15);

        $this->send($email, 'Ihr Bestätigungscode: '.$code, <<<TEXT
            Guten Tag,

            Ihr Bestätigungscode für den sicheren Zugang zum Dokumentenportal lautet:

                {$code}

            Der Code gilt {$minutes} Minuten. Falls Sie keinen Zugang einrichten wollten,
            können Sie diese E-Mail ignorieren.
            TEXT);
    }

    public function newShipment(Account $account): void
    {
        $url = route('account.login');

        $this->send($account->email, 'Neues Dokument im Dokumentenportal', <<<TEXT
            Guten Tag,

            im Dokumentenportal liegen neue Unterlagen für Sie bereit.

            Bitte melden Sie sich an, um sie abzurufen:
            {$url}

            Den Betreff der Sendung finden Sie nach der Anmeldung.
            TEXT);
    }

    public function securityNotice(Account $account, string $what): void
    {
        $time = now()->timezone(config('portal.display_timezone', 'Europe/Berlin'))->format('d.m.Y H:i');

        $this->send($account->email, 'Sicherheitshinweis zu Ihrem Zugang', <<<TEXT
            Guten Tag,

            an Ihrem Zugang zum Dokumentenportal wurde am {$time} Uhr Folgendes geändert:

                {$what}

            Waren Sie das nicht, wenden Sie sich bitte umgehend an uns.
            TEXT);
    }

    public function inactivityNotice(Account $account, int $days): void
    {
        $url = route('account.login');

        $this->send($account->email, 'Ihr Zugang zum Dokumentenportal wird gelöscht', <<<TEXT
            Guten Tag,

            Sie haben sich länger nicht im Dokumentenportal angemeldet. Ihr Zugang
            wird deshalb in {$days} Tagen gelöscht.

            Möchten Sie ihn behalten, melden Sie sich bitte einmal an:
            {$url}
            TEXT);
    }

    private function send(string $email, string $subject, string $text): void
    {
        $sender = (string) config('portal.sender_name');

        try {
            Mail::raw($text."\n\n-- \n".$sender."\n", function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
