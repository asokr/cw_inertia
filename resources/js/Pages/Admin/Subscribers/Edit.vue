<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Switch from "@/components/ui/Switch.vue";
import Textarea from "@/components/ui/Textarea.vue";
import Card from "@/components/ui/Card.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import TabsContent from "@/components/ui/TabsContent.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Badge from "@/components/ui/Badge.vue";
import SubscriberLimitsEditor from "@/components/admin/SubscriberLimitsEditor.vue";

const props = defineProps({
    subscriber: { type: Object, required: true },
    payments: { type: Array, default: () => [] },
    totalDeposits: { type: Number, default: 0 },
    plans: { type: Array, default: () => [] },
    limitKeys: { type: Array, default: () => [] },
});

const activeTab = ref("profile");
const reverseDialogOpen = ref(false);
const reverseTransactionId = ref(null);

const form = useForm({
    user_id: props.subscriber.user_id,
    status: props.subscriber.status ?? 1,
    plan_id: "",
    user: {
        name: props.subscriber.user?.name ?? "",
        email: props.subscriber.user?.email ?? "",
        phone: props.subscriber.user?.phone ?? "",
    },
    subscriptions: (props.subscriber.subscriptions ?? []).map((s) => ({
        id: s.id,
        limits_plan: { ...(s.limits_plan ?? {}) },
        limits_month: { ...(s.limits_month ?? {}) },
        extra_limits_month: { ...(s.extra_limits_month ?? {}) },
    })),
});

const depositForm = useForm({ amount: "" });
const withdrawForm = useForm({ amount: "", comment: "" });
const reverseForm = useForm({ comment: "" });

const formatCurrency = (value) => new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
    maximumFractionDigits: 2,
}).format(Number(value ?? 0));

const currentBalance = computed(() => {
    const balances = props.subscriber.user?.balances;
    if (!balances) return 0;
    if (typeof balances === "object" && "value" in balances) return Number(balances.value ?? 0);
    return Number(balances?.[0]?.value ?? 0);
});

const activeSubscription = computed(() => (props.subscriber.subscriptions ?? []).find((s) => s.status) ?? null);

const activeSubscriptionForm = computed(() => {
    if (!activeSubscription.value) return null;
    return form.subscriptions.find((s) => s.id === activeSubscription.value.id) ?? null;
});

function saveProfile() {
    form.put(`/cw-page/subscribers/${props.subscriber.id}`);
}

function submitDeposit() {
    depositForm.post(`/cw-page/subscribers/${props.subscriber.id}/deposit`, {
        preserveScroll: true,
        onSuccess: () => depositForm.reset(),
    });
}

function submitWithdraw() {
    withdrawForm.post(`/cw-page/subscribers/${props.subscriber.id}/withdraw`, {
        preserveScroll: true,
        onSuccess: () => withdrawForm.reset(),
    });
}

function openReverse(transactionId) {
    reverseTransactionId.value = transactionId;
    reverseForm.reset();
    reverseDialogOpen.value = true;
}

function confirmReverse() {
    if (!reverseTransactionId.value) return;
    reverseForm.post(`/cw-page/subscribers/${props.subscriber.id}/transactions/${reverseTransactionId.value}/reverse`, {
        preserveScroll: true,
        onSuccess: () => { reverseDialogOpen.value = false; },
    });
}

const paymentStatusLabel = (status) => ({
    CREATED: "Создан",
    FAILED: "Неудачный",
    CONFIRMED: "Подтверждён",
    RETURNED: "Возврат",
}[status] ?? status);
</script>

