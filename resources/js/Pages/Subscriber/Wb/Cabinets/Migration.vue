<script setup>
import { computed, ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { Check, ChevronRight, KeyRound, Link2, Rocket, Trash2 } from "lucide-vue-next";
import ApiKeyField from "@/components/subscriber/tools/ApiKeyField.vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const props = defineProps({
    wizard: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const step = ref(1);

const cabinets = computed(() => props.wizard.cabinets ?? []);
const services = computed(() => props.wizard.services ?? []);
const totalOld = computed(() =>
    services.value.reduce((sum, group) => sum + (group.cabinets?.length ?? 0), 0)
);

// assignments: { [wbCabinetId]: { [serviceKey]: oldCabinetId } }
const assignments = ref({});
// deletions: Set of "serviceKey:oldId"
const deletions = ref(new Set());

const createForm = useForm({
    name: "",
    apikey: "",
});

const migrateForm = useForm({
    assignments: [],
    deletions: [],
});

function oldKey(serviceKey, oldId) {
    return `${serviceKey}:${oldId}`;
}

const mappedCount = computed(() => {
    const used = new Set();
    Object.values(assignments.value).forEach((map) => {
        Object.entries(map || {}).forEach(([service, oldId]) => {
            if (oldId) {
                used.add(oldKey(service, oldId));
            }
        });
    });
    return used.size;
});

const deletedCount = computed(() => deletions.value.size);

const remainingCount = computed(() =>
    Math.max(0, totalOld.value - mappedCount.value - deletedCount.value)
);

const canGoStep2 = computed(() => cabinets.value.length > 0);
const canGoStep3 = computed(() => remainingCount.value === 0 && totalOld.value > 0);

function isDeleted(serviceKey, oldId) {
    return deletions.value.has(oldKey(serviceKey, oldId));
}

function isOldUsed(serviceKey, oldId, exceptCabinetId = null) {
    if (isDeleted(serviceKey, oldId)) {
        return true;
    }
    for (const [wbId, map] of Object.entries(assignments.value)) {
        if (exceptCabinetId && String(wbId) === String(exceptCabinetId)) {
            continue;
        }
        if (map?.[serviceKey] === oldId) {
            return true;
        }
    }
    return false;
}

function isChecked(wbCabinetId, serviceKey, oldId) {
    return assignments.value[wbCabinetId]?.[serviceKey] === oldId;
}

function clearMappingForOld(serviceKey, oldId) {
    Object.keys(assignments.value).forEach((wbId) => {
        if (assignments.value[wbId]?.[serviceKey] === oldId) {
            delete assignments.value[wbId][serviceKey];
        }
    });
}

function toggleMapping(wbCabinetId, serviceKey, oldId, checked = true) {
    if (isDeleted(serviceKey, oldId)) {
        return;
    }

    if (!assignments.value[wbCabinetId]) {
        assignments.value[wbCabinetId] = {};
    }

    if (!checked) {
        if (assignments.value[wbCabinetId][serviceKey] === oldId) {
            delete assignments.value[wbCabinetId][serviceKey];
        }
        return;
    }

    if (isOldUsed(serviceKey, oldId, wbCabinetId)) {
        return;
    }

    // Only one old cabinet per service per new shared cabinet
    assignments.value[wbCabinetId][serviceKey] = oldId;
}

function markForDelete(serviceKey, oldId) {
    clearMappingForOld(serviceKey, oldId);
    const next = new Set(deletions.value);
    next.add(oldKey(serviceKey, oldId));
    deletions.value = next;
}

function unmarkDelete(serviceKey, oldId) {
    const next = new Set(deletions.value);
    next.delete(oldKey(serviceKey, oldId));
    deletions.value = next;
}

function findOldName(serviceKey, oldId) {
    const group = services.value.find((s) => s.key === serviceKey);
    const old = group?.cabinets?.find((c) => c.id === oldId);
    return old?.name || `#${oldId}`;
}

function submitCreate() {
    createForm.post("/panel/wb/cabinets/migration/cabinets", {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
        },
    });
}

