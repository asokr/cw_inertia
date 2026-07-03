<script setup>
import { ref } from "vue";
import { CloudUpload, X } from "lucide-vue-next";

const props = defineProps({
    modelValue: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    multiple: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "error", "files-added"]);

const fileInput = ref(null);
const isDragging = ref(false);

const MAX_FILE_SIZE = 10 * 1024 * 1024;
const ALLOWED_TYPES = new Set(["image/png", "image/jpeg", "image/webp", "image/gif"]);
const ALLOWED_EXTENSIONS = new Set(["png", "jpg", "jpeg", "webp", "gif"]);

function openFilePicker() {
    if (!props.disabled) {
        fileInput.value?.click();
    }
}

function getExtension(file) {
    const name = file?.name || "";
    const extension = name.includes(".") ? name.split(".").pop()?.toLowerCase() : "";
    return extension || "";
}

function isAllowedFile(file) {
    if (!file) return false;
    if (file.type && ALLOWED_TYPES.has(file.type)) return true;
    return ALLOWED_EXTENSIONS.has(getExtension(file));
}

async function isAnimatedGif(file) {
    try {
        const buffer = await file.arrayBuffer();
        const bytes = new Uint8Array(buffer);
        let frames = 0;

        for (let i = 0; i < bytes.length - 2; i += 1) {
            if (bytes[i] === 0x21 && bytes[i + 1] === 0xf9 && bytes[i + 2] === 0x04) {
                frames += 1;
                if (frames > 1) return true;
            }
        }

        return false;
    } catch {
        return true;
    }
}

async function processFile(file, returnResult = false) {
    if (!file) return null;

    if (!isAllowedFile(file)) {
        emit("error", "format-not-allowed");
        return null;
    }

    if (file.size > MAX_FILE_SIZE) {
        emit("error", "size-exceeded");
        return null;
    }

    const extension = getExtension(file);
    const isGif = file.type === "image/gif" || extension === "gif";

    if (isGif) {
        const animated = await isAnimatedGif(file);
        if (animated) {
            emit("error", "animated-gif");
            return null;
        }
    }

    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = typeof reader.result === "string" ? reader.result : "";
            if (!returnResult) emit("update:modelValue", result);
            resolve(result);
        };
        reader.readAsDataURL(file);
    });
}

async function handleFiles(files) {
    if (!files.length) return;

    if (props.multiple) {
        const results = [];
        for (const file of files) {
            const res = await processFile(file, true);
            if (res) results.push(res);
        }
        if (results.length) emit("files-added", results);
    } else {
        processFile(files[0]);
    }
}

async function handleFileChange(event) {
    const files = Array.from(event.target?.files || []);
    await handleFiles(files);
    if (fileInput.value) fileInput.value.value = "";
}

async function handleDrop(event) {
    isDragging.value = false;
    const files = Array.from(event.dataTransfer?.files || []);
    await handleFiles(files);
}

function clearImage() {
    emit("update:modelValue", "");
}
</script>

<template>
    <div :class="disabled ? 'pointer-events-none opacity-50' : ''">
        <input
            ref="fileInput"
            type="file"
            accept=".png,.jpg,.jpeg,.webp,.gif"
            class="hidden"
            :multiple="multiple"
            :disabled="disabled"
            @change="handleFileChange"
        />

        <div
            v-if="!modelValue"
            class="flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed px-4 py-5 text-center transition-colors"
            :class="isDragging ? 'border-primary bg-primary/5' : 'border-border bg-card hover:border-primary/60 hover:bg-muted/30'"
            @click="openFilePicker"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop"
        >
            <div class="mb-1 flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <CloudUpload class="h-5 w-5" />
            </div>
            <p class="text-sm text-muted-foreground">
                Перетащите или <span class="font-semibold text-primary underline decoration-dotted underline-offset-2">выберите файл</span>
            </p>
            <p class="text-xs text-muted-foreground/80">PNG, JPG, WEBP, GIF — до 10 МБ</p>
        </div>

        <div v-else class="relative overflow-hidden rounded-xl border">
            <img :src="modelValue" alt="Превью" class="block max-h-[300px] w-full bg-muted object-contain" />
            <button
                v-if="!disabled"
                type="button"
                class="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-lg border bg-background/90 text-muted-foreground shadow-sm hover:text-destructive"
                @click="clearImage"
            >
                <X class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>