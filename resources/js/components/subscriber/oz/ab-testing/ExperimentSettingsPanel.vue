<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { ChevronDown, Settings2 } from "lucide-vue-next";
import ExperimentSettingField from "./ExperimentSettingField.vue";
import Button from "@/components/ui/Button.vue";
import {
    formatSettingsSummary,
    normalizeSettings,
    settingsFields,
    validateSettingsClient,
} from "./abTestingSettings.js";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    experiment: {
        type: Object,
        default: null,
    },
    baseUrl: {
        type: String,
        required: true,
    },
    defaultOpen: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["experiment-updated"]);

const { showError, showSuccess } = useFlashToast();

const open = ref(props.defaultOpen);
const saving = ref(false);
const form = ref(normalizeSettings(props.experiment?.settings));
/** Last values confirmed on server (or defaults before first save). */
const lastSaved = ref(normalizeSettings(props.experiment?.settings));
const fieldErrors = ref({});

/** Bound campaign payment type: cpm | cpc — drives bid field labels/limits. */
const paymentType = computed(
    () => props.experiment?.campaign_payment_type || "cpm",
);

const fields = computed(() => settingsFields(paymentType.value));

const summary = ref(
    props.experiment?.settings_summary ||
        formatSettingsSummary(lastSaved.value, paymentType.value),
);

const isDraft = computed(() => (props.experiment?.status ?? "draft") === "draft");
const editable = computed(
    () => !!props.experiment?.can_edit && !!props.experiment?.id,
);
const settingsReady = computed(() => !!props.experiment?.settings_ready);

const settingsUrl = computed(() => {
    if (!props.experiment?.id) {
        return null;
    }
    return `${props.baseUrl}/experiments/${props.experiment.id}/settings`;
});

const isDirty = computed(() => {
    const a = normalizeSettings(form.value);
    const b = normalizeSettings(lastSaved.value);
    return (
        a.impressions_per_photo !== b.impressions_per_photo ||
        a.impressions_per_round !== b.impressions_per_round ||
        a.round_minutes !== b.round_minutes ||
        a.cpm !== b.cpm
    );
});

const canSave = computed(
    () => editable.value && !saving.value && (isDirty.value || !settingsReady.value),
);

const statusBadge = computed(() => {
    if (!editable.value) {
        return { label: "Только просмотр", tone: "muted" };
    }
    if (saving.value) {
        return { label: "Сохранение…", tone: "muted" };
    }
    if (isDirty.value || !settingsReady.value) {
        return { label: "Не сохранены", tone: "warn" };
    }
    return { label: "Сохранены", tone: "ok" };
});

function syncFromExperiment(experiment) {
    if (!experiment) {
        return;
    }
    const next = normalizeSettings(experiment.settings);
    const pay = experiment.campaign_payment_type || "cpm";
    form.value = { ...next };
    lastSaved.value = { ...next };
    summary.value =
        experiment.settings_summary || formatSettingsSummary(next, pay);
    fieldErrors.value = {};
}

watch(
    () => props.experiment?.id,
    () => {
        syncFromExperiment(props.experiment);
    },
);

watch(
    () => props.experiment?.settings,
    () => {
        if (saving.value || isDirty.value) {
            return;
        }
        if (props.experiment) {
            syncFromExperiment(props.experiment);
        }
    },
    { deep: true },
);

// Re-label summary when bound campaign payment type changes (e.g. after bind).
watch(paymentType, (type) => {
    if (!isDirty.value) {
        summary.value =
            props.experiment?.settings_summary ||
            formatSettingsSummary(lastSaved.value, type);
    }
    // Clear bid error if limits changed (CPC allows lower values).
    if (fieldErrors.value.cpm) {
        const recheck = validateSettingsClient(form.value, type);
        if (!recheck.cpm) {
            const next = { ...fieldErrors.value };
            delete next.cpm;
            fieldErrors.value = next;
        }
    }
});

