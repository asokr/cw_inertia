<script setup>
import { ref } from "vue";
import { CloudUpload } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";

const props = defineProps({
    disabled: {
        type: Boolean,
        default: false,
    },
    remaining: {
        type: Number,
        default: 6,
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["files"]);

const fileInput = ref(null);
const isDragging = ref(false);

function openPicker() {
    if (props.disabled || props.busy || props.remaining <= 0) {
        return;
    }
    fileInput.value?.click();
}

function emitFiles(fileList) {
    if (props.disabled || props.busy || props.remaining <= 0) {
        return;
    }

    const files = Array.from(fileList || []).slice(0, props.remaining);
    if (!files.length) {
        return;
    }

    emit("files", files);
}

function onInputChange(event) {
    emitFiles(event.target?.files);
    if (fileInput.value) {
        fileInput.value.value = "";
    }
}

function onDrop(event) {
    isDragging.value = false;
    emitFiles(event.dataTransfer?.files);
}
</script>

<template>
    <div
        class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center transition-colors"
        :class="[
            disabled || remaining <= 0
                ? 'pointer-events-none opacity-50'
                : isDragging
                  ? 'border-primary bg-primary/5'
                  : 'border-border bg-card hover:border-primary/60 hover:bg-muted/30',
        ]"
        @click="openPicker"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="onDrop"
    >
        <input
            ref="fileInput"
            type="file"
            class="hidden"
            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            multiple
            :disabled="disabled || busy || remaining <= 0"
            @change="onInputChange"
            @click.stop
        />

        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary">
            <CloudUpload class="h-6 w-6" />
        </div>
        <div class="space-y-1">
            <p class="text-sm text-muted-foreground">
                Перетащите файлы или
                <span class="font-semibold text-primary underline decoration-dotted underline-offset-2">
                    выберите на диске
                </span>
            </p>
            <p class="text-xs text-muted-foreground/80">
                JPEG, PNG, WEBP · до 10 МБ · ещё можно {{ remaining }} из 6
            </p>
        </div>
        <Button
            type="button"
            size="sm"
            variant="outline"
            class="mt-1"
            :disabled="disabled || busy || remaining <= 0"
            @click.stop="openPicker"
        >
            Выбрать фотографии
        </Button>
    </div>
</template>
