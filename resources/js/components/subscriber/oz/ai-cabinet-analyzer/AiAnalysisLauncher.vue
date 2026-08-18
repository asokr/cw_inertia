<script setup>
import { computed } from "vue";
import { Play, Sparkles } from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { formatCredits } from "@/utils/credits";

const props = defineProps({
    isReportReady: { type: Boolean, default: false },
    reportStatus: { type: String, default: null },
    templates: { type: Array, default: () => [] },
    /** Analyses for the current report (to block duplicate in-flight runs). */
    analyses: { type: Array, default: () => [] },
    processing: { type: Boolean, default: false },
    launchingTemplateId: { type: [Number, String, null], default: null },
});

const emit = defineEmits(["start"]);

const reportStateHint = computed(() => {
    if (props.reportStatus === "processing") {
        return "Дождитесь завершения сбора данных кабинета.";
    }
    if (props.reportStatus !== "done") {
        return "Сначала соберите данные кабинета за период — после этого можно запускать анализы.";
    }
    return "";
});

/** Template IDs that already have a processing analysis on this report. */
const runningTemplateIds = computed(() => {
    const ids = new Set();
    for (const item of props.analyses || []) {
        if (item?.status === "processing" && item?.template_id != null) {
            ids.add(Number(item.template_id));
        }
    }
    return ids;
});

function isTemplateRunning(templateId) {
    return runningTemplateIds.value.has(Number(templateId));
}

function isLaunching(templateId) {
    return props.processing
        && props.launchingTemplateId != null
        && Number(props.launchingTemplateId) === Number(templateId);
}

function isStartDisabled(templateId) {
    if (!props.isReportReady) return true;
    if (isTemplateRunning(templateId)) return true;
    // Block only the template currently being submitted (other types can start in parallel).
    if (props.processing && Number(props.launchingTemplateId) === Number(templateId)) return true;
    return false;
}

function startButtonLabel(templateId) {
    if (isLaunching(templateId)) return "Запуск…";
    if (isTemplateRunning(templateId)) return "Выполняется…";
    return "Запустить";
}

function onStart(templateId) {
    if (isStartDisabled(templateId)) return;
    emit("start", Number(templateId));
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex items-start gap-3">
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
            >
                <Sparkles class="h-4 w-4" />
            </div>
            <div class="min-w-0 space-y-0.5">
                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    Шаг 3
                </p>
                <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">
                    Анализы
                </h2>
                <p class="text-sm text-muted-foreground sm:text-base">
                    Выберите тип анализа и запустите его на собранных данных.
                </p>
            </div>
        </div>

        <Alert v-if="!isReportReady">{{ reportStateHint }}</Alert>

        <div
            v-if="templates.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Card
                v-for="template in templates"
                :key="template.id"
                class="group flex flex-col overflow-hidden transition-all duration-200 hover:border-primary/30 hover:shadow-md"
            >
                <div class="flex flex-1 flex-col gap-4 p-5">
                    <div class="space-y-2">
                        <h3 class="text-base font-semibold tracking-tight sm:text-lg">
                            {{ template.name }}
                        </h3>
                        <p
                            v-if="template.description"
                            class="text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ template.description }}
                        </p>
                        <p class="text-sm font-medium">
                            Стоимость отчёта: {{ formatCredits(template.credits_cost) }}
                        </p>
                    </div>
                </div>

                <div class="border-t bg-muted/20 px-5 py-3.5">
                    <Button
                        class="w-full"
                        :disabled="isStartDisabled(template.id)"
                        @click="onStart(template.id)"
                    >
                        <Play class="mr-2 h-4 w-4" />
                        {{ startButtonLabel(template.id) }}
                    </Button>
                </div>
            </Card>
        </div>

        <p v-else class="text-sm text-muted-foreground">
            Нет доступных шаблонов анализа.
        </p>
    </section>
</template>
