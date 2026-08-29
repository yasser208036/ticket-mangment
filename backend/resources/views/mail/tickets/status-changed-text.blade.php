@extends('mail.layout-text')

@section('content')
Hello {{ $requesterName }},

Your request has moved status.

@include('mail.partials.summary-text', ['rows' => $rows])
@if (filled($resolution))

How it was resolved:

{!! $resolution !!}
@endif

Reply to this email quoting the reference above if anything is still outstanding.

@endsection
