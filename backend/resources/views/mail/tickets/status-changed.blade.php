@extends('mail.layout')

@section('content')
{{--
    {{ }} everywhere: the resolution is free text an agent wrote and must be
    escaped. <pre> preserves its newlines -- measured for TM-53, the markdown
    pipeline collapses them, which is why these views exist rather than
    MailMessage->line().

    Nothing here may reference the assignee, the creator, an agent name or
    email, ticket_activities, or the reopen reason. TM-54 AC2 asks for the
    resolution note and nothing else.
--}}
<p>Hello {{ $requesterName }},</p>

<p>Your request has moved status.</p>

@include('mail.partials.summary', ['rows' => $rows])

@if (filled($resolution))
    <p>How it was resolved:</p>

    <pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $resolution }}</pre>
@endif

<p>Reply to this email quoting the reference above if anything is still outstanding.</p>
@endsection
