@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Ошибка авторизации кабинета',
    'subtitle' => 'Работа инструмента приостановлена',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

@include('emails.partials.info-box', [
    'type' => 'warning',
    'content' => $reason,
])

@include('emails.partials.info-box', [
    'items' => [
        ['label' => 'Кабинет: ', 'value' => $cabinet],
        ['label' => 'Инструмент: ', 'value' => $service],
    ],
])

<p style="margin: 0 0 8px 0;">Проверьте, что API-ключ действителен и у него установлены необходимые разрешения. После исправления работа кабинета возобновится автоматически.</p>

@include('emails.partials.button', ['url' => $link, 'text' => 'Проверить API-ключ'])

@include('emails.partials.fallback-link', ['url' => $link])

@include('emails.partials.footer')