<script setup>
import { computed } from "vue";
import RevenueStructure from "@/components/subscriber/wb/profitability/RevenueStructure.vue";
import TopLowMarginProducts from "@/components/subscriber/wb/profitability/TopLowMarginProducts.vue";
import TopProfitableProducts from "@/components/subscriber/wb/profitability/TopProfitableProducts.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    widget: { type: Object, default: null },
});

const breakdownSchema = [
    { key: "margin", label: "Прибыль", color: "bg-indigo-500" },
    { key: "correction_sales", label: "Компенсации", color: "bg-emerald-500" },
    { key: "logistics", label: "Логистика", color: "bg-purple-400" },
    { key: "purchase_cost", label: "Себестоимость", color: "bg-orange-400" },
    { key: "deduction", label: "Реклама", color: "bg-sky-500" },
    { key: "storage_fee", label: "Хранение", color: "bg-lime-500" },
    { key: "acceptance", label: "Приемка", color: "bg-blue-400" },
    { key: "penalties", label: "Штрафы", color: "bg-rose-400" },
];

function formatCurrency(value) {
    if (value == null || value === "") return "—";
    const num = Number(value);
    if (!Number.isFinite(num)) return "—";
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(num);
}

function formatDate(value, shortYear = false) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: shortYear ? "2-digit" : "numeric",
    });
}

function normalizeProductName(value) {
    if (!value) return "";
    return value.replace(/[_]+/g, " ").trim();
}

function mapProducts(source, prefix, { allowNonPositive = false } = {}) {
    const items = Array.isArray(source) ? source.slice(0, 5) : [];

    return items
        .map((item, index) => {
            const percentRaw = Number(item?.profitability_percent);
            if (!Number.isFinite(percentRaw)) return null;
            if (!allowNonPositive && percentRaw <= 0) return null;

            return {
                key: item?.nm_id ? `${prefix}-${item.nm_id}-${index}` : `${prefix}-product-${index}`,
                id: item?.nm_id ? String(item.nm_id) : "—",
                title: normalizeProductName(item?.sa_name) || "—",
                image: item?.image || null,
                total_margin: item?.total_margin != null ? Number(item.total_margin) : null,
                percentValue: percentRaw,
            };
        })
        .filter(Boolean);
}

const periodRangeText = computed(() => {
    if (!props.widget?.date_from || !props.widget?.date_to) return "";
    const from = formatDate(props.widget.date_from);
    const to = formatDate(props.widget.date_to, true);
    return `с ${from} по ${to}`;
});

const distributionStats = computed(() => {
    if (!props.widget) {
        return { minNegative: 0, maxPositive: 0, zeroOffset: 0 };
    }

    let minNegative = 0;
    let maxPositive = 0;

    for (const item of breakdownSchema) {
        const raw = Number(props.widget[item.key]);
        if (!Number.isFinite(raw)) continue;
        if (raw < minNegative) minNegative = raw;
        if (raw > maxPositive) maxPositive = raw;
    }

    const negativeSpan = Math.abs(minNegative);
    const positiveSpan = maxPositive;
    let zeroOffset = 0;

    if (negativeSpan && positiveSpan) {
        zeroOffset = (negativeSpan / (negativeSpan + positiveSpan)) * 100;
    } else if (negativeSpan && !positiveSpan) {
        zeroOffset = 100;
    }

    zeroOffset = Math.min(100, Math.max(0, zeroOffset));

    return { minNegative, maxPositive, zeroOffset };
});

const breakdownItems = computed(() => {
    if (!props.widget) return [];

    const { minNegative, maxPositive, zeroOffset: offset } = distributionStats.value;
    const negativeSpan = Math.abs(minNegative);
    const positiveSpan = maxPositive;
    const negativeAvailable = offset;
    const positiveAvailable = 100 - offset;

    return breakdownSchema
        .map((item) => {
            const raw = Number(props.widget[item.key]);
            const value = Number.isFinite(raw) ? raw : 0;
            const negativeWidth = value < 0 && negativeSpan
                ? Math.min((Math.abs(value) / negativeSpan) * negativeAvailable, negativeAvailable)
                : 0;
            const positiveWidth = value > 0 && positiveSpan
                ? Math.min((value / positiveSpan) * positiveAvailable, positiveAvailable)
                : 0;

            return {
                ...item,
                value,
                negativeWidth,
                positiveWidth,
                display: formatCurrency(value),
            };
        })
        .filter((item) => item.value !== 0);
});

const topProfitableProducts = computed(() => mapProducts(props.widget?.top_profitable_products, "profitable"));
const topLowMarginProducts = computed(() => mapProducts(props.widget?.top_low_margin_products, "low", { allowNonPositive: true }));

const hasBreakdownData = computed(() => breakdownItems.value.length > 0);
const hasProfitableProducts = computed(() => topProfitableProducts.value.length > 0);
const hasLowMarginProducts = computed(() => topLowMarginProducts.value.length > 0);
const hasAnyData = computed(() => hasBreakdownData.value || hasProfitableProducts.value || hasLowMarginProducts.value);
const showZeroAxis = computed(() => distributionStats.value.minNegative < 0);
</script>

<template>
    <Card class="p-5">
        <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold tracking-tight">Структура выручки</h2>
                <span v-if="periodRangeText" class="text-xs uppercase tracking-wide text-muted-foreground">
                    {{ periodRangeText }}
                </span>
            </div>
        </header>

        <div v-if="hasAnyData" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div class="col-span-1 md:col-span-2 xl:col-span-1">
                <RevenueStructure
                    v-if="hasBreakdownData"
                    :items="breakdownItems"
                    :zero-offset="distributionStats.zeroOffset"
                    :show-zero-axis="showZeroAxis"
                />
            </div>

            <TopProfitableProducts v-if="hasProfitableProducts" :products="topProfitableProducts" />
            <TopLowMarginProducts v-if="hasLowMarginProducts" :products="topLowMarginProducts" />
        </div>

        <div
            v-else
            class="flex flex-col items-center gap-2 rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            <p>Данных по прибыльности для выбранного кабинета пока нет.</p>
        </div>
    </Card>
</template>