<template>
    <Head :title="`Подписчик #${subscriber.id}`" />

    <AdminLayout
        title="Подписчик"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Подписчики', href: '/cw-page/subscribers' },
            { label: subscriber.user?.name ?? `#${subscriber.id}` },
        ]"
    >
        <PageHeader
            :title="subscriber.user?.name ?? `Подписчик #${subscriber.id}`"
            :description="subscriber.user?.email"
        />

        <SubscribersSubnav />

        <Tabs v-model="activeTab" default-value="profile">
            <TabsList class="mb-4">
                <TabsTrigger value="profile">Профиль</TabsTrigger>
                <TabsTrigger value="payments">Платежи и баланс</TabsTrigger>
            </TabsList>

            <TabsContent value="profile">
                <Card class="p-4">
                    <form class="space-y-4 max-w-xl" @submit.prevent="saveProfile">
                        <div>
                            <label class="mb-1 block text-sm">Имя</label>
                            <Input v-model="form.user.name" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Email</label>
                            <Input v-model="form.user.email" type="email" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm">Телефон</label>
                            <Input v-model="form.user.phone" />
                        </div>

                        <div v-if="activeSubscription" class="rounded-md border p-3 text-sm">
                            <p class="font-medium mb-2">Текущая подписка</p>
                            <p>{{ activeSubscription.plan?.name }} — до {{ activeSubscription.end_date }}</p>
                        </div>

                        <div v-if="activeSubscriptionForm" class="rounded-md border p-4">
                            <p class="mb-3 text-sm font-medium">Лимиты подписки</p>
                            <SubscriberLimitsEditor
                                v-model="activeSubscriptionForm"
                                :limit-keys="limitKeys"
                                :plan-limits="{
                                    limits_plan: activeSubscription?.plan?.limits_plan ?? {},
                                    limits_month: activeSubscription?.plan?.limits_month ?? {},
                                }"
                                editable
                            />
                        </div>
                        <p v-else class="text-sm text-muted-foreground">Нет активной подписки для редактирования лимитов</p>

                        <div>
                            <label class="mb-1 block text-sm">Сменить тариф (апгрейд)</label>
                            <Select v-model="form.plan_id">
                                <option value="">Не менять</option>
                                <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }} ({{ plan.price }} ₽)</option>
                            </Select>
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="text-sm">Активность</label>
                            <Switch
                                :model-value="form.status === 1"
                                @update:model-value="form.status = $event ? 1 : 0"
                            />
                        </div>

                        <Button type="submit" :disabled="form.processing">Сохранить</Button>
                    </form>
                </Card>
            </TabsContent>

            <TabsContent value="payments">
                <div class="grid gap-4 lg:grid-cols-2">
                    <Card class="p-4">
                        <p class="text-sm text-muted-foreground mb-1">Текущий баланс</p>
                        <p class="text-2xl font-semibold mb-4">{{ formatCurrency(currentBalance) }}</p>
                        <p class="text-sm text-muted-foreground mb-4">Всего пополнений: {{ formatCurrency(totalDeposits) }}</p>

                        <form class="space-y-3 mb-6" @submit.prevent="submitDeposit">
                            <label class="block text-sm font-medium">Пополнение</label>
                            <Input v-model="depositForm.amount" type="number" min="1" step="0.01" placeholder="Сумма" />
                            <Button type="submit" size="sm" :disabled="depositForm.processing || !depositForm.amount">Пополнить</Button>
                        </form>

                        <form class="space-y-3" @submit.prevent="submitWithdraw">
                            <label class="block text-sm font-medium">Списание</label>
                            <Input v-model="withdrawForm.amount" type="number" min="1" step="0.01" placeholder="Сумма" />
                            <Textarea v-model="withdrawForm.comment" placeholder="Комментарий" rows="2" />
                            <Button type="submit" variant="destructive" size="sm" :disabled="withdrawForm.processing || !withdrawForm.amount || !withdrawForm.comment">Списать</Button>
                        </form>
                    </Card>

                    <Card class="p-4">
                        <h3 class="font-medium mb-3">История операций</h3>
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            <div
                                v-for="payment in payments"
                                :key="payment.id"
                                class="flex items-start justify-between gap-2 border-b pb-2 text-sm"
                            >
                                <div>
                                    <p :class="payment.amount >= 0 ? 'text-green-600' : 'text-red-600'">
                                        {{ formatCurrency(payment.amount) }}
                                    </p>
                                    <p class="text-muted-foreground text-xs">{{ payment.created_at }}</p>
                                    <p class="text-xs">{{ payment.description ?? "—" }}</p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <Badge variant="secondary">{{ paymentStatusLabel(payment.status) }}</Badge>
                                    <Button
                                        v-if="payment.type === 'deposit' && !payment.meta?.reversal_transaction_id"
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 text-xs"
                                        @click="openReverse(payment.id)"
                                    >
                                        Отменить
                                    </Button>
                                </div>
                            </div>
                            <p v-if="!payments.length" class="text-sm text-muted-foreground">Нет операций</p>
                        </div>
                    </Card>
                </div>
            </TabsContent>
        </Tabs>

        <Dialog v-model:open="reverseDialogOpen" title="Отмена операции">
            <Textarea v-model="reverseForm.comment" placeholder="Причина отмены" rows="3" maxlength="500" />
            <template #footer>
                <Button variant="outline" @click="reverseDialogOpen = false">Закрыть</Button>
                <Button :disabled="reverseForm.processing || !reverseForm.comment" @click="confirmReverse">Подтвердить</Button>
            </template>
        </Dialog>
    </AdminLayout>
</template>