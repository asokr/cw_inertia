<!-- Yandex.Metrika counter -->
<script type="text/javascript">
    (function(m, e, t, r, i, k, a) {
        m[i] = m[i] || function() {
            (m[i].a = m[i].a || []).push(arguments)
        };
        m[i].l = 1 * new Date();
        for (var j = 0; j < document.scripts.length; j++) {
            if (document.scripts[j].src === r) {
                return;
            }
        }
        k = e.createElement(t), a = e.getElementsByTagName(t)[0], k.async = 1, k.src = r, a.parentNode.insertBefore(
            k, a)
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');

    ym(96944938, 'init', {
        webvisor: true,
        clickmap: true,
        referrer: document.referrer,
        url: location.href,
        accurateTrackBounce: true,
        trackLinks: true
    });
</script>
<noscript>
    <div><img src="https://mc.yandex.ru/watch/96944938" style="position:absolute; left:-9999px;" alt="" />
    </div>
</noscript>
<!-- /Yandex.Metrika counter -->

@if (\App\Support\AnalyticsScripts::shouldLoadJivo())
    <script>
        const d = document;
        const s = d.createElement("script");

        s.src = "//code.jivo.ru/widget/xJLdCtgnnJ";
        s.async = 1;
        d.getElementsByTagName("head")[0].appendChild(s);
    </script>
@endif