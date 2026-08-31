<script setup>
import { computed, nextTick, onMounted, ref, watch } from "vue";
import axios from "axios";
import { CheckCircle2, Megaphone, Pause, Trash2, Wallet } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { resolveCampaignStatus } from "./campaignStatus";
import { MIN_BUDGET_DEPOSIT } from "./abTestingSettings";

const props = defineProps({
    experiment: {
        type: Object,
        required: true,
    },
    baseUrl: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["experiment-updated", "campaign-deleted"]);

const { showError, showSuccess } = useFlashToast();

const busy = ref(false);
const deleteOpen = ref(false);
const depositOpen = ref(false);
const depositSum = ref(MIN_BUDGET_DEPOSIT);
const depositError = ref("");

/** Live flags from last pause response or optimistic defaults. */
const liveStatus = ref(null);
const liveStatusLabel = ref("");
const liveCanPause = ref(true);

/** Budget state (loaded from WB + updated after deposit). */
const budgetTotal = ref(null);
const budgetLoading = ref(false);
const budgetError = ref("");
const lastDepositMessage = ref("");
const lastDepositedSum = ref(null);

const advertId = computed(() => props.experiment?.wb_advert_id ?? null);
const advertName = computed(
    () => props.experiment?.wb_advert_name || (advertId.value ? `Кампания #${advertId.value}` : "—"),
);

const experimentStatus = computed(() => props.experiment?.status ?? "draft");
const isRunning = computed(() => experimentStatus.value === "running");
const canEdit = computed(() => !!props.experiment?.can_edit);

/** Deposit allowed for editable or running (need budget). */
const canDeposit = computed(
    () => !!advertId.value && (canEdit.value || isRunning.value),
);

/** Pause only when not busy by running AB and experiment is editable. */
const canPause = computed(
    () => !!advertId.value && !isRunning.value && canEdit.value && liveCanPause.value,
);

/** Удаление только для кампаний, созданных в инструменте. */
const canDelete = computed(
    () =>
        !!advertId.value &&
        !isRunning.value &&
        canEdit.value &&
        !!props.experiment?.campaign_created_by_tool,
);

const statusBadge = computed(() => {
    if (liveStatus.value != null) {
        const resolved = resolveCampaignStatus(liveStatus.value);
        return {
            label: liveStatusLabel.value || resolved.label,
            variant: resolved.variant,
        };
    }
    return null;
});

const budgetLabel = computed(() => {
    if (budgetLoading.value) {
        return "Загрузка…";
    }
    if (budgetTotal.value == null) {
        return budgetError.value ? "не удалось загрузить" : "—";
    }
    return `${formatRub(budgetTotal.value)} ₽`;
});

const budgetTone = computed(() => {
    if (budgetTotal.value == null) {
        return "muted";
    }
    if (budgetTotal.value < 1) {
        return "danger";
    }
    if (budgetTotal.value < MIN_BUDGET_DEPOSIT) {
        return "warn";
    }
    return "ok";
});

function formatRub(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return "—";
    }
    return Math.round(n).toLocaleString("ru-RU");
}

async function loadBudget() {
    if (!advertId.value) {
        budgetTotal.value = null;
        return;
    }

    budgetLoading.value = true;
    budgetError.value = "";

    try {
        const { data } = await axios.get(
            `${props.baseUrl}/campaigns/${advertId.value}/budget`,
        );

        if (!data?.success) {
            budgetError.value = data?.messages?.[0] || "Не удалось загрузить бюджет";
            budgetTotal.value = null;
            return;
        }

        budgetTotal.value =
            data.budget_total != null && data.budget_total !== ""
                ? Number(data.budget_total)
                : null;
    } catch (error) {
        budgetError.value =
            error?.response?.data?.messages?.[0] ||
            "Не удалось загрузить бюджет";
        budgetTotal.value = null;
    } finally {
        budgetLoading.value = false;
    }
}

