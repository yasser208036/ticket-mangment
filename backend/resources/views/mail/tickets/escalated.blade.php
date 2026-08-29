@extends('mail.layout')

@section('content')
{{--
    This email goes to admins, so unlike TM-53's and TM-54's it MAY name staff,
    the assignee and the priority, and MAY carry a link into the SPA. It still
    may not carry internal note bodies or stack traces -- TM-56 AC5.

    {{ }} everywhere: the reason is free text an agent wrote and must be
    escaped. <pre> preserves its newlines -- measured for TM-53, the markdown
    pipeline collapses them.
--}}
<p>Hello {{ $adminName }},</p>

@if ($level > 1)
    <p><strong>This ticket has now been escalated {{ $level }} times.</strong></p>
@endif

<p>Escalated by {{ $escalatedBy }}.</p>

@include('mail.partials.summary', ['rows' => $rows])

<p>Reason given:</p>

<pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $reason }}</pre>

@include('mail.partials.link', ['text' => 'Open the ticket', 'url' => $ticketUrl])
@endsection
