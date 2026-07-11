<script setup>
import { onUnmounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
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
    syncUrl: { type: String, required: true },
    importVolumeUrl: { type: String, required: true },
    importExcelUrl: { type: String, required: true },
    exportExcelUrl: { type: String, required: true },
});

const emit = defineEmits(["open-settings"]);

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

function startRateLimit(seconds = 60) {
    rateLimitTimeLeft.value = seconds;
    if (rateLimitInterval) clearInterval(rateLimitInterval);
    rateLimitInterval = setInterval(() => {
        rateLimitTimeLeft.value -= 1;
        if (rateLimitTimeLeft.value <= 0) {
            clearInterval(rateLimitInterval);
            rateLimitInterval = null;
        }
    }, 1000);
}

function wasSuccessfulVisit(visit) {
    return !visit?.props?.flash?.error;
}

function sync() {
    syncing.value = true;
    router.post(props.syncUrl, {}, {
        preserveScroll: true,
        onFinish: () => {
            syncing.value = false;
        },
        onSuccess: (visit) => {
            if (!wasSuccessfulVisit(visit)) {
                return;
            }

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
    volumeInput.value?.click();
}

function triggerExcelImport() {
    if (rateLimitTimeLeft.value > 0) return;
    excelInput.value?.click();
}

async function handleExport() {
    try {
        await downloadPost(props.exportExcelUrl, `price-calc-${new Date().toISOString().slice(0, 10)}.xlsx`);
    } catch {
        showError("Не удалось экспортировать XLSX");
    }
}

function uploadVolume(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

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
    if (!file) return;

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
                if (wasSuccessfulVisit(visit)) {
                    startRateLimit(60);
                }
            },
            onError: () => showError("Не удалось импортировать Excel."),
        },
    );
}

onUnmounted(() => {
    if (rateLimitInterval) clearInterval(rateLimitInterval);
    if (highlightTimeout) clearTimeout(highlightTimeout);
});
</script>

<template>
    <Card class="p-4">
        <div class="flex flex-wrap gap-3">
            <Button :disabled="syncing" @click="sync">
                <RefreshCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': syncing }" />
                {{ syncing ? "Обновление…" : "Обновить список товаров" }}
            </Button>

            <Button
                variant="outline"
                :disabled="importingVolume || cardsMeta.total === 0"
                :class="{ 'ring-2 ring-primary': highlightVolume }"
                @click="triggerVolumeImport"
            >
                <Package class="mr-2 h-4 w-4" />
                {{ importingVolume ? "Импорт…" : "Импорт объёма" }}
            </Button>

            <Button variant="outline" :disabled="downloading" @click="handleExport">
                <FileDown class="mr-2 h-4 w-4" />
                {{ downloading ? "Экспорт…" : "Экспорт Excel" }}
            </Button>

            <Button
                variant="outline"
                :disabled="importingExcel || rateLimitTimeLeft > 0"
                @click="triggerExcelImport"
            >
                <FileUp class="mr-2 h-4 w-4" />
                {{
                    rateLimitTimeLeft > 0
                        ? `Импорт Excel (${rateLimitTimeLeft})`
                        : importingExcel
                          ? "Импорт…"
                          : "Импорт Excel"
                }}
            </Button>

            <Button variant="outline" size="icon" @click="emit('open-settings')">
                <Settings class="h-4 w-4" />
            </Button>
        </div>

        <input ref="excelInput" type="file" accept=".xlsx" class="hidden" @change="uploadExcel" />
        <input ref="volumeInput" type="file" accept=".xlsx,.zip" class="hidden" @change="uploadVolume" />
    </Card>
</template>