function buildPayload() {
    const assignmentRows = cabinets.value
        .map((cabinet) => {
            const map = assignments.value[cabinet.id] || {};
            const mappings = Object.entries(map)
                .filter(([, oldId]) => oldId)
                .map(([service, oldId]) => ({
                    service,
                    old_cabinet_id: oldId,
                }));

            return {
                wb_cabinet_id: cabinet.id,
                mappings,
            };
        })
        .filter((row) => row.mappings.length > 0);

    const deletionRows = Array.from(deletions.value).map((key) => {
        const [service, id] = key.split(":");
        return {
            service,
            old_cabinet_id: Number(id),
        };
    });

    return { assignmentRows, deletionRows };
}

function runMigration() {
    const { assignmentRows, deletionRows } = buildPayload();
    migrateForm.assignments = assignmentRows;
    migrateForm.deletions = deletionRows;
    migrateForm.post("/panel/wb/cabinets/migration/run", {
        preserveScroll: true,
    });
}

const deletionList = computed(() =>
    Array.from(deletions.value).map((key) => {
        const [service, id] = key.split(":");
        const group = services.value.find((s) => s.key === service);
        return {
            key,
            service,
            serviceLabel: group?.label || service,
            oldId: Number(id),
            name: findOldName(service, Number(id)),
        };
    })
);

const steps = [
    { id: 1, title: "Общие кабинеты", icon: KeyRound },
    { id: 2, title: "Привязка", icon: Link2 },
    { id: 3, title: "Перенос", icon: Rocket },
];
</script>

