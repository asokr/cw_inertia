<script setup>
import { Link } from "@inertiajs/vue3";
import logoFooter from "@/assets/blog/logo_footer.svg";
</script>

<template>
    <footer class="blog-footer">
        <div class="blog-wrapper">
            <div class="blog-footer-block">
                <div class="blog-footer-logo">
                    <Link href="/blog">
                        <img :src="logoFooter" alt="CW Platform">
                    </Link>
                    <a
                        href="https://creative-wave.ru"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="blog-btn"
                    >
                        Перейти на сайт <strong>CREATIVEWAVE</strong>
                    </a>
                </div>
                <div class="blog-footer-contacts">
                    <div class="mb-3">
                        <p>По любым вопросам:</p>
                        <a href="tel:88005513342">8 800 551-33-42</a>
                        |
                        <a href="tel:+74932499991">+ 7 4932 499-991</a>
                        <p>
                            <a href="mailto:info@cwplatform.ru">info@cwplatform.ru</a>
                        </p>
                    </div>
                    <div class="mb-3 text-sm opacity-80">
                        <p>ООО "КВ РУС"</p>
                        <p>ИНН 9707008804, ОГРНИП 1237700657150</p>
                    </div>
                    <div>
                        <Link href="/blog">Блог</Link>
                    </div>
                </div>
            </div>
            <div class="blog-footer-powered">CREATIVEWAVE DEVELOPMENT</div>
        </div>
    </footer>
</template>