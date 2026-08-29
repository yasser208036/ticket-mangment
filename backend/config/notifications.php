<?php

return [

    'requester' => [

        // Status slugs a requester hears about. A move INTO one of these queues
        // an email; every other target is internal-only. Slugs, never ids --
        // ids differ between the development and test databases, and
        // StatusSeeder guarantees these seven. `new` and `open` are absent on
        // purpose: TM-53's confirmation already covered creation, and `open` is
        // a triage step with no requester-facing meaning.
        'visible_statuses' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOTIFY_REQUESTER_STATUSES', 'in-progress,pending,resolved,closed,reopened')),
        ))),

        // How long a status email waits before it is sent. During the window a
        // later change supersedes it, so an agent working through a ticket in
        // one sitting produces one email rather than four. 0 sends immediately
        // and effectively disables supersession.
        'delay_seconds' => (int) env('NOTIFY_REQUESTER_DELAY_SECONDS', 300),

    ],

    'admin' => [

        // Escalations go to every active admin. AC1 of TM-55 is unqualified,
        // and TicketPolicy::escalate() admits all staff, so the escalating user
        // is frequently not an admin at all. Set to false to exclude an admin
        // who escalated a ticket from their own notification.
        'notify_escalating_admin' => (bool) env('NOTIFY_ESCALATING_ADMIN', true),

        // The token every escalation subject starts with, so a mail rule can
        // match on prefix. `[ESCALATED` is the invariant; the level suffix is
        // appended above level one.
        'escalation_subject_token' => env('NOTIFY_ESCALATION_TOKEN', 'ESCALATED'),

    ],

    'retry' => [

        // Attempts per notification, not retries after the first: tries = 3
        // means one send and two retries. Read from config when the job is
        // QUEUED, so changing this does not alter jobs already in `jobs`.
        'tries' => (int) env('NOTIFY_TRIES', 3),

        // Seconds to wait before each retry, indexed by attempt. Laravel joins
        // this into a comma string in the payload and repeats the last entry if
        // there are more attempts than entries. Six minutes of total patience
        // absorbs a restarting relay; a wrong MAIL_HOST fails and is visible.
        'backoff' => [
            (int) env('NOTIFY_BACKOFF_FIRST', 60),
            (int) env('NOTIFY_BACKOFF_SECOND', 300),
        ],

        // Seconds a single send may run. MUST stay below the database queue's
        // retry_after (config/queue.php:43, currently 90) or a slow job is
        // handed to a second worker while the first is still sending it.
        // Requires ext-pcntl; without it the worker cannot enforce this.
        'timeout' => (int) env('NOTIFY_TIMEOUT', 30),

    ],

];
