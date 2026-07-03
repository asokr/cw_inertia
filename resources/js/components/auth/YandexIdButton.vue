<script setup>
import { computed } from "vue";

const props = defineProps({
    label: {
        type: String,
        default: "Войти с Яндекс ID",
    },
    couponCode: {
        type: String,
        default: null,
    },
    rounded: {
        type: [String, Boolean, Number],
        default: "lg",
    },
});

const roundedClass = computed(() => {
    if (props.rounded === true || props.rounded === "") {
        return "rounded-full";
    }

    if (props.rounded === false) {
        return "rounded-none";
    }

    return `rounded-${props.rounded}`;
});

const yandexLogo = "/images/logo-yandex.svg";

const redirectUrl = computed(() => {
    const base = "/auth/yandex/redirect";

    if (props.couponCode) {
        return `${base}?coupon_code=${encodeURIComponent(props.couponCode)}`;
    }

    return base;
});
</script>

<template>
    <a
        :href="redirectUrl"
        class="yandex-id-button"
        :class="roundedClass"
    >
        <span class="yandex-id-button__icon-wrap" aria-hidden="true">
            <img class="yandex-id-button__icon" :src="yandexLogo" alt="" />
        </span>

        <span class="yandex-id-button__label">{{ label }}</span>
    </a>
</template>

<style scoped>
.yandex-id-button,
.yandex-id-button * {
    box-sizing: border-box;
}

.yandex-id-button {
    width: 100%;
    min-height: 44px;
    padding: 0 14px;
    border: 0;
    background: #000000;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: background-color 0.15s ease, opacity 0.2s ease;
}

.yandex-id-button:hover {
    background: #1a1a1a;
}

.yandex-id-button__icon-wrap {
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
}

.yandex-id-button__icon {
    width: 24px;
    height: 24px;
    display: block;
    border-radius: 50%;
}

.yandex-id-button__label {
    font-family: "YS Text", "Inter", sans-serif;
    font-weight: 500;
    font-size: 16px;
    line-height: 20px;
    letter-spacing: 0.15px;
    display: inline-flex;
    align-items: center;
}
</style>