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

const props = defineProps({
    plan: { type: Object, default: null },
    permissions: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.plan?.id));

const limitsToString = (limits) => {
    if (!limits || typeof limits !== "object") return "";
    return Object.entries(limits).map(([k, v]) => `${k}:${v}`).join("|");
};

const form = useForm({
    name: props.plan?.name ?? "",
    description: props.plan?.description ?? "",
    duration: props.plan?.duration ?? 30,
    price: props.plan?.price ?? 0,
    permissions: [...(props.plan?.permissions ?? [])],
    limits_plan: limitsToString(props.plan?.limits_plan),
    limits_month: limitsToString(props.plan?.limits_month),
    status: Boolean(props.plan?.status ?? true),
    hidden: Boolean(props.plan?.hidden ?? false),
});

function setPermission(name, checked) {
    const idx = form.permissions.indexOf(name);
    if (checked && idx < 0) form.permissions.push(name);
    if (!checked && idx >= 0) form.permissions.splice(idx, 1);
}

function submit() {
    const payload = {
        ...form.data(),
        status: form.status ? 1 : 0,
        hidden: form.hidden ? 1 : 0,
    };

    if (isEdit.value) {
        form.transform(() => payload).put(`/cw-page/plans/${props.plan.id}`);
    } else {
        form.transform(() => payload).post("/cw-page/plans");
    }
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
                <div>
                    <label class="mb-1 block text-sm">Название</label>
                    <Input v-model="form.name" required />
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
                    <Input v-model="form.limits_plan" placeholder="key:value|key2:value2" />
                    <p class="mt-1 text-xs text-muted-foreground">Формат: имя_лимита:количество через |</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">Месячные лимиты</label>
                    <Input v-model="form.limits_month" placeholder="key:value|key2:value2" />
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium">Разрешения тарифа</p>
                    <div class="grid gap-2 sm:grid-cols-2 max-h-48 overflow-y-auto">
                        <label
                            v-for="perm in permissions"
                            :key="perm.id"
                            class="flex items-center gap-2 text-sm cursor-pointer"
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