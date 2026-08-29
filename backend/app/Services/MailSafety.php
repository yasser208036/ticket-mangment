<?php

namespace App\Services;

use RuntimeException;

/**
 * Development mail must not be able to leave the machine.
 *
 * Registered on Illuminate\Mail\Events\MessageSending, which the Mailer
 * dispatches through until() *before* the transport connects — so a refusal
 * throws instead of half-sending. The rule is a property of the transport,
 * never of the recipient: rewriting or filtering addresses would destroy the
 * evidence every E8 story needs to assert in Mailpit.
 */
class MailSafety
{
    /** @var list<string> */
    private const FAN_OUT_TRANSPORTS = ['failover', 'roundrobin'];

    public function guardOutgoingMail(): void
    {
        if (! $this->isGuardedEnvironment()) {
            return;
        }

        $this->assertMailerIsSafe((string) config('mail.default'));
    }

    public function isGuardedEnvironment(): bool
    {
        return in_array(
            config('app.env'),
            (array) config('mail.safety.guarded_environments', []),
            true,
        );
    }

    /**
     * @param  list<string>  $seen  mailers already visited, so a failover cycle terminates
     */
    public function assertMailerIsSafe(string $mailer, array $seen = []): void
    {
        if (in_array($mailer, $seen, true)) {
            return;
        }

        $transport = config("mail.mailers.{$mailer}.transport");

        if (! is_string($transport)) {
            throw new RuntimeException(
                "Refusing to send mail: the [{$mailer}] mailer is not configured in config/mail.php."
            );
        }

        if (in_array($transport, self::FAN_OUT_TRANSPORTS, true)) {
            foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $nested) {
                $this->assertMailerIsSafe((string) $nested, [...$seen, $mailer]);
            }

            return;
        }

        if (in_array($transport, (array) config('mail.safety.safe_transports', []), true)) {
            return;
        }

        if ($transport === 'smtp' && $this->isLocalCatcher($this->smtpHost($mailer))) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to send mail: the [%s] mailer uses the [%s] transport%s in the [%s] environment, '
            .'which could reach a real inbox. Point MAIL_MAILER at Mailpit (smtp on %s) or set MAIL_MAILER=log.',
            $mailer,
            $transport,
            $transport === 'smtp' ? " on host [{$this->smtpHost($mailer)}]" : '',
            (string) config('app.env'),
            implode(', ', (array) config('mail.safety.safe_smtp_hosts', [])),
        ));
    }

    /**
     * MAIL_URL wins over MAIL_HOST when both are set (config/mail.php:43), so
     * the DSN's host is the one that matters.
     */
    private function smtpHost(string $mailer): string
    {
        $url = config("mail.mailers.{$mailer}.url");

        if (is_string($url) && $url !== '') {
            return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        return (string) config("mail.mailers.{$mailer}.host", '');
    }

    private function isLocalCatcher(string $host): bool
    {
        $safe = array_map(
            fn (string $candidate): string => mb_strtolower(trim($candidate)),
            (array) config('mail.safety.safe_smtp_hosts', []),
        );

        return $host !== '' && in_array(mb_strtolower($host), $safe, true);
    }
}
