@include('emails.partials.header')
<p>Здравствуйте, {{ $name }}.</p>
<p>&nbsp;</p>
<p>{{ $reason }}</p>
<p><strong>Кабинет: </strong> {{ $cabinet }}</p>
<p><strong>Инструмент: </strong>{{ $service }}</p>
<p>Работа кабинета приостановлена.</p>
<p>Убедитесь что ключ API действительный, что у него установлены необходимые разрешения.</p>
<p>Вы можете перейти <a target="_blank" href="{{ $link }}">по ссылке</a>, проверить ключ API и возобновить
    работу кабинета.</p>
@include('emails.partials.footer')
