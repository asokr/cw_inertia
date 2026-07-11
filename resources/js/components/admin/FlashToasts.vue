<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { AlertCircle, AlertTriangle, CheckCircle2, X } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import { programmaticToast } from "@/composables/useFlashToast";

const page = usePage();
const visible = ref(false);
const message = ref("");
const variant = ref("default");
const errorDetails = ref(null);
const successDetails = ref(null);

let dismissTimer = null;

const limitViolations = computed(() => errorDetails.value?.limit_violations ?? []);
const downgradeOverages = computed(() => successDetails.value?.limit_overages ?? []);
const isError = computed(() => variant.value === "destructive");
const isImportantSuccess = computed(() => successDetails.value?.type === "downgrade_scheduled");

function dismiss() {
    visible.value = false;
    errorDetails.value = null;
    successDetails.value = null;
    programmaticToast.value = null;

    if (dismissTimer) {
        window.clearTimeout(dismissTimer);
        dismissTimer = null;
    }
}

function showToastPayload(payload) {
    message.value = payload.message;
    errorDetails.value = payload.errorDetails ?? null;
    successDetails.value = payload.successDetails ?? null;
    variant.value = payload.variant ?? "default";
    visible.value = true;

    if (payload.variant === "destructive" || payload.successDetails?.type) {
        if (dismissTimer) {
            window.clearTimeout(dismissTimer);
            dismissTimer = null;
        }

        return;
    }

    scheduleAutoDismiss();
}

function scheduleAutoDismiss() {
    if (dismissTimer) {
        window.clearTimeout(dismissTimer);
    }

    dismissTimer = window.setTimeout(() => {
        visible.value = false;
        dismissTimer = null;
    }, 4000);
}

function consumeSessionFlash(flash = {}) {
    if (flash.success) {
        showToastPayload({
            message: flash.success,
            variant: "success",
            successDetails: flash.success_details ?? null,
        });
        return;
    }

    if (flash.error) {
        showToastPayload({
            message: flash.error,
            variant: "destructive",
            errorDetails: flash.error_details ?? null,
        });
        return;
    }

    if (flash.messages?.length) {
        showToastPayload({
            message: flash.messages[0],
            variant: "success",
        });
    }
}

let removeSuccessListener = null;

onMounted(() => {
    consumeSessionFlash(page.props.flash ?? {});

    removeSuccessListener = router.on("success", (event) => {
        consumeSessionFlash(event.detail.page.props.flash ?? {});
    });
});

watch(
    programmaticToast,
    (value) => {
        if (!value) {
            return;
        }

        showToastPayload(value);
    },
);

onUnmounted(() => {
    removeSuccessListener?.();
    removeSuccessListener = null;

    if (dismissTimer) {
        window.clearTimeout(dismissTimer);
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="visible && isImportantSuccess"
            class="fixed inset-x-4 top-4 z-[120] mx-auto max-w-lg sm:inset-x-auto sm:right-6 sm:top-6 sm:mx-0"
        >
            <div
                class="overflow-hidden rounded-xl border border-amber-500/40 bg-card shadow-2xl shadow-amber-500/10 ring-1 ring-amber-500/20"
                role="alert"
                aria-live="polite"
            >
                <div class="h-1 bg-gradient-to-r from-amber-500/20 via-amber-500 to-amber-500/20" />

                <div class="p-4 sm:p-5">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
                            <AlertTriangle class="h-5 w-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-base font-semibold leading-snug text-foreground">
                                    {{ message }}
                                </p>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    aria-label="Закрыть"
                                    @click="dismiss"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                                С <strong class="text-foreground">{{ successDetails.period_end }}</strong>
                                у вас будет тариф «<strong class="text-foreground">{{ successDetails.pending_plan_name }}</strong>».
                                До этой даты действует текущий тариф.
                            </p>

                            <div
                                v-if="downgradeOverages.length"
                                class="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/5 px-3 py-2.5 text-sm"
                            >
                                <p class="font-medium text-foreground">При смене лишние ресурсы удалятся автоматически:</p>
                                <ul class="mt-2 space-y-1 text-muted-foreground">
                                    <li v-for="overage in downgradeOverages" :key="overage.key">
                                        <strong class="text-foreground">{{ overage.label }}</strong> —
                                        сейчас {{ overage.used }}, на новом тарифе {{ overage.allowed }},
                                        удалим {{ overage.deficit }}
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Button size="sm" @click="dismiss">
                            Понятно
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div
            v-else-if="visible && isError"
            class="fixed inset-x-4 top-4 z-[120] mx-auto max-w-lg sm:inset-x-auto sm:right-6 sm:top-6 sm:mx-0"
        >
            <div
                class="overflow-hidden rounded-xl border border-destructive/40 bg-card shadow-2xl shadow-destructive/10 ring-1 ring-destructive/20"
                role="alert"
                aria-live="assertive"
            >
                <div class="h-1 bg-gradient-to-r from-destructive/20 via-destructive to-destructive/20" />

                <div class="p-4 sm:p-5">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                            <AlertCircle class="h-5 w-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-base font-semibold leading-snug text-foreground">
                                    {{ message }}
                                </p>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    aria-label="Закрыть"
                                    @click="dismiss"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>

                            <p
                                v-if="limitViolations.length"
                                class="mt-2 text-sm leading-relaxed text-muted-foreground"
                            >
                                Освободите ресурсы или выберите тариф с более высокими лимитами:
                            </p>

                            <ul v-if="limitViolations.length" class="mt-3 space-y-2">
                                <li
                                    v-for="violation in limitViolations"
                                    :key="violation.key"
                                    class="rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2.5 text-sm"
                                >
                                    <p class="font-medium text-foreground">{{ violation.label }}</p>
                                    <p class="mt-0.5 text-muted-foreground">
                                        Используется <strong class="text-foreground">{{ violation.used }}</strong>,
                                        на новом тарифе — <strong class="text-foreground">{{ violation.allowed }}</strong>.
                                        Нужно освободить
                                        <strong class="text-destructive">{{ violation.deficit }}</strong>.
                                    </p>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Button variant="destructive" size="sm" @click="dismiss">
                            Понятно
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="visible" class="fixed bottom-4 right-4 z-[100] max-w-sm">
            <Alert :variant="variant" class="pr-10 shadow-lg">
                <div class="flex items-start gap-2">
                    <CheckCircle2 v-if="variant === 'success'" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                    <span>{{ message }}</span>
                </div>
                <button
                    type="button"
                    class="absolute right-3 top-3 rounded-md p-0.5 text-muted-foreground transition hover:text-foreground"
                    aria-label="Закрыть"
                    @click="dismiss"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </Alert>
        </div>
    </Teleport>
</template>