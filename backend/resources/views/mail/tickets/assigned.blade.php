@extends('mail.layout')

@section('content')
<p>Hello {{ $agentName }},</p>

<p>A ticket has been assigned to you.</p>

@include('mail.partials.summary', ['rows' => $rows])

@if (filled($reason))
    <p>Handover note:</p>

    <pre style="white-space:pre-wrap; word-break:break-word; font-family:inherit; margin:0 0 16px;">{{ $reason }}</pre>
@endif

@include('mail.partials.link', ['text' => 'View the ticket', 'url' => $ticketUrl])

<p>You are receiving this because the ticket is now assigned to you.</p>
@endsection
