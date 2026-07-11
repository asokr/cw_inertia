@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Тестовый период заканчивается',
    'subtitle' => 'Через 2 дня доступ к инструментам будет ограничен',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">Ваш тестовый период в CW Platform подходит к концу. Чтобы продолжить пользоваться автоответами, репрайсером, расчётом рентабельности и другими инструментами — выберите подходящий тариф.</p>

@include('emails.partials.info-box', [
    'content' => 'После окончания тестового периода доступ к платным функциям будет приостановлен, но ваши данные и настройки сохранятся.',
])

@include('emails.partials.button', ['url' => $link, 'text' => 'Выбрать тариф'])

@include('emails.partials.fallback-link', ['url' => $link])

@include('emails.partials.footer')