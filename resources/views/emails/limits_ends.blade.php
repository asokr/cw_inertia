@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Лимит подходит к концу',
    'subtitle' => 'Рекомендуем пополнить лимиты заранее',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">У вас заканчивается лимит на одну из услуг CW Platform. После исчерпания лимита работа услуги может быть ограничена.</p>

@include('emails.partials.info-box', [
    'type' => 'warning',
    'items' => [
        ['label' => 'Тип лимита: ', 'value' => $limit],
    ],
])

<p style="margin: 0 0 8px 0;">Дополнительные лимиты можно приобрести в профиле — это займёт пару минут.</p>

@include('emails.partials.button', ['url' => 'https://cwplatform.ru/panel/user/profile', 'text' => 'Перейти в профиль'])

@include('emails.partials.footer')