<table width="100%" cellpadding="0" cellspacing="0" style="margin: 32px 0;">
    <tr>
        <td>
            <a href="{{ $url }}" style="display: inline-block; background-color: {{ $color ?? '#eb2027' }}; color: #ffffff; font-weight: 600; font-size: 16px; text-decoration: none; padding: 14px 28px; border-radius: 4px; box-shadow: 0 4px 6px rgba(50,50,93,0.11), 0 1px 3px rgba(0,0,0,0.08); transition: all 0.15s ease;">
                {{ $slot }} &larr;
            </a>
        </td>
    </tr>
</table>
