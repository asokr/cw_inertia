<script setup>
import { computed, ref } from "vue";
import { Trash2 } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import { formatLimitLabel } from "@/utils/limitLabels";

const model = defineModel({
    type: Object,
    required: true,
});

const props = defineProps({
    limitKeys: { type: Array, default: () => [] },
    planLimits: {
        type: Object,
        default: () => ({ limits_plan: {}, limits_month: {} }),
    },
    editable: { type: Boolean, default: true },
});

function tariffPlanValue(key) {
    return props.planLimits?.limits_plan?.[key];
}

function tariffMonthValue(key) {
    return props.planLimits?.limits_month?.[key];
}

const newPlanKey = ref("");
const newMonthKey = ref("");
const newExtraKey = ref("");

const planEntries = computed(() => Object.entries(model.value.limits_plan ?? {}));
const monthEntries = computed(() => Object.entries(model.value.limits_month ?? {}));
const extraEntries = computed(() => Object.entries(model.value.extra_limits_month ?? {}));

function availableKeys(existingKeys) {
    const used = new Set(existingKeys);
    return props.limitKeys.filter((key) => !used.has(key));
}

const availablePlanKeys = computed(() => availableKeys(Object.keys(model.value.limits_plan ?? {})));
const availableMonthKeys = computed(() => availableKeys(Object.keys(model.value.limits_month ?? {})));
const availableExtraKeys = computed(() => availableKeys(Object.keys(model.value.extra_limits_month ?? {})));

function ensureField(field) {
    if (!model.value[field] || typeof model.value[field] !== "object") {
        model.value[field] = {};
    }
}

function updateLimit(field, key, value) {
    ensureField(field);
    model.value[field] = {
        ...model.value[field],
        [key]: Math.max(0, Number.parseInt(String(value), 10) || 0),
    };
}

function removeLimit(field, key) {
    ensureField(field);
    const next = { ...model.value[field] };
    delete next[key];
    model.value[field] = next;
}

function addLimit(field, key) {
    if (!key) return;

    ensureField(field);
    if (model.value[field][key] !== undefined) {
        return;
    }

    model.value[field] = {
        ...model.value[field],
        [key]: 0,
    };
}

function addPlanLimit() {
    addLimit("limits_plan", newPlanKey.value);
    newPlanKey.value = "";
}

function addMonthLimit() {
    addLimit("limits_month", newMonthKey.value);
    newMonthKey.value = "";
}

function addExtraLimit() {
    addLimit("extra_limits_month", newExtraKey.value);
    newExtraKey.value = "";
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                <p class="font-medium">По тарифу</p>
                <p v-if="planEntries.length" class="text-xs text-muted-foreground">остаток / по тарифу</p>
            </div>
            <div v-if="planEntries.length" class="space-y-2">
                <div
                    v-for="[key, value] in planEntries"
                    :key="`plan-${key}`"
                    class="flex items-center gap-2"
                >
                    <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                        {{ formatLimitLabel(key) }}
                    </span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <Input
                            v-if="editable"
                            :model-value="value"
                            type="number"
                            min="0"
                            step="1"
                            class="w-20"
                            @update:model-value="updateLimit('limits_plan', key, $event)"
                        />
                        <span v-else class="w-20 text-right text-sm font-semibold tabular-nums">{{ value }}</span>
                        <span
                            v-if="tariffPlanValue(key) !== undefined"
                            class="min-w-[3rem] text-right text-xs text-muted-foreground tabular-nums"
                        >
                            / {{ tariffPlanValue(key) }}
                        </span>
                    </div>
                    <Button
                        v-if="editable"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                        @click="removeLimit('limits_plan', key)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">Нет плановых лимитов</p>
            <div v-if="editable && availablePlanKeys.length" class="mt-3 flex flex-wrap items-center gap-2">
                <Select v-model="newPlanKey" class="min-w-[12rem]">
                    <option value="">Добавить лимит</option>
                    <option v-for="key in availablePlanKeys" :key="key" :value="key">{{ formatLimitLabel(key) }}</option>
                </Select>
                <Button type="button" variant="outline" size="sm" :disabled="!newPlanKey" @click="addPlanLimit">
                    Добавить
                </Button>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                <p class="font-medium">На период тарифа</p>
                <p v-if="monthEntries.length" class="text-xs text-muted-foreground">остаток / по тарифу</p>
            </div>
            <div v-if="monthEntries.length" class="space-y-2">
                <div
                    v-for="[key, value] in monthEntries"
                    :key="`month-${key}`"
                    class="flex items-center gap-2"
                >
                    <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                        {{ formatLimitLabel(key) }}
                    </span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <Input
                            v-if="editable"
                            :model-value="value"
                            type="number"
                            min="0"
                            step="1"
                            class="w-20"
                            @update:model-value="updateLimit('limits_month', key, $event)"
                        />
                        <span v-else class="w-20 text-right text-sm font-semibold tabular-nums">{{ value }}</span>
                        <span
                            v-if="tariffMonthValue(key) !== undefined"
                            class="min-w-[3rem] text-right text-xs text-muted-foreground tabular-nums"
                        >
                            / {{ tariffMonthValue(key) }}
                        </span>
                    </div>
                    <Button
                        v-if="editable"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                        @click="removeLimit('limits_month', key)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">Нет месячных лимитов</p>
            <div v-if="editable && availableMonthKeys.length" class="mt-3 flex flex-wrap items-center gap-2">
                <Select v-model="newMonthKey" class="min-w-[12rem]">
                    <option value="">Добавить лимит</option>
                    <option v-for="key in availableMonthKeys" :key="key" :value="key">{{ formatLimitLabel(key) }}</option>
                </Select>
                <Button type="button" variant="outline" size="sm" :disabled="!newMonthKey" @click="addMonthLimit">
                    Добавить
                </Button>
            </div>
        </div>

        <div>
            <p class="mb-2 text-sm font-medium">Дополнительные лимиты</p>
            <div v-if="extraEntries.length" class="space-y-2">
                <div
                    v-for="[key, value] in extraEntries"
                    :key="`extra-${key}`"
                    class="flex items-center gap-2"
                >
                    <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">
                        {{ formatLimitLabel(key) }}
                    </span>
                    <div class="flex shrink-0 items-center gap-1.5">
                        <Input
                            v-if="editable"
                            :model-value="value"
                            type="number"
                            min="0"
                            step="1"
                            class="w-20"
                            @update:model-value="updateLimit('extra_limits_month', key, $event)"
                        />
                        <span v-else class="w-20 text-right text-sm font-semibold tabular-nums text-primary">+{{ value }}</span>
                        <span
                            v-if="tariffMonthValue(key) !== undefined"
                            class="min-w-[3rem] text-right text-xs text-muted-foreground tabular-nums"
                            title="Месячный лимит по тарифу"
                        >
                            / {{ tariffMonthValue(key) }}
                        </span>
                    </div>
                    <Button
                        v-if="editable"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 shrink-0"
                        @click="removeLimit('extra_limits_month', key)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">Нет дополнительных лимитов</p>
            <div v-if="editable && availableExtraKeys.length" class="mt-3 flex flex-wrap items-center gap-2">
                <Select v-model="newExtraKey" class="min-w-[12rem]">
                    <option value="">Добавить лимит</option>
                    <option v-for="key in availableExtraKeys" :key="key" :value="key">{{ formatLimitLabel(key) }}</option>
                </Select>
                <Button type="button" variant="outline" size="sm" :disabled="!newExtraKey" @click="addExtraLimit">
                    Добавить
                </Button>
            </div>
        </div>
    </div>
</template>