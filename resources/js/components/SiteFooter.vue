<script setup>
import { Link } from "@inertiajs/vue3";
import logoFooter from "@/assets/blog/logo_footer.svg";
</script>

<template>
    <footer class="site-footer landing-page__fade border-t border-border/60 bg-card/40 backdrop-blur">
        <div class="site-footer__inner mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="site-footer__block">
                <div class="site-footer__logo">
                    <Link href="/" class="site-footer__logo-link">
                        <img :src="logoFooter" alt="CW Platform">
                    </Link>
                    <a href="https://creative-wave.ru" target="_blank" rel="noopener noreferrer"
                        class="site-footer__cw-btn">
                        Перейти на сайт&nbsp;<strong>CREATIVEWAVE</strong>
                    </a>
                </div>

                <div class="site-footer__contacts" id="contacts">
                    <div class="site-footer__contacts-block">
                        <p>По любым вопросам:</p>
                        <p>
                            <a href="tel:88005513342" class="site-footer__link">8 800 551-33-42</a>
                            <span class="px-2 text-muted-foreground/60">|</span>
                            <a href="tel:+74932499991" class="site-footer__link">+ 7 4932 499-991</a>
                        </p>
                        <p>
                            <a href="mailto:info@cwplatform.ru" class="site-footer__link">info@cwplatform.ru</a>
                        </p>
                    </div>

                    <div class="site-footer__legal">
                        <p>ООО "КВ РУС"</p>
                        <p>ИНН 9707008804, ОГРНИП 1237700657150</p>
                    </div>

                    <div class="site-footer__menu">
                        <Link href="/public-offer" class="site-footer__menu-link">Оферта</Link>
                        <span class="px-2 text-muted-foreground/60">|</span>
                        <Link href="/blog" class="site-footer__menu-link">Блог</Link>
                    </div>
                </div>
            </div>

            <div class="site-footer__powered">CREATIVEWAVE DEVELOPMENT</div>
        </div>
    </footer>
</template>