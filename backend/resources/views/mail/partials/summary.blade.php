{{--
    The ticket-summary block. $rows is a label => value map built by the
    NOTIFICATION, never by this file: TM-53's and TM-54's emails deliberately
    omit priority, status and category, and both have tests asserting it. This
    partial owns how a summary looks, never what a summary contains.

    Free text -- a description, a resolution note, an escalation reason -- is
    NOT a row. It needs pre-wrap and a full-width column, and each view keeps
    its own <pre> block for it.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px; font:400 15px/1.6 -apple-system,'Segoe UI',Arial,sans-serif;">
@foreach ($rows as $label => $value)
    <tr>
        <td valign="top" style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ $label }}</td>
        <td valign="top" style="padding:4px 0; color:#18181b;">{{ $value }}</td>
    </tr>
@endforeach
</table>