<template>
    <Head title="Обновление кабинетов" />

    <div class="min-h-screen bg-background text-foreground">
        <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-4 py-8 md:py-12">
            <div class="mb-8 text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-primary">Обновление платформы</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight md:text-3xl">
                    Обновление кабинетов
                </h1>
                <p class="mx-auto mt-3 max-w-2xl text-sm text-muted-foreground">
                    Теперь все инструменты Wildberries работают через один общий кабинет.
                    Завершите перенос, чтобы продолжить работу. Пропустить этот шаг нельзя.
                </p>
            </div>

            <div class="mb-8 flex flex-wrap items-center justify-center gap-2">
                <template v-for="(item, index) in steps" :key="item.id">
                    <div
                        class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm"
                        :class="
                            step === item.id
                                ? 'border-primary bg-primary/10 text-primary'
                                : step > item.id
                                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-600'
                                    : 'border-border text-muted-foreground'
                        "
                    >
                        <Check v-if="step > item.id" class="h-3.5 w-3.5" />
                        <component :is="item.icon" v-else class="h-3.5 w-3.5" />
                        <span>{{ item.title }}</span>
                    </div>
                    <ChevronRight
                        v-if="index < steps.length - 1"
                        class="hidden h-4 w-4 text-muted-foreground sm:block"
                    />
                </template>
            </div>

            <div class="mb-4 flex flex-wrap items-center justify-center gap-3 text-xs text-muted-foreground">
                <span>Общих кабинетов: <strong class="text-foreground">{{ cabinets.length }}</strong></span>
                <span>Старых: <strong class="text-foreground">{{ totalOld }}</strong></span>
                <span>Привязано: <strong class="text-foreground">{{ mappedCount }}</strong></span>
                <span>К удалению: <strong class="text-foreground">{{ deletedCount }}</strong></span>
                <span>Осталось: <strong class="text-foreground">{{ remainingCount }}</strong></span>
            </div>

            <Card class="flex-1 p-5 md:p-8">
                <!-- Step 1 -->
                <div v-if="step === 1" class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold">Создайте общие кабинеты</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Добавьте один или несколько общих кабинетов с актуальными API-ключами Wildberries.
                            Один общий кабинет будет использоваться во всех инструментах.
                        </p>
                    </div>

                    <Alert variant="warning" class="text-sm">
                        {{ wizard.api_key_warning }}
                    </Alert>

                    <div v-if="cabinets.length" class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="cabinet in cabinets"
                            :key="cabinet.id"
                            class="rounded-lg border border-border bg-muted/30 px-3 py-2 text-sm"
                        >
                            {{ cabinet.name }}
                        </div>
                    </div>

                    <form class="space-y-4 rounded-xl border border-dashed border-border p-4" @submit.prevent="submitCreate">
                        <div class="space-y-2">
                            <Label>Название</Label>
                            <Input v-model="createForm.name" placeholder="ООО Ромашка" :error="Boolean(createForm.errors.name)" />
                            <p v-if="createForm.errors.name" class="text-xs text-destructive">{{ createForm.errors.name }}</p>
                        </div>
                        <ApiKeyField
                            v-model="createForm.apikey"
                            :error="Boolean(createForm.errors.apikey)"
                        />
                        <p v-if="createForm.errors.apikey" class="text-xs text-destructive">{{ createForm.errors.apikey }}</p>
                        <Button type="submit" :disabled="createForm.processing">
                            {{ createForm.processing ? "Проверка ключа…" : "Добавить кабинет" }}
                        </Button>
                    </form>

                    <div class="flex justify-end">
                        <Button :disabled="!canGoStep2" @click="step = 2">
                            Далее
                            <ChevronRight class="ml-1 h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <!-- Step 2 -->
                <div v-else-if="step === 2" class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold">Привяжите или удалите старые кабинеты</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Укажите, какие старые кабинеты относятся к каждому новому общему кабинету.
                            Ненужные можно <strong>удалить</strong> — будут удалены кабинет и все его данные в инструменте.
                            Каждый старый кабинет нужно либо привязать, либо удалить.
                        </p>
                    </div>

                    <Alert variant="warning" class="text-sm">
                        Удаление необратимо: вместе с кабинетом удаляются товары, отчёты, стратегии, отзывы и другие данные этого кабинета в инструменте.
                    </Alert>

                    <div v-if="!services.length" class="text-sm text-muted-foreground">
                        Старых кабинетов не найдено.
                    </div>

                    <!-- Flat list for delete actions by service -->
                    <div v-for="group in services" :key="`del-${group.key}`" class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ group.label }}
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div
                                v-for="old in group.cabinets"
                                :key="`old-${group.key}-${old.id}`"
                                class="flex items-start justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                :class="
                                    isDeleted(group.key, old.id)
                                        ? 'border-destructive/40 bg-destructive/5'
                                        : isOldUsed(group.key, old.id)
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border'
                                "
                            >
                                <div class="min-w-0">
                                    <p class="font-medium">{{ old.name }}</p>
                                    <p v-if="old.created_at" class="text-xs text-muted-foreground">{{ old.created_at }}</p>
                                    <p v-if="isDeleted(group.key, old.id)" class="mt-1 text-xs text-destructive">
                                        Будет удалён со всеми данными
                                    </p>
                                    <p
                                        v-else-if="isOldUsed(group.key, old.id)"
                                        class="mt-1 text-xs text-primary"
                                    >
                                        Привязан к общему кабинету
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col gap-1">
                                    <Button
                                        v-if="!isDeleted(group.key, old.id)"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="text-destructive"
                                        @click="markForDelete(group.key, old.id)"
                                    >
                                        <Trash2 class="mr-1 h-3.5 w-3.5" />
                                        Удалить
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        @click="unmarkDelete(group.key, old.id)"
                                    >
                                        Отменить
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-for="cabinet in cabinets"
                        :key="cabinet.id"
                        class="space-y-4 rounded-xl border border-border p-4"
                    >
                        <h3 class="font-medium">Привязать к общему кабинету «{{ cabinet.name }}»</h3>

                        <div
                            v-for="group in services"
                            :key="`${cabinet.id}-${group.key}`"
                            class="space-y-2"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ group.label }}
                            </p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label
                                    v-for="old in group.cabinets"
                                    :key="`${cabinet.id}-${group.key}-${old.id}`"
                                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-border px-3 py-2 text-sm"
                                    :class="{
                                        'opacity-40': (isOldUsed(group.key, old.id, cabinet.id) || isDeleted(group.key, old.id)) && !isChecked(cabinet.id, group.key, old.id),
                                        'border-primary/50 bg-primary/5': isChecked(cabinet.id, group.key, old.id),
                                    }"
                                >
                                    <Checkbox
                                        :model-value="isChecked(cabinet.id, group.key, old.id)"
                                        :disabled="
                                            isDeleted(group.key, old.id)
                                                || (isOldUsed(group.key, old.id, cabinet.id) && !isChecked(cabinet.id, group.key, old.id))
                                        "
                                        @update:model-value="(v) => toggleMapping(cabinet.id, group.key, old.id, v)"
                                    />
                                    <span>
                                        <span class="font-medium">{{ old.name }}</span>
                                        <span v-if="old.created_at" class="mt-0.5 block text-xs text-muted-foreground">
                                            {{ old.created_at }}
                                        </span>
                                        <span v-if="isDeleted(group.key, old.id)" class="mt-0.5 block text-xs text-destructive">
                                            Помечен на удаление
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <Alert v-if="remainingCount > 0" variant="warning" class="text-sm">
                        Осталось обработать: {{ remainingCount }} из {{ totalOld }}
                        (привязать или удалить).
                    </Alert>

                    <div class="flex justify-between">
                        <Button variant="outline" @click="step = 1">Назад</Button>
                        <Button :disabled="!canGoStep3" @click="step = 3">
                            Далее
                            <ChevronRight class="ml-1 h-4 w-4" />
                        </Button>
                    </div>
                </div>

                <!-- Step 3 -->
                <div v-else class="space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold">Подтвердите перенос</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Привязанные данные перейдут в выбранные общие кабинеты.
                            Помеченные на удаление кабинеты и их данные будут удалены безвозвратно.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="cabinet in cabinets"
                            :key="`summary-${cabinet.id}`"
                            class="rounded-lg border border-border p-3 text-sm"
                        >
                            <p class="font-medium">{{ cabinet.name }}</p>
                            <ul class="mt-2 space-y-1 text-muted-foreground">
                                <li
                                    v-for="(oldId, serviceKey) in assignments[cabinet.id] || {}"
                                    :key="`${cabinet.id}-${serviceKey}`"
                                >
                                    {{ services.find((s) => s.key === serviceKey)?.label || serviceKey }}
                                    → {{ findOldName(serviceKey, oldId) }}
                                </li>
                                <li v-if="!Object.keys(assignments[cabinet.id] || {}).length">
                                    Без привязок
                                </li>
                            </ul>
                        </div>

                        <div
                            v-if="deletionList.length"
                            class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                        >
                            <p class="font-medium text-destructive">Будут удалены</p>
                            <ul class="mt-2 space-y-1 text-muted-foreground">
                                <li v-for="item in deletionList" :key="item.key">
                                    {{ item.serviceLabel }}: {{ item.name }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <p v-if="migrateForm.errors.mappings" class="text-sm text-destructive">
                        {{ migrateForm.errors.mappings }}
                    </p>
                    <p v-if="page.props.errors?.mappings" class="text-sm text-destructive">
                        {{ page.props.errors.mappings }}
                    </p>

                    <div class="flex justify-between">
                        <Button variant="outline" @click="step = 2">Назад</Button>
                        <Button :disabled="migrateForm.processing || !canGoStep3" @click="runMigration">
                            {{ migrateForm.processing ? "Выполняется…" : "Завершить перенос" }}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
