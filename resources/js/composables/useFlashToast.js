import { ref, watch } from "vue";

let nextId = 0;

export const programmaticToast = ref(null);

export function useFlashToast() {
    function pushToast(payload) {
        programmaticToast.value = {
            id: ++nextId,
            ...payload,
        };
    }

    function showError(message, errorDetails = null) {
        if (!message) {
            return;
        }

        pushToast({
            message,
            variant: "destructive",
            errorDetails,
        });
    }

    function showSuccess(message, successDetails = null) {
        if (!message) {
            return;
        }

        pushToast({
            message,
            variant: "success",
            successDetails,
        });
    }

    function showMessage(message, toastVariant = "default") {
        if (!message) {
            return;
        }

        pushToast({
            message,
            variant: toastVariant,
        });
    }

    function clearProgrammaticToast() {
        programmaticToast.value = null;
    }

    function notify(message, variant = "destructive") {
        if (variant === "success") {
            showSuccess(message);
            return;
        }

        if (variant === "destructive") {
            showError(message);
            return;
        }

        showMessage(message, variant);
    }

    function watchPropToast(source, variant = "destructive") {
        watch(
            source,
            (value) => {
                if (!value) {
                    return;
                }

                if (Array.isArray(value)) {
                    value.forEach((item) => notify(item, variant));
                    return;
                }

                notify(value, variant);
            },
            { immediate: true },
        );
    }

    return {
        showError,
        showSuccess,
        showMessage,
        clearProgrammaticToast,
        watchPropToast,
    };
}