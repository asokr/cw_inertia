<script setup>
import { CircleHelp } from "lucide-vue-next";

defineProps({
    /** Выравнивание тултипа относительно иконки. */
    align: {
        type: String,
        default: "left",
    },
});
</script>

<template>
    <span class="group/hint relative inline-flex shrink-0" tabindex="0" aria-label="Какие кампании подходят для теста">
        <CircleHelp
            class="h-4 w-4 cursor-help text-muted-foreground/70 transition-colors group-hover/hint:text-foreground group-focus-within/hint:text-foreground"
            aria-hidden="true" />
        <span role="tooltip"
            class="pointer-events-none invisible absolute top-full z-50 mt-1.5 w-80 max-w-[calc(100vw-3rem)] rounded-md bg-zinc-900 px-3 py-2.5 text-left text-xs font-normal leading-relaxed text-white opacity-0 shadow-lg transition-opacity group-hover/hint:visible group-hover/hint:opacity-100 group-focus-within/hint:visible group-focus-within/hint:opacity-100"
            :class="align === 'center' ? 'left-1/2 -translate-x-1/2' : 'left-0'">
            Для теста подходят кампании «Оплата за клик» с ручной ставкой — активные или остановленные.
            <br /><br />
            Не подойдут: оплата за заказ, баннеры, автостратегия, архив, модерация и запланированные.
            <br /><br />
        </span>
    </span>
</template>
