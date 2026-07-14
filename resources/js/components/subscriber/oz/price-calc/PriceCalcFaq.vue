<script setup>
import ToolGuideFaq from "@/components/subscriber/tools/ToolGuideFaq.vue";
</script>

<template>
    <ToolGuideFaq>
        <ol class="list-decimal space-y-2 pl-5">
            <li>Добавьте кабинет с Client ID и API-ключом Ozon (доступ: товары, цены, аналитика).</li>
            <li>Откройте кабинет и выберите схему продаж — FBO или FBS.</li>
            <li>Обновите номенклатуру из Ozon, затем экспортируйте Excel-шаблон.</li>
            <li>Заполните жёлтые ячейки в Excel и импортируйте файл обратно.</li>
            <li>Итоговые цены рассчитаются автоматически при импорте.</li>
        </ol>
    </ToolGuideFaq>
</template>