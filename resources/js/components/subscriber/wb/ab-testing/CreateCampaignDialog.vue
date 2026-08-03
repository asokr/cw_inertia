<script setup>
import { computed, ref, watch } from "vue";
import Dialog from "@/components/ui/Dialog.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import Checkbox from "@/components/ui/Checkbox.vue";

const MIN_DEPOSIT = 1000;

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    defaultName: {
        type: String,
        default: "",
    },
    submitting: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["update:open", "submit"]);

const name = ref("");
const bidType = ref("unified");
const paymentType = ref("cpm");
const placeSearch = ref(true);
const placeRecommendations = ref(false);
/** Deposit is on by default — WB will not start a campaign with zero budget. */
const depositEnabled = ref(true);
const budgetDeposit = ref(MIN_DEPOSIT);
const depositError = ref("");

const showPlacements = computed(() => bidType.value === "manual");

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            name.value = props.defaultName || "";
            bidType.value = "unified";
            paymentType.value = "cpm";
            placeSearch.value = true;
            placeRecommendations.value = false;
            depositEnabled.value = true;
            budgetDeposit.value = MIN_DEPOSIT;
            depositError.value = "";
        }
    },
);

function close() {
    if (props.submitting) {
        return;
    }
    emit("update:open", false);
}

function validateDeposit() {
    if (!depositEnabled.value) {
        depositError.value = "";
        return true;
    }

    const sum = Number(budgetDeposit.value);
    if (!Number.isFinite(sum) || sum < MIN_DEPOSIT) {
        depositError.value = `Минимальная сумма пополнения — ${MIN_DEPOSIT} ₽`;
        return false;
    }
    if (sum % 50 !== 0) {
        depositError.value = "Сумма должна быть кратна 50 ₽";
        return false;
    }

    depositError.value = "";
    return true;
}

function submit() {
    if (props.submitting) {
        return;
    }

    if (!validateDeposit()) {
        return;
    }

    const payload = {
        name: name.value.trim() || props.defaultName,
        bid_type: bidType.value,
        payment_type: paymentType.value,
    };

    if (bidType.value === "manual") {
        const placements = [];
        if (placeSearch.value) {
            placements.push("search");
        }
        if (placeRecommendations.value) {
            placements.push("recommendations");
        }
        payload.placement_types = placements.length ? placements : ["search"];
    }

    if (depositEnabled.value) {
        payload.budget_deposit = Number(budgetDeposit.value) || 0;
    }

    emit("submit", payload);
}
</script>

<template>
    <Dialog
        :open="open"
        title="Создать кампанию"
        description="Кампания будет создана в рекламном кабинете Wildberries. Запуск — только вместе с A/B-тестом."
        class="max-w-lg"
        @update:open="emit('update:open', $event)"
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <Label for="ab-campaign-name">Название</Label>
                <Input
                    id="ab-campaign-name"
                    v-model="name"
                    :disabled="submitting"
                    placeholder="A/B тест — артикул"
                    maxlength="255"
                />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label for="ab-bid-type">Тип ставки</Label>
                    <Select id="ab-bid-type" v-model="bidType" :disabled="submitting">
                        <option value="unified">Единая ставка</option>
                        <option value="manual">Ручная ставка</option>
                    </Select>
                </div>
                <div class="space-y-1.5">
                    <Label for="ab-payment-type">Тип оплаты</Label>
                    <Select id="ab-payment-type" v-model="paymentType" :disabled="submitting">
                        <option value="cpm">CPM (за показы)</option>
                        <option value="cpc">CPC (за клики)</option>
                    </Select>
                </div>
            </div>

            <div v-if="showPlacements" class="space-y-2 rounded-md border border-border/70 p-3">
                <p class="text-sm font-medium">Места размещения</p>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="placeSearch" :disabled="submitting" />
                    Поиск
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="placeRecommendations" :disabled="submitting" />
                    Рекомендации
                </label>
            </div>

            <div class="space-y-2 rounded-md border border-amber-500/25 bg-amber-500/5 p-3">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <Checkbox v-model="depositEnabled" :disabled="submitting" />
                    Пополнить бюджет при создании
                </label>
                <p class="text-xs text-muted-foreground">
                    Без бюджета WB не запустит кампанию. Минимум {{ MIN_DEPOSIT }} ₽, кратно 50 ₽.
                    Средства списываются с рекламного счёта / баланса продавца на WB.
                </p>
                <div class="flex flex-wrap items-end gap-2">
                    <div class="space-y-1">
                        <Label for="ab-budget-deposit" class="text-xs text-muted-foreground">
                            Сумма, ₽
                        </Label>
                        <Input
                            id="ab-budget-deposit"
                            v-model.number="budgetDeposit"
                            type="number"
                            :min="MIN_DEPOSIT"
                            step="50"
                            :disabled="submitting || !depositEnabled"
                            class="max-w-[12rem]"
                            @update:model-value="depositError = ''"
                        />
                    </div>
                </div>
                <p v-if="depositError" class="text-xs text-destructive">
                    {{ depositError }}
                </p>
                <p
                    v-else-if="!depositEnabled"
                    class="text-xs font-medium text-amber-800 dark:text-amber-200"
                >
                    Без пополнения кампания создастся с нулевым бюджетом — запуск эксперимента
                    будет недоступен, пока не пополните бюджет в кабинете WB.
                </p>
            </div>

            <p class="text-xs text-muted-foreground">
                Выбранный товар эксперимента будет добавлен в кампанию автоматически.
            </p>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="submitting" @click="close">
                Отмена
            </Button>
            <Button :disabled="submitting" @click="submit">
                {{ submitting ? "Создание…" : "Создать" }}
            </Button>
        </template>
    </Dialog>
</template>
