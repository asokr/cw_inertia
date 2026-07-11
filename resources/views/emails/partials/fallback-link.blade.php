{{-- Fallback link below CTA. Params: $url --}}
<p style="margin: 8px 0 0 0; font-size: 13px; line-height: 1.5; color: #888888; text-align: {{ $align ?? 'center' }}; word-break: break-all;">
    Если кнопка не работает, скопируйте ссылку:<br>
    <a href="{{ $url }}" target="_blank" style="color: #666666; text-decoration: underline;">{{ $url }}</a>
</p>