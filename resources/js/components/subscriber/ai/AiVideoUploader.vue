<script setup>
import { ref } from "vue";
import { Plus } from "lucide-vue-next";

const props = defineProps({
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(["files-added", "error"]);

const fileInput = ref(null);
const isDragging = ref(false);

const MAX_FILE_SIZE = 25 * 1024 * 1024;
const MAX_DURATION = 8.7;

function openFilePicker() {
    if (!props.disabled) {
        fileInput.value?.click();
    }
}

function isAllowedFile(file) {
    if (!file) {
        return false;
    }

    const name = (file.name || "").toLowerCase();
    if (file.type === "video/mp4") {
        return true;
    }

    return name.endsWith(".mp4");
}

function readVideoMeta(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement("video");
        video.preload = "metadata";
        video.muted = true;
        video.src = url;

        const cleanup = () => {
            URL.revokeObjectURL(url);
            video.removeAttribute("src");
            video.load();
        };

        video.onloadedmetadata = () => {
            const duration = Number(video.duration);
            const height = Number(video.videoHeight);
            cleanup();
            resolve({ duration, height });
        };

        video.onerror = () => {
            cleanup();
            reject(new Error("meta"));
        };
    });
}

async function processFile(file) {
    if (!isAllowedFile(file)) {
        emit("error", "format-not-allowed");
        return null;
    }

    if (file.size > MAX_FILE_SIZE) {
        emit("error", "size-exceeded");
        return null;
    }

    try {
        const meta = await readVideoMeta(file);
        if (!Number.isFinite(meta.duration) || meta.duration <= 0) {
            emit("error", "format-not-allowed");
            return null;
        }

        if (meta.duration > MAX_DURATION) {
            emit("error", "duration-exceeded");
            return null;
        }

        return {
            file,
            duration: meta.duration,
            height: meta.height || 0,
        };
    } catch {
        emit("error", "format-not-allowed");
        return null;
    }
}

async function handleFiles(files) {
    if (!files.length) {
        return;
    }

    const result = await processFile(files[0]);
    if (result) {
        emit("files-added", result);
    }
}

async function handleFileChange(event) {
    const files = Array.from(event.target?.files || []);
    await handleFiles(files);
    if (fileInput.value) {
        fileInput.value.value = "";
    }
}

async function handleDrop(event) {
    isDragging.value = false;
    if (props.disabled) {
        return;
    }

    await handleFiles(Array.from(event.dataTransfer?.files || []));
}
</script>

<template>
    <div :class="disabled ? 'pointer-events-none opacity-50' : ''">
        <input
            ref="fileInput"
            type="file"
            accept="video/mp4,.mp4"
            class="hidden"
            :disabled="disabled"
            @change="handleFileChange"
        />

        <div
            class="flex h-12 w-12 cursor-pointer items-center justify-center rounded-lg border border-dashed bg-muted/30 transition-colors hover:border-primary/50 hover:bg-muted/50"
            :class="isDragging ? 'border-primary bg-primary/5' : ''"
            @click="openFilePicker"
            @dragover.prevent="!disabled && (isDragging = true)"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop"
        >
            <Plus class="h-4 w-4 text-muted-foreground" />
        </div>
    </div>
</template>
