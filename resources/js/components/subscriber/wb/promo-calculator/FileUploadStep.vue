<script setup>
import { ref } from "vue";
import { CheckCircle2, FileSpreadsheet, Upload } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const emit = defineEmits(["uploaded"]);

const { uploadFile } = usePromoCalculatorApi();
const { showError } = useFlashToast();
const fileInput = ref(null);
const uploading = ref(false);
const uploaded = ref(false);
const fileName = ref("");
const dragOver = ref(false);

function openPicker() {
    fileInput.value?.click();
}

async function handleFile(file) {
    if (!file) return;

    uploading.value = true;
    uploaded.value = false;

    try {
        const path = await uploadFile(file);
        uploaded.value = true;
        fileName.value = file.name;
        emit("uploaded", path);
    } catch (err) {
        showError(err?.message ?? "Не удалось загрузить файл");
    } finally {
        uploading.value = false;
    }
}

function onFileChange(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    handleFile(file);
}

function onDrop(event) {
    dragOver.value = false;
    handleFile(event.dataTransfer.files?.[0] ?? null);
}
</script>

<template>
    <Card class="overflow-hidden">
        <div class="border-b border-border/60 px-5 py-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                        Шаг 1
                    </p>
                    <h3 class="mt-1 text-base font-semibold">Загрузите отчёт по акции</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Excel из раздела WB «Цены и скидки → Календарь акций».
                    </p>
                </div>
                <div
                    v-if="uploaded"
                    class="flex shrink-0 items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                >
                    <CheckCircle2 class="h-3.5 w-3.5" />
                    Готово
                </div>
            </div>
        </div>

        <div class="p-5">
            <div
                class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center transition-colors"
                :class="
                    dragOver
                        ? 'border-primary bg-primary/5'
                        : uploaded
                          ? 'border-emerald-500/40 bg-emerald-500/5'
                          : 'border-border/80 bg-muted/20'
                "
                @dragover.prevent="dragOver = true"
                @dragleave.prevent="dragOver = false"
                @drop.prevent="onDrop"
            >
                <div
                    class="flex h-12 w-12 items-center justify-center rounded-full"
                    :class="uploaded ? 'bg-emerald-500/15' : 'bg-muted'"
                >
                    <CheckCircle2 v-if="uploaded" class="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
                    <FileSpreadsheet v-else class="h-6 w-6 text-muted-foreground" />
                </div>

                <div class="space-y-1">
                    <p class="text-sm font-medium">
                        {{
                            uploading
                                ? "Загрузка…"
                                : uploaded
                                  ? "Файл загружен"
                                  : "Перетащите .xlsx сюда или выберите на диске"
                        }}
                    </p>
                    <p v-if="fileName" class="text-xs text-muted-foreground">{{ fileName }}</p>
                    <p v-else class="text-xs text-muted-foreground">Только формат Office Open XML (.xlsx)</p>
                </div>

                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    accept="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.xlsx"
                    @change="onFileChange"
                />

                <Button type="button" variant="outline" :disabled="uploading" @click="openPicker">
                    <Upload class="mr-2 h-4 w-4" />
                    {{ uploaded ? "Заменить файл" : "Выбрать файл" }}
                </Button>
            </div>
        </div>
    </Card>
</template>
