<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { Maximize2, PauseCircle, PlayCircle, X } from "lucide-vue-next";
import HistorySelectionChart from "@/components/subscriber/wb/stock-history/HistorySelectionChart.vue";
import OrdersHistoryTable from "@/components/subscriber/wb/stock-history/OrdersHistoryTable.vue";
import StocksHistoryTable from "@/components/subscriber/wb/stock-history/StocksHistoryTable.vue";
import JobProgressPanel from "@/components/ui/JobProgressPanel.vue";
import {
    resolveHistoryProgressPercent,
    WB_ORDER_HISTORY_STAGES,
    WB_STOCK_HISTORY_STAGES,
} from "@/config/wbStockHistoryJobStages";
import {
    orderHistoryRowKey,
    orderHistoryRowLabel,
    stockHistoryRowKey,
    stockHistoryRowLabel,
} from "@/utils/wbStockHistoryRows";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsContent from "@/components/ui/TabsContent.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useToolPoll } from "@/composables/useToolPoll";

const props = defineProps({
    cabinet: { type: Object, required: true },
    tab: { type: String, default: "stocks" },
    tracking: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    dates: { type: Array, default: () => [] },
    loadedDates: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    rowsMeta: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "История остатков и заказов" },
];

const baseUrl = "/panel/wb/stock-history";
const { showError, showSuccess } = useFlashToast();

// Вкладка заказов скрыта в интерфейсе; маршруты и сбор данных остаются.
const showOrdersTab = false;
const activeTab = ref(props.tab === "orders" ? "orders" : "stocks");
const subjectInput = ref(props.filters.subject ?? "");
const vendorInput = ref(props.filters.vendor_code ?? "");
const nmInput = ref(props.filters.nm_id ?? "");
const sizeInput = ref(props.filters.tech_size ?? "");
const barcodeInput = ref(props.filters.barcode ?? "");
const fromInput = ref(props.filters.from ?? "");
const toInput = ref(props.filters.to ?? "");
const retentionDays = ref(props.tracking.retention_days ?? 90);
const busy = ref(false);
const fullscreen = ref(false);
const loadOpen = ref(false);
const selectedStocks = ref({});
const selectedOrders = ref({});

const tabState = computed(() => (
    activeTab.value === "orders" ? props.tracking.orders : props.tracking.stocks
) || {});

const tabJob = computed(() => tabState.value.job || {});
const isLoading = computed(() => Boolean(tabState.value.is_loading) || tabJob.value.status === "processing");
const isTracking = computed(() => Boolean(tabState.value.tracking_enabled));
const hasHistory = computed(() => Boolean(tabState.value.has_history));
const lastError = computed(() => tabState.value.last_error || tabJob.value.error || "");
const jobStages = computed(() => (
    activeTab.value === "orders" ? WB_ORDER_HISTORY_STAGES : WB_STOCK_HISTORY_STAGES
));
const showJobProgress = computed(() => (
    isLoading.value || tabJob.value.status === "failed"
));
const canStartTracking = computed(() => !isTracking.value && !isLoading.value);
const canStopTracking = computed(() => isTracking.value && !isLoading.value);
const jobProgressTitle = computed(() => (
    activeTab.value === "orders" ? "Собираем историю заказов" : "Собираем историю остатков"
));

const isRetentionSaved = computed(() => (
    Number(retentionDays.value) === Number(props.tracking.retention_days)
));

const latestHistoryDate = computed(() => props.tracking.today || "");

const poll = useToolPoll(2500, {
    requestOptions: {
        only: ["tracking", "rows", "rowsMeta", "dates", "loadedDates", "filters", "tab"],
        preserveState: true,
        preserveScroll: true,
    },
    isComplete: (pageProps) => {
        const tracking = pageProps.tracking || {};
        if (tracking.is_loading) {
            return false;
        }
        const tab = pageProps.tab === "orders" ? tracking.orders : tracking.stocks;
        return tab?.job?.status !== "processing";
    },
});



watch(
    () => [props.tracking.is_loading, tabJob.value.status],
    ([loading, status]) => {
        if (loading || status === "processing") {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);



watch(
    () => props.tab,
    (value) => {
        activeTab.value = value === "orders" ? "orders" : "stocks";
    },
);

watch(
    () => props.tracking.retention_days,
    (value) => {
        if (typeof value === "number") {
            retentionDays.value = value;
        }
    },
);

let searchTimeout;
function scheduleReload() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ page: 1 }), 400);
}

