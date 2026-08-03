<script setup>
import { computed, ref, watch } from "vue";
import axios from "axios";
import { ImagePlus } from "lucide-vue-next";
import BoundCampaignPanel from "./BoundCampaignPanel.vue";
import ExperimentPhotoCard from "./ExperimentPhotoCard.vue";
import ExperimentPhotoUploadZone from "./ExperimentPhotoUploadZone.vue";
import ExperimentSettingsPanel from "./ExperimentSettingsPanel.vue";
import SelectedProductCard from "./SelectedProductCard.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const MAX_PHOTOS = 6;
const MIN_PHOTOS = 2;

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
    /** Workspace mode: no sticky continue / less chrome. */
    compact: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["continue", "experiment-updated", "campaign-deleted"]);

const { showError, showSuccess } = useFlashToast();

const photos = ref([]);
const loading = ref(false);
const busy = ref(false);
const loadError = ref("");
const dragFromIndex = ref(null);

const settingsPanel = ref(null);

const isEditableStatus = computed(() => !!props.experiment?.can_edit);
const remaining = computed(() => Math.max(0, MAX_PHOTOS - photos.value.length));
const settingsReady = computed(() => !!props.experiment?.settings_ready);
const canContinue = computed(
    () => photos.value.length >= MIN_PHOTOS && settingsReady.value,
);
const canEdit = computed(() => isEditableStatus.value && !busy.value);

/** Reasons why «Продолжить» is blocked (shown under the button). */
const continueBlockers = computed(() => {
    const reasons = [];
    if (photos.value.length < MIN_PHOTOS) {
        const need = MIN_PHOTOS - photos.value.length;
        reasons.push(
            need === 1
                ? "Загрузите ещё минимум 1 фотографию (нужно от 2)."
                : `Загрузите ещё минимум ${need} фотографии (нужно от 2).`,
        );
    }
    if (!settingsReady.value) {
        reasons.push(
            "Откройте блок «Настройки эксперимента» и нажмите «Сохранить настройки» — без сохранения переход недоступен.",
        );
    }
    return reasons;
});

const photosUrl = computed(() => {
    if (!props.experiment?.id) {
        return null;
    }
    return `${props.baseUrl}/experiments/${props.experiment.id}/photos`;
});

function applyPayload(data) {
    if (Array.isArray(data?.photos)) {
        photos.value = data.photos;
    }
    if (data?.experiment) {
        emit("experiment-updated", data.experiment);
    }
}

/**
 * Keep local photo cards in sync when Inertia poll refreshes selectedExperiment
 * (stats, progress) without re-hitting the photos JSON endpoint.
 */
watch(
    () => props.experiment?.photos,
    (next) => {
        if (!Array.isArray(next) || !next.length) {
            return;
        }
        // Prefer full list from experiment payload; merge stats if ids match.
        if (!photos.value.length) {
            photos.value = next;
            return;
        }
        const byId = new Map(next.map((p) => [p.id, p]));
        photos.value = photos.value.map((local) => {
            const fresh = byId.get(local.id);
            if (!fresh) {
                return local;
            }
            return {
                ...local,
                ...fresh,
                stats: fresh.stats ?? local.stats,
            };
        });
        // Append any new ids (order from server).
        if (next.length !== photos.value.length) {
            photos.value = next;
        }
    },
    { deep: true },
);

async function loadPhotos() {
    if (!photosUrl.value) {
        photos.value = [];
        return;
    }

    loading.value = true;
    loadError.value = "";

    try {
        // Prefer photos already on experiment payload to avoid extra round-trip.
        if (Array.isArray(props.experiment?.photos) && props.experiment.photos.length) {
            photos.value = props.experiment.photos;
        }

        const { data } = await axios.get(photosUrl.value);
        if (!data?.success) {
            loadError.value = data?.messages?.[0] || "Не удалось загрузить фотографии";
            return;
        }
        applyPayload(data);
    } catch (error) {
        loadError.value =
            error?.response?.data?.messages?.[0] ||
            error?.response?.data?.message ||
            "Не удалось загрузить фотографии";
    } finally {
        loading.value = false;
    }
}

