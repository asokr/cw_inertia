<script setup>
import { ref } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { Calculator, FileSpreadsheet, RefreshCw } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";

const props = defineProps({
    mode: { type: String, required: true },
    baseUrl: { type: String, required: true },
    jobStatus: { type: Object, default: () => ({}) },
});

const fileInput = ref(null);

const importForm = useForm({ file: null });

const isBusy = () => Boolean(
    props.jobStatus.is_syncing
    || props.jobStatus.is_calculating
    || props.jobStatus.is_importing
    || props.jobStatus.is_exporting,
);

function actionUrl(action) {
    if (props.mode === "fbs") {
        return `${props.baseUrl}/fbs/${action}`;
    }

    return `${props.baseUrl}/${action}`;
}

function postAction(action) {
    router.post(actionUrl(action), {}, { preserveScroll: true });
}

function triggerImport() {
    if (isBusy()) return;
    fileInput.value?.click();
}

function onFileChange(event) {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    importForm.file = file;
    importForm.post(actionUrl("import"), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            importForm.reset();
        },
    });
}
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <Button
            variant="outline"
            :disabled="isBusy()"
            @click="postAction('sync')"
        >
            <RefreshCw class="mr-1.5 h-4 w-4" :class="{ 'animate-spin': jobStatus.is_syncing }" />
            Обновить номенклатуру
        </Button>

        <Button
            :disabled="isBusy()"
            @click="postAction('calculate')"
        >
            <Calculator class="mr-1.5 h-4 w-4" />
            Рассчитать
        </Button>

        <Button
            variant="secondary"
            :disabled="isBusy()"
            @click="triggerImport"
        >
            <FileSpreadsheet class="mr-1.5 h-4 w-4" />
            Импорт XLSX
        </Button>

        <Button
            variant="secondary"
            :disabled="isBusy()"
            @click="postAction('export')"
        >
            <FileSpreadsheet class="mr-1.5 h-4 w-4" />
            Экспорт XLSX
        </Button>

        <input
            ref="fileInput"
            type="file"
            accept=".xlsx"
            class="hidden"
            @change="onFileChange"
        />
    </div>
</template>