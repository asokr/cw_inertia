<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
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
import Select from "@/components/ui/Select.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import { formatCredits } from "@/utils/credits";

const props = defineProps({
    rubles_per_credit: { type: [String, Number], default: "2.00" },
    services: { type: Array, default: () => [] },
    cabinet_analyzer_models: { type: Array, default: () => [] },
    cabinet_analyzer_tariffs: { type: Array, default: () => [] },
    cabinet_analyzer_charges: {
        type: Object,
        default: () => ({
            data: [],
            current_page: 1,
            last_page: 1,
            total: 0,
            marketplace: "",
        }),
    },
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

const tariffForms = {};
for (const tariff of props.cabinet_analyzer_tariffs) {
    tariffForms[tariff.id] = useForm({
        provider: tariff.provider,
        model: tariff.model,
        input_credits_per_1k: Number(tariff.input_credits_per_1k ?? 0),
        output_credits_per_1k: Number(tariff.output_credits_per_1k ?? 0),
        coefficient: Number(tariff.coefficient ?? 1),
        is_default: Boolean(tariff.is_default),
        is_active: Boolean(tariff.is_active),
    });
}

const tariffDialogOpen = ref(false);
const addTariffForm = useForm({
    provider: "gemini",
    model: "",
    input_credits_per_1k: 0.03,
    output_credits_per_1k: 0.18,
    coefficient: 1,
    is_default: false,
    is_active: true,
});

const snapshotOpen = ref(false);
const snapshotJson = ref("");

function saveTariff(tariff) {
    const form = tariffForms[tariff.id];
    if (!form) return;
    form.put(`/cw-page/credit-pricing/cabinet-analyzer-tariffs/${tariff.id}`, { preserveScroll: true });
}

function openAddTariff() {
    addTariffForm.reset();
    addTariffForm.clearErrors();
    addTariffForm.provider = "gemini";
    addTariffForm.model = "";
    addTariffForm.input_credits_per_1k = 0.03;
    addTariffForm.output_credits_per_1k = 0.18;
    addTariffForm.coefficient = 1;
    addTariffForm.is_default = false;
    addTariffForm.is_active = true;
    tariffDialogOpen.value = true;
}

function submitAddTariff() {
    addTariffForm.post("/cw-page/credit-pricing/cabinet-analyzer-tariffs", {
        preserveScroll: true,
        onSuccess: () => {
            tariffDialogOpen.value = false;
        },
    });
}

function destroyTariff(tariff) {
    if (!confirm(`Удалить ставку ${tariff.provider_label} / ${tariff.model}?`)) return;
    addTariffForm.delete(`/cw-page/credit-pricing/cabinet-analyzer-tariffs/${tariff.id}`, {
        preserveScroll: true,
    });
}

function chargesPage(page) {
    router.get("/cw-page/credit-pricing", {
        page,
        marketplace: props.cabinet_analyzer_charges?.marketplace || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function filterCharges(marketplace) {
    router.get("/cw-page/credit-pricing", {
        marketplace: marketplace || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function openSnapshot(charge) {
    snapshotJson.value = JSON.stringify(charge.tariff_snapshot ?? {}, null, 2);
    snapshotOpen.value = true;
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

        <div class="mt-10 space-y-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                ИИ-анализ кабинета
            </h2>

            <Card class="border-amber-500/30 bg-amber-500/5 p-5">
                <p class="text-base font-semibold">Используемые модели</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Это текущие модели из настроек сервера. Если модель изменят в коде или в окружении,
                    проверьте ставки ниже — иначе списание может уйти в строку «по умолчанию» или не запуститься.
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="item in cabinet_analyzer_models"
                        :key="item.tool"
                        class="rounded-md border border-border/70 bg-background p-3 text-sm"
                    >
                        <p class="font-medium">{{ item.tool }}</p>
                        <p class="mt-1 text-muted-foreground">
                            Основная: {{ item.provider }} · {{ item.model }}
                        </p>
                        <p class="text-muted-foreground">
                            Запасная: {{ item.fallback_provider }} · {{ item.fallback_model }}
                        </p>
                    </div>
                </div>
            </Card>

            <Card class="p-4">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">Стоимость по токенам</p>
                        <p class="text-xs text-muted-foreground">
                            Сколько кредитов списывать за 1000 входящих и 1000 исходящих токенов.
                            Коэффициент умножает итог. Ставка «по умолчанию» используется, если точное имя модели не найдено.
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" @click="openAddTariff">
                        Добавить ставку
                    </Button>
                </div>

                <div v-if="!cabinet_analyzer_tariffs.length" class="text-sm text-muted-foreground">
                    Ставок ещё нет. Добавьте хотя бы Gemini и GPT.
                </div>

                <div class="space-y-3">
                    <div
                        v-for="tariff in cabinet_analyzer_tariffs"
                        :key="tariff.id"
                        class="rounded-md border border-border/70 p-3"
                    >
                        <form
                            class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6"
                            @submit.prevent="saveTariff(tariff)"
                        >
                            <div class="space-y-1">
                                <Label :for="`tariff-provider-${tariff.id}`">Провайдер</Label>
                                <Select
                                    :id="`tariff-provider-${tariff.id}`"
                                    v-model="tariffForms[tariff.id].provider"
                                >
                                    <option value="gemini">Gemini</option>
                                    <option value="gpt">GPT</option>
                                </Select>
                            </div>
                            <div class="space-y-1 lg:col-span-2">
                                <Label :for="`tariff-model-${tariff.id}`">Модель</Label>
                                <Input
                                    :id="`tariff-model-${tariff.id}`"
                                    v-model="tariffForms[tariff.id].model"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label :for="`tariff-in-${tariff.id}`">Вход / 1000</Label>
                                <Input
                                    :id="`tariff-in-${tariff.id}`"
                                    v-model.number="tariffForms[tariff.id].input_credits_per_1k"
                                    type="number"
                                    min="0"
                                    step="0.000001"
                                    class="tabular-nums"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label :for="`tariff-out-${tariff.id}`">Ответ / 1000</Label>
                                <Input
                                    :id="`tariff-out-${tariff.id}`"
                                    v-model.number="tariffForms[tariff.id].output_credits_per_1k"
                                    type="number"
                                    min="0"
                                    step="0.000001"
                                    class="tabular-nums"
                                />
                            </div>
                            <div class="space-y-1">
                                <Label :for="`tariff-coef-${tariff.id}`">Коэффициент</Label>
                                <Input
                                    :id="`tariff-coef-${tariff.id}`"
                                    v-model.number="tariffForms[tariff.id].coefficient"
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    class="tabular-nums"
                                />
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model="tariffForms[tariff.id].is_default" />
                                По умолчанию
                            </label>
                            <label class="flex items-center gap-2 text-sm">
                                <Checkbox v-model="tariffForms[tariff.id].is_active" />
                                Активна
                            </label>
                            <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="tariffForms[tariff.id].processing"
                                >
                                    Сохранить
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="destroyTariff(tariff)"
                                >
                                    <Trash2 class="mr-1 h-4 w-4" />
                                    Удалить
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </Card>

            <Card class="p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-medium">История расчётов</p>
                        <p class="text-xs text-muted-foreground">
                            Фактически применённые ставки. Старые анализы не пересчитываются при изменении тарифа.
                        </p>
                    </div>
                    <Select
                        :model-value="cabinet_analyzer_charges.marketplace || ''"
                        @update:model-value="filterCharges"
                    >
                        <option value="">Все маркетплейсы</option>
                        <option value="wb">Wildberries</option>
                        <option value="ozon">Ozon</option>
                    </Select>
                </div>

                <div v-if="!cabinet_analyzer_charges.data?.length" class="text-sm text-muted-foreground">
                    Пока нет списаний ИИ-анализа кабинета.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-left text-sm">
                        <thead class="text-xs uppercase text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3 font-medium">Дата</th>
                                <th class="py-2 pr-3 font-medium">Маркетплейс</th>
                                <th class="py-2 pr-3 font-medium">Пользователь</th>
                                <th class="py-2 pr-3 font-medium">Модель</th>
                                <th class="py-2 pr-3 font-medium">Вход</th>
                                <th class="py-2 pr-3 font-medium">Ответ</th>
                                <th class="py-2 pr-3 font-medium">Кредиты</th>
                                <th class="py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="charge in cabinet_analyzer_charges.data"
                                :key="charge.id"
                                class="border-t border-border/60"
                            >
                                <td class="py-2 pr-3 tabular-nums">{{ charge.created_at }}</td>
                                <td class="py-2 pr-3">{{ charge.marketplace_label }}</td>
                                <td class="py-2 pr-3">{{ charge.user_email || charge.user_id }}</td>
                                <td class="py-2 pr-3">
                                    {{ charge.provider }} · {{ charge.model }}
                                </td>
                                <td class="py-2 pr-3 tabular-nums">{{ charge.input_tokens }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ charge.output_tokens }}</td>
                                <td class="py-2 pr-3 tabular-nums">
                                    {{ formatCredits(charge.credits_charged) }}
                                </td>
                                <td class="py-2">
                                    <Button type="button" variant="ghost" size="sm" @click="openSnapshot(charge)">
                                        Условия
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="(cabinet_analyzer_charges.last_page || 1) > 1"
                    class="mt-3 flex items-center gap-2"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="cabinet_analyzer_charges.current_page <= 1"
                        @click="chargesPage(cabinet_analyzer_charges.current_page - 1)"
                    >
                        Назад
                    </Button>
                    <span class="text-xs text-muted-foreground">
                        {{ cabinet_analyzer_charges.current_page }} / {{ cabinet_analyzer_charges.last_page }}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="cabinet_analyzer_charges.current_page >= cabinet_analyzer_charges.last_page"
                        @click="chargesPage(cabinet_analyzer_charges.current_page + 1)"
                    >
                        Вперёд
                    </Button>
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

        <Dialog v-model:open="tariffDialogOpen" title="Новая ставка ИИ-анализа">
            <div class="space-y-3">
                <div class="space-y-1">
                    <Label for="new-tariff-provider">Провайдер</Label>
                    <Select id="new-tariff-provider" v-model="addTariffForm.provider">
                        <option value="gemini">Gemini</option>
                        <option value="gpt">GPT</option>
                    </Select>
                </div>
                <div class="space-y-1">
                    <Label for="new-tariff-model">Модель</Label>
                    <Input id="new-tariff-model" v-model="addTariffForm.model" placeholder="gemini-3.1-pro-preview" />
                    <p v-if="addTariffForm.errors.model" class="text-xs text-destructive">
                        {{ addTariffForm.errors.model }}
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1">
                        <Label for="new-tariff-in">Вход / 1000 токенов</Label>
                        <Input
                            id="new-tariff-in"
                            v-model.number="addTariffForm.input_credits_per_1k"
                            type="number"
                            min="0"
                            step="0.000001"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label for="new-tariff-out">Ответ / 1000 токенов</Label>
                        <Input
                            id="new-tariff-out"
                            v-model.number="addTariffForm.output_credits_per_1k"
                            type="number"
                            min="0"
                            step="0.000001"
                        />
                    </div>
                </div>
                <div class="space-y-1">
                    <Label for="new-tariff-coef">Коэффициент</Label>
                    <Input
                        id="new-tariff-coef"
                        v-model.number="addTariffForm.coefficient"
                        type="number"
                        min="0.0001"
                        step="0.0001"
                    />
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="addTariffForm.is_default" />
                    Использовать по умолчанию для этого провайдера
                </label>
            </div>
            <template #footer>
                <Button variant="outline" @click="tariffDialogOpen = false">Отмена</Button>
                <Button :disabled="addTariffForm.processing" @click="submitAddTariff">Добавить</Button>
            </template>
        </Dialog>

        <Dialog v-model:open="snapshotOpen" title="Условия расчёта">
            <pre class="max-h-96 overflow-auto rounded-md bg-muted p-3 text-xs">{{ snapshotJson }}</pre>
            <template #footer>
                <Button variant="outline" @click="snapshotOpen = false">Закрыть</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>
