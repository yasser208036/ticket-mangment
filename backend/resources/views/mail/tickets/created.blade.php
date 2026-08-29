@extends('mail.layout')

@section('content')
{{--
    {{ }} everywhere: the description is user-submitted text and must be
    escaped. <pre> is what preserves its newlines — measured, the markdown
    pipeline collapses them, which is why this file exists at all.

    Nothing here may reference priority, status, category, the assignee, the
    creator or ticket_activities. TM-53 AC5, and TM-47's deferred criterion.
--}}
<p>Hello {{ $requesterName }},</p>

<p>We have logged your request. Please quote the reference below in any reply.</p>

@include('mail.partials.summary', ['rows' => $rows])

<p>What you told us:</p>

<pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $description }}</pre>

<p>A member of our team will review your request and reply to this email address. Quote the reference above in any follow-up so we can find your request straight away.</p>
@endsection
