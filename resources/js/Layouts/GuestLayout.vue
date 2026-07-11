<script setup>
import { Link } from "@inertiajs/vue3";
import FlashToasts from "@/components/admin/FlashToasts.vue";
import Button from "@/components/ui/Button.vue";

defineProps({
    authenticated: { type: Boolean, default: false },
    homeUrl: { type: String, default: "/login" },
    cabinetLabel: { type: String, default: "В кабинет" },
});
</script>

<template>
    <FlashToasts />

    <div class="landing-page relative min-h-screen overflow-hidden bg-background text-foreground">
        <div class="landing-page__glow landing-page__glow--left" />
        <div class="landing-page__glow landing-page__glow--right" />
        <div class="landing-page__glow landing-page__glow--center" />
        <div class="landing-page__grid" />

        <div class="relative z-10 flex min-h-screen flex-col">
            <header class="landing-page__fade mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6 sm:px-6 lg:px-8">
                <Link href="/" class="text-sm font-semibold tracking-tight text-foreground/90 transition hover:text-primary">
                    CW Platform
                </Link>

                <nav class="hidden items-center gap-6 text-sm text-muted-foreground md:flex">
                    <Link href="/#features" class="transition hover:text-foreground">Инструменты</Link>
                    <Link href="/#tariffs" class="transition hover:text-foreground">Тарифы</Link>
                    <Link href="/#reviews" class="transition hover:text-foreground">Отзывы</Link>
                    <Link href="/#faq" class="transition hover:text-foreground">FAQ</Link>
                    <Link href="/blog" class="transition hover:text-foreground">Блог</Link>
                </nav>

                <div class="flex items-center gap-2">
                    <template v-if="authenticated">
                        <Button as="a" :href="homeUrl" size="sm">
                            {{ cabinetLabel }}
                        </Button>
                    </template>
                    <template v-else>
                        <Button as="a" href="/login" variant="ghost" size="sm" class="hidden sm:inline-flex">
                            Войти
                        </Button>
                        <Button as="a" href="/register" size="sm">
                            Регистрация
                        </Button>
                    </template>
                </div>
            </header>

            <main class="mx-auto flex w-full max-w-lg flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
                <div class="landing-page__fade landing-page__fade--1 w-full">
                    <slot />
                </div>
            </main>

            <footer class="landing-page__fade border-t border-border/60 bg-card/40 backdrop-blur">
                <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>© {{ new Date().getFullYear() }} CW Platform — инструменты для селлеров WB и Ozon</p>
                    <div class="flex flex-wrap gap-4">
                        <a href="tel:88005513342" class="hover:text-foreground">8-800-551-33-42</a>
                        <a href="mailto:support@cwplatform.ru" class="hover:text-foreground">support@cwplatform.ru</a>
                        <Link href="/blog" class="hover:text-foreground">Блог</Link>
                    </div>
                </div>
            </footer>
        </div>
    </div>
</template>