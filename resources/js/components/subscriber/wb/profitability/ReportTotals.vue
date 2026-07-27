<script setup>
import { computed, ref } from "vue";
import { Download } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    report: { type: Object, required: true },
    exportStartUrl: { type: String, required: true },
    exportStatusUrl: { type: String, required: true },
    exportDownloadUrl: { type: String, required: true },
});

const { showError, showSuccess } = useFlashToast();

const EXPORT_ERROR_MESSAGE = "Не удалось выгрузить отчёт. Попробуйте чуть позже.";
const FALLBACK_LABELS = [
    "Готовим файл…",
    "Собираем таблицы…",
    "Пишем данные…",
    "Ещё немного…",
];

const exporting = ref(false);
const statusLabel = ref("");

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

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

async function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function failExport(message = EXPORT_ERROR_MESSAGE) {
    throw new Error(message);
}

/**
 * Poll with heartbeat: timer resets when stage/updated_at changes.
 * Absolute max ~20 min; stale (no change) ~4 min.
 */
async function pollUntilReady() {
    const pollIntervalMs = 2500;
    const staleLimitMs = 4 * 60 * 1000;
    const absoluteLimitMs = 20 * 60 * 1000;
    const startedAt = Date.now();
    let lastHeartbeatKey = "";
    let lastHeartbeatAt = Date.now();
    let fallbackTick = 0;

    while (true) {
        if (Date.now() - startedAt > absoluteLimitMs) {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        if (Date.now() - lastHeartbeatAt > staleLimitMs) {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        let response;
        try {
            response = await fetch(props.exportStatusUrl, {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });
        } catch {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        if (!response.ok) {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        const payload = await response.json().catch(() => ({}));
        const status = String(payload.status || "");
        const heartbeatKey = `${payload.stage || ""}|${payload.updated_at || ""}|${payload.stage_label || ""}`;

        if (heartbeatKey && heartbeatKey !== lastHeartbeatKey) {
            lastHeartbeatKey = heartbeatKey;
            lastHeartbeatAt = Date.now();
        }

        if (status === "failed") {
            failExport(payload.error || payload.message || EXPORT_ERROR_MESSAGE);
        }

        if (payload.ready === true) {
            return payload;
        }

        if (status === "done" && payload.ready !== true) {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        if (status === "idle") {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        if (payload.stage_label || payload.message) {
            statusLabel.value = payload.stage_label || payload.message;
        } else {
            statusLabel.value = FALLBACK_LABELS[fallbackTick % FALLBACK_LABELS.length];
            fallbackTick += 1;
        }

        await sleep(pollIntervalMs);
    }
}

async function downloadReadyFile() {
    let response;
    try {
        response = await fetch(props.exportDownloadUrl, {
            method: "GET",
            credentials: "same-origin",
        });
    } catch {
        failExport(EXPORT_ERROR_MESSAGE);
    }

    if (!response.ok) {
        failExport(EXPORT_ERROR_MESSAGE);
    }

    const blob = await response.blob();
    const from = props.report.date_from ?? "from";
    const to = props.report.date_to ?? "to";
    const objectUrl = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = objectUrl;
    link.download = `profitability_${from}_${to}.xlsx`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(objectUrl);
}

async function downloadReport() {
    if (exporting.value) {
        return;
    }

    exporting.value = true;
    statusLabel.value = "Встали в очередь…";

    try {
        let startResponse;
        try {
            startResponse = await fetch(props.exportStartUrl, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: "{}",
            });
        } catch {
            failExport(EXPORT_ERROR_MESSAGE);
        }

        const startPayload = await startResponse.json().catch(() => ({}));

        if (!startResponse.ok || startPayload.success === false) {
            failExport(startPayload.message || startPayload.error || EXPORT_ERROR_MESSAGE);
        }

        if (startPayload.status === "failed") {
            failExport(startPayload.error || startPayload.message || EXPORT_ERROR_MESSAGE);
        }

        if (startPayload.ready) {
            statusLabel.value = "Скачиваем…";
            await downloadReadyFile();
            if (startPayload.truncated) {
                showSuccess(
                    startPayload.truncated_note
                        || "Файл готов. Часть строк не попала в выгрузку — см. лист «Итоги».",
                );
            } else {
                showSuccess("Файл скачан");
            }
            return;
        }

        statusLabel.value = startPayload.stage_label || startPayload.message || "Готовим файл…";
        const finalPayload = await pollUntilReady();
        statusLabel.value = "Скачиваем…";
        await downloadReadyFile();

        if (finalPayload?.truncated) {
            showSuccess(
                finalPayload.truncated_note
                    || "Файл готов. Часть строк не попала в выгрузку — см. лист «Итоги».",
            );
        } else {
            showSuccess("Файл скачан");
        }
    } catch (e) {
        const message =
            e?.message && String(e.message).trim() !== ""
                ? String(e.message)
                : EXPORT_ERROR_MESSAGE;
        showError(
            message.includes("Попробуйте") || message.includes("период") || message.includes("Итоги")
                ? message
                : EXPORT_ERROR_MESSAGE,
        );
    } finally {
        exporting.value = false;
        statusLabel.value = "";
    }
}
</script>

<template>
    <div class="min-w-0 space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <p class="text-base sm:text-xl">
                Отчёт за
                <strong class="whitespace-nowrap">{{ report.date_from }}</strong>
                —
                <strong class="whitespace-nowrap">{{ report.date_to }}</strong>
            </p>
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <Button size="sm" variant="outline" class="w-full sm:w-auto" :disabled="exporting" @click="downloadReport">
                    <Download class="mr-1.5 h-4 w-4" />
                    {{ exporting ? "Готовим файл…" : "Скачать отчёт" }}
                </Button>
                <span v-if="exporting && statusLabel" class="text-sm text-muted-foreground">{{ statusLabel }}</span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 min-[400px]:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Продажи</h3>
                <p class="break-words text-lg font-medium sm:text-xl">
                    {{ report.sales_quantity }} ед.
                    <span class="block text-base sm:inline sm:text-xl">
                        на {{ formatNumber(report.sales_amount) }}
                    </span>
                </p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Возвраты</h3>
                <p class="break-words text-lg font-medium sm:text-xl">
                    {{ report.returns_quantity }} ед.
                    <span class="block text-base sm:inline sm:text-xl">
                        на {{ formatNumber(report.returns_amount) }}
                    </span>
                </p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Выкуп</h3>
                <p class="text-lg font-medium sm:text-xl">{{ report.percent_buy }} %</p>
            </Card>

            <Card class="min-w-0 p-3 min-[400px]:col-span-2 md:col-span-3 xl:col-span-1 sm:p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                    <div class="min-w-0">
                        <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Доп. расходы и доплаты</h3>
                        <p class="text-lg font-medium sm:text-xl">{{ formatNumber(surcharges) }}</p>
                    </div>
                    <ul class="min-w-0 flex-1 space-y-1 text-xs text-muted-foreground sm:max-w-[12rem]">
                        <li
                            v-for="item in surchargeBreakdown"
                            :key="item.label"
                            class="flex justify-between gap-2"
                        >
                            <span class="truncate">{{ item.label }}</span>
                            <span class="shrink-0 font-medium text-foreground">{{ formatNumber(item.value) }}</span>
                        </li>
                    </ul>
                </div>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Логистика</h3>
                <p class="break-words text-lg font-medium sm:text-xl">{{ formatNumber(report.logistics) }}</p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Себестоимость</h3>
                <p class="break-words text-lg font-medium sm:text-xl">
                    {{ Math.round(report.purchase_cost).toLocaleString("ru-RU") }}
                </p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Итог</h3>
                <p class="break-words text-lg font-medium sm:text-xl">{{ formatNumber(report.itog) }}</p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Маржинальность</h3>
                <p class="break-words text-lg font-medium sm:text-xl">{{ formatNumber(report.margin) }}</p>
            </Card>

            <Card class="min-w-0 p-3 sm:p-4">
                <h3 class="mb-1.5 text-xs text-muted-foreground sm:mb-2 sm:text-sm">Рентабельность</h3>
                <p class="text-lg font-semibold sm:text-xl">{{ report.total_profitability }} %</p>
            </Card>
        </div>
    </div>
</template>
