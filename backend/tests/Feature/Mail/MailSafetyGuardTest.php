<?php

namespace Tests\Feature\Mail;

use App\Services\MailSafety;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class MailSafetyGuardTest extends TestCase
{
    public function test_the_suites_array_transport_is_allowed(): void
    {
        Mail::raw('body', fn ($m) => $m->to('nobody@example.test')->subject('probe'));

        $this->assertTrue(true);
    }

    public function test_smtp_pointed_at_mailpit_is_allowed(): void
    {
        config()->set('mail.mailers.smtp.host', '127.0.0.1');

        $this->app->make(MailSafety::class)->assertMailerIsSafe('smtp');

        $this->assertTrue(true);
    }

    public function test_smtp_pointed_off_the_machine_is_refused(): void
    {
        config()->set('mail.mailers.smtp.host', 'smtp.gmail.com');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/smtp\.gmail\.com/');
        $this->expectExceptionMessageMatches('/testing/');

        $this->app->make(MailSafety::class)->assertMailerIsSafe('smtp');
    }

    public function test_the_guard_is_wired_to_the_message_sending_event(): void
    {
        config(['mail.safety.safe_transports' => []]);

        $this->expectException(RuntimeException::class);

        Mail::raw('body', fn ($m) => $m->to('nobody@example.test')->subject('probe'));
    }

    public function test_a_refused_message_throws_rather_than_being_dropped_silently(): void
    {
        config(['mail.safety.safe_transports' => []]);

        try {
            Mail::raw('body', fn ($m) => $m->to('nobody@example.test')->subject('probe'));
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
        }
    }
}
