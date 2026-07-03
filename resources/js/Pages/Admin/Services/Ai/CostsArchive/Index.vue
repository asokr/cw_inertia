<script setup>
import { Head, router } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Card from "@/components/ui/Card.vue";

import { ref, watch } from "vue";

const props = defineProps({
    items: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const localFilters = ref({ ...props.filters });

watch(() => props.filters, (value) => {
    localFilters.value = { ...value };
}, { deep: true });

const currencyFormatter = new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "USD",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const formatMoney = (value) => currencyFormatter.format(Number(value || 0));

const columns = [
    { accessorKey: "date", header: "Дата", cell: ({ row }) => row.original.date },
    { accessorKey: "gpt", header: "GPT", cell: ({ row }) => formatMoney(row.original.gpt) },
    { accessorKey: "gemini", header: "Gemini", cell: ({ row }) => formatMoney(row.original.gemini) },
    { accessorKey: "grok", header: "Grok", cell: ({ row }) => formatMoney(row.original.grok) },
    {
        accessorKey: "total",
        header: "Всего",
        cell: ({ row }) => formatMoney(row.original.total),
    },
];

function applyFilters() {
    router.get("/cw-page/services/ai/costs-archive", {
        date_from: localFilters.value.date_from,
        date_to: localFilters.value.date_to,
    }, { preserveState: true });
}

function setCurrentMonth() {
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    const fmt = (d) => d.toISOString().slice(0, 10);

    router.get("/cw-page/services/ai/costs-archive", {
        date_from: fmt(from),
        date_to: fmt(to),
    });
}
</script>

<template>
    <Head title="Архив расходов AI" />

    <AdminLayout
        title="Архив расходов AI"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Архив расходов AI' }]"
    >
        <PageHeader title="Архив расходов AI" description="Расходы по провайдерам за выбранный период" />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">Дата от</label>
                    <Input v-model="localFilters.date_from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Дата до</label>
                    <Input v-model="localFilters.date_to" type="date" />
                </div>
                <div class="flex items-end gap-2">
                    <Button @click="applyFilters">Применить</Button>
                    <Button variant="outline" @click="setCurrentMonth">Текущий месяц</Button>
                </div>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="items" />
            <div class="mt-4 flex flex-wrap gap-4 border-t pt-3 text-sm">
                <span>Итог за период:</span>
                <span>GPT: {{ formatMoney(totals.gpt) }}</span>
                <span>Gemini: {{ formatMoney(totals.gemini) }}</span>
                <span>Grok: {{ formatMoney(totals.grok) }}</span>
                <span class="font-semibold">Всего: {{ formatMoney(totals.total) }}</span>
            </div>
        </Card>
    </AdminLayout>
</template>