<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { Head, router } from "@inertiajs/vue3";
import axios from "axios";
import { ChevronDown, ChevronUp, Loader2, Maximize2, PauseCircle, PlayCircle, RefreshCw, X } from "lucide-vue-next";
import StockHistoryTable from "@/components/subscriber/oz/stock-history/StockHistoryTable.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useToolPoll } from "@/composables/useToolPoll";

const props = defineProps({
    cabinet: { type: Object, required: true },
    tracking: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    dates: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    productsMeta: { type: Object, default: () => ({}) },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "История остатков" },
];

const baseUrl = "/panel/oz/stock-history";
const { showError, showSuccess } = useFlashToast();

const searchInput = ref(props.filters.search ?? "");
const retentionDays = ref(props.tracking.retention_days ?? 90);
const busy = ref(false);
const expanded = ref({});
const details = ref({});
const loadingSku = ref({});
const fullscreen = ref(false);

const isLoading = computed(() => Boolean(props.tracking.is_loading));
const isActive = computed(() => props.tracking.tracking_status === "active");
const isIdle = computed(() => props.tracking.tracking_status === "idle" || !props.tracking.tracking_status);
const isError = computed(() => props.tracking.tracking_status === "error");
const hasHistory = computed(() => Boolean(props.tracking.has_history));
const canRetrySnapshot = computed(() => (
    isActive.value && Boolean(props.tracking.last_error)
));

const isRetentionSaved = computed(() => (
    Number(retentionDays.value) === Number(props.tracking.retention_days)
));

const loadingLabel = computed(() => {
    if (props.tracking.tracking_status === "loading_products") {
        return "Загружаем товары кабинета…";
    }
    if (props.tracking.tracking_status === "loading_stocks") {
        return "Сохраняем остатки за вчера…";
    }
    return "Загрузка…";
});

const poll = useToolPoll(2500, {
    requestOptions: {
        only: ["tracking", "products", "productsMeta", "dates", "filters"],
        preserveState: true,
        preserveScroll: true,
    },
    isComplete: (pageProps) => !pageProps.tracking?.is_loading,
});

watch(
    () => props.tracking.is_loading,
    (loading) => {
        if (loading) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
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
watch(searchInput, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => reload({ search: value || "", page: 1 }), 400);
});

function reload(overrides = {}) {
    router.get(
        baseUrl,
        {
            search: overrides.search ?? props.filters.search ?? "",
            page: overrides.page ?? props.filters.page ?? 1,
        },
        {
            only: ["products", "productsMeta", "filters", "dates", "tracking"],
            preserveState: true,
            preserveScroll: true,
        },
    );
}

function postAction(url, onSuccess) {
    busy.value = true;
    router.post(url, {}, {
        preserveScroll: true,
        onSuccess: () => onSuccess?.(),
        onError: () => showError("Не удалось выполнить действие"),
        onFinish: () => {
            busy.value = false;
        },
    });
}

function startTracking() {
    postAction(`${baseUrl}/start`, () => poll.start());
}

function stopTracking() {
    postAction(`${baseUrl}/stop`);
}

function retrySnapshot() {
    postAction(`${baseUrl}/sync`, () => poll.start());
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

async function loadDetail(sku) {
    if (loadingSku.value[sku]) {
        return;
    }
    loadingSku.value = { ...loadingSku.value, [sku]: true };
    try {
        const { data } = await axios.get(`${baseUrl}/products/${sku}`, {
            params: {
                from: props.filters.from,
                to: props.filters.to,
            },
        });
        if (data?.success) {
            details.value = { ...details.value, [sku]: data.data };
        } else {
            showError((data?.messages || []).join(" ") || "Не удалось открыть склады");
        }
    } catch {
        showError("Не удалось открыть склады");
    } finally {
        loadingSku.value = { ...loadingSku.value, [sku]: false };
    }
}

async function toggleProduct(sku) {
    if (expanded.value[sku]) {
        expanded.value = { ...expanded.value, [sku]: false };
        return;
    }
    expanded.value = { ...expanded.value, [sku]: true };
    if (!details.value[sku]) {
        await loadDetail(sku);
    }
}

watch(
    () => (props.dates || []).join(","),
    (next, prev) => {
        if (!prev || next === prev) {
            return;
        }
        details.value = {};
        Object.keys(expanded.value).forEach((sku) => {
            if (expanded.value[sku]) {
                loadDetail(Number(sku));
            }
        });
    },
);

async function expandAll() {
    await Promise.all(props.products.map((product) => {
        if (!expanded.value[product.sku]) {
            return toggleProduct(product.sku);
        }
        return Promise.resolve();
    }));
}

function collapseAll() {
    expanded.value = {};
}

function changePage(page) {
    reload({ page });
}

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
});
</script>

