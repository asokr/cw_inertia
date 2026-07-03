@include('emails.partials.header')
<p>Здравствуйте, {{ $name }}.</p>
<p></p>
<p>Через два дня у Вас закончится тестовый период в CW Platform.</p>
<p>Выберите подходящий <a target="_blank" href="{{ $link }}">тариф</a></p>
<div style="margin-top: 40px; text-align: center;">
    <a href="{{ $link }}"
        style="background-color: #0c0c0c; color: #fff; padding: 10px 30px; border-radius: 5px; text-decoration: none;">Выбрать
        тариф</a>
</div>
@include('emails.partials.footer')
