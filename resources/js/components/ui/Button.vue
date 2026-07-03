<script setup>
import { Link } from "@inertiajs/vue3";
import { computed, useAttrs } from "vue";
import { cn } from "@/lib/utils";

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    variant: {
        type: String,
        default: "default",
    },
    size: {
        type: String,
        default: "default",
    },
    type: {
        type: String,
        default: "button",
    },
    disabled: Boolean,
    as: {
        type: String,
        default: "button",
    },
    href: {
        type: String,
        default: undefined,
    },
});

const attrs = useAttrs();

const isExternalLink = computed(() => {
    if (!props.href) {
        return false;
    }

    return /^(https?:|mailto:|tel:)/i.test(props.href) || props.href.startsWith("#");
});

const componentTag = computed(() => {
    if (props.href && !isExternalLink.value) {
        return Link;
    }

    if (props.href || props.as === "a") {
        return "a";
    }

    return props.as;
});

const classes = computed(() => {
    const base =
        "inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50";

    const variants = {
        default: "bg-primary text-primary-foreground hover:bg-primary/90",
        secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        outline: "border border-input bg-background hover:bg-accent hover:text-accent-foreground",
        ghost: "hover:bg-accent hover:text-accent-foreground",
        destructive: "bg-destructive text-destructive-foreground hover:bg-destructive/90",
    };

    const sizes = {
        default: "h-9 px-4 py-2",
        sm: "h-8 rounded-md px-3 text-xs",
        lg: "h-10 rounded-md px-8",
        icon: "h-9 w-9",
    };

    return cn(base, variants[props.variant], sizes[props.size]);
});
</script>

<template>
    <component
        :is="componentTag"
        v-bind="attrs"
        :href="href"
        :type="componentTag === 'button' ? type : undefined"
        :disabled="disabled"
        :class="classes"
    >
        <slot />
    </component>
</template>