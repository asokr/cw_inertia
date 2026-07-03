<script setup>
import { computed } from "vue";

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    iconSrc: {
        type: String,
        required: true,
    },
    iconAlt: {
        type: String,
        default: "",
    },
    backgroundColor: {
        type: String,
        required: true,
    },
    textColor: {
        type: String,
        default: "#ffffff",
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

const buttonStyle = computed(() => ({
    "--social-button-bg": props.backgroundColor,
    "--social-button-text": props.textColor,
}));
</script>

<template>
    <a
        :href="href"
        class="one-tap-button"
        :class="roundedClass"
        :style="buttonStyle"
    >
        <div class="x-left">
            <div class="swap-icon">
                <img class="logo-provider-28" :src="iconSrc" :alt="iconAlt" />
            </div>
        </div>
        <div class="x-middle-m">
            <div class="button-label">
                <div class="button-label2">{{ label }}</div>
            </div>
        </div>
    </a>
</template>

<style scoped>
.one-tap-button,
.one-tap-button * {
    box-sizing: border-box;
}

.one-tap-button {
    background: var(--social-button-bg, #0077ff);
    padding: 8px;
    display: flex;
    flex-direction: row;
    gap: 0;
    align-items: center;
    justify-content: center;
    align-self: stretch;
    flex-shrink: 0;
    position: relative;
    border: none;
    cursor: pointer;
    width: 100%;
    text-decoration: none;
    transition: opacity 0.2s ease;
}

.one-tap-button:hover {
    opacity: 0.9;
}

.x-left {
    display: flex;
    flex-direction: row;
    gap: 0;
    align-items: center;
    justify-content: flex-start;
    flex-shrink: 0;
    position: relative;
}

.swap-icon {
    display: flex;
    flex-direction: row;
    gap: 0;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}

.logo-provider-28 {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    position: relative;
    overflow: visible;
}

.x-middle-m {
    display: flex;
    flex-direction: column;
    gap: 0;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}

.button-label {
    padding: 0 8px;
    display: flex;
    flex-direction: row;
    gap: 0;
    align-items: center;
    justify-content: flex-start;
    flex-shrink: 0;
    position: relative;
}

.button-label2 {
    color: var(--social-button-text, #ffffff);
    text-align: left;
    font-family: "Roboto", "Inter", sans-serif;
    font-size: 16px;
    line-height: 20px;
    letter-spacing: 0.15px;
    font-weight: 500;
    position: relative;
    text-overflow: ellipsis;
    overflow: hidden;
    white-space: nowrap;
    display: flex;
    align-items: center;
}
</style>