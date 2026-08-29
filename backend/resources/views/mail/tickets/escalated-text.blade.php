@extends('mail.layout-text')

@section('content')
Hello {{ $adminName }},
@if ($level > 1)

This ticket has now been escalated {{ $level }} times.
@endif

Escalated by {{ $escalatedBy }}.

@include('mail.partials.summary-text', ['rows' => $rows])
Reason given:

{!! $reason !!}

@include('mail.partials.link-text', ['text' => 'Open the ticket', 'url' => $ticketUrl])
@endsection
