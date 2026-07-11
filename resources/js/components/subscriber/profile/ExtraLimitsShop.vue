<script setup>
import { router } from "@inertiajs/vue3";
import {
    Bot,
    ImageIcon,
    MessageSquare,
    Package,
    ShoppingBag,
    Sparkles,
    Store,
    TrendingUp,
    Video,
    Wallet,
    Zap,
} from "lucide-vue-next";
import { computed, ref } from "vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { formatLimitLabel, getLimitCategory, limitCategoryMeta } from "@/utils/limitLabels";

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    userExtraLimits: { type: Object, default: () => ({}) },
    balance: { type: Number, default: 0 },
});

const purchasingId = ref(null);
const confirmItem = ref(null);
const confirmOpen = ref(false);

const activeLimits = computed(() =>
    Object.entries(props.userExtraLimits ?? {})
        .map(([key, value]) => [key, Number(value)])
        .filter(([, value]) => Number.isFinite(value) && value > 0)
);

const groupedCatalog = computed(() => {
    const groups = new Map();

    for (const item of props.catalog) {
        const category = getLimitCategory(item.limit_name);

        if (!groups.has(category)) {
            groups.set(category, []);
        }

        groups.get(category).push(item);
    }

    return [...groups.entries()]
        .map(([category, items]) => ({
            category,
            label: limitCategoryMeta[category]?.label ?? category,
            order: limitCategoryMeta[category]?.order ?? 99,
            items: [...items].sort((a, b) => Number(a.price) - Number(b.price)),
        }))
        .sort((a, b) => a.order - b.order);
});

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(props.balance ?? 0)
    )
);

function formatPrice(price) {
    return new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(price ?? 0)
    );
}

function ownedQuantity(limitName) {
    return Number(props.userExtraLimits?.[limitName] ?? 0);
}

function canAfford(price) {
    return Number(props.balance ?? 0) >= Number(price ?? 0);
}

function shortfall(price) {
    return Math.max(0, Math.ceil(Number(price ?? 0) - Number(props.balance ?? 0)));
}

function limitIcon(limitName) {
    const icons = {
        feedbacks_gpt_query: MessageSquare,
        ai_text_query: Bot,
        ai_image_query: ImageIcon,
        ai_video_query: Video,
        feedbacks_clients: MessageSquare,
        oz_feedbacks_clients: MessageSquare,
        price_calc_clients: TrendingUp,
        oz_price_calc_clients: TrendingUp,
        repricer_nmid: Package,
        adverts_clients: Zap,
    };

    return icons[limitName] ?? Sparkles;
}

function categoryIcon(category) {
    const icons = {
        ai: Sparkles,
        wb: Store,
        ozon: ShoppingBag,
        other: Package,
    };

    return icons[category] ?? Package;
}

const categoryThemes = {
    ai: {
        accent: "border-l-violet-500/50",
        icon: "bg-violet-500/10 text-violet-600 dark:text-violet-400",
        quantity: "bg-violet-500/10 text-violet-700 dark:text-violet-300",
    },
    wb: {
        accent: "border-l-primary/50",
        icon: "bg-primary/10 text-primary",
        quantity: "bg-primary/10 text-primary",
    },
    ozon: {
        accent: "border-l-sky-500/50",
        icon: "bg-sky-500/10 text-sky-600 dark:text-sky-400",
        quantity: "bg-sky-500/10 text-sky-700 dark:text-sky-300",
    },
    other: {
        accent: "border-l-border",
        icon: "bg-muted text-muted-foreground",
        quantity: "bg-muted text-foreground",
    },
};

function categoryTheme(category) {
    return categoryThemes[category] ?? categoryThemes.other;
}