async function pauseCampaign() {
    if (!advertId.value || !canPause.value || busy.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${advertId.value}/pause`,
            { experiment_id: props.experiment?.id },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось поставить кампанию на паузу");
            return;
        }

        showSuccess(data?.messages?.[0] || "Кампания на паузе");
        if (data.campaign) {
            liveStatus.value = data.campaign.status ?? 11;
            liveStatusLabel.value = data.campaign.status_label || "Приостановлена";
            liveCanPause.value = !!data.campaign.can_pause;
        } else {
            liveStatus.value = 11;
            liveStatusLabel.value = "Приостановлена";
            liveCanPause.value = false;
        }
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось поставить кампанию на паузу",
        );
    } finally {
        busy.value = false;
    }
}

function openDeposit() {
    depositSum.value = MIN_BUDGET_DEPOSIT;
    depositError.value = "";
    depositOpen.value = true;
}

function closeDeposit() {
    if (busy.value) {
        return;
    }
    depositOpen.value = false;
    depositError.value = "";
}

async function confirmDeposit() {
    if (!advertId.value || busy.value) {
        return;
    }

    const sum = Number(depositSum.value);
    if (!Number.isFinite(sum) || sum < MIN_BUDGET_DEPOSIT) {
        depositError.value = `Минимальная сумма — ${MIN_BUDGET_DEPOSIT} ₽`;
        return;
    }
    if (sum % 50 !== 0) {
        depositError.value = "Сумма должна быть кратна 50 ₽";
        return;
    }

    depositError.value = "";
    busy.value = true;

    try {
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${advertId.value}/deposit`,
            { sum, experiment_id: props.experiment?.id },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось пополнить бюджет");
            return;
        }

        const message =
            data?.messages?.[0] ||
            `Бюджет пополнен на ${formatRub(sum)} ₽`;

        if (data.budget_total != null && data.budget_total !== "") {
            budgetTotal.value = Number(data.budget_total);
        } else {
            await loadBudget();
        }

        lastDepositedSum.value = data.deposited_sum != null
            ? Number(data.deposited_sum)
            : sum;
        lastDepositMessage.value = message;

        depositOpen.value = false;
        // Toast after dialog closes so it is not covered by the overlay.
        await nextTick();
        showSuccess(message);
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось пополнить бюджет",
        );
    } finally {
        busy.value = false;
    }
}

function openDelete() {
    deleteOpen.value = true;
}

async function confirmDelete() {
    if (!advertId.value || busy.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.delete(
            `${props.baseUrl}/campaigns/${advertId.value}`,
            {
                params: { experiment_id: props.experiment?.id },
            },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось удалить кампанию");
            return;
        }

        showSuccess(data?.messages?.[0] || "Кампания удалена");
        deleteOpen.value = false;

        if (data.experiment) {
            emit("campaign-deleted", data.experiment);
            emit("experiment-updated", data.experiment);
        } else {
            emit("campaign-deleted", {
                ...(props.experiment ?? {}),
                wb_advert_id: null,
                wb_advert_name: null,
            });
        }
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось удалить кампанию",
        );
    } finally {
        busy.value = false;
    }
}

watch(
    advertId,
    (id) => {
        lastDepositMessage.value = "";
        lastDepositedSum.value = null;
        if (id) {
            loadBudget();
        } else {
            budgetTotal.value = null;
        }
    },
);

onMounted(() => {
    if (advertId.value) {
        loadBudget();
    }
});
</script>

