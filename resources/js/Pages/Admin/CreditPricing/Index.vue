<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import { Trash2 } from "lucide-vue-next";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";

const props = defineProps({
    rubles_per_credit: { type: [String, Number], default: "2.00" },
    services: { type: Array, default: () => [] },
});

const rublesForm = useForm({
    rubles_per_credit: Number(props.rubles_per_credit ?? 2),
});

const fixedForms = {};
for (const service of props.services) {
    if (service.billing_mode === "fixed") {
        fixedForms[service.id] = useForm({ amount: Number(service.amount ?? 1) });
    }
}

const tierAmountForms = {};
for (const service of props.services) {
    for (const tier of service.tiers ?? []) {
        tierAmountForms[tier.id] = useForm({
            param_value: tier.param_value,
            amount: Number(tier.amount ?? 1),
        });
    }
}

const addDialogOpen = ref(false);
const addingService = ref(null);
const addTierForm = useForm({
    param_value: "",
    amount: 1,
});

const rublesPreview = computed(() => {
    const value = Number(rublesForm.rubles_per_credit);
    if (!Number.isFinite(value) || value < 0) return "0,00";
    return new Intl.NumberFormat("ru-RU", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
});

function saveRubles() {
    rublesForm.put("/cw-page/credit-pricing/rubles", { preserveScroll: true });
}

function saveFixed(service) {
    const form = fixedForms[service.id];
    if (!form) return;
    form.put(`/cw-page/credit-pricing/services/${service.id}`, { preserveScroll: true });
}

function saveTier(tier) {
    const form = tierAmountForms[tier.id];
    if (!form) return;
    form.put(`/cw-page/credit-pricing/tiers/${tier.id}`, { preserveScroll: true });
}

function openAddTier(service) {
    addingService.value = service;
    addTierForm.reset();
    addTierForm.clearErrors();
    addTierForm.param_value = "";
    addTierForm.amount = 1;
    addDialogOpen.value = true;
}

function submitAddTier() {
    if (!addingService.value) return;
    addTierForm.post(`/cw-page/credit-pricing/services/${addingService.value.id}/tiers`, {
        preserveScroll: true,
        onSuccess: () => {
            addDialogOpen.value = false;
            addingService.value = null;
        },
    });
}

function destroyTier(tier) {
    if (!confirm(`Удалить разрешение «${tier.param_value}»?`)) return;
    addTierForm.delete(`/cw-page/credit-pricing/tiers/${tier.id}`, { preserveScroll: true });
}

function amountHint(service) {
    return service.billing_mode === "per_second_by_resolution"
        ? "кредитов / сек"
        : "кредитов";
}
</script>

<template>
    <Head title="Стоимость кредитов" />

    <AdminLayout
        title="Стоимость кредитов"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Стоимость кредитов' }]"
    >
        <PageHeader
            title="Стоимость кредитов"
            description="Цена покупки одного кредита и сколько кредитов списывается за AI-операции"
        />

        <SubscribersSubnav />

        <Card class="mb-6 border-primary/30 bg-primary/5 p-5">
            <p class="text-base font-semibold">Стоимость одного кредита</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Сколько рублей пользователь платит за покупку 1 кредита. Это не стоимость AI-услуги.
            </p>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="saveRubles">
                <div class="min-w-[10rem] space-y-1">
                    <Label for="rubles-per-credit">1 кредит, ₽</Label>
                    <Input
                        id="rubles-per-credit"
                        v-model.number="rublesForm.rubles_per_credit"
                        type="number"
                        min="0"
                        step="0.01"
                        class="tabular-nums"
                    />
                    <p v-if="rublesForm.errors.rubles_per_credit" class="text-xs text-destructive">
                        {{ rublesForm.errors.rubles_per_credit }}
                    </p>
                </div>
                <Button type="submit" :disabled="rublesForm.processing">Сохранить</Button>
            </form>
            <p class="mt-3 text-sm">
                Сейчас: <span class="font-semibold">1 кредит = {{ rublesPreview }} ₽</span>
            </p>
        </Card>

        <div class="space-y-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Стоимость AI-услуг
            </h2>

            <Card v-if="!services.length" class="p-4 text-sm text-muted-foreground">
                Каталог услуг ещё не заполнен. Обновите страницу — базовые стоимости подставятся автоматически.
            </Card>

            <Card v-for="service in services" :key="service.id" class="p-4">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">{{ service.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ service.billing_mode_label }}</p>
                    </div>
                    <Button
                        v-if="service.billing_mode !== 'fixed'"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="openAddTier(service)"
                    >
                        Добавить разрешение
                    </Button>
                </div>

                <form
                    v-if="service.billing_mode === 'fixed'"
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="saveFixed(service)"
                >
                    <div class="min-w-[8rem] space-y-1">
                        <Label :for="`service-${service.id}`">Кредитов за операцию</Label>
                        <Input
                            :id="`service-${service.id}`"
                            v-model.number="fixedForms[service.id].amount"
                            type="number"
                            min="1"
                            step="1"
                            class="tabular-nums"
                        />
                    </div>
                    <Button type="submit" size="sm" :disabled="fixedForms[service.id].processing">
                        Сохранить
                    </Button>
                </form>

                <div v-else class="space-y-2">
                    <div
                        v-for="tier in service.tiers"
                        :key="tier.id"
                        class="flex flex-wrap items-end gap-2 rounded-md border border-border/70 p-2"
                    >
                        <div class="min-w-[7rem] space-y-1">
                            <Label :for="`tier-name-${tier.id}`">Разрешение</Label>
                            <Input
                                :id="`tier-name-${tier.id}`"
                                v-model="tierAmountForms[tier.id].param_value"
                                class="tabular-nums"
                            />
                        </div>
                        <div class="min-w-[8rem] space-y-1">
                            <Label :for="`tier-amount-${tier.id}`">{{ amountHint(service) }}</Label>
                            <Input
                                :id="`tier-amount-${tier.id}`"
                                v-model.number="tierAmountForms[tier.id].amount"
                                type="number"
                                min="1"
                                step="1"
                                class="tabular-nums"
                            />
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            :disabled="tierAmountForms[tier.id].processing"
                            @click="saveTier(tier)"
                        >
                            Сохранить
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="h-9 w-9"
                            @click="destroyTier(tier)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                    <p v-if="!service.tiers?.length" class="text-sm text-muted-foreground">
                        Нет разрешений. Добавьте, например, 1K или 480p.
                    </p>
                </div>
            </Card>
        </div>

        <Dialog
            v-model:open="addDialogOpen"
            :title="addingService ? `Новое разрешение: ${addingService.name}` : 'Новое разрешение'"
        >
            <div class="space-y-3">
                <div class="space-y-1">
                    <Label for="new-resolution">Разрешение</Label>
                    <Input id="new-resolution" v-model="addTierForm.param_value" placeholder="8K или 1080p" />
                    <p v-if="addTierForm.errors.param_value" class="text-xs text-destructive">
                        {{ addTierForm.errors.param_value }}
                    </p>
                </div>
                <div class="space-y-1">
                    <Label for="new-resolution-amount">
                        {{ addingService ? amountHint(addingService) : "кредитов" }}
                    </Label>
                    <Input
                        id="new-resolution-amount"
                        v-model.number="addTierForm.amount"
                        type="number"
                        min="1"
                        step="1"
                    />
                    <p v-if="addTierForm.errors.amount" class="text-xs text-destructive">
                        {{ addTierForm.errors.amount }}
                    </p>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" @click="addDialogOpen = false">Отмена</Button>
                <Button :disabled="addTierForm.processing" @click="submitAddTier">Добавить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>
