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
        default: () => ({ limits_plan: {} }),
    },
    editable: { type: Boolean, default: true },
});

function tariffPlanValue(key) {
    return props.planLimits?.limits_plan?.[key];
}

const newPlanKey = ref("");

const planEntries = computed(() => Object.entries(model.value.limits_plan ?? {}));

function availableKeys(existingKeys) {
    const used = new Set(existingKeys);
    return props.limitKeys.filter((key) => !used.has(key));
}

const availablePlanKeys = computed(() => availableKeys(Object.keys(model.value.limits_plan ?? {})));

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
            <p v-else class="text-sm text-muted-foreground">Нет лимитов тарифа</p>
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
    </div>
</template>
