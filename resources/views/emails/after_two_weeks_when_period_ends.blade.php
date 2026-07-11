@include('emails.partials.header')

@include('emails.partials.heading', [
    'text' => 'Мы скучаем по вам',
    'subtitle' => 'Ваша подписка истекла две недели назад',
])

<p style="margin: 0 0 16px 0;">Здравствуйте, {{ $name }}!</p>

<p style="margin: 0 0 8px 0;">Надеемся, у вас всё хорошо! Мы заметили, что ваша подписка на CW Platform истекла, и хотим напомнить — вы можете в любой момент вернуться и возобновить доступ ко всем инструментам.</p>

<p style="margin: 0 0 16px 0;">CW Platform помогает автоматизировать рутину на маркетплейсе: автоответы на отзывы, расчёт рентабельности, ценообразование и генерация контента для карточек.</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0;">
    <tr>
        <td style="padding: 0;">
            <p style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #0c0c0c;">Почему стоит вернуться:</p>
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                <tr>
                    <td style="padding: 8px 0; font-size: 15px; line-height: 1.5; color: #3a3a3a; vertical-align: top; width: 28px;">&#10003;</td>
                    <td style="padding: 8px 0; font-size: 15px; line-height: 1.5; color: #3a3a3a;">Полное восстановление всех данных и настроек</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-size: 15px; line-height: 1.5; color: #3a3a3a; vertical-align: top; width: 28px;">&#10003;</td>
                    <td style="padding: 8px 0; font-size: 15px; line-height: 1.5; color: #3a3a3a;">Доступ ко всем обновлениям и новым функциям</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@include('emails.partials.button', ['url' => $link, 'text' => 'Вернуть доступ'])

@include('emails.partials.fallback-link', ['url' => $link])

<p style="margin: 24px 0 0 0; font-size: 14px; line-height: 1.5; color: #888888;">
    Есть вопросы или нужны особые условия? Напишите нам в
    <a href="https://t.me/CWPlatform" target="_blank" style="color: #666666; text-decoration: underline;">Telegram</a>.
</p>

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 24px 0 0 0;">
    <tr>
        <td style="border-top: 1px solid #eeeeee; padding-top: 20px;">
            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #aaaaaa; font-style: italic;">
                Если вы решили пока отказаться от сервиса — спасибо за время, проведённое с нами. Будем рады вашей обратной связи: что можно улучшить?
            </p>
        </td>
    </tr>
</table>

@include('emails.partials.footer')