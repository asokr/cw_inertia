@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Подтвердите почту',
    'subtitle' => 'Остался один шаг до начала работы с CW Platform',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $user->name }}!</p>

<p style="margin: 0 0 8px 0;">Нажмите кнопку ниже, чтобы подтвердить адрес электронной почты и активировать аккаунт.</p>

@include('emails.partials.button', ['url' => $url, 'text' => 'Подтвердить почту'])

@include('emails.partials.fallback-link', ['url' => $url])

<p style="margin: 24px 0 0 0; font-size: 14px; line-height: 1.5; color: #888888;">
    Если вы не регистрировались на CW Platform, просто проигнорируйте это письмо.
</p>

@include('emails.partials.footer')