<template>
    <div
        v-if="advertId"
        class="rounded-xl border border-border/70 bg-card/60 px-3.5 py-3 sm:px-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <Megaphone class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">
                        Рекламная кампания эксперимента
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground sm:text-sm">
                        <span class="font-medium text-foreground">{{ advertName }}</span>
                        <span class="mx-1.5 text-muted-foreground">·</span>
                        <span class="tabular-nums">ID {{ advertId }}</span>
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <span class="text-muted-foreground">
                            Бюджет:
                            <span
                                class="ml-1 font-semibold tabular-nums"
                                :class="{
                                    'text-muted-foreground': budgetTone === 'muted',
                                    'text-destructive': budgetTone === 'danger',
                                    'text-amber-700 dark:text-amber-400': budgetTone === 'warn',
                                    'text-emerald-700 dark:text-emerald-400': budgetTone === 'ok',
                                }"
                            >
                                {{ budgetLabel }}
                            </span>
                        </span>
                        <button
                            v-if="!budgetLoading"
                            type="button"
                            class="text-xs text-primary underline-offset-2 hover:underline"
                            :disabled="busy"
                            @click="loadBudget"
                        >
                            Обновить
                        </button>
                    </div>
                    <div v-if="statusBadge || isRunning" class="mt-1.5 flex flex-wrap gap-1">
                        <Badge v-if="statusBadge" :variant="statusBadge.variant" class="text-[11px]">
                            {{ statusBadge.label }}
                        </Badge>
                        <Badge v-if="isRunning" variant="warning" class="text-[11px]">
                            В A/B-тесте
                        </Badge>
                    </div>
                    <p
                        v-if="isRunning"
                        class="mt-1 text-[11px] text-muted-foreground"
                    >
                        Пока эксперимент запущен, пауза и удаление кампании недоступны — сначала
                        остановите эксперимент.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8"
                    :disabled="!canDeposit || busy"
                    @click="openDeposit"
                >
                    <Wallet class="mr-1.5 h-3.5 w-3.5" />
                    Пополнить
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8"
                    :disabled="!canPause || busy"
                    @click="pauseCampaign"
                >
                    <Pause class="mr-1.5 h-3.5 w-3.5" />
                    Пауза
                </Button>
                <Button
                    v-if="experiment?.campaign_created_by_tool"
                    size="sm"
                    variant="ghost"
                    class="h-8 text-destructive hover:text-destructive"
                    :disabled="!canDelete || busy"
                    @click="openDelete"
                >
                    <Trash2 class="mr-1.5 h-3.5 w-3.5" />
                    Удалить
                </Button>
            </div>
        </div>

        <div
            v-if="lastDepositMessage"
            class="mt-3 flex gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-900 dark:text-emerald-100"
        >
            <CheckCircle2 class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <div class="min-w-0 space-y-0.5">
                <p class="font-medium">{{ lastDepositMessage }}</p>
                <p v-if="budgetTotal != null" class="tabular-nums text-emerald-800/90 dark:text-emerald-200/90">
                    Текущий бюджет кампании: {{ formatRub(budgetTotal) }} ₽
                    <template v-if="lastDepositedSum != null">
                        (пополнено на {{ formatRub(lastDepositedSum) }} ₽)
                    </template>
                </p>
            </div>
        </div>

        <p
            v-else-if="budgetTone === 'danger' && !budgetLoading"
            class="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100"
        >
            Бюджет пуст — WB не запустит кампанию. Пополните минимум на {{ MIN_BUDGET_DEPOSIT }} ₽.
        </p>

        <Dialog
            :open="depositOpen"
            title="Пополнить бюджет"
            :description="`Средства списываются с рекламного баланса продавца на WB. Минимум ${MIN_BUDGET_DEPOSIT} ₽, кратно 50 ₽.`"
            @update:open="(v) => { if (!v) closeDeposit() }"
        >
            <div class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    Кампания:
                    <span class="font-medium text-foreground">{{ advertName }}</span>
                    (ID {{ advertId }})
                </p>
                <p v-if="budgetTotal != null" class="text-sm">
                    Сейчас на кампании:
                    <span class="font-semibold tabular-nums">{{ formatRub(budgetTotal) }} ₽</span>
                </p>
                <div class="space-y-1.5">
                    <Label for="bound-campaign-deposit-sum">Сумма, ₽</Label>
                    <Input
                        id="bound-campaign-deposit-sum"
                        v-model.number="depositSum"
                        type="number"
                        :min="MIN_BUDGET_DEPOSIT"
                        step="50"
                        :disabled="busy"
                        class="max-w-[12rem]"
                    />
                    <p v-if="depositError" class="text-xs text-destructive">
                        {{ depositError }}
                    </p>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" :disabled="busy" @click="closeDeposit">
                    Отмена
                </Button>
                <Button :disabled="busy" @click="confirmDeposit">
                    {{ busy ? "Пополнение…" : "Пополнить" }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="deleteOpen"
            title="Удалить кампанию?"
            description="Кампания будет удалена в Wildberries и отвязана от эксперимента. Потребуется заново выбрать или создать кампанию."
            @update:open="(v) => { if (!v) deleteOpen = false }"
        >
            <p class="text-sm text-muted-foreground">
                Кампания:
                <span class="font-medium text-foreground">{{ advertName }}</span>
                (ID {{ advertId }})
            </p>
            <template #footer>
                <Button variant="outline" :disabled="busy" @click="deleteOpen = false">
                    Отмена
                </Button>
                <Button variant="destructive" :disabled="busy" @click="confirmDelete">
                    {{ busy ? "Удаление…" : "Удалить кампанию" }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