async function uploadFiles(files) {
    if (!photosUrl.value || !canEdit.value || !files?.length) {
        return;
    }

    const slice = Array.from(files).slice(0, remaining.value);
    if (!slice.length) {
        showError(`Можно загрузить не более ${MAX_PHOTOS} фотографий`);
        return;
    }

    const formData = new FormData();
    slice.forEach((file, index) => formData.append(`photos[${index}]`, file));

    busy.value = true;
    try {
        const { data } = await axios.post(photosUrl.value, formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });
        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось загрузить фотографии");
            return;
        }
        applyPayload(data);
        showSuccess(data?.messages?.[0] || "Фотографии загружены");
    } catch (error) {
        const messages = error?.response?.data?.messages;
        const validation = error?.response?.data?.errors;
        const firstValidation = validation
            ? Object.values(validation).flat()?.[0]
            : null;
        showError(
            messages?.[0] ||
                firstValidation ||
                error?.response?.data?.message ||
                "Не удалось загрузить фотографии",
        );
    } finally {
        busy.value = false;
    }
}

async function replacePhoto(photo, file) {
    if (!props.experiment?.id || !photo?.id || !canEdit.value || !file) {
        return;
    }

    const formData = new FormData();
    formData.append("photo", file);

    busy.value = true;
    try {
        const { data } = await axios.post(
            `${props.baseUrl}/experiments/${props.experiment.id}/photos/${photo.id}`,
            formData,
            { headers: { "Content-Type": "multipart/form-data" } },
        );
        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось заменить фотографию");
            return;
        }
        applyPayload(data);
        showSuccess(data?.messages?.[0] || "Фотография заменена");
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось заменить фотографию",
        );
    } finally {
        busy.value = false;
    }
}

async function deletePhoto(photo) {
    if (!props.experiment?.id || !photo?.id || !canEdit.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.delete(
            `${props.baseUrl}/experiments/${props.experiment.id}/photos/${photo.id}`,
        );
        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось удалить фотографию");
            return;
        }
        applyPayload(data);
        showSuccess(data?.messages?.[0] || "Фотография удалена");
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось удалить фотографию",
        );
    } finally {
        busy.value = false;
    }
}

async function persistOrder(nextPhotos) {
    if (!props.experiment?.id || !canEdit.value) {
        return;
    }

    const previous = photos.value;
    photos.value = nextPhotos;
    busy.value = true;

    try {
        const { data } = await axios.patch(
            `${props.baseUrl}/experiments/${props.experiment.id}/photos/reorder`,
            { order: nextPhotos.map((p) => p.id) },
        );
        if (!data?.success) {
            photos.value = previous;
            showError(data?.messages?.[0] || "Не удалось изменить порядок");
            return;
        }
        applyPayload(data);
    } catch (error) {
        photos.value = previous;
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось изменить порядок",
        );
    } finally {
        busy.value = false;
    }
}

function onDragStart(index, event) {
    dragFromIndex.value = index;
    if (event?.dataTransfer) {
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", String(index));
    }
}

function onDrop(toIndex) {
    const fromIndex = dragFromIndex.value;
    dragFromIndex.value = null;
    if (fromIndex == null || fromIndex === toIndex) {
        return;
    }
    if (fromIndex < 0 || toIndex < 0 || fromIndex >= photos.value.length || toIndex >= photos.value.length) {
        return;
    }

    const next = [...photos.value];
    const [item] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, item);
    persistOrder(next);
}

function onDragEnd() {
    dragFromIndex.value = null;
}

function onContinue() {
    if (photos.value.length < MIN_PHOTOS) {
        showError("Загрузите минимум 2 фотографии для A/B-теста");
        return;
    }
    if (!settingsReady.value) {
        settingsPanel.value?.expand?.();
        showError(
            "Сначала сохраните настройки эксперимента кнопкой «Сохранить настройки» — без этого продолжить нельзя",
        );
        return;
    }
    emit("continue");
}

