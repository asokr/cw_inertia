@component('mail::layout')

    @slot('header')
        @component('mail::header', ['url' => config('app.url')])

        @endcomponent
    @endslot

![CWplatform](https://cwplatform.ru/pwa-192x192.png "CWplatform")

Здравствуйте, {{ $user->name }}.

Нажмите на кнопку ниже, чтобы подтвердить почту.

<x-mail::button :url="$url">
    Подтвердить почту
</x-mail::button>

Если кнопа не работает, перейдите [по ссылке]({{ $url }})

    @slot('footer')
        @component('mail::footer')
            {{ $year }}. CW Platform
        @endcomponent
    @endslot
@endcomponent
