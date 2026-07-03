<script setup>
import { computed } from "vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Select from "@/components/ui/Select.vue";

const props = defineProps({
    isReportReady: { type: Boolean, default: false },
    reportStatus: { type: String, default: null },
    templates: { type: Array, default: () => [] },
    selectedTemplateId: { type: [Number, String, null], default: null },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(["update:selectedTemplateId", "start"]);

const reportStateHint = computed(() => {
    if (props.reportStatus === "processing") return "Дождитесь завершения сбора данных кабинета.";
    if (props.reportStatus !== "done") return "Сначала соберите данные кабинета за период.";
    return "";
});

const selectedTemplate = computed(() => props.templates.find((t) => Number(t.id) === Number(props.selectedTemplateId)));
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-lg font-semibold">Варианты ИИ-анализа</h3>
            <p class="text-sm text-muted-foreground">Выберите шаблон и запустите ИИ-анализ.</p>
        </div>

        <Alert v-if="!isReportReady">{{ reportStateHint }}</Alert>

        <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
            <div class="space-y-1">
                <label class="text-sm font-medium">Шаблон ИИ-анализа</label>
                <Select
                    :model-value="selectedTemplateId"
                    :disabled="!isReportReady || processing || !templates.length"
                    @update:model-value="emit('update:selectedTemplateId', Number($event))"
                >
                    <option v-for="template in templates" :key="template.id" :value="template.id">
                        {{ template.name }}
                    </option>
                </Select>
            </div>
            <Button
                :disabled="!isReportReady || !selectedTemplateId || processing"
                @click="emit('start')"
            >
                Запустить ИИ-анализ
            </Button>
        </div>

        <Alert v-if="selectedTemplate?.description">{{ selectedTemplate.description }}</Alert>
    </div>
</template>