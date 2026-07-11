<script setup>
import { computed, ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { useWbApiRateLimitCooldown } from "@/composables/useWbApiRateLimitCooldown";

const props = defineProps({
    cabinetId: { type: Number, required: true },
    submitLabel: { type: String, default: "Обновить данные" },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(["polling-start"]);

const WB_RATE_LIMIT_TOOLTIP = "Ожидаем окончания лимита Wildberries";

const { isActive: isRateLimitActive, start: startRateLimitCooldown } = useWbApiRateLimitCooldown(
    () => props.cabinetId,
);

const today = new Date().toISOString().slice(0, 10);

const form = useForm({
    date_from: "",
    date_to: "",
    dop_rashod: "",
    nalog_percent: "",
});

function isValidIsoDate(value) {
    if (!value || typeof value !== "string") return false;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;

    const [year, month, day] = value.split("-").map(Number);
    const parsed = new Date(year, month - 1, day);

    return parsed.getFullYear() === year
        && parsed.getMonth() === month - 1
        && parsed.getDate() === day;
}

function getDateToLimit(dateFrom) {
    if (!isValidIsoDate(dateFrom)) return today;

    const [y, m, d] = dateFrom.split("-").map(Number);
    const dateObj = new Date(y, m - 1, d);
    dateObj.setDate(dateObj.getDate() + 30);

    const plus30 = dateObj.toISOString().slice(0, 10);
    return plus30 > today ? today : plus30;
}

function normalizeDates() {
    if (form.date_from && (!isValidIsoDate(form.date_from) || form.date_from > today)) {
        form.date_from = today;
    }

    const allowedDateToMax = form.date_from ? getDateToLimit(form.date_from) : today;

    if (!form.date_to) return;

    if (!isValidIsoDate(form.date_to)) {
        form.date_to = "";
        return;
    }

    if (form.date_from && form.date_to < form.date_from) {
        form.date_to = form.date_from;
        return;
    }

    if (form.date_to > allowedDateToMax) {
        form.date_to = allowedDateToMax;
    }
}

watch(() => form.date_from, normalizeDates);
watch(() => form.date_to, normalizeDates);

const maxDateTo = computed(() => {
    if (!form.date_from || !isValidIsoDate(form.date_from)) return today;
    return getDateToLimit(form.date_from);
});

function submit() {
    normalizeDates();

    form.post(`/panel/wb/profitability/cabinets/${props.cabinetId}/report`, {
        preserveScroll: true,
        onSuccess: () => {
            startRateLimitCooldown();
            emit("polling-start");
        },
    });
}

const isBusy = computed(() => props.processing || form.processing);
const isSubmitDisabled = computed(() => isBusy.value || isRateLimitActive.value);
const showRateLimitTooltip = computed(() => isRateLimitActive.value && !isBusy.value);
</script>

<template>
    <div class="space-y-4">
        <Alert variant="destructive">
            <strong>Важно!</strong> Для верного расчёта у вас должны быть актуальные данные в инструменте
            «Ценообразование». Расчёт будет неверным, если в Ценообразовании не будет всей номенклатуры с установленной себестоимостью.
        </Alert>

        <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="submit">
            <div class="space-y-1">
                <Label for="date_from">Дата с</Label>
                <Input
                    id="date_from"
                    v-model="form.date_from"
                    type="date"
                    :max="today"
                    required
                    @blur="normalizeDates"
                />
            </div>

            <div class="space-y-1">
                <Label for="date_to">Дата по</Label>
                <Input
                    id="date_to"
                    v-model="form.date_to"
                    type="date"
                    :min="form.date_from || undefined"
                    :max="maxDateTo"
                    required
                    @blur="normalizeDates"
                />
            </div>

            <div class="space-y-1">
                <Label for="dop_rashod">Доп. расходы</Label>
                <Input
                    id="dop_rashod"
                    v-model="form.dop_rashod"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="0"
                />
            </div>

            <div class="space-y-1">
                <Label for="nalog_percent">Налог, %</Label>
                <Input
                    id="nalog_percent"
                    v-model="form.nalog_percent"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    placeholder="0"
                />
            </div>

            <div class="flex items-end">
                <div
                    class="inline-flex w-full sm:w-auto"
                    :class="{ 'group relative': showRateLimitTooltip }"
                >
                    <Button
                        type="submit"
                        class="w-full sm:w-auto"
                        :class="{ 'pointer-events-none': showRateLimitTooltip }"
                        :disabled="isSubmitDisabled"
                        :aria-describedby="showRateLimitTooltip ? 'wb-rate-limit-hint' : undefined"
                    >
                        {{ submitLabel }}
                    </Button>

                    <span
                        v-if="showRateLimitTooltip"
                        id="wb-rate-limit-hint"
                        role="tooltip"
                        class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 hidden w-max max-w-[16rem] -translate-x-1/2 rounded-md bg-foreground px-3 py-1.5 text-center text-xs text-background shadow-md group-hover:block"
                    >
                        {{ WB_RATE_LIMIT_TOOLTIP }}
                    </span>
                </div>
            </div>
        </form>

        <p v-if="form.errors.date_from" class="text-sm text-destructive">{{ form.errors.date_from }}</p>
        <p v-if="form.errors.date_to" class="text-sm text-destructive">{{ form.errors.date_to }}</p>
    </div>
</template>