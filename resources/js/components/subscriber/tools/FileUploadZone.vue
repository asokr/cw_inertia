<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { FileSpreadsheet, Upload } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    action: {
        type: String,
        required: true,
    },
    field: {
        type: String,
        default: "file",
    },
    accept: {
        type: String,
        default: ".xlsx,.xls",
    },
    label: {
        type: String,
        default: "Перетащите Excel-файл или выберите на диске",
    },
    processing: Boolean,
    extraData: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(["success", "error"]);

const dragOver = ref(false);
const fileInput = ref(null);
const selectedName = ref("");

function pickFile() {
    fileInput.value?.click();
}

function upload(file) {
    if (!file) {
        return;
    }

    selectedName.value = file.name;

    const formData = {
        [props.field]: file,
        ...props.extraData,
    };

    router.post(props.action, formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit("success", file),
        onError: (errors) => emit("error", errors),
    });
}

function onInputChange(event) {
    upload(event.target.files?.[0] ?? null);
    event.target.value = "";
}

function onDrop(event) {
    dragOver.value = false;
    upload(event.dataTransfer.files?.[0] ?? null);
}
</script>

<template>
    <Card
        class="flex flex-col items-center justify-center gap-3 border-dashed p-8 text-center transition-colors"
        :class="dragOver ? 'border-primary bg-primary/5' : ''"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop"
    >
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
            <FileSpreadsheet class="h-6 w-6 text-muted-foreground" />
        </div>
        <div class="space-y-1">
            <p class="text-sm font-medium">{{ label }}</p>
            <p v-if="selectedName" class="text-xs text-muted-foreground">{{ selectedName }}</p>
        </div>
        <input
            ref="fileInput"
            type="file"
            class="hidden"
            :accept="accept"
            @change="onInputChange"
        />
        <Button type="button" variant="outline" :disabled="processing" @click="pickFile">
            <Upload class="mr-2 h-4 w-4" />
            Выбрать файл
        </Button>
    </Card>
</template>