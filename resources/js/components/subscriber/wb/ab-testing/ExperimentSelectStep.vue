<script setup>
import { computed, ref } from "vue";
import { router } from "@inertiajs/vue3";
import { FlaskConical, Plus } from "lucide-vue-next";
import ExperimentsTable from "./ExperimentsTable.vue";
import SelectedProductCard from "./SelectedProductCard.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    product: {
        type: Object,
        default: null,
    },
    experiments: {
        type: Array,
        default: () => [],
    },
    createUrl: {
        type: String,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["open", "created"]);

const { showError } = useFlashToast();
const creating = ref(false);

const hasExperiments = computed(() => (props.experiments?.length ?? 0) > 0);

const runningExperiment = computed(() =>
    (props.experiments ?? []).find((item) => item?.status === "running") ?? null,
);

function createExperiment() {
    if (!props.product?.id || creating.value) {
        return;
    }

    creating.value = true;
    router.post(
        props.createUrl,
        { product_id: props.product.id },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const created = page.props?.flash?.created_experiment ?? null;
                if (created) {
                    emit("created", created);
                }
            },
            onError: (errors) => {
                const message =
                    errors?.product_id ||
                    errors?.name ||
                    "Не удалось создать эксперимент";
                showError(typeof message === "string" ? message : "Не удалось создать эксперимент");
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

function onOpen(experiment) {
    emit("open", experiment);
}
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1">
            <h3 class="text-lg font-semibold">Эксперименты</h3>
            <p class="text-sm text-muted-foreground">
                Можно создать несколько черновиков. Одновременно запущен может быть только один
                эксперимент по товару. Нажмите на строку, чтобы открыть эксперимент.
            </p>
        </div>

        <SelectedProductCard v-if="product" :product="product" />

        <div
            v-if="runningExperiment"
            class="rounded-lg border border-primary/30 bg-primary/5 px-3.5 py-2.5 text-sm"
        >
            <span class="text-muted-foreground">Сейчас запущен:</span>
            <span class="ml-1.5 font-medium">{{ runningExperiment.name }}</span>
        </div>

        <div
            v-if="loading"
            class="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-10 text-center text-sm text-muted-foreground"
        >
            Загрузка экспериментов…
        </div>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p v-if="hasExperiments" class="text-sm text-muted-foreground">
                    Экспериментов: {{ experiments.length }}
                </p>
                <div :class="hasExperiments ? '' : 'ml-auto'">
                    <Button size="sm" :disabled="creating || !product?.id" @click="createExperiment">
                        <Plus class="mr-1.5 h-4 w-4" />
                        Создать эксперимент
                    </Button>
                </div>
            </div>

            <Card
                v-if="!hasExperiments"
                class="flex flex-col items-center justify-center gap-3 p-10 text-center"
            >
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <FlaskConical class="h-5 w-5 text-muted-foreground" />
                </div>
                <div class="space-y-1">
                    <h4 class="text-base font-semibold">Экспериментов пока нет</h4>
                    <p class="max-w-md text-sm text-muted-foreground">
                        Создайте первый эксперимент для A/B-тестирования главной фотографии этого товара.
                    </p>
                </div>
                <Button class="mt-1" :disabled="creating || !product?.id" @click="createExperiment">
                    <Plus class="mr-1.5 h-4 w-4" />
                    Создать эксперимент
                </Button>
            </Card>

            <ExperimentsTable
                v-else
                :items="experiments"
                @open="onOpen"
            />
        </template>
    </div>
</template>