watch([subjectInput, vendorInput, nmInput, sizeInput, barcodeInput], scheduleReload);

function queryParams(overrides = {}) {
    return {
        tab: overrides.tab ?? activeTab.value,
        from: overrides.from ?? fromInput.value ?? props.filters.from ?? "",
        to: overrides.to ?? toInput.value ?? props.filters.to ?? "",
        page: overrides.page ?? props.filters.page ?? 1,
        subject: overrides.subject ?? subjectInput.value ?? "",
        vendor_code: overrides.vendor_code ?? vendorInput.value ?? "",
        nm_id: overrides.nm_id ?? nmInput.value ?? "",
        tech_size: overrides.tech_size ?? sizeInput.value ?? "",
        barcode: overrides.barcode ?? barcodeInput.value ?? "",
    };
}

function reload(overrides = {}) {
    router.get(baseUrl, queryParams(overrides), {
        only: ["rows", "rowsMeta", "filters", "dates", "loadedDates", "tracking", "tab"],
        preserveState: true,
        preserveScroll: true,
    });
}

function changeTab(value) {
    if (value === activeTab.value) {
        return;
    }
    activeTab.value = value;
    reload({ tab: value, page: 1 });
}

function applyPeriod() {
    reload({ from: fromInput.value, to: toInput.value, page: 1 });
}

