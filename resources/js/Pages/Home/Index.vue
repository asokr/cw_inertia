<script setup>
import { Head, Link } from "@inertiajs/vue3";
import {
    ArrowRight,
    Bot,
    Check,
    LogIn,
    Rocket,
    Star,
    UserPlus,
    Wrench,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import ContactForm from "@/components/landing/ContactForm.vue";
import ReviewsSection from "@/components/landing/ReviewsSection.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { aiToolScreenshotSize, aiTools, faqItems, features, highlights, plans } from "@/config/homeContent";
import LandingLayout from "@/Layouts/LandingLayout.vue";

const props = defineProps({
    authenticated: { type: Boolean, default: false },
    userName: { type: String, default: null },
    homeUrl: { type: String, default: "/login" },
    cabinetLabel: { type: String, default: "В кабинет" },
    isSubscriber: { type: Boolean, default: false },
});

const openFaq = ref(0);
const specialModalOpen = ref(false);

const specialTariffFields = [
    { name: "phone", label: "Номер телефона", required: true, type: "tel" },
    { name: "email", label: "Email", type: "email" },
    { name: "comment", label: "Комментарий", type: "textarea" },
];

function closeSpecialModal() {
    specialModalOpen.value = false;
}

const profileUrl = computed(() => (props.isSubscriber ? "/panel/user/profile" : props.homeUrl));

const screenshotSize = { width: 1200, height: 675 };

const heroImage = {
    webp: "/images/home/previews/hero.webp",
    png: "/images/home/previews/hero.png",
};
</script>

<template>
    <Head title="Онлайн сервис для работы с маркетплейсами - CWPlatform">
        <meta
            head-key="description"
            name="description"
            content="Набор инструментов для работы с маркетплейсами✔️ Ценообразование WB ✔️ Работа с отзывами ✔️ Рентабельность акций ✔️ Инструменты ИИ "
        />
        <meta
            head-key="og:title"
            property="og:title"
            content="Набор инструментов для работы с маркетплейсами✔️ Ценообразование WB ✔️ Работа с отзывами ✔️ Рентабельность акций ✔️ Инструменты ИИ "
        />
        <meta
            head-key="og:description"
            property="og:description"
            content="Набор инструментов для работы с маркетплейсами✔️ Ценообразование WB ✔️ Работа с отзывами ✔️ Рентабельность акций ✔️ Инструменты ИИ "
        />
        <meta head-key="og:image" property="og:image" content="/android-chrome-512x512.png" />
    </Head>

    <LandingLayout
        :authenticated="authenticated"
        :home-url="homeUrl"
        :cabinet-label="cabinetLabel"
    >
        <!-- Hero -->
        <section class="landing-page__fade landing-page__fade--1 mx-auto max-w-6xl px-4 pb-16 pt-4 sm:px-6 lg:px-8">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-14">
                <div class="space-y-6">
                    <p class="inline-flex items-center gap-2 rounded-full border bg-card/80 px-4 py-1.5 text-xs font-medium text-muted-foreground backdrop-blur">
                        <Rocket class="h-3.5 w-3.5 text-primary" />
                        <template v-if="authenticated">
                            С возвращением{{ userName ? `, ${userName}` : "" }}
                        </template>
                        <template v-else>
                            5 дней бесплатно для новых пользователей
                        </template>
                    </p>

                    <h1 class="text-3xl font-bold leading-tight tracking-tight sm:text-4xl lg:text-5xl">
                        Инструменты для продаж на
                        <span class="bg-gradient-to-r from-foreground to-primary bg-clip-text text-transparent">
                            Wildberries и Ozon
                        </span>
                    </h1>

                    <p class="max-w-xl text-base leading-relaxed text-muted-foreground sm:text-lg">
                        Хватит гадать, приносит ли товар прибыль. CW Platform показывает маржу по SKU, управляет ценами и отзывами, а ИИ закрывает рутину — вы принимаете решения, которые увеличивают доход, а не просто оборот.
                    </p>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <template v-if="authenticated">
                            <Button as="a" :href="homeUrl" size="lg" class="w-full sm:w-auto">
                                <Wrench class="mr-2 h-4 w-4" />
                                {{ cabinetLabel }}
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                            <Button as="a" href="/blog" variant="outline" size="lg" class="w-full sm:w-auto">
                                Читать блог
                            </Button>
                        </template>
                        <template v-else>
                            <Button as="a" href="/register" size="lg" class="w-full sm:w-auto">
                                <UserPlus class="mr-2 h-4 w-4" />
                                Начать бесплатно
                                <ArrowRight class="ml-2 h-4 w-4" />
                            </Button>
                            <Button as="a" href="/login" variant="outline" size="lg" class="w-full sm:w-auto">
                                <LogIn class="mr-2 h-4 w-4" />
                                Войти
                            </Button>
                        </template>
                    </div>

                    <ul class="grid gap-2 text-sm text-muted-foreground sm:grid-cols-2">
                        <li class="flex items-center gap-2">
                            <Check class="h-4 w-4 shrink-0 text-primary" />
                            Все инструменты в каждом тарифе
                        </li>
                        <li class="flex items-center gap-2">
                            <Check class="h-4 w-4 shrink-0 text-primary" />
                            Оплата через ЮKassa
                        </li>
                        <li class="flex items-center gap-2">
                            <Check class="h-4 w-4 shrink-0 text-primary" />
                            Поддержка 8-800-551-33-42
                        </li>
                        <li class="flex items-center gap-2">
                            <Check class="h-4 w-4 shrink-0 text-primary" />
                            Без привязки карты на тесте
                        </li>
                    </ul>
                </div>

                <Card class="overflow-hidden border-border/70 bg-card/90 shadow-xl shadow-primary/5 backdrop-blur">
                    <picture>
                        <source :srcset="heroImage.webp" type="image/webp" />
                        <img
                            :src="heroImage.png"
                            alt="Панель CW Platform"
                            :width="screenshotSize.width"
                            :height="screenshotSize.height"
                            class="aspect-video w-full object-cover"
                            loading="eager"
                        />
                    </picture>
                </Card>
            </div>
        </section>

        <!-- Features grid -->
        <section id="features" class="landing-page__fade landing-page__fade--2 mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="mb-10 max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    Инструменты, которые масштабируются вместе с бизнесом
                </h2>
                <p class="mt-3 text-muted-foreground">
                    Один кабинет вместо десятка сервисов. Автоматизируйте цены, отзывы и аналитику — растите оборот без роста штата.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Card
                    v-for="feature in features"
                    :key="feature.title"
                    class="border-border/70 bg-card/80 p-5 backdrop-blur transition hover:border-primary/30 hover:shadow-md hover:shadow-primary/5"
                >
                    <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <component :is="feature.icon" class="h-5 w-5" />
                    </div>
                    <h3 class="mb-2 font-semibold">{{ feature.title }}</h3>
                    <p class="text-sm leading-relaxed text-muted-foreground">{{ feature.description }}</p>
                </Card>
            </div>
        </section>

        <!-- Highlights -->
        <section class="landing-page__fade landing-page__fade--3 mx-auto max-w-6xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <Card
                v-for="(item, index) in highlights"
                :key="item.id"
                class="overflow-hidden border-border/70 bg-card/90 backdrop-blur"
            >
                <div
                    class="grid items-center gap-8 p-6 sm:p-8 lg:grid-cols-2"
                    :class="index % 2 === 1 && 'lg:[&>*:first-child]:order-2'"
                >
                    <div class="space-y-4">
                        <span class="inline-flex rounded-full border bg-background px-3 py-1 text-xs font-medium text-primary">
                            {{ item.badge }}
                        </span>
                        <h3 class="text-xl font-semibold sm:text-2xl">{{ item.title }}</h3>
                        <p class="text-sm leading-relaxed text-muted-foreground sm:text-base">{{ item.description }}</p>
                    </div>
                    <div class="overflow-hidden rounded-xl border bg-background/50">
                        <picture>
                            <source :srcset="item.image" type="image/webp" />
                            <img
                                :src="item.imageFallback"
                                :alt="item.title"
                                :width="screenshotSize.width"
                                :height="screenshotSize.height"
                                class="aspect-video w-full object-cover"
                                loading="lazy"
                            />
                        </picture>
                    </div>
                </div>
            </Card>
        </section>

        <!-- AI block -->
        <section class="landing-page__fade landing-page__fade--4 mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
            <Card class="border-border/70 bg-card/90 p-6 sm:p-10 backdrop-blur">
                <div class="mb-8 flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Bot class="h-6 w-6" />
                    </div>
                    <div>
                        <h2 class="text-2xl font-semibold sm:text-3xl">Полный цикл AI-контента</h2>
                        <p class="mt-2 max-w-2xl text-muted-foreground">
                            Запускайте карточки быстрее: описания, фото и видео из одного кабинета. ИИ адаптирует контент под требования WB и Ozon.
                        </p>
                    </div>
                </div>

                <div class="mb-8 grid gap-4 md:grid-cols-3">
                    <div
                        v-for="tool in aiTools"
                        :key="tool.title"
                        class="overflow-hidden rounded-xl border bg-background/70"
                    >
                        <picture>
                            <source :srcset="tool.image" type="image/webp" />
                            <img
                                :src="tool.imageFallback"
                                :alt="tool.title"
                                :width="aiToolScreenshotSize.width"
                                :height="aiToolScreenshotSize.height"
                                class="aspect-[4/3] w-full object-cover"
                                loading="lazy"
                            />
                        </picture>
                        <div class="p-4">
                            <h3 class="mb-2 font-medium">{{ tool.title }}</h3>
                            <p class="text-sm text-muted-foreground">{{ tool.text }}</p>
                        </div>
                    </div>
                </div>

                <Button as="a" :href="authenticated ? homeUrl : '/register'" size="lg">
                    {{ authenticated ? cabinetLabel : "Попробовать ИИ-инструменты" }}
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>
        </section>

        <!-- Pricing -->
        <section id="tariffs" class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="mb-10 text-center">
                <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                    Выберите тариф под масштаб бизнеса
                </h2>
                <p class="mx-auto mt-3 max-w-2xl text-muted-foreground">
                    Все инструменты платформы включены в каждый тариф. Разница — только в лимитах кабинетов и объёме ИИ-генераций.
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card
                    v-for="plan in plans"
                    :key="plan.name"
                    class="relative flex flex-col border-border/70 bg-card/90 p-6 backdrop-blur"
                    :class="plan.popular && 'border-primary/40 shadow-lg shadow-primary/10'"
                >
                    <div
                        v-if="plan.popular"
                        class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-primary px-3 py-0.5 text-xs font-medium text-primary-foreground"
                    >
                        Популярный
                    </div>

                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold">{{ plan.name }}</h3>
                        <div class="flex gap-0.5">
                            <Star
                                v-for="n in 3"
                                :key="n"
                                class="h-4 w-4"
                                :class="n <= plan.stars ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/30'"
                            />
                        </div>
                    </div>

                    <p class="mb-4 text-sm text-muted-foreground">{{ plan.subtitle }}</p>

                    <div class="mb-6">
                        <span class="text-3xl font-bold">{{ plan.price }}</span>
                        <span class="text-sm text-muted-foreground">{{ plan.period }}</span>
                    </div>

                    <Button
                        as="a"
                        :href="authenticated ? profileUrl : '/register'"
                        :variant="plan.popular ? 'default' : 'outline'"
                        class="mb-6 w-full"
                    >
                        {{ authenticated ? "Управлять тарифом" : "Попробовать бесплатно" }}
                    </Button>

                    <div class="space-y-4 text-sm">
                        <div>
                            <p class="mb-2 font-medium text-foreground">Лимиты по тарифу</p>
                            <ul class="space-y-1.5 text-muted-foreground">
                                <li v-for="limit in plan.limits" :key="limit" class="flex gap-2">
                                    <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                    {{ limit }}
                                </li>
                            </ul>
                        </div>
                        <div>
                            <p class="mb-2 font-medium text-foreground">Обновляемые лимиты</p>
                            <ul class="space-y-1.5 text-muted-foreground">
                                <li v-for="item in plan.monthly" :key="item" class="flex gap-2">
                                    <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                    {{ item }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </Card>
            </div>

            <Card class="mt-8 border-border/70 bg-card/80 p-6 text-center backdrop-blur sm:p-8">
                <h3 class="text-lg font-semibold sm:text-xl">
                    <span class="text-primary">Индивидуальный</span> тариф для вас!
                </h3>
                <p class="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">
                    <strong class="font-medium text-foreground">Нужны особые условия?</strong>
                    Мы рассчитаем персональный тариф, который идеально подходит именно для вашего бизнеса.
                </p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">
                    Оставьте заявку — предложим выгодные условия без лишних переплат.
                </p>
                <Button type="button" variant="outline" class="mt-4" @click="specialModalOpen = true">
                    Оставить заявку
                </Button>
            </Card>
        </section>

        <Dialog
            v-model:open="specialModalOpen"
            title="Персональный тариф"
            class="max-w-lg"
        >
            <ContactForm
                :fields="specialTariffFields"
                subject="Запрос персонального тарифа"
                @success="closeSpecialModal"
            />
        </Dialog>

        <ReviewsSection />

        <!-- FAQ -->
        <section id="faq" class="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
            <h2 class="mb-8 text-center text-2xl font-semibold sm:text-3xl">Часто задаваемые вопросы</h2>

            <div class="space-y-3">
                <Card
                    v-for="(item, index) in faqItems"
                    :key="item.question"
                    class="overflow-hidden border-border/70 bg-card/80 backdrop-blur"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 p-4 text-left text-sm font-medium sm:p-5 sm:text-base"
                        @click="openFaq = openFaq === index ? -1 : index"
                    >
                        {{ item.question }}
                        <span
                            class="text-muted-foreground transition"
                            :class="openFaq === index && 'rotate-45 text-primary'"
                        >+</span>
                    </button>
                    <div
                        v-show="openFaq === index"
                        class="border-t px-4 pb-4 pt-4 text-sm leading-relaxed text-muted-foreground sm:px-5 sm:pb-5 sm:pt-5"
                    >
                        {{ item.answer }}
                    </div>
                </Card>
            </div>
        </section>

        <!-- Final CTA -->
        <section class="mx-auto max-w-6xl px-4 pb-20 sm:px-6 lg:px-8">
            <Card class="border-border/70 bg-gradient-to-br from-card/95 to-primary/5 p-8 text-center shadow-xl shadow-primary/5 backdrop-blur sm:p-12">
                <h2 class="text-2xl font-semibold sm:text-3xl">
                    Дайте бизнесу инструменты для роста
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-muted-foreground">
                    <template v-if="authenticated">
                        Продолжайте работу в личном кабинете — все инструменты уже доступны.
                    </template>
                    <template v-else>
                        Присоединяйтесь сейчас — 5 дней бесплатно, все функции, без долгих внедрений.
                    </template>
                </p>
                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <Button as="a" :href="authenticated ? homeUrl : '/register'" size="lg">
                        {{ authenticated ? cabinetLabel : "Создать аккаунт" }}
                        <ArrowRight class="ml-2 h-4 w-4" />
                    </Button>
                    <Button as="a" href="/blog" variant="outline" size="lg">
                        Читать блог для селлеров
                    </Button>
                </div>
            </Card>
        </section>
    </LandingLayout>
</template>