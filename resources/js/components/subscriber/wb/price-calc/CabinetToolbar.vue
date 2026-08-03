<script setup>
import { computed, onUnmounted, ref, watch } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useFlashToast } from "@/composables/useFlashToast";
import {
    FileDown,
    FileUp,
    Package,
    RefreshCw,
    Settings,
} from "lucide-vue-next";
import { useFileDownload } from "@/composables/useFileDownload";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    cabinet: { type: Object, required: true },
    cardsMeta: { type: Object, default: () => ({}) },
    operationLock: {
        type: Object,
        default: () => ({ busy: false, retry_after: 0, reason: null }),
    },
    jobProcessing: { type: Boolean, default: false },
    syncUrl: { type: String, required: true },
    importVolumeUrl: { type: String, required: true },
    importExcelUrl: { type: String, required: true },
    exportExcelUrl: { type: String, required: true },
});

const emit = defineEmits(["open-settings", "job-started"]);

const page = usePage();
const { showError } = useFlashToast();

const syncing = ref(false);
const importingVolume = ref(false);
const importingExcel = ref(false);
const highlightVolume = ref(false);
const rateLimitTimeLeft = ref(0);
const { downloading, downloadPost } = useFileDownload();

let rateLimitInterval = null;
let highlightTimeout = null;

const excelInput = ref(null);
const volumeInput = ref(null);

const anyLocalBusy = computed(
    () => syncing.value || importingVolume.value || importingExcel.value || downloading.value,
);

const heavyOpsDisabled = computed(
    () =>
        anyLocalBusy.value ||
        props.jobProcessing ||
        rateLimitTimeLeft.value > 0 ||
        Boolean(props.operationLock?.busy),
);

function startRateLimit(seconds = 60) {
    const next = Math.max(0, Math.floor(Number(seconds) || 0));
    if (next <= 0) {
        return;
    }

    rateLimitTimeLeft.value = Math.max(rateLimitTimeLeft.value, next);
    if (rateLimitInterval) {
        return;
    }

    rateLimitInterval = setInterval(() => {
        rateLimitTimeLeft.value -= 1;
        if (rateLimitTimeLeft.value <= 0) {
            rateLimitTimeLeft.value = 0;
            clearInterval(rateLimitInterval);
            rateLimitInterval = null;
        }
    }, 1000);
}

function wasSuccessfulVisit(visit) {
    return !visit?.props?.flash?.error;
}

function applyRetryAfterFromFlash(visit) {
    const seconds = Number(visit?.props?.flash?.price_calc_retry_after ?? 0);
    if (seconds > 0) {
        startRateLimit(seconds);
    }
}

function sync() {
    if (heavyOpsDisabled.value) {
        return;
    }

    syncing.value = true;
    router.post(props.syncUrl, {}, {
        preserveScroll: true,
        onFinish: () => {
            syncing.value = false;
        },
        onSuccess: (visit) => {
            applyRetryAfterFromFlash(visit);

            if (!wasSuccessfulVisit(visit)) {
                return;
            }

            emit("job-started");
            highlightVolume.value = true;
            if (highlightTimeout) clearTimeout(highlightTimeout);
            highlightTimeout = setTimeout(() => {
                highlightVolume.value = false;
            }, 6000);
        },
        onError: () => {
            showError("Не удалось обновить список товаров. Попробуйте ещё раз.");
        },
    });
}

function triggerVolumeImport() {
    if (heavyOpsDisabled.value || props.cardsMeta.total === 0) {
        return;
    }
    volumeInput.value?.click();
}

function triggerExcelImport() {
    if (heavyOpsDisabled.value) {
        return;
    }
    excelInput.value?.click();
}

async function handleExport() {
    if (anyLocalBusy.value || props.jobProcessing) {
        return;
    }

    try {
        await downloadPost(props.exportExcelUrl, `price-calc-${new Date().toISOString().slice(0, 10)}.xlsx`);
    } catch {
        showError("Не удалось экспортировать XLSX");
    }
}

function uploadVolume(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file || heavyOpsDisabled.value) return;

    importingVolume.value = true;
    highlightVolume.value = false;
    router.post(
        props.importVolumeUrl,
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                importingVolume.value = false;
            },
            onError: () => showError("Не удалось импортировать объёмы."),
        },
    );
}

function uploadExcel(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file || heavyOpsDisabled.value) return;

    importingExcel.value = true;
    router.post(
        props.importExcelUrl,
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                importingExcel.value = false;
            },
            onSuccess: (visit) => {
                applyRetryAfterFromFlash(visit);
                if (wasSuccessfulVisit(visit)) {
                    emit("job-started");
                }
            },
            onError: () => showError("Не удалось импортировать Excel."),
        },
    );
}

watch(
    () => props.operationLock,
    (lock) => {
        const seconds = Number(lock?.retry_after ?? 0);
        if (seconds > 0 && !props.jobProcessing) {
            startRateLimit(seconds);
        }
    },
    { immediate: true, deep: true },
);

watch(
    () => page.props.flash?.price_calc_retry_after,
    (seconds) => {
        if (Number(seconds) > 0) {
            startRateLimit(Number(seconds));
        }
    },
);

onUnmounted(() => {
    if (rateLimitInterval) clearInterval(rateLimitInterval);
    if (highlightTimeout) clearTimeout(highlightTimeout);
});
</script>

<template>
    <Card class="p-4">
        <div class="flex flex-wrap gap-3">
            <Button :disabled="heavyOpsDisabled" @click="sync">
                <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': syncing || jobProcessing }" />
                <template v-if="syncing || jobProcessing">Обновление…</template>
                <template v-else-if="rateLimitTimeLeft > 0">Через {{ rateLimitTimeLeft }} с</template>
                <template v-else>Обновить список товаров</template>
            </Button>

            <Button
                variant="outline"
                :disabled="heavyOpsDisabled || cardsMeta.total === 0"
                :class="{ 'ring-2 ring-primary': highlightVolume }"
                @click="triggerVolumeImport"
            >
                <Package class="mr-2 h-4 w-4" />
                {{ importingVolume ? "Импорт…" : "Импорт объёма" }}
            </Button>

            <Button variant="outline" :disabled="anyLocalBusy || jobProcessing" @click="handleExport">
                <FileDown class="mr-2 h-4 w-4" />
                {{ downloading ? "Экспорт…" : "Экспорт Excel" }}
            </Button>

            <Button
                variant="outline"
                :disabled="heavyOpsDisabled"
                @click="triggerExcelImport"
            >
                <FileUp class="mr-2 h-4 w-4" />
                <template v-if="importingExcel || jobProcessing">Импорт…</template>
                <template v-else-if="rateLimitTimeLeft > 0">Через {{ rateLimitTimeLeft }} с</template>
                <template v-else>Импорт Excel</template>
            </Button>

            <Button variant="outline" size="icon" @click="emit('open-settings')">
                <Settings class="h-4 w-4" />
            </Button>
        </div>

        <p v-if="jobProcessing" class="mt-3 text-xs text-muted-foreground">
            Идёт обработка… Дождитесь завершения.
        </p>
        <p v-else-if="rateLimitTimeLeft > 0" class="mt-3 text-xs text-muted-foreground">
            Подождите… Операции временно недоступны.
        </p>

        <input ref="excelInput" type="file" accept=".xlsx" class="hidden" @change="uploadExcel" />
        <input ref="volumeInput" type="file" accept=".xlsx,.zip" class="hidden" @change="uploadVolume" />
    </Card>
</template>
