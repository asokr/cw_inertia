@include('emails.partials.header')
<p style="margin-bottom: 24px">Здравствуйте, {{ $name }}.</p>
<p>Ваша подписка на CW Platform подходит к концу.</p>
<p>На вашем счёте не хватает средств для продления.</p>
<p style="margin-bottom: 20px;">Не достаточно: <b>{{ $not_enough }} руб.</b></p>
<p>Пополните баланс, чтобы продлить подписку.</p>
<p>&nbsp;</p>
<div style="margin-top: 40px; text-align: center;">
    <a href="{{ $link }}"
        style="background-color: #0c0c0c; color: #fff; padding: 10px 30px; border-radius: 5px; text-decoration: none;">Пополнить
        баланс</a>
</div>
@include('emails.partials.footer')
