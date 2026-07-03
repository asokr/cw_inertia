@include('emails.partials.header')
<p style="text-align: center"> <strong>Лимиты по тарифу подходят к концу!</strong></p>
<p>&nbsp;</p>
<p>Здравствуйте, {{ $name }}.</p>
<p>У вас заканчивается лимит на одну из услуг CW Platform.</p>
<p><strong>Тип лимита: </strong>{{ $limit }}.</p>
<p>&nbsp;</p>
<p>После исчерпания лимита работа услуги в полной мере не гарантируется.</p>
<p>Приобрести дополнительные лимиты к тарифу, можно <a target="_blank" href="https://cwplatform.ru/panel/user/profile">в
        своём профиле</a>.</p>
<div style="margin-top: 40px; text-align: center;">
    <a href="https://cwplatform.ru/panel/user/profile"
        style="background-color: #0c0c0c; color: #fff; padding: 10px 30px; border-radius: 5px; text-decoration: none;">К
        профилю</a>
</div>
@include('emails.partials.footer')
