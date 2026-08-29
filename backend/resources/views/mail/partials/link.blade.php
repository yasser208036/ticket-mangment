{{--
    The one call-to-action shape. TM-55 deferred this here by name.

    A styled anchor rather than a table-cell button: it renders in every client,
    it is still a link with CSS stripped, and the href is visible to a reader
    who cannot click it because the text part carries the bare URL.
--}}
<p style="margin:0 0 16px;"><a href="{{ $url }}" style="color:#2563eb; text-decoration:underline;">{{ $text }}</a></p>
