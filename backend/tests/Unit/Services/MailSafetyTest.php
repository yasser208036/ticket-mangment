<?php

namespace Tests\Unit\Services;

use App\Services\MailSafety;
use RuntimeException;
use Tests\TestCase;

class MailSafetyTest extends TestCase
{
    public function test_log_and_array_transports_are_safe(): void
    {
        $guard = new MailSafety;

        $guard->assertMailerIsSafe('log');
        $guard->assertMailerIsSafe('array');

        $this->assertTrue(true);
    }

    public function test_a_sending_transport_is_refused(): void
    {
        $guard = new MailSafety;

        foreach (['ses', 'postmark', 'resend', 'sendmail'] as $mailer) {
            try {
                $guard->assertMailerIsSafe($mailer);
                $this->fail("Expected [{$mailer}] to be refused.");
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_message_names_the_mailer_the_transport_and_the_environment(): void
    {
        $guard = new MailSafety;

        try {
            $guard->assertMailerIsSafe('ses');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ses', $e->getMessage());
            $this->assertStringContainsString('testing', $e->getMessage());
        }
    }

    public function test_mail_url_is_checked_instead_of_mail_host(): void
    {
        $guard = new MailSafety;

        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.url', 'smtp://user:pass@smtp.sendgrid.net:587');

        try {
            $guard->assertMailerIsSafe('smtp');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        config()->set('mail.mailers.smtp.host', 'smtp.gmail.com');
        config()->set('mail.mailers.smtp.url', 'smtp://127.0.0.1:1025');

        $guard->assertMailerIsSafe('smtp');
        $this->assertTrue(true);
    }

    public function test_failover_is_safe_only_when_every_nested_mailer_is(): void
    {
        $guard = new MailSafety;

        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        $guard->assertMailerIsSafe('failover');
        $this->assertTrue(true);

        config()->set('mail.mailers.smtp.host', 'smtp.gmail.com');
        try {
            $guard->assertMailerIsSafe('failover');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public function test_roundrobin_fanning_out_to_ses_is_refused(): void
    {
        $guard = new MailSafety;

        try {
            $guard->assertMailerIsSafe('roundrobin');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public function test_an_unknown_mailer_is_refused(): void
    {
        $guard = new MailSafety;

        try {
            $guard->assertMailerIsSafe('smpt');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }
    }

    public function test_production_is_not_guarded(): void
    {
        $guard = new MailSafety;

        config()->set('app.env', 'production');
        config()->set('mail.default', 'ses');
        $guard->guardOutgoingMail();
        $this->assertTrue(true);

        config()->set('app.env', 'local');
        try {
            $guard->guardOutgoingMail();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }

    public function test_a_mailer_listing_itself_does_not_recurse_forever(): void
    {
        $guard = new MailSafety;

        config()->set('mail.mailers.cyclic', [
            'transport' => 'failover',
            'mailers' => ['cyclic', 'log'],
        ]);

        $guard->assertMailerIsSafe('cyclic');
        $this->assertTrue(true);
    }
}
