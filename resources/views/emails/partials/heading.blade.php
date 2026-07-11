{{-- Email heading. Params: $text, $subtitle (optional) --}}
<h1 style="margin: 0 0 {{ isset($subtitle) ? '8px' : '24px' }} 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 22px; font-weight: 700; line-height: 1.3; color: #0c0c0c;">
    {{ $text }}
</h1>
@if(isset($subtitle))
<p style="margin: 0 0 24px 0; font-size: 15px; line-height: 1.5; color: #888888;">
    {{ $subtitle }}
</p>
@endif