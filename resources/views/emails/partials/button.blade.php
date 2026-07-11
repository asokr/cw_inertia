{{-- Bulletproof CTA button. Params: $url, $text, $align (optional, default center) --}}
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: {{ $margin ?? '32px 0 8px 0' }};">
    <tr>
        <td align="{{ $align ?? 'center' }}">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="13%" strokecolor="#0c0c0c" fillcolor="#0c0c0c">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:sans-serif;font-size:16px;font-weight:bold;">{{ $text }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-->
            <a href="{{ $url }}" class="email-button" target="_blank" style="display: inline-block; background-color: #0c0c0c; color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 600; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px; border-radius: 8px; mso-hide: all;">{{ $text }}</a>
            <!--<![endif]-->
        </td>
    </tr>
</table>