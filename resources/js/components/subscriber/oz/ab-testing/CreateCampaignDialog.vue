<script setup>
import { ref, watch } from "vue";
import Dialog from "@/components/ui/Dialog.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    defaultName: {
        type: String,
        default: "",
    },
    submitting: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:open", "submit"]);

const name = ref("");

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            name.value = props.defaultName || "";
        }
    },
);

function close() {
    if (props.submitting) {
        return;
    }
    emit("update:open", false);
}

function submit() {
    if (props.submitting) {
        return;
    }

    emit("submit", {
        name: name.value.trim() || props.defaultName,
    });
}
</script>

<template>
    <Dialog
        :open="open"
        title="Создать кампанию"
        description="Кампания появится в рекламном кабинете Ozon. Запуск — вместе с экспериментом."
        class="max-w-lg"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="ab-campaign-name">Название</Label>
                <Input
                    id="ab-campaign-name"
                    v-model="name"
                    :disabled="submitting"
                    placeholder="A/B тест — артикул"
                    maxlength="255"
                />
            </div>

            <p class="text-xs text-muted-foreground">
                Создаётся кампания с оплатой за клик, выбранный товар добавится сам.
                Реклама списывается с рекламного счёта Ozon. Пополнить счёт через сервис нельзя —
                это делается в кабинете Ozon.
            </p>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="submitting" @click="close">
                Отмена
            </Button>
            <Button :disabled="submitting" @click="submit">
                {{ submitting ? "Создание…" : "Создать" }}
            </Button>
        </template>
    </Dialog>
</template>