function scrollToBalance() {
    document.getElementById("balance-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function openConfirm(item) {
    if (!canAfford(item.price)) {
        scrollToBalance();
        return;
    }

    confirmItem.value = item;
    confirmOpen.value = true;
}

function closeConfirm() {
    confirmOpen.value = false;
    confirmItem.value = null;
}

function buyExtraLimit(id) {
    purchasingId.value = id;
    closeConfirm();

    router.post(
        "/panel/user/extra-limits",
        { id },
        {
            preserveScroll: true,
            onFinish: () => {
                purchasingId.value = null;
            },
        }
    );
}
</script>

<template>
    <Card v-if="catalog.length" class="mt-6 border-border/70 p-6">
        <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold">Дополнительные лимиты</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Докупите лимиты к текущему тарифу — списание с баланса
                </p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <Wallet class="h-4 w-4 text-muted-foreground" />
                <span class="text-muted-foreground">Баланс:</span>
                <span class="font-semibold tabular-nums">{{ formattedBalance }}</span>
            </div>
        </div>

        <div v-if="activeLimits.length" class="mb-5 flex flex-wrap gap-2">
            <span
                v-for="[key, value] in activeLimits"
                :key="key"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-muted/40 px-2.5 py-1 text-xs"
            >
                <span class="text-muted-foreground">{{ formatLimitLabel(key) }}</span>
                <span class="font-semibold tabular-nums">+{{ value }}</span>
            </span>
        </div>

        <Alert variant="default" class="mb-6 text-sm leading-relaxed">
            Лимиты сохраняются на подписке и используются после исчерпания основного лимита тарифа.
        </Alert>

        <div class="space-y-8">
            <section
                v-for="group in groupedCatalog"
                :key="group.category"
                class="rounded-xl border border-border/70 border-l-[3px] bg-muted/20 p-4"
                :class="categoryTheme(group.category).accent"
            >
                <div class="mb-4 flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                        :class="categoryTheme(group.category).icon"
                    >
                        <component :is="categoryIcon(group.category)" class="h-4 w-4" />
                    </div>
                    <h3 class="text-sm font-semibold">{{ group.label }}</h3>
                    <span class="text-xs text-muted-foreground">· {{ group.items.length }}</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="item in group.items"
                        :key="item.id"
                        class="flex flex-col rounded-xl border border-border/70 bg-card p-4"
                    >
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                    :class="categoryTheme(group.category).icon"
                                >
                                    <component :is="limitIcon(item.limit_name)" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-sm font-medium leading-snug">{{ formatLimitLabel(item.limit_name) }}</h4>
                                    <p
                                        v-if="ownedQuantity(item.limit_name) > 0"
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        Куплено +{{ ownedQuantity(item.limit_name) }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="shrink-0 rounded-lg px-2.5 py-1 text-center"
                                :class="categoryTheme(group.category).quantity"
                            >
                                <span class="text-xl font-bold leading-none tabular-nums">+{{ item.quantity }}</span>
                            </div>
                        </div>

                        <div class="mt-auto flex items-center justify-between gap-3 border-t border-border/60 pt-3">
                            <div>
                                <p class="text-base font-semibold tabular-nums">{{ formatPrice(item.price) }}</p>
                                <p
                                    v-if="!canAfford(item.price)"
                                    class="text-xs text-amber-700 dark:text-amber-300"
                                >
                                    −{{ shortfall(item.price).toLocaleString("ru-RU") }} ₽
                                </p>
                            </div>
                            <Button
                                size="sm"
                                :variant="canAfford(item.price) ? 'default' : 'outline'"
                                :disabled="purchasingId === item.id"
                                @click="openConfirm(item)"
                            >
                                <span v-if="purchasingId === item.id">...</span>
                                <span v-else-if="canAfford(item.price)">Купить</span>
                                <span v-else>Пополнить</span>
                            </Button>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <Dialog
            :open="confirmOpen"
            title="Подтвердите покупку"
            :description="
                confirmItem
                    ? `${formatLimitLabel(confirmItem.limit_name)} +${confirmItem.quantity} за ${formatPrice(confirmItem.price)}`
                    : ''
            "
            @update:open="confirmOpen = $event"
        >
            <div v-if="confirmItem" class="space-y-4">
                <div class="rounded-xl border border-border/70 bg-muted/40 p-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Списание с баланса</span>
                        <span class="font-semibold tabular-nums">{{ formatPrice(confirmItem.price) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Останется на балансе</span>
                        <span class="font-medium tabular-nums">
                            {{
                                formatPrice(
                                    Math.max(0, Number(balance ?? 0) - Number(confirmItem.price ?? 0))
                                )
                            }}
                        </span>
                    </div>
                </div>

                <p
                    v-if="!canAfford(confirmItem.price)"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200"
                >
                    Недостаточно средств. Пополните баланс в блоке выше, затем повторите покупку.
                </p>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="closeConfirm">Отмена</Button>
                    <Button
                        :disabled="!canAfford(confirmItem.price) || purchasingId === confirmItem.id"
                        @click="buyExtraLimit(confirmItem.id)"
                    >
                        {{ purchasingId === confirmItem.id ? "Оформляем..." : "Подтвердить покупку" }}
                    </Button>
                </div>
            </div>
        </Dialog>
    </Card>
</template>