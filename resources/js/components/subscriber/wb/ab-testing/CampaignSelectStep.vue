<script setup>
import { computed, onMounted, ref, watch } from "vue";
import axios from "axios";
import { Megaphone, Plus, RefreshCw } from "lucide-vue-next";
import CampaignsTable from "./CampaignsTable.vue";
import CreateCampaignDialog from "./CreateCampaignDialog.vue";
import SelectedProductCard from "./SelectedProductCard.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    product: {
        type: Object,
        default: null,
    },
    experiment: {
        type: Object,
        default: null,
    },
    baseUrl: {
        type: String,
        required: true,
    },
    /** Workspace: no product card, no auto-advance after bind. */
    embedded: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["experiment-updated", "continue"]);

const { showError, showSuccess } = useFlashToast();

const campaigns = ref([]);
const loading = ref(false);
const loadError = ref("");
const busyAdvertId = ref(null);
const creating = ref(false);
const createOpen = ref(false);
const defaultCampaignName = ref("");
const deleteTarget = ref(null);
const deleting = ref(false);
const depositTarget = ref(null);
const depositSum = ref(1000);
const depositing = ref(false);
const depositError = ref("");

const selectedAdvertId = computed(() => props.experiment?.wb_advert_id ?? null);

const hasCampaigns = computed(() => (campaigns.value?.length ?? 0) > 0);

function goToPhotosIfBound(experiment) {
    if (props.embedded) {
        return;
    }
    if (experiment?.wb_advert_id) {
        emit("continue");
    }
}

const suggestedName = computed(() => {
    if (defaultCampaignName.value) {
        return defaultCampaignName.value;
    }
    const code = props.product?.vendor_code || props.product?.nm_id;
    return code ? `A/B тест — ${code}` : "A/B тест";
});

async function loadCampaigns() {
    if (!props.experiment?.id) {
        return;
    }

    loading.value = true;
    loadError.value = "";

    try {
        const { data } = await axios.get(`${props.baseUrl}/campaigns`, {
            params: { experiment_id: props.experiment.id },
        });

        if (!data?.success) {
            loadError.value =
                data?.messages?.[0] || "Не удалось загрузить рекламные кампании";
            campaigns.value = [];
            return;
        }

        campaigns.value = Array.isArray(data.campaigns) ? data.campaigns : [];
        if (data.default_campaign_name) {
            defaultCampaignName.value = data.default_campaign_name;
        }
        if (data.experiment) {
            emit("experiment-updated", data.experiment);
        }
    } catch (error) {
        const message =
            error?.response?.data?.messages?.[0] ||
            error?.response?.data?.message ||
            "Не удалось загрузить рекламные кампании";
        loadError.value = message;
        campaigns.value = [];
    } finally {
        loading.value = false;
    }
}

/**
 * Attach campaign to experiment (auto-prepares nm under the hood).
 * Campaign joins the product — not "product actions" in the table.
 */
async function selectCampaign(campaign) {
    if (!campaign?.id || !props.experiment?.id || !campaign.can_select) {
        if (campaign?.edit_block_reason) {
            showError(campaign.edit_block_reason);
        }
        return;
    }

    if (Number(selectedAdvertId.value) === Number(campaign.id)) {
        return;
    }

    busyAdvertId.value = campaign.id;

    try {
        // prepare swaps nms to current product + binds (works whether product already in RК or not).
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${campaign.id}/prepare`,
            {
                experiment_id: props.experiment.id,
            },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось выбрать кампанию");
            return;
        }

        if (data.experiment) {
            emit("experiment-updated", data.experiment);
            showSuccess(data?.messages?.[0] || "Кампания привязана к эксперименту");
            goToPhotosIfBound(data.experiment);
            return;
        }
        showSuccess(data?.messages?.[0] || "Кампания привязана к эксперименту");
        await loadCampaigns();
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось выбрать кампанию",
        );
    } finally {
        busyAdvertId.value = null;
    }
}

async function pauseCampaign(campaign) {
    if (!campaign?.id || !campaign.can_pause) {
        return;
    }

    busyAdvertId.value = campaign.id;

    try {
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${campaign.id}/pause`,
            { experiment_id: props.experiment?.id },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось поставить кампанию на паузу");
            return;
        }

        showSuccess(data?.messages?.[0] || "Кампания на паузе");
        if (data.campaign) {
            const idx = campaigns.value.findIndex((c) => Number(c.id) === Number(campaign.id));
            if (idx >= 0) {
                campaigns.value[idx] = { ...campaigns.value[idx], ...data.campaign };
            } else {
                await loadCampaigns();
            }
        } else {
            await loadCampaigns();
        }
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось поставить кампанию на паузу",
        );
    } finally {
        busyAdvertId.value = null;
    }
}

