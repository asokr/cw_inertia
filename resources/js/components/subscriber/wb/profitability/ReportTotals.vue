<script setup>
import { computed } from "vue";
import { Download } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFileDownload } from "@/composables/useFileDownload";

const props = defineProps({
    report: { type: Object, required: true },
    exportUrl: { type: String, required: true },
});

const { downloading, downloadGet } = useFileDownload();

const formatNumber = (value) => Number(value ?? 0).toLocaleString("ru-RU");

const surchargeBreakdown = computed(() => {
    const items = [
        { label: "Удержания", value: Number(props.report.deduction) || 0 },
        { label: "Штрафы", value: Number(props.report.penalties) || 0 },
        { label: "Хранение", value: Number(props.report.storage_fee) || 0 },
    ];

    const cashback = Number(props.report.cashback) || 0;
    if (cashback > 0) {
        items.push({ label: "Кэшбэк", value: cashback });
    }

    return items;
});

const surcharges = computed(() => surchargeBreakdown.value.reduce((sum, item) => sum + item.value, 0));

async function downloadReport() {
    const from = props.report.date_from ?? "from";
    const to = props.report.date_to ?? "to";
    await downloadGet(props.exportUrl, `profitability_${from}_${to}.xlsx`);
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <p class="text-xl">
                Отчёт за <strong>{{ report.date_from }}</strong> — <strong>{{ report.date_to }}</strong>
            </p>
            <Button class="mt-3" size="sm" variant="outline" :disabled="downloading" @click="downloadReport">
                <Download class="mr-1.5 h-4 w-4" />
                Скачать отчёт
            </Button>
        </div>

        <div class="flex flex-wrap gap-3">
            <Card class="w-64 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Продажи</h3>
                <p class="text-xl font-medium">
                    {{ report.sales_quantity }} ед. на {{ formatNumber(report.sales_amount) }}
                </p>
            </Card>

            <Card class="w-64 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Возвраты</h3>
                <p class="text-xl font-medium">
                    {{ report.returns_quantity }} ед. на {{ formatNumber(report.returns_amount) }}
                </p>
            </Card>

            <Card class="w-32 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Выкуп</h3>
                <p class="text-xl font-medium">{{ report.percent_buy }} %</p>
            </Card>

            <Card class="w-80 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="mb-2 text-sm text-muted-foreground">Доп.расходы и доплаты</h3>
                        <p class="text-xl font-medium">{{ formatNumber(surcharges) }}</p>
                    </div>
                    <ul class="space-y-1 text-xs text-muted-foreground">
                        <li v-for="item in surchargeBreakdown" :key="item.label" class="flex justify-between gap-2">
                            <span>{{ item.label }}</span>
                            <span class="font-medium text-foreground">{{ formatNumber(item.value) }}</span>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card class="w-40 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Логистика</h3>
                <p class="text-xl font-medium">{{ formatNumber(report.logistics) }}</p>
            </Card>

            <Card class="w-40 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Себестоимость</h3>
                <p class="text-xl font-medium">{{ Math.round(report.purchase_cost).toLocaleString("ru-RU") }}</p>
            </Card>

            <Card class="w-40 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Итог</h3>
                <p class="text-xl font-medium">{{ formatNumber(report.itog) }}</p>
            </Card>

            <Card class="w-40 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Маржинальность</h3>
                <p class="text-xl font-medium">{{ formatNumber(report.margin) }}</p>
            </Card>

            <Card class="w-40 p-4">
                <h3 class="mb-2 text-sm text-muted-foreground">Рентабельность</h3>
                <p class="text-xl font-semibold">{{ report.total_profitability }} %</p>
            </Card>
        </div>
    </div>
</template>