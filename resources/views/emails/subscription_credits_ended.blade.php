@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Кредиты закончились',
    'subtitle' => 'Можно докупить кредиты и продолжить работу',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">Кредиты на счёте закончились. Чтобы продолжить работу с ИИ, докупите кредиты в профиле или через «Пополнить» в шапке кабинета.</p>

@include('emails.partials.button', ['url' => $profileUrl, 'text' => 'Перейти в профиль'])

@include('emails.partials.footer')