function askDeleteCampaign(campaign) {
    deleteTarget.value = campaign;
}

async function confirmDeleteCampaign() {
    const campaign = deleteTarget.value;
    if (!campaign?.id || deleting.value) {
        return;
    }

    deleting.value = true;
    busyAdvertId.value = campaign.id;

    try {
        const { data } = await axios.delete(
            `${props.baseUrl}/campaigns/${campaign.id}`,
            {
                params: { experiment_id: props.experiment?.id },
            },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось удалить кампанию");
            return;
        }

        if (data.experiment) {
            emit("experiment-updated", data.experiment);
        }
        showSuccess(data?.messages?.[0] || "Кампания удалена");
        deleteTarget.value = null;
        await loadCampaigns();
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось удалить кампанию",
        );
    } finally {
        deleting.value = false;
        busyAdvertId.value = null;
    }
}

function openDeposit(campaign) {
    depositTarget.value = campaign;
    depositSum.value = 1000;
    depositError.value = "";
}

function closeDeposit() {
    if (depositing.value) {
        return;
    }
    depositTarget.value = null;
    depositError.value = "";
}

async function confirmDeposit() {
    const campaign = depositTarget.value;
    if (!campaign?.id || depositing.value) {
        return;
    }

    const sum = Number(depositSum.value);
    if (!Number.isFinite(sum) || sum < 1000) {
        depositError.value = "Минимальная сумма — 1000 ₽";
        return;
    }
    if (sum % 50 !== 0) {
        depositError.value = "Сумма должна быть кратна 50 ₽";
        return;
    }

    depositError.value = "";
    depositing.value = true;
    busyAdvertId.value = campaign.id;

    try {
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${campaign.id}/deposit`,
            { sum, experiment_id: props.experiment?.id },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось пополнить бюджет");
            return;
        }

        showSuccess(data?.messages?.[0] || "Бюджет пополнен");
        depositTarget.value = null;
        await loadCampaigns();
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось пополнить бюджет",
        );
    } finally {
        depositing.value = false;
        busyAdvertId.value = null;
    }
}

async function createCampaign(payload) {
    if (!props.experiment?.id || creating.value) {
        return;
    }

    creating.value = true;

    try {
        const { data } = await axios.post(`${props.baseUrl}/campaigns`, {
            experiment_id: props.experiment.id,
            ...payload,
        });

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось создать кампанию");
            return;
        }

        const message = data?.messages?.[0] || "Кампания создана";
        // Deposit is soft-fail on backend: campaign still bound, but budget may be zero.
        const depositFailed =
            data?.budget_deposited === false ||
            (typeof message === "string" &&
                /пополнить бюджет не удалось|бюджет.*не удалось/i.test(message));

        if (data.experiment) {
            emit("experiment-updated", data.experiment);
            createOpen.value = false;
            if (depositFailed) {
                showError(message);
            } else {
                showSuccess(message);
            }
            goToPhotosIfBound(data.experiment);
            return;
        }
        if (depositFailed) {
            showError(message);
        } else {
            showSuccess(message);
        }
        createOpen.value = false;
        await loadCampaigns();
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось создать кампанию",
        );
    } finally {
        creating.value = false;
    }
}

watch(
    () => props.experiment?.id,
    (id) => {
        if (id) {
            loadCampaigns();
        }
    },
);

onMounted(() => {
    if (props.experiment?.id) {
        loadCampaigns();
    }
});
</script>

<template>
    <div class="space-y-4">
        <div v-if="!embedded" class="space-y-1">
            <h3 class="text-lg font-semibold">Рекламная кампания</h3>
            <p class="text-sm text-muted-foreground">
                Кампании A/B-инструмента. Клик по строке привязывает кампанию к эксперименту.
            </p>
        </div>
        <div v-else class="space-y-1">
            <h4 class="text-sm font-semibold">Привязать рекламную кампанию</h4>
            <p class="text-xs text-muted-foreground">
                Создайте новую или выберите существующую из списка. Товар подставится автоматически.
            </p>
        </div>

        <SelectedProductCard v-if="product && !embedded" :product="product" />

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p v-if="hasCampaigns && !loading" class="text-sm text-muted-foreground">
                Кампаний: {{ campaigns.length }}
            </p>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="loading || !experiment?.id"
                    @click="loadCampaigns"
                >
                    <RefreshCw class="mr-1.5 h-4 w-4" :class="loading ? 'animate-spin' : ''" />
                    Обновить
                </Button>
                <Button size="sm" :disabled="!experiment?.id" @click="createOpen = true">
                    <Plus class="mr-1.5 h-4 w-4" />
                    Создать кампанию
                </Button>
            </div>
        </div>

        <div
            v-if="loading"
            class="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-10 text-center text-sm text-muted-foreground"
        >
            Загрузка рекламных кампаний…
        </div>

        <Card
            v-else-if="loadError"
            class="space-y-3 p-6 text-center"
        >
            <p class="text-sm text-destructive">{{ loadError }}</p>
            <Button size="sm" variant="outline" @click="loadCampaigns">
                Повторить
            </Button>
        </Card>

        <Card
            v-else-if="!hasCampaigns"
            class="flex flex-col items-center justify-center gap-3 p-10 text-center"
        >
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                <Megaphone class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-1">
                <h4 class="text-base font-semibold">Кампаний A/B-тестирования пока нет</h4>
                <p class="max-w-md text-sm text-muted-foreground">
                    Создайте первую кампанию — она сохранится в сервисе и её можно будет
                    переиспользовать для других товаров, не плодя рекламу в кабинете WB.
                </p>
            </div>
            <Button class="mt-1" @click="createOpen = true">
                <Plus class="mr-1.5 h-4 w-4" />
                Создать кампанию
            </Button>
        </Card>

        <CampaignsTable
            v-else
            :items="campaigns"
            :selected-id="selectedAdvertId"
            :busy-advert-id="busyAdvertId"
            @select="selectCampaign"
            @pause="pauseCampaign"
            @delete="askDeleteCampaign"
            @deposit="openDeposit"
        />

        <p class="text-xs text-muted-foreground">
            После привязки кампании к эксперименту вы автоматически перейдёте к фотографиям.
        </p>

        <CreateCampaignDialog
            v-model:open="createOpen"
            :default-name="suggestedName"
            :submitting="creating"
            @submit="createCampaign"
        />

        <Dialog
            :open="!!deleteTarget"
            title="Удалить кампанию?"
            description="Кампания будет удалена в рекламном кабинете Wildberries и убрана из списка A/B. Это действие необратимо."
            @update:open="(v) => { if (!v) deleteTarget = null }"
        >
            <p v-if="deleteTarget" class="text-sm text-muted-foreground">
                Кампания:
                <span class="font-medium text-foreground">{{ deleteTarget.name }}</span>
                (ID {{ deleteTarget.id }})
            </p>
            <template #footer>
                <Button variant="outline" :disabled="deleting" @click="deleteTarget = null">
                    Отмена
                </Button>
                <Button variant="destructive" :disabled="deleting" @click="confirmDeleteCampaign">
                    {{ deleting ? "Удаление…" : "Удалить кампанию" }}
                </Button>
            </template>
        </Dialog>

        <Dialog
            :open="!!depositTarget"
            title="Пополнить бюджет"
            description="Средства списываются с рекламного баланса продавца на WB. Минимум 1000 ₽, кратно 50 ₽."
            @update:open="(v) => { if (!v) closeDeposit() }"
        >
            <div v-if="depositTarget" class="space-y-3">
                <p class="text-sm text-muted-foreground">
                    Кампания:
                    <span class="font-medium text-foreground">{{ depositTarget.name }}</span>
                    (ID {{ depositTarget.id }})
                </p>
                <div class="space-y-1.5">
                    <Label for="ab-campaign-deposit-sum">Сумма, ₽</Label>
                    <Input
                        id="ab-campaign-deposit-sum"
                        v-model.number="depositSum"
                        type="number"
                        min="1000"
                        step="50"
                        :disabled="depositing"
                        class="max-w-[12rem]"
                    />
                    <p v-if="depositError" class="text-xs text-destructive">
                        {{ depositError }}
                    </p>
                </div>
            </div>
            <template #footer>
                <Button variant="outline" :disabled="depositing" @click="closeDeposit">
                    Отмена
                </Button>
                <Button :disabled="depositing" @click="confirmDeposit">
                    {{ depositing ? "Пополнение…" : "Пополнить" }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
