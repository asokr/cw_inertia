<script setup>
import { ref } from "vue";
import { Check, Upload } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const emit = defineEmits(["uploaded"]);

const { uploadFile } = usePromoCalculatorApi();
const fileInput = ref(null);
const uploading = ref(false);
const uploaded = ref(false);
const fileName = ref("");
const error = ref("");

function openPicker() {
    fileInput.value?.click();
}

async function onFileChange(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;

    uploading.value = true;
    error.value = "";
    uploaded.value = false;

    try {
        const path = await uploadFile(file);
        uploaded.value = true;
        fileName.value = file.name;
        emit("uploaded", path);
    } catch (err) {
        error.value = err?.message ?? "Не удалось загрузить файл";
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <h3 class="text-lg font-semibold">Шаг 2: Загрузите данные с товарами, участвующими в акции</h3>

        <div class="flex flex-wrap items-center gap-4">
            <Button :disabled="uploading" @click="openPicker">
                <Check v-if="uploaded" class="mr-2 h-4 w-4" />
                <Upload v-else class="mr-2 h-4 w-4" />
                {{ uploading ? "Загрузка…" : uploaded ? "Файл загружен" : "Загрузить" }}
            </Button>

            <p class="text-sm text-muted-foreground">
                Скачайте отчёт с товарами по акции: Цены и скидки → Календарь акций (формат Excel).
            </p>
        </div>

        <p v-if="fileName" class="text-sm text-muted-foreground">{{ fileName }}</p>
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <input
            ref="fileInput"
            type="file"
            class="hidden"
            accept="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            @change="onFileChange"
        />
    </div>
</template>