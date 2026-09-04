<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { Megaphone, Plus, RefreshCw } from "lucide-vue-next";
import CampaignsTable from "./CampaignsTable.vue";
import CampaignSuitabilityHint from "./CampaignSuitabilityHint.vue";
import CreateCampaignDialog from "./CreateCampaignDialog.vue";
import SelectedProductCard from "./SelectedProductCard.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Dialog from "@/components/ui/Dialog.vue";
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
    if (!props.experiment?.id || loading.value) {
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
        // prepare добавляет товар, если его нет, и привязывает кампанию.
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

        if (data.experiment) {
            emit("experiment-updated", data.experiment);
            createOpen.value = false;
            showSuccess(message);
            goToPhotosIfBound(data.experiment);
            return;
        }
        showSuccess(message);
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
    { immediate: true },
);
</script>

<template>
    <div class="space-y-4 overflow-visible">
        <div v-if="!embedded" class="space-y-1">
            <div class="flex items-center gap-1.5">
                <h3 class="text-lg font-semibold">Рекламная кампания</h3>
                <CampaignSuitabilityHint />
            </div>
            <p class="text-sm text-muted-foreground">
                Выберите кампанию из кабинета Ozon или создайте новую. Клик по строке привязывает её к эксперименту.
            </p>
        </div>
        <div v-else class="space-y-1">
            <div class="flex items-center gap-1.5">
                <h4 class="text-sm font-semibold">Привязать рекламную кампанию</h4>
                <CampaignSuitabilityHint />
            </div>
            <p class="text-xs text-muted-foreground">
                Выберите кампанию из кабинета или создайте новую. Если товара ещё нет в кампании, он будет добавлен.
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
            class="flex flex-col items-center justify-center gap-3 overflow-visible p-10 text-center"
        >
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                <Megaphone class="h-5 w-5 text-muted-foreground" />
            </div>
            <div class="space-y-1">
                <h4 class="inline-flex items-center justify-center gap-1.5 text-base font-semibold">
                    Нет подходящих кампаний
                    <CampaignSuitabilityHint align="center" />
                </h4>
                <p class="max-w-md text-sm text-muted-foreground">
                    Для теста нужны кампании «Оплата за клик» с ручной ставкой.
                    Создайте новую — товар добавится автоматически.
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
            description="Кампания будет удалена в кабинете Ozon. Это действие необратимо."
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
    </div>
</template>
