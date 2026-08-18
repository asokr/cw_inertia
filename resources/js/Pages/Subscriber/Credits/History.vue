<script setup>
import { Head, Link } from "@inertiajs/vue3";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import Card from "@/components/ui/Card.vue";
import { formatCredits, formatCreditsRemaining } from "@/utils/credits";

defineProps({
    entries: { type: Array, default: () => [] },
    credits: {
        type: Object,
        default: () => ({
            available: 0,
            subscription: 0,
            purchased: 0,
            held: 0,
            plan_per_period: 0,
        }),
    },
});
</script>

<template>
    <Head title="История кредитов" />

    <SubscriberLayout
        title="История кредитов"
        :breadcrumbs="[
            { label: 'Панель', href: '/panel' },
            { label: 'Профиль', href: '/panel/user/profile' },
            { label: 'Кредиты' },
        ]"
    >
        <Card class="mb-4 p-6">
            <p class="text-sm text-muted-foreground">Доступно</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
                {{ formatCreditsRemaining(credits.available ?? 0) }}
            </p>
            <p v-if="credits.held" class="mt-2 text-sm text-amber-800 dark:text-amber-200">
                Зарезервировано: {{ formatCredits(credits.held) }}. Эти кредиты спишутся, когда ИИ закончит генерацию.
            </p>
        </Card>

        <Card class="p-0">
            <div v-if="entries.length" class="divide-y divide-border/60">
                <div
                    v-for="entry in entries"
                    :key="entry.id"
                    class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 text-sm"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">{{ entry.user_label }}</p>
                            <span
                                v-if="entry.type === 'hold'"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950/60 dark:text-amber-100"
                            >
                                Зарезервировано
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ entry.created_at }}</p>
                    </div>
                    <div class="text-right">
                        <p
                            class="font-semibold tabular-nums"
                            :class="entry.direction === 'credit' ? 'text-emerald-600' : 'text-foreground'"
                        >
                            {{ entry.direction === "credit" ? "+" : "−" }}{{ formatCredits(entry.amount) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Осталось {{ formatCredits(entry.available_after) }}
                        </p>
                    </div>
                </div>
            </div>
            <p v-else class="px-6 py-8 text-sm text-muted-foreground">Операций с кредитами пока нет.</p>
        </Card>

        <Link href="/panel/user/profile" class="mt-4 inline-block text-sm text-primary hover:underline">
            Назад в профиль
        </Link>
    </SubscriberLayout>
</template>
