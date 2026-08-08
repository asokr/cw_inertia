<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Textarea from "@/components/ui/Textarea.vue";
import Switch from "@/components/ui/Switch.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Card from "@/components/ui/Card.vue";
import Alert from "@/components/ui/Alert.vue";

const props = defineProps({
    plan: { type: Object, default: null },
    permissions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.plan?.id));

const availablePermissionNames = computed(() =>
    (props.permissions ?? []).map((perm) => perm.name).filter(Boolean),
);

/**
 * Keep only permission names that still exist in the options list.
 * Legacy plan rows may still store removed permissions (autosupply, profit analyzer, …);
 * submitting them fails `exists` validation and blocks any save.
 */
function normalizePermissions(value) {
    const raw = Array.isArray(value)
        ? value
        : typeof value === "string" && value !== ""
            ? (() => {
                try {
                    const parsed = JSON.parse(value);
                    return Array.isArray(parsed) ? parsed : [];
                } catch {
                    return [];
                }
            })()
            : [];

    const allowed = new Set(availablePermissionNames.value);

    return [...new Set(raw.filter((name) => typeof name === "string" && allowed.has(name)))];
}

const droppedLegacyPermissions = computed(() => {
    const raw = Array.isArray(props.plan?.permissions) ? props.plan.permissions : [];
    const allowed = new Set(availablePermissionNames.value);

    return raw.filter((name) => typeof name === "string" && !allowed.has(name));
});

const limitsToString = (limits) => {
    if (!limits || typeof limits !== "object") return "";
    return Object.entries(limits).map(([k, v]) => `${k}:${v}`).join("|");
};

const form = useForm({
    name: props.plan?.name ?? "",
    description: props.plan?.description ?? "",
    duration: props.plan?.duration ?? 30,
    price: props.plan?.price ?? 0,
    permissions: normalizePermissions(props.plan?.permissions),
    limits_plan: limitsToString(props.plan?.limits_plan),
    limits_month: limitsToString(props.plan?.limits_month),
    status: Boolean(props.plan?.status ?? true),
    hidden: Boolean(props.plan?.hidden ?? false),
});

function setPermission(name, checked) {
    const current = Array.isArray(form.permissions) ? form.permissions : [];
    if (checked) {
        if (!current.includes(name)) {
            form.permissions = [...current, name];
        }
        return;
    }
    form.permissions = current.filter((item) => item !== name);
}

function submit() {
    form
        .transform((data) => ({
            ...data,
            permissions: normalizePermissions(data.permissions),
            status: data.status ? 1 : 0,
            hidden: data.hidden ? 1 : 0,
        }))
        [isEdit.value ? "put" : "post"](
            isEdit.value ? `/cw-page/plans/${props.plan.id}` : "/cw-page/plans",
        );
}
</script>

<template>
    <Head :title="isEdit ? 'Редактирование тарифа' : 'Новый тариф'" />

    <AdminLayout
        :title="isEdit ? 'Редактирование' : 'Новый тариф'"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Планы', href: '/cw-page/plans' },
            { label: isEdit ? plan.name : 'Создание' },
        ]"
    >
        <PageHeader :title="isEdit ? `Тариф: ${plan.name}` : 'Добавление тарифа'" />

        <SubscribersSubnav />

        <Card class="max-w-2xl p-4">
            <form class="space-y-4" @submit.prevent="submit">
                <Alert v-if="Object.keys(form.errors).length" variant="destructive" class="text-sm">
                    <p class="font-medium">Не удалось сохранить тариф</p>
                    <ul class="mt-1 list-disc pl-4">
                        <li v-for="(message, key) in form.errors" :key="key">{{ message }}</li>
                    </ul>
                </Alert>

                <Alert v-if="droppedLegacyPermissions.length" class="text-sm">
                    В тарифе были устаревшие разрешения, которых больше нет в системе
                    (они не попадут в сохранение):
                    <span class="font-medium">{{ droppedLegacyPermissions.join(", ") }}</span>.
                    Сохраните тариф, чтобы очистить список.
                </Alert>

                <div>
                    <label class="mb-1 block text-sm">Название</label>
                    <Input v-model="form.name" required />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-destructive">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Описание</label>
                    <Textarea v-model="form.description" rows="4" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm">Продолжительность (дней)</label>
                        <Input v-model.number="form.duration" type="number" min="1" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm">Стоимость (₽)</label>
                        <Input v-model.number="form.price" type="number" min="0" step="0.01" required />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Лимиты тарифа</label>
                    <Input
                        v-model="form.limits_plan"
                        placeholder="wb_cabinets:3|repricer_nmid:100|feedbacks_gpt_query:500"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Формат: <code>ключ:число</code> через
                        <code>|</code>. Кабинеты WB после унификации:
                        <code>wb_cabinets:N</code> (N — сколько общих кабинетов можно создать).
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Частые ключи:
                        <code>wb_cabinets</code>,
                        <code>oz_cabinets</code>,
                        <code>repricer_nmid</code>,
                        <code>feedbacks_gpt_query</code>,
                        <code>ai_text_query</code>,
                        <code>ai_image_query</code>,
                        <code>ai_video_query</code>.
                        Старые <code>feedbacks_clients</code> /
                        <code>price_calc_clients</code> ещё работают как fallback для WB, если
                        <code>wb_cabinets</code> не задан. Для Ozon только
                        <code>oz_cabinets</code>.
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Месячные лимиты</label>
                    <Input v-model="form.limits_month" placeholder="feedbacks_gpt_query:100|ai_text_query:50" />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Сбрасываются/обновляются помесячно (запросы ИИ и т.п.).
                    </p>
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium">Разрешения тарифа</p>
                    <p v-if="form.errors.permissions" class="mb-2 text-xs text-destructive">
                        {{ form.errors.permissions }}
                    </p>
                    <div class="grid max-h-56 gap-2 overflow-y-auto sm:grid-cols-2">
                        <label
                            v-for="perm in permissions"
                            :key="perm.id"
                            class="flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="form.permissions.includes(perm.name)"
                                @update:model-value="setPermission(perm.name, $event)"
                            />
                            {{ perm.name }}
                        </label>
                    </div>
                </div>
                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-2 text-sm">
                        <Switch v-model="form.status" />
                        Активен
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Switch v-model="form.hidden" />
                        Скрытый тариф
                    </label>
                </div>
                <div class="flex gap-2">
                    <Button type="submit" :disabled="form.processing">Сохранить</Button>
                    <Button type="button" variant="outline" as="a" href="/cw-page/plans">Отмена</Button>
                </div>
            </form>
        </Card>
    </AdminLayout>
</template>