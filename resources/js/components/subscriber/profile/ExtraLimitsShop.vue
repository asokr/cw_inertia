<script setup>
import { router } from "@inertiajs/vue3";
import { Wallet } from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import { formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    userExtraLimits: { type: Object, default: () => ({}) },
    balance: { type: Number, default: 0 },
});

const quantityPresets = [100, 300, 500, 1000, 5000];

const selectedId = ref(props.catalog[0]?.id ?? null);
const quantity = ref(100);
const purchasing = ref(false);
const confirmOpen = ref(false);

watch(
    () => props.catalog,
    (items) => {
        if (!items?.length) {
            selectedId.value = null;
            return;
        }
        const stillValid = items.some((item) => item.id === selectedId.value);
        if (!stillValid) {
            selectedId.value = items[0].id;
        }
    },
    { immediate: true }
);

const selectedItem = computed(() => props.catalog.find((item) => item.id === selectedId.value) ?? null);

const unitPrice = computed(() => Number(selectedItem.value?.price ?? 0));

const parsedQuantity = computed(() => {
    const n = Number(quantity.value);
    return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
});

const totalPrice = computed(() => Math.round(parsedQuantity.value * unitPrice.value * 100) / 100);

const activeLimits = computed(() =>
    Object.entries(props.userExtraLimits ?? {})
        .map(([key, value]) => [key, Number(value)])
        .filter(([, value]) => Number.isFinite(value) && value > 0)
);

const nameBySlug = computed(() => {
    const map = {};
    for (const item of props.catalog) {
        if (item.slug) {
            map[item.slug] = item.name;
        }
    }
    return map;
});

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(props.balance ?? 0)
    )
);

function formatPrice(price) {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 2,
    }).format(Number(price ?? 0));
}

function limitNameBySlug(slug) {
    const fromCatalog = nameBySlug.value[slug];
    if (typeof fromCatalog === "string" && fromCatalog !== "" && fromCatalog !== slug) {
        return fromCatalog;
    }

    return formatLimitLabel(slug);
}

function canAfford(total) {
    return Number(props.balance ?? 0) >= Number(total ?? 0);
}

function shortfall(total) {
    return Math.max(0, Math.ceil(Number(total ?? 0) - Number(props.balance ?? 0)));
}

function selectQuantity(preset) {
    quantity.value = preset;
}

function isPresetActive(preset) {
    return parsedQuantity.value === preset;
}

function scrollToBalance() {
    document.getElementById("balance-section")?.scrollIntoView({ behavior: "smooth", block: "start" });
}

function openConfirm() {
    if (!selectedItem.value || parsedQuantity.value < 1) {
        return;
    }
    if (!canAfford(totalPrice.value)) {
        scrollToBalance();
        return;
    }
    confirmOpen.value = true;
}

function closeConfirm() {
    confirmOpen.value = false;
}

