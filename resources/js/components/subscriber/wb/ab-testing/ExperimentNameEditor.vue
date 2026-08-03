<script setup>
import { nextTick, ref, watch } from "vue";
import axios from "axios";
import { Check, Pencil, X } from "lucide-vue-next";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    experiment: {
        type: Object,
        required: true,
    },
    updateUrl: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["updated"]);

const { showError, showSuccess } = useFlashToast();

const editing = ref(false);
const draft = ref(props.experiment?.name ?? "");
const saving = ref(false);
const inputRef = ref(null);

watch(
    () => props.experiment?.name,
    (value) => {
        if (!editing.value) {
            draft.value = value ?? "";
        }
    },
);

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

async function startEdit(event) {
    event?.stopPropagation?.();
    draft.value = props.experiment?.name ?? "";
    editing.value = true;
    await nextTick();
    inputRef.value?.focus?.();
    inputRef.value?.select?.();
}

function cancelEdit(event) {
    event?.stopPropagation?.();
    draft.value = props.experiment?.name ?? "";
    editing.value = false;
}

async function save(event) {
    event?.stopPropagation?.();
    event?.preventDefault?.();

    const name = String(draft.value ?? "").trim();
    if (!name) {
        showError("Укажите название эксперимента");
        return;
    }

    if (name === (props.experiment?.name ?? "")) {
        editing.value = false;
        return;
    }

    if (saving.value) {
        return;
    }

    saving.value = true;
    try {
        const { data } = await axios.patch(
            props.updateUrl,
            { name },
            {
                headers: {
                    "X-CSRF-TOKEN": getCsrfToken(),
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            },
        );

        if (!data?.success || !data?.experiment) {
            showError(data?.messages?.[0] || "Не удалось сохранить название");
            return;
        }

        emit("updated", data.experiment);
        draft.value = data.experiment.name;
        editing.value = false;
        showSuccess(data.messages?.[0] || "Название сохранено");
    } catch (error) {
        const message =
            error?.response?.data?.errors?.name?.[0] ||
            error?.response?.data?.messages?.[0] ||
            error?.response?.data?.message ||
            "Не удалось сохранить название";
        showError(message);
    } finally {
        saving.value = false;
    }
}

function onKeydown(event) {
    if (event.key === "Enter") {
        save(event);
    }
    if (event.key === "Escape") {
        cancelEdit(event);
    }
}
</script>

<template>
    <div class="inline-flex min-w-0 max-w-full items-center gap-1.5" @click.stop>
        <template v-if="!editing">
            <span class="truncate font-medium text-foreground" :title="experiment.name">
                {{ experiment.name }}
            </span>
            <button
                type="button"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground"
                title="Переименовать"
                @click="startEdit"
            >
                <Pencil class="h-3.5 w-3.5" />
            </button>
        </template>
        <template v-else>
            <input
                ref="inputRef"
                v-model="draft"
                type="text"
                maxlength="255"
                class="h-8 min-w-[12rem] max-w-full flex-1 rounded-md border border-input bg-background px-2 text-sm text-foreground outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring"
                :disabled="saving"
                @keydown="onKeydown"
                @click.stop
            />
            <button
                type="button"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-emerald-600 transition hover:bg-emerald-500/10 disabled:opacity-50"
                title="Сохранить"
                :disabled="saving"
                @click="save"
            >
                <Check class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:opacity-50"
                title="Отмена"
                :disabled="saving"
                @click="cancelEdit"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </template>
    </div>
</template>
