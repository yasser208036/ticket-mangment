@extends('mail.layout-text')

@section('content')
Hello {{ $requesterName }},

We have logged your request. Please quote the reference below in any reply.

@include('mail.partials.summary-text', ['rows' => $rows])
What you told us:

{!! $description !!}

A member of our team will review your request and reply to this email address.
Quote the reference above in any follow-up so we can find your request straight
away.

@endsection
