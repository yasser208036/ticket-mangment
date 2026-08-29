<?php

namespace App\Notifications\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queue policy every notification in this application shares.
 *
 * Three settings, three different mechanisms, because that is how the framework
 * reads them -- get one wrong and it fails silently:
 *
 *   tries   -- a PROPERTY, read by SendQueuedNotifications::__construct() via
 *              getAttributeValue(). A tries() method is never called.
 *   timeout -- the same.
 *   backoff -- a METHOD. SendQueuedNotifications::backoff() overrides the
 *              attribute with $notification->backoff() when it exists, and
 *              Queue::getJobBackoff() joins the array into a comma string.
 *   failed  -- a METHOD, called by SendQueuedNotifications::failed(). It is the
 *              only hook AC2's "with context" can use.
 *
 * tries and timeout are read from config when the job is QUEUED and frozen into
 * the payload, so a config change does not alter jobs already waiting.
 */
trait HasRetryPolicy
{
    public ?int $tries = null;

    public ?int $timeout = null;

    /**
     * Call as the LAST statement of the using class's constructor. A trait
     * cannot supply a constructor, and Notification is not
     * #[AllowDynamicProperties], so the properties are declared above and
     * assigned here.
     */
    protected function applyRetryPolicy(): void
    {
        $this->tries = max(1, (int) config('notifications.retry.tries', 3));
        $this->timeout = max(1, (int) config('notifications.retry.timeout', 30));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $backoff = array_values(array_map(
            fn ($seconds): int => max(0, (int) $seconds),
            (array) config('notifications.retry.backoff', [60, 300]),
        ));

        return $backoff === [] ? [60] : $backoff;
    }

    /**
     * Called once, after the last attempt, immediately before the job is
     * written to failed_jobs. That row already holds the payload and the full
     * exception; this line holds what the row cannot give you without
     * unserialising it -- which ticket, and which notification.
     *
     * No email is sent from here: an alert that travels by the channel that
     * just failed is not an alert.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Notification permanently failed.', [
            'notification' => static::class,
            'tries' => $this->tries,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            ...$this->failureContext(),
        ]);
    }

    /**
     * Scalar identifiers only. Never a model, never an email address -- TM-56's
     * fifth criterion keeps staff addresses out of emails, and a log file is
     * the same leak with a longer half-life.
     *
     * @return array<string, scalar|null>
     */
    abstract protected function failureContext(): array;
}
