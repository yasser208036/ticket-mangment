{{--
    The one HTML mail layout. Every notification's HTML view extends this.

    Tables and align=center, not flexbox or grid: Outlook renders with Word.
    Desktop appearance lives in inline style attributes because Gmail strips
    <style> in some contexts; the block below carries only the mobile
    improvement, so losing it degrades to a fixed 600px card rather than to
    unstyled text.

    No link in the header or footer. TM-53 and TM-54 both assert that a
    requester's email contains no URL at all, because a requester has no login.
    Staff emails build their own link from config('app.frontend_url').

    No image, no web font, no Tailwind class, no @vite. A mail client runs no
    build step and fetches no stylesheet.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('app.name') }}</title>
<style>
    @media only screen and (max-width: 600px) {
        .tm-card { width: 100% !important; padding: 20px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background:#f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" class="tm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background:#ffffff; border:1px solid #e4e4e7; padding:32px;">
        <tr><td style="font:600 18px/1.3 -apple-system,'Segoe UI',Arial,sans-serif; color:#18181b; padding-bottom:20px;">{{ config('app.name') }}</td></tr>
        <tr><td style="font:400 15px/1.6 -apple-system,'Segoe UI',Arial,sans-serif; color:#18181b;">@yield('content')</td></tr>
        <tr><td style="font:400 13px/1.5 -apple-system,'Segoe UI',Arial,sans-serif; color:#71717a; padding-top:24px; border-top:1px solid #e4e4e7;">&mdash; {{ config('app.name') }}</td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
