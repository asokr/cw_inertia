<script setup>
import { computed } from "vue";
import SocialIdButton from "@/components/auth/SocialIdButton.vue";

const props = defineProps({
    label: {
        type: String,
        default: "Войти с VK ID",
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

const redirectUrl = computed(() => {
    const base = "/auth/vk/redirect";

    if (props.couponCode) {
        return `${base}?coupon_code=${encodeURIComponent(props.couponCode)}`;
    }

    return base;
});
</script>

<template>
    <SocialIdButton
        :href="redirectUrl"
        :label="label"
        :rounded="rounded"
        icon-src="/images/logo-vk.svg"
        icon-alt="VK"
        background-color="#0077ff"
    />
</template>