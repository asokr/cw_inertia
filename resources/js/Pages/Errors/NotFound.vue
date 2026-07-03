<script setup>
import { Head, Link } from "@inertiajs/vue3";
import {
    ArrowRight,
    BookOpen,
    Compass,
    LogIn,
    Sparkles,
    UserPlus,
    Wrench,
} from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    authenticated: { type: Boolean, default: false },
    userName: { type: String, default: null },
    homeUrl: { type: String, default: "/login" },
    isSubscriber: { type: Boolean, default: false },
    isAdmin: { type: Boolean, default: false },
});

const toolLinks = [
    { label: "Отзывы WB", href: "/panel/wb/feedbacks" },
    { label: "Репрайсер", href: "/panel/wb/repricer" },
    { label: "ИИ-маркетплейс", href: "/panel/ai" },
    { label: "Профиль", href: "/panel/user/profile" },
];
</script>

<template>
    <Head title="Страница не найдена" />

    <div class="landing-page relative min-h-screen overflow-hidden bg-background text-foreground">
        <div class="landing-page__glow landing-page__glow--left" />
        <div class="landing-page__glow landing-page__glow--right" />
        <div class="landing-page__grid" />

        <div class="relative z-10 mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-10 sm:px-6 lg:px-8">
            <header class="landing-page__fade flex items-center justify-between">
                <Link href="/" class="text-sm font-semibold tracking-tight text-foreground/90 transition hover:text-primary">
                    CW Platform
                </Link>
                <Link
                    v-if="!authenticated"
                    href="/login"
                    class="text-sm text-muted-foreground transition hover:text-foreground"
                >
                    Уже есть аккаунт?
                </Link>
            </header>

            <main class="flex flex-1 flex-col items-center justify-center py-12">
                <div class="landing-page__fade landing-page__fade--1 mb-8 text-center">
                    <p class="mb-3 inline-flex items-center gap-2 rounded-full border bg-card/80 px-4 py-1.5 text-xs font-medium text-muted-foreground backdrop-blur">
                        <Compass class="h-3.5 w-3.5 text-primary" />
                        Ошибка 404
                    </p>
                    <div class="error-page__code select-none">404</div>
                </div>

                <Card class="landing-page__fade landing-page__fade--2 w-full max-w-2xl border-border/70 bg-card/90 p-8 shadow-xl shadow-primary/5 backdrop-blur sm:p-10">
                    <template v-if="authenticated">
                        <div class="mb-6 space-y-3 text-center sm:text-left">
                            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                                Здесь ничего нет, {{ userName || "друг" }}
                            </h1>
                            <p class="text-sm leading-relaxed text-muted-foreground sm:text-base">
                                <template v-if="isSubscriber">
                                    Ссылка устарела или страница ещё не перенесена. Вернитесь к инструментам — там всё на месте.
                                </template>
                                <template v-else-if="isAdmin">
                                    Запрошенный раздел не найден. Вернитесь в панель управления и продолжите работу оттуда.
                                </template>
                                <template v-else>
                                    Страница недоступна. Перейдите в личный кабинет или выберите другой раздел.
                                </template>
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Button as="a" :href="homeUrl" size="lg" class="w-full sm:w-auto">
                                <Wrench v-if="isSubscriber" class="mr-2 h-4 w-4" />
                                <Sparkles v-else class="mr-2 h-4 w-4" />
                                {{ isSubscriber ? "К инструментам" : isAdmin ? "В панель управления" : "В личный кабинет" }}
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                            <Button as="a" href="/blog" variant="outline" size="lg" class="w-full sm:w-auto">
                                <BookOpen class="mr-2 h-4 w-4" />
                                Читать блог
                            </Button>
                        </div>

                        <div v-if="isSubscriber" class="mt-8 border-t pt-6">
                            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Быстрый переход
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Link
                                    v-for="tool in toolLinks"
                                    :key="tool.href"
                                    :href="tool.href"
                                    class="rounded-full border bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground transition hover:border-primary/30 hover:bg-primary/5 hover:text-foreground"
                                >
                                    {{ tool.label }}
                                </Link>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="mb-6 space-y-3 text-center sm:text-left">
                            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                                Такой страницы не существует
                            </h1>
                            <p class="text-sm leading-relaxed text-muted-foreground sm:text-base">
                                Возможно, ссылка устарела или вы ошиблись в адресе. Зарегистрируйтесь бесплатно и получите доступ к инструментам для Wildberries, Ozon и ИИ-автоматизации.
                            </p>
                        </div>

                        <ul class="mb-8 grid gap-3 sm:grid-cols-3">
                            <li class="rounded-lg border bg-background/70 px-4 py-3 text-sm text-muted-foreground">
                                <span class="mb-1 block font-medium text-foreground">Автоответы</span>
                                Отзывы и вопросы на маркетплейсах
                            </li>
                            <li class="rounded-lg border bg-background/70 px-4 py-3 text-sm text-muted-foreground">
                                <span class="mb-1 block font-medium text-foreground">Репрайсер</span>
                                Управление ценами и остатками
                            </li>
                            <li class="rounded-lg border bg-background/70 px-4 py-3 text-sm text-muted-foreground">
                                <span class="mb-1 block font-medium text-foreground">ИИ-инструменты</span>
                                Анализ и генерация контента
                            </li>
                        </ul>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Button as="a" href="/register" size="lg" class="w-full sm:flex-1">
                                <UserPlus class="mr-2 h-4 w-4" />
                                Создать аккаунт
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                            <Button as="a" href="/login" variant="outline" size="lg" class="w-full sm:w-auto">
                                <LogIn class="mr-2 h-4 w-4" />
                                Войти
                            </Button>
                        </div>

                        <p class="mt-6 text-center text-xs text-muted-foreground sm:text-left">
                            Не хотите регистрироваться?
                            <Link href="/blog" class="font-medium text-primary hover:underline">Почитайте наш блог</Link>
                            — там полезные материалы для селлеров.
                        </p>
                    </template>
                </Card>
            </main>
        </div>
    </div>
</template>

<style scoped>
.error-page__code {
    font-size: clamp(5rem, 18vw, 9rem);
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.06em;
    background: linear-gradient(180deg, hsl(var(--foreground)) 0%, hsl(var(--primary)) 100%);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    opacity: 0.92;
}

</style>