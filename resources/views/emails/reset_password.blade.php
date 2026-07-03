@include('emails.partials.header')
<p>Здравствуйте, {{ $name }}.</p>
<p>&nbsp;</p>
<p>Вы (или кто-то) запросили смену пароля на CW Platform.</p>
<p>Если это были вы, то нажмите по <a target="_blank" href="{{ $url }}">ссылке</a> для смены пароля.</p>
<p>Если вы не запрашивали смену пароля, то просто проигнорируйте это письмо.</p>
@include('emails.partials.footer')