function postAction(url, payload, onSuccess) {
    busy.value = true;
    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            poll.start();
            onSuccess?.();
        },
        onError: (errors) => {
            const first = Object.values(errors || {})[0];
            showError(first || "Не удалось выполнить действие");
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function openLoadDialog() {
    loadOpen.value = true;
}

function startTracking() {
    if (!hasHistory.value) {
        openLoadDialog();
        return;
    }
    const url = activeTab.value === "orders"
        ? `${baseUrl}/orders/start`
        : `${baseUrl}/stocks/start`;
    postAction(url, {}, () => {
        showSuccess("История будет обновляться автоматически");
    });
}

function stopTracking() {
    const url = activeTab.value === "orders"
        ? `${baseUrl}/orders/stop`
        : `${baseUrl}/stocks/stop`;
    postAction(url, {}, () => {
        showSuccess("Автообновление остановлено");
    });
}

function submitLoad() {
    const url = activeTab.value === "orders"
        ? `${baseUrl}/orders/load`
        : `${baseUrl}/stocks/load`;
    postAction(url, {}, () => {
        loadOpen.value = false;
        showSuccess(activeTab.value === "orders"
            ? "Собираем историю заказов"
            : "Собираем историю остатков");
    });
}

function saveRetention() {
    busy.value = true;
    router.put(`${baseUrl}/settings`, {
        retention_days: Number(retentionDays.value),
    }, {
        preserveScroll: true,
        onSuccess: () => showSuccess("Срок хранения обновлён"),
        onError: (errors) => {
            showError(errors.retention_days || "Не удалось сохранить настройки");
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function onRetentionConfirm(checked) {
    if (!checked || isRetentionSaved.value || busy.value) {
        return;
    }
    saveRetention();
}

function changePage(page) {
    reload({ page });
}

function currentRowKey(row) {
    return activeTab.value === "orders" ? orderHistoryRowKey(row) : stockHistoryRowKey(row);
}

function currentRowLabel(row) {
    return activeTab.value === "orders" ? orderHistoryRowLabel(row) : stockHistoryRowLabel(row);
}

const selectedMap = computed(() => (
    activeTab.value === "orders" ? selectedOrders.value : selectedStocks.value
));

const selectedCount = computed(() => Object.keys(selectedMap.value).length);

const chartItems = computed(() => Object.entries(selectedMap.value).map(([key, row]) => ({
    key,
    label: currentRowLabel(row),
    series: row.series || [],
})));

function setSelectedMap(next) {
    if (activeTab.value === "orders") {
        selectedOrders.value = next;
        return;
    }
    selectedStocks.value = next;
}

function toggleRow(row) {
    const key = currentRowKey(row);
    const next = { ...selectedMap.value };
    if (next[key]) {
        delete next[key];
    } else {
        next[key] = row;
    }
    setSelectedMap(next);
}

function togglePage(checked) {
    const next = { ...selectedMap.value };
    for (const row of props.rows) {
        const key = currentRowKey(row);
        if (checked) {
            next[key] = row;
        } else {
            delete next[key];
        }
    }
    setSelectedMap(next);
}

function clearSelection() {
    setSelectedMap({});
}

watch(() => props.cabinet.id, () => {
    selectedStocks.value = {};
    selectedOrders.value = {};
});

const datesSignature = computed(() => (props.dates || []).join(","));

watch(
    [() => props.rows, datesSignature],
    ([rows, dates], previous) => {
        const current = selectedMap.value;
        if (Object.keys(current).length === 0) {
            return;
        }
        const oldDates = previous?.[1];
        const byKey = {};
        for (const row of rows) {
            byKey[currentRowKey(row)] = row;
        }
        const next = {};
        for (const [key, row] of Object.entries(current)) {
            if (byKey[key]) {
                next[key] = byKey[key];
            } else if (dates === oldDates) {
                next[key] = row;
            }
        }
        setSelectedMap(next);
    },
);

watch(fullscreen, (open) => {
    document.body.style.overflow = open ? "hidden" : "";
});

function onFullscreenKeydown(event) {
    if (event.key === "Escape" && fullscreen.value) {
        fullscreen.value = false;
    }
}

onMounted(() => document.addEventListener("keydown", onFullscreenKeydown));
onUnmounted(() => {
    document.removeEventListener("keydown", onFullscreenKeydown);
    document.body.style.overflow = "";
    clearTimeout(searchTimeout);
});
</script>

<template>
    <Head :title="`История остатков и заказов — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="История остатков и заказов"
            description="Смотрите, как менялись остатки товаров каждый день"
        />

        <div class="space-y-4">
            <Tabs :model-value="activeTab" @update:model-value="changeTab">
                <TabsList v-if="showOrdersTab">
                    <TabsTrigger value="stocks">История остатков</TabsTrigger>
                    <TabsTrigger value="orders">История заказов</TabsTrigger>
                </TabsList>

                <Card :class="showOrdersTab ? 'mt-4 p-4 sm:p-5' : 'p-4 sm:p-5'">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="space-y-2">
                            <h3 class="font-medium">История данных</h3>
                            <p class="max-w-xl text-sm text-muted-foreground">
                                <template v-if="activeTab === 'orders'">
                                    Соберём историю заказов за последние 2 месяца и продолжим автоматически обновлять её каждый день.
                                </template>
                                <template v-else>
                                    Соберём историю остатков за последние 2 месяца и продолжим автоматически обновлять её каждый день.
                                </template>
                            </p>
                            <p v-if="isTracking" class="text-sm">
                                {{ activeTab === "orders"
                                    ? "История заказов обновляется автоматически каждый день."
                                    : "История остатков обновляется автоматически каждый день." }}
                            </p>
                            <p v-else-if="hasHistory" class="text-sm text-muted-foreground">
                                Автообновление остановлено. Уже собранная история остаётся на экране.
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="canStartTracking"
                                :disabled="busy || isLoading"
                                @click="startTracking"
                            >
                                <PlayCircle class="mr-2 h-4 w-4" />
                                {{ hasHistory ? "Включить снова" : "Начать отслеживание" }}
                            </Button>
                            <Button
                                v-if="canStopTracking"
                                variant="outline"
                                :disabled="busy"
                                @click="stopTracking"
                            >
                                <PauseCircle class="mr-2 h-4 w-4" />
                                Остановить отслеживание
                            </Button>
                        </div>
                    </div>

                    <JobProgressPanel
                        v-if="showJobProgress"
                        class="mt-4"
                        :title="jobProgressTitle"
                        :stages="jobStages"
                        :current-stage="tabJob.stage || 'queued'"
                        :status-label="tabJob.status_label"
                        :progress-percent="resolveHistoryProgressPercent(tabJob)"
                        waiting-hint="Можно закрыть страницу — мы продолжим сбор данных в фоновом режиме. Когда история будет готова, она появится здесь."
                        :started-at="tabJob.started_at"
                        :failed="tabJob.status === 'failed'"
                        :error="tabJob.error || lastError"
                        :completed="false"
                    />

                    <Alert v-if="lastError && !isLoading && tabJob.status !== 'failed'" class="mt-4" variant="warning">
                        {{ lastError }}
                    </Alert>

                    <div class="mt-5 space-y-1.5 border-t pt-4">
                        <Label>Период хранения истории</Label>
                        <p class="text-xs text-muted-foreground">
                            Выберите, сколько дней показывать в истории. Более ранние данные будут удаляться автоматически.
                        </p>
                        <div class="flex w-fit items-center gap-2">
                            <div class="w-16 shrink-0">
                                <Input
                                    v-model="retentionDays"
                                    type="number"
                                    min="7"
                                    max="180"
                                    :disabled="busy"
                                />
                            </div>
                            <label class="flex cursor-pointer items-center gap-2 whitespace-nowrap text-sm">
                                <Checkbox
                                    :model-value="isRetentionSaved"
                                    :disabled="busy || isRetentionSaved"
                                    @update:model-value="onRetentionConfirm"
                                />
                                Сохранить
                            </label>
                        </div>
                    </div>
                </Card>

                <TabsContent value="stocks" class="mt-0 space-y-4">
                    <template v-if="hasHistory && activeTab === 'stocks'">
                        <Card v-show="!fullscreen" class="p-4">
                            <div class="grid gap-3 lg:grid-cols-7">
                                <div class="space-y-1.5">
                                    <Label>Предмет</Label>
                                    <Input v-model="subjectInput" placeholder="Например, футболки" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Артикул продавца</Label>
                                    <Input v-model="vendorInput" placeholder="Артикул" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Артикул WB</Label>
                                    <Input v-model="nmInput" placeholder="Номер" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Размер</Label>
                                    <Input v-model="sizeInput" placeholder="Размер" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Баркод</Label>
                                    <Input v-model="barcodeInput" placeholder="Баркод" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>С</Label>
                                    <Input v-model="fromInput" type="date" :max="latestHistoryDate" @change="applyPeriod" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>По</Label>
                                    <Input v-model="toInput" type="date" :max="latestHistoryDate" @change="applyPeriod" />
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-muted-foreground">
                                    Отметьте строки, чтобы построить график внизу.
                                </p>
                                <Button
                                    v-if="rows.length > 0"
                                    size="sm"
                                    variant="outline"
                                    @click="fullscreen = true"
                                >
                                    <Maximize2 class="mr-1 h-4 w-4" />
                                    На весь экран
                                </Button>
                            </div>
                        </Card>

                        <div v-if="rows.length === 0" class="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
                            Нет товаров с остатками за выбранный период.
                        </div>
                    </template>
                    <Card v-else-if="!isLoading && activeTab === 'stocks'" class="p-8 text-center text-sm text-muted-foreground">
                        История остатков появится после первой загрузки данных.
                    </Card>
                </TabsContent>

                <TabsContent value="orders" class="mt-0 space-y-4">
                    <template v-if="hasHistory && activeTab === 'orders'">
                        <Card v-show="!fullscreen" class="p-4">
                            <div class="grid gap-3 lg:grid-cols-7">
                                <div class="space-y-1.5">
                                    <Label>Предмет</Label>
                                    <Input v-model="subjectInput" placeholder="Например, футболки" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Артикул продавца</Label>
                                    <Input v-model="vendorInput" placeholder="Артикул" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Артикул WB</Label>
                                    <Input v-model="nmInput" placeholder="Номер" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Размер</Label>
                                    <Input v-model="sizeInput" placeholder="Размер" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>Баркод</Label>
                                    <Input v-model="barcodeInput" placeholder="Баркод" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>С</Label>
                                    <Input v-model="fromInput" type="date" :max="latestHistoryDate" @change="applyPeriod" />
                                </div>
                                <div class="space-y-1.5">
                                    <Label>По</Label>
                                    <Input v-model="toInput" type="date" :max="latestHistoryDate" @change="applyPeriod" />
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-muted-foreground">
                                    Отметьте строки, чтобы построить график внизу.
                                </p>
                                <Button
                                    v-if="rows.length > 0"
                                    size="sm"
                                    variant="outline"
                                    @click="fullscreen = true"
                                >
                                    <Maximize2 class="mr-1 h-4 w-4" />
                                    На весь экран
                                </Button>
                            </div>
                        </Card>

                        <div v-if="rows.length === 0" class="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
                            Нет заказов за выбранный период.
                        </div>
                    </template>
                    <Card v-else-if="!isLoading && activeTab === 'orders'" class="p-8 text-center text-sm text-muted-foreground">
                        История заказов появится после первой загрузки данных.
                    </Card>
                </TabsContent>
            </Tabs>

            <div
                v-if="hasHistory && rows.length > 0"
                :class="fullscreen ? 'fixed inset-0 z-50 flex flex-col gap-3 bg-background p-4' : 'space-y-2'"
            >
                <div v-if="fullscreen" class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">
                        {{ activeTab === "orders" ? "История заказов" : "История остатков" }}
                    </h2>
                    <Button size="sm" variant="outline" @click="fullscreen = false">
                        <X class="mr-1 h-4 w-4" />
                        Закрыть
                    </Button>
                </div>

                <StocksHistoryTable
                    v-if="activeTab === 'stocks'"
                    :dates="dates"
                    :rows="rows"
                    :selected-keys="selectedMap"
                    :fill-height="fullscreen"
                    max-height="calc(100dvh - 14rem)"
                    @toggle="toggleRow"
                    @toggle-page="togglePage"
                />
                <OrdersHistoryTable
                    v-else
                    :dates="dates"
                    :rows="rows"
                    :selected-keys="selectedMap"
                    :fill-height="fullscreen"
                    max-height="calc(100dvh - 14rem)"
                    @toggle="toggleRow"
                    @toggle-page="togglePage"
                />

                <div
                    v-if="(rowsMeta.last_page || 1) > 1"
                    class="flex items-center justify-between text-sm text-muted-foreground"
                >
                    <span>Страница {{ rowsMeta.current_page }} из {{ rowsMeta.last_page }}</span>
                    <div class="flex gap-2">
                        <Button
                            v-if="rowsMeta.current_page > 1"
                            size="sm"
                            variant="outline"
                            @click="changePage(rowsMeta.current_page - 1)"
                        >
                            Назад
                        </Button>
                        <Button
                            v-if="rowsMeta.current_page < rowsMeta.last_page"
                            size="sm"
                            variant="outline"
                            @click="changePage(rowsMeta.current_page + 1)"
                        >
                            Далее
                        </Button>
                    </div>
                </div>
            </div>

            <Card v-show="!fullscreen && hasHistory && rows.length > 0" class="p-4 sm:p-5">
                <div v-if="selectedCount > 0" class="mb-3 flex justify-end">
                    <Button size="sm" variant="ghost" @click="clearSelection">
                        Снять выделение
                    </Button>
                </div>
                <HistorySelectionChart
                    :dates="dates"
                    :items="chartItems"
                    :metric="activeTab"
                />
            </Card>
        </div>

        <Dialog
            :open="loadOpen"
            title="Начать отслеживание"
            @update:open="loadOpen = $event"
        >
            <div class="space-y-3 text-sm text-muted-foreground">
                <template v-if="activeTab === 'orders'">
                    <p>
                        Соберём историю заказов за последние 2 месяца и начнём автоматически обновлять её каждый день.
                    </p>
                    <p>
                        После запуска вы сможете видеть, сколько заказов было у каждого товара в разные даты.
                    </p>
                </template>
                <template v-else>
                    <p>
                        Загрузим историю остатков за последние 2 месяца и начнём ежедневно сохранять новые данные.
                    </p>
                    <p>
                        История будет по товарам и размерам: сколько было в наличии в разные дни и сколько сейчас в пути.
                    </p>
                    <p>
                        После запуска каждый день будет автоматически добавляться новый день в историю.
                    </p>
                </template>
            </div>
            <template #footer>
                <Button variant="outline" @click="loadOpen = false">Отмена</Button>
                <Button :disabled="busy || isLoading" @click="submitLoad">Начать</Button>
            </template>
        </Dialog>
    </SubscriberLayout>
</template>
