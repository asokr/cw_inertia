<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const page = usePage();
const open = ref(false);

const costs = computed(() => page.props.aiCostsToday ?? null);

const currencyFormatter = new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const buttonLabel = computed(() => {
    const total = Number(costs.value?.total ?? 0);
    return `AI: ${currencyFormatter.format(total)}`;
});

const providerLabel = (provider) => ({
    gpt: "GPT",
    gemini: "Gemini",
    grok: "Grok",
}[provider] ?? provider);
</script>

<template>
    <div v-if="costs" class="relative">
        <Button variant="outline" size="sm" class="rounded-full" @click="open = !open">
            {{ buttonLabel }}
        </Button>
        <Card v-if="open" class="absolute right-0 top-full z-50 mt-2 min-w-[220px] p-3 shadow-lg">
            <p class="mb-2 text-xs text-muted-foreground">Расход AI за сегодня</p>
            <div
                v-for="item in costs.providers"
                :key="item.provider"
                class="flex items-center justify-between py-0.5 text-sm"
            >
                <span>{{ providerLabel(item.provider) }}</span>
                <span>{{ currencyFormatter.format(Number(item.cost || 0)) }}</span>
            </div>
            <div class="mt-2 flex items-center justify-between border-t pt-2 text-sm font-semibold">
                <span>Всего</span>
                <span>{{ currencyFormatter.format(Number(costs.total || 0)) }}</span>
            </div>
            <Link href="/cw-page/services/ai/costs-archive" class="mt-2 block text-xs text-primary hover:underline">
                Архив →
            </Link>
        </Card>
    </div>
</template>