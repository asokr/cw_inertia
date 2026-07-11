@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Недостаточно средств',
    'subtitle' => 'Подписка не будет продлена автоматически',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">Ваша подписка на CW Platform подходит к концу, но на балансе не хватает средств для автоматического продления.</p>

@include('emails.partials.info-box', [
    'type' => 'warning',
    'items' => [
        ['label' => 'Недостаточно: ', 'value' => number_format($not_enough, 0, ',', ' ') . ' ₽'],
    ],
])

<p style="margin: 0 0 8px 0;">Пополните баланс, чтобы подписка продлилась без перерыва в работе инструментов.</p>

@include('emails.partials.button', ['url' => $link, 'text' => 'Пополнить баланс'])

@include('emails.partials.fallback-link', ['url' => $link])

@include('emails.partials.footer')