function buyExtraLimit() {
    if (!selectedItem.value || parsedQuantity.value < 1) {
        return;
    }

    purchasing.value = true;
    closeConfirm();

    router.post(
        "/panel/user/extra-limits",
        {
            id: selectedItem.value.id,
            quantity: parsedQuantity.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                purchasing.value = false;
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
                    Докупите лимиты к текущему тарифу
                </p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <Wallet class="h-4 w-4 text-muted-foreground" />
                <span class="text-muted-foreground">Баланс:</span>
                <span class="font-semibold tabular-nums">{{ formattedBalance }}</span>
            </div>
        </div>

        <div v-if="activeLimits.length" class="mb-5 flex flex-wrap gap-2">
            <span v-for="[key, value] in activeLimits" :key="key"
                class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-muted/40 px-2.5 py-1 text-xs">
                <span class="text-muted-foreground">{{ limitNameBySlug(key) }}</span>
                <span class="font-semibold tabular-nums">+{{ value }}</span>
            </span>
        </div>

        <Alert variant="default" class="mb-6 text-sm leading-relaxed">
            Лимиты сохраняются на аккаунте и используются после исчерпания основного лимита тарифа.
        </Alert>

        <form class="max-w-lg space-y-4" @submit.prevent="openConfirm">
            <div class="space-y-2">
                <Label for="extra-limit-type">Тип лимита</Label>
                <Select id="extra-limit-type" :model-value="selectedId" class="h-10"
                    @update:model-value="selectedId = Number($event)">
                    <option v-for="item in catalog" :key="item.id" :value="item.id">
                        {{ item.name }} — {{ formatPrice(item.price) }}/шт
                    </option>
                </Select>
            </div>

            <div class="space-y-2">
                <Label>Количество</Label>
                <div class="flex flex-wrap gap-2">
                    <button v-for="preset in quantityPresets" :key="preset" type="button"
                        class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors" :class="isPresetActive(preset)
                            ? 'border-primary bg-primary/10 font-medium text-primary'
                            : 'border-border/70 text-muted-foreground hover:border-border hover:bg-muted/50'
                            " @click="selectQuantity(preset)">
                        {{ preset.toLocaleString("ru-RU") }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[8rem] flex-1 space-y-1">
                    <Label for="extra-limit-qty">Своё количество</Label>
                    <Input id="extra-limit-qty" v-model.number="quantity" type="number" min="1" step="1"
                        class="tabular-nums" />
                </div>
                <Button type="submit" class="self-end" :disabled="purchasing || !selectedItem || parsedQuantity < 1"
                    :variant="canAfford(totalPrice) ? 'default' : 'outline'">
                    <span v-if="purchasing">...</span>
                    <span v-else-if="canAfford(totalPrice)">Купить</span>
                    <span v-else>Пополнить</span>
                </Button>
            </div>

            <div class="rounded-xl border border-border/70 bg-muted/30 px-4 py-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-muted-foreground">Итого к списанию</span>
                    <span class="text-lg font-semibold tabular-nums">{{ formatPrice(totalPrice) }}</span>
                </div>
                <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        {{ parsedQuantity.toLocaleString("ru-RU") }} × {{ formatPrice(unitPrice) }}
                    </span>
                    <span v-if="!canAfford(totalPrice)" class="font-medium text-amber-700 dark:text-amber-300">
                        Не хватает {{ shortfall(totalPrice).toLocaleString("ru-RU") }} ₽
                    </span>
                </div>
            </div>
        </form>

        <Dialog :open="confirmOpen" title="Подтвердите покупку" :description="selectedItem
            ? `${selectedItem.name} +${parsedQuantity.toLocaleString('ru-RU')} за ${formatPrice(totalPrice)}`
            : ''
            " @update:open="confirmOpen = $event">
            <div v-if="selectedItem" class="space-y-4">
                <div class="rounded-xl border border-border/70 bg-muted/40 p-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Лимит</span>
                        <span class="font-medium text-right">{{ selectedItem.name }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Количество</span>
                        <span class="font-semibold tabular-nums">
                            +{{ parsedQuantity.toLocaleString("ru-RU") }}
                        </span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3 border-t border-border/60 pt-2">
                        <span class="text-muted-foreground">Списание с баланса</span>
                        <span class="font-semibold tabular-nums">{{ formatPrice(totalPrice) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Останется на балансе</span>
                        <span class="font-medium tabular-nums">
                            {{
                                formatPrice(
                                    Math.max(0, Number(balance ?? 0) - Number(totalPrice ?? 0))
                                )
                            }}
                        </span>
                    </div>
                </div>

                <p v-if="!canAfford(totalPrice)"
                    class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
                    Недостаточно средств. Пополните баланс в блоке выше, затем повторите покупку.
                </p>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="closeConfirm">Отмена</Button>
                    <Button :disabled="!canAfford(totalPrice) || purchasing || parsedQuantity < 1"
                        @click="buyExtraLimit">
                        {{ purchasing ? "Оформляем..." : "Подтвердить покупку" }}
                    </Button>
                </div>
            </div>
        </Dialog>
    </Card>
</template>
