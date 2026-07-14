<script setup>
import { onMounted } from "vue";
import { Link } from "@inertiajs/vue3";
import SiteFooter from "@/components/SiteFooter.vue";
import Button from "@/components/ui/Button.vue";
import { useLandingSectionNav } from "@/composables/useLandingSectionNav";

const landingSections = [
    { id: "features", label: "Инструменты" },
    { id: "tariffs", label: "Тарифы" },
    { id: "reviews", label: "Отзывы" },
    { id: "faq", label: "FAQ" },
];

const { navigateToSection, scrollToHashFromUrl } = useLandingSectionNav();

onMounted(() => {
    scrollToHashFromUrl();
});

defineProps({
    authenticated: { type: Boolean, default: false },
    homeUrl: { type: String, default: "/login" },
    cabinetLabel: { type: String, default: "В кабинет" },
});
</script>

<template>
    <div class="landing-page relative min-h-screen overflow-hidden bg-background text-foreground">
        <div class="landing-page__glow landing-page__glow--left" />
        <div class="landing-page__glow landing-page__glow--right" />
        <div class="landing-page__glow landing-page__glow--center" />
        <div class="landing-page__grid" />

        <div class="relative z-10 flex min-h-screen flex-col">
            <header class="landing-page__fade mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-6 sm:px-6 lg:px-8">
                <Link href="/" class="text-base font-semibold tracking-tight text-foreground/90 transition hover:text-primary">
                    CW Platform
                </Link>

                <nav class="hidden items-center gap-6 text-sm text-muted-foreground md:flex">
                    <a
                        v-for="section in landingSections"
                        :key="section.id"
                        :href="`#${section.id}`"
                        class="transition hover:text-foreground"
                        @click="navigateToSection(section.id, $event)"
                    >
                        {{ section.label }}
                    </a>
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

            <main class="flex-1">
                <slot />
            </main>

            <SiteFooter />
        </div>
    </div>
</template>