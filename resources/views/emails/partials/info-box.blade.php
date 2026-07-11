{{-- Highlighted info box. Params: $items (array of ['label' => '', 'value' => '']) or $content (html string), $type (optional: default|warning|success) --}}
@php
    $styles = match($type ?? 'default') {
        'warning' => ['bg' => '#fff8f0', 'border' => '#f5a623', 'label' => '#8a5a00', 'value' => '#3a3a3a'],
        'success' => ['bg' => '#f0faf4', 'border' => '#34c759', 'label' => '#1a6b32', 'value' => '#3a3a3a'],
        default => ['bg' => '#f7f7f7', 'border' => '#0c0c0c', 'label' => '#666666', 'value' => '#0c0c0c'],
    };
@endphp
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0;">
    <tr>
        <td style="background-color: {{ $styles['bg'] }}; border-left: 4px solid {{ $styles['border'] }}; border-radius: 0 8px 8px 0; padding: 16px 20px;">
            @if(isset($content))
                <p style="margin: 0; font-size: 15px; line-height: 1.5; color: {{ $styles['value'] }};">{!! $content !!}</p>
            @else
                @foreach($items as $item)
                    <p style="margin: {{ $loop->first ? '0' : '8px 0 0 0' }}; font-size: 14px; line-height: 1.5;">
                        <span style="color: {{ $styles['label'] }};">{{ $item['label'] }}</span>
                        <span style="color: {{ $styles['value'] }}; font-weight: 600;">{{ $item['value'] }}</span>
                    </p>
                @endforeach
            @endif
        </td>
    </tr>
</table>