watch(
    () => props.experiment?.id,
    (id) => {
        if (id) {
            if (Array.isArray(props.experiment?.photos)) {
                photos.value = props.experiment.photos;
            }
            loadPhotos();
        } else {
            photos.value = [];
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1">
            <h3 :class="compact ? 'text-base font-semibold' : 'text-lg font-semibold'">
                Фотографии
            </h3>
            <p class="text-sm text-muted-foreground">
                Варианты главной фотографии (от {{ MIN_PHOTOS }} до {{ MAX_PHOTOS }}).
                Используются только загруженные вами файлы.
                <span class="tabular-nums font-medium text-foreground">
                    {{ photos.length }} / {{ MAX_PHOTOS }}
                </span>
            </p>
        </div>

        <SelectedProductCard v-if="product" :product="product" />

        <template v-if="!compact">
            <BoundCampaignPanel
                v-if="experiment?.wb_advert_id"
                :experiment="experiment"
                :base-url="baseUrl"
                @experiment-updated="(exp) => emit('experiment-updated', exp)"
                @campaign-deleted="(exp) => emit('campaign-deleted', exp)"
            />

            <ExperimentSettingsPanel
                v-if="experiment"
                ref="settingsPanel"
                :experiment="experiment"
                :base-url="baseUrl"
                :default-open="!experiment.settings_ready && isEditableStatus"
                @experiment-updated="(exp) => emit('experiment-updated', exp)"
            />
        </template>

        <div
            v-if="loading"
            class="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-10 text-center text-sm text-muted-foreground"
        >
            Загрузка фотографий…
        </div>

        <Card
            v-else-if="loadError"
            class="space-y-3 p-6 text-center"
        >
            <p class="text-sm text-destructive">{{ loadError }}</p>
            <Button size="sm" variant="outline" @click="loadPhotos">
                Повторить
            </Button>
        </Card>

        <template v-else>
            <Card
                v-if="!photos.length"
                class="flex flex-col items-center justify-center gap-3 p-10 text-center"
            >
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <ImagePlus class="h-5 w-5 text-muted-foreground" />
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-semibold">Пока нет вариантов фото</h4>
                    <p class="max-w-md text-sm text-muted-foreground">
                        Загрузите первые фотографии для проведения эксперимента.
                    </p>
                </div>
            </Card>

            <div
                v-else
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
            >
                <ExperimentPhotoCard
                    v-for="(photo, index) in photos"
                    :key="photo.id"
                    :photo="photo"
                    :index="index"
                    :editable="isEditableStatus"
                    :busy="busy"
                    :draggable="isEditableStatus && photos.length > 1"
                    :experiment-status="experiment?.status || 'draft'"
                    @replace="(file) => replacePhoto(photo, file)"
                    @delete="deletePhoto(photo)"
                    @drag-start="(e) => onDragStart(index, e)"
                    @drop="() => onDrop(index)"
                    @drag-end="onDragEnd"
                />
            </div>

            <ExperimentPhotoUploadZone
                v-if="isEditableStatus && remaining > 0"
                :remaining="remaining"
                :busy="busy"
                :disabled="!experiment?.id"
                @files="uploadFiles"
            />

            <p
                v-else-if="isEditableStatus && remaining <= 0"
                class="text-center text-xs text-muted-foreground"
            >
                Достигнут лимит {{ MAX_PHOTOS }} фотографий. Удалите вариант, чтобы загрузить другой.
            </p>

            <p
                v-else-if="!isEditableStatus"
                class="text-center text-xs text-muted-foreground"
            >
                Фотографии только для просмотра в текущем статусе эксперимента.
            </p>
        </template>

        <div
            v-if="!compact"
            class="sticky bottom-0 z-10 -mx-1 mt-2 space-y-2 border-t border-border/60 bg-background/95 px-1 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    <template v-if="canContinue">
                        Порядок карточек = порядок участия в эксперименте
                    </template>
                    <template v-else>
                        Заполните условия ниже, чтобы перейти к запуску
                    </template>
                </p>
                <Button :disabled="!canContinue" @click="onContinue">
                    Продолжить
                </Button>
            </div>
            <div
                v-if="continueBlockers.length"
                class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-100"
            >
                <p class="font-medium">Пока нельзя продолжить:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-4">
                    <li v-for="(reason, idx) in continueBlockers" :key="idx">
                        {{ reason }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