<template>
    <Head :title="`История остатков — ${cabinet.name}`" />

    <SubscriberLayout :title="cabinet.name" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="История остатков"
            description="Смотрите, сколько товара было на складах Ozon в разные дни"
        />

        <div class="space-y-4">
            <Card class="p-4 sm:p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-2">
                        <h3 class="font-medium">Отслеживание</h3>
                        <p class="max-w-xl text-sm text-muted-foreground">
                            Чтобы видеть, как менялись остатки, включите отслеживание. Сначала загрузим товары кабинета, затем каждый день будем сохранять остатки по складам.
                        </p>
                        <p v-if="isActive && tracking.last_stock_date_label" class="text-sm">
                            Данные за {{ tracking.last_stock_date_label }}
                        </p>
                        <p v-else-if="isIdle && hasHistory" class="text-sm text-muted-foreground">
                            Отслеживание остановлено. Новые дни не добавляются.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="isIdle || isError"
                            :disabled="busy || isLoading"
                            @click="startTracking"
                        >
                            <PlayCircle class="mr-2 h-4 w-4" />
                            {{ hasHistory ? "Включить снова" : "Начать отслеживание" }}
                        </Button>
                        <Button
                            v-if="isActive"
                            variant="outline"
                            :disabled="busy"
                            @click="stopTracking"
                        >
                            <PauseCircle class="mr-2 h-4 w-4" />
                            Остановить отслеживание
                        </Button>
                        <Button
                            v-if="canRetrySnapshot"
                            variant="outline"
                            :disabled="busy || isLoading"
                            @click="retrySnapshot"
                        >
                            <RefreshCw class="mr-2 h-4 w-4" />
                            Обновить за вчера
                        </Button>
                    </div>
                </div>

                <div v-if="isLoading" class="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 class="h-4 w-4 animate-spin" />
                    {{ loadingLabel }}
                </div>

                <Alert v-if="tracking.last_error && !isLoading" class="mt-4" :variant="isError ? 'destructive' : 'warning'">
                    {{ tracking.last_error }}
                </Alert>

                <div class="mt-5 space-y-1.5 border-t pt-4">
                    <Label>Сколько дней хранить историю</Label>
                    <p class="text-xs text-muted-foreground">
                        Максимум — полгода. Более старые данные удаляются автоматически.
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

            <template v-if="hasHistory">
                <Card v-show="!fullscreen" class="p-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div class="flex-1 space-y-1.5">
                            <Label>Поиск</Label>
                            <Input v-model="searchInput" placeholder="Артикул или название" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button size="sm" variant="ghost" @click="expandAll">
                                <ChevronDown class="mr-1 h-4 w-4" />
                                Развернуть всё
                            </Button>
                            <Button size="sm" variant="ghost" @click="collapseAll">
                                <ChevronUp class="mr-1 h-4 w-4" />
                                Свернуть всё
                            </Button>
                            <Button
                                v-if="products.length > 0"
                                size="sm"
                                variant="outline"
                                @click="fullscreen = true"
                            >
                                <Maximize2 class="mr-1 h-4 w-4" />
                                На весь экран
                            </Button>
                        </div>
                    </div>
                </Card>

                <div v-if="products.length === 0" class="rounded-lg border bg-card p-8 text-center text-sm text-muted-foreground">
                    Нет товаров с остатками.
                </div>

                <div v-else class="space-y-2">
                    <div
                        :class="fullscreen
                            ? 'fixed inset-0 z-50 flex flex-col gap-3 bg-background p-4'
                            : ''"
                    >
                        <div v-if="fullscreen" class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-lg font-semibold">История остатков</h2>
                            <div class="flex flex-wrap gap-2">
                                <Button size="sm" variant="ghost" @click="expandAll">
                                    <ChevronDown class="mr-1 h-4 w-4" />
                                    Развернуть всё
                                </Button>
                                <Button size="sm" variant="ghost" @click="collapseAll">
                                    <ChevronUp class="mr-1 h-4 w-4" />
                                    Свернуть всё
                                </Button>
                                <Button size="sm" variant="outline" @click="fullscreen = false">
                                    <X class="mr-1 h-4 w-4" />
                                    Закрыть
                                </Button>
                            </div>
                        </div>
                        <StockHistoryTable
                            :dates="dates"
                            :products="products"
                            :expanded="expanded"
                            :details="details"
                            :loading-sku="loadingSku"
                            :fill-height="fullscreen"
                            max-height="calc(100dvh - 14rem)"
                            @toggle="toggleProduct"
                        />
                        <div
                            v-if="(productsMeta.last_page || 1) > 1"
                            class="flex items-center justify-between text-sm text-muted-foreground"
                        >
                            <span>Страница {{ productsMeta.current_page }} из {{ productsMeta.last_page }}</span>
                            <div class="flex gap-2">
                                <Button
                                    v-if="productsMeta.current_page > 1"
                                    size="sm"
                                    variant="outline"
                                    @click="changePage(productsMeta.current_page - 1)"
                                >
                                    Назад
                                </Button>
                                <Button
                                    v-if="productsMeta.current_page < productsMeta.last_page"
                                    size="sm"
                                    variant="outline"
                                    @click="changePage(productsMeta.current_page + 1)"
                                >
                                    Далее
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <Card v-else-if="!isLoading" class="p-8 text-center text-sm text-muted-foreground">
                История появится после первой загрузки остатков.
            </Card>
        </div>
    </SubscriberLayout>
</template>
