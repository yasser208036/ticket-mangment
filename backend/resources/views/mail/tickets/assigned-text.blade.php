@extends('mail.layout-text')

@section('content')
Hello {{ $agentName }},

A ticket has been assigned to you.

@include('mail.partials.summary-text', ['rows' => $rows])
@if (filled($reason))

Handover note:

{!! $reason !!}
@endif

@include('mail.partials.link-text', ['text' => 'View the ticket', 'url' => $ticketUrl])

You are receiving this because the ticket is now assigned to you.

@endsection