async function saveSettings() {
    if (!settingsUrl.value || !editable.value || saving.value) {
        return false;
    }

    const payload = normalizeSettings(form.value);
    const localErrors = validateSettingsClient(payload, paymentType.value);
    if (Object.keys(localErrors).length) {
        fieldErrors.value = localErrors;
        open.value = true;
        return false;
    }

    fieldErrors.value = {};
    saving.value = true;

    try {
        const { data } = await axios.patch(settingsUrl.value, payload);

        if (!data?.success) {
            const errors = data?.errors ?? {};
            fieldErrors.value = Object.fromEntries(
                Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]),
            );
            if (Object.keys(fieldErrors.value).length) {
                open.value = true;
            }
            showError(data?.messages?.[0] || "Не удалось сохранить настройки");
            return false;
        }

        if (data.experiment) {
            const pay = data.experiment.campaign_payment_type || paymentType.value;
            summary.value =
                data.experiment.settings_summary ||
                formatSettingsSummary(payload, pay);
            form.value = normalizeSettings(data.experiment.settings);
            lastSaved.value = normalizeSettings(data.experiment.settings);
            emit("experiment-updated", data.experiment);
        } else {
            lastSaved.value = { ...payload };
            form.value = { ...payload };
            summary.value = formatSettingsSummary(payload, paymentType.value);
        }

        showSuccess(data?.messages?.[0] || "Настройки сохранены");
        return true;
    } catch (error) {
        const errors = error?.response?.data?.errors ?? {};
        fieldErrors.value = Object.fromEntries(
            Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : String(v)]),
        );
        if (Object.keys(fieldErrors.value).length) {
            open.value = true;
        }
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось сохранить настройки",
        );
        return false;
    } finally {
        saving.value = false;
    }
}

function toggle() {
    open.value = !open.value;
}

const rootEl = ref(null);
const saveButtonEl = ref(null);

function expand() {
    open.value = true;
    // Next tick: panel content is visible — bring save action into view.
    requestAnimationFrame(() => {
        const target = saveButtonEl.value?.$el ?? saveButtonEl.value ?? rootEl.value;
        if (target && typeof target.scrollIntoView === "function") {
            target.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    });
}

defineExpose({ expand, saveSettings, isDirty });
</script>

<template>
    <div ref="rootEl" class="rounded-xl border border-border/70 bg-card/60 backdrop-blur">
        <button
            type="button"
            class="flex w-full items-start gap-3 px-3.5 py-3 text-left transition hover:bg-muted/30 sm:px-4"
            @click="toggle"
        >
            <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Settings2 class="h-4 w-4" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                    <p class="text-sm font-semibold text-foreground">
                        Настройки эксперимента
                    </p>
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                        :class="{
                            'bg-muted/80 text-muted-foreground': statusBadge.tone === 'muted',
                            'bg-amber-500/15 text-amber-700 dark:text-amber-400': statusBadge.tone === 'warn',
                            'bg-primary/10 text-primary': statusBadge.tone === 'ok',
                        }"
                    >
                        {{ statusBadge.label }}
                    </span>
                </div>
                <p class="mt-0.5 text-xs tabular-nums text-muted-foreground sm:text-sm">
                    {{ summary }}
                    <span v-if="isDirty && editable" class="text-amber-700 dark:text-amber-400">
                        · есть несохранённые изменения
                    </span>
                </p>
            </div>
            <ChevronDown
                class="mt-1 h-4 w-4 shrink-0 text-muted-foreground transition-transform"
                :class="open ? 'rotate-180' : ''"
            />
        </button>

        <div
            v-show="open"
            class="border-t border-border/60"
        >
            <div class="px-3.5 pb-4 pt-3 sm:px-4">
                <p class="mb-3 text-xs leading-snug text-muted-foreground">
                    Параметры ротации вариантов фото и рекламной ставки.
                    <span v-if="editable" class="font-medium text-foreground/80">
                        Нажмите «Сохранить настройки», чтобы зафиксировать значения. Без сохранения запуск недоступен.
                    </span>
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <ExperimentSettingField
                        v-for="field in fields"
                        :key="field.key + '-' + (field.title || '')"
                        :title="field.title"
                        :description="field.description"
                        :unit="field.unit"
                        :min="field.min"
                        :max="field.max"
                        :model-value="form[field.key]"
                        :error="fieldErrors[field.key] || ''"
                        :disabled="!editable"
                        @update:model-value="form[field.key] = $event"
                    />
                </div>

                <div
                    v-if="editable"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border/50 pt-3"
                >
                    <p class="text-xs text-muted-foreground">
                        <template v-if="!settingsReady && !isDirty">
                            Значения по умолчанию — сохраните, чтобы продолжить.
                        </template>
                        <template v-else-if="isDirty">
                            Есть изменения, которые ещё не сохранены.
                        </template>
                        <template v-else>
                            Текущие параметры сохранены в эксперименте.
                        </template>
                    </p>
                    <Button
                        ref="saveButtonEl"
                        size="sm"
                        :disabled="!canSave"
                        @click="saveSettings"
                    >
                        {{ saving ? "Сохранение…" : "Сохранить настройки" }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
