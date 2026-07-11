@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Восстановление пароля',
    'subtitle' => 'Запрос на смену пароля для вашего аккаунта',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">Мы получили запрос на смену пароля для вашего аккаунта CW Platform. Если это были вы — нажмите кнопку ниже, чтобы задать новый пароль.</p>

@include('emails.partials.button', ['url' => $url, 'text' => 'Сменить пароль'])

@include('emails.partials.fallback-link', ['url' => $url])

<p style="margin: 24px 0 0 0; font-size: 14px; line-height: 1.5; color: #888888;">
    Если вы не запрашивали смену пароля, ничего делать не нужно — ваш пароль останется прежним.
</p>

@include('emails.partials.footer')