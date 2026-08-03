<script setup>
import ToolGuideFaq from "@/components/subscriber/tools/ToolGuideFaq.vue";
</script>

<template>
    <ToolGuideFaq>
        <ol class="list-decimal space-y-2 pl-5">
            <li>
                Убедитесь, что в шапке выбран нужный кабинет WB, а в
                <a href="/panel/wb/price-calc" class="font-medium text-foreground underline underline-offset-2">
                    Ценообразовании
                </a>
                есть актуальные данные по номенклатуре.
            </li>
            <li>
                Скачайте отчёт по акции в ЛК WB: <strong>Цены и скидки → Календарь акций</strong> (Excel).
            </li>
            <li>Загрузите файл на этой странице и нажмите «Рассчитать».</li>
            <li>
                В таблице зелёные строки — прибыльные позиции, красные — убыточные. Выберите нужные
                номенклатуры (по умолчанию отмечены рентабельные).
            </li>
            <li>
                При необходимости экспортируйте Excel или отправьте выбранные позиции в репрайсер
                на даты проведения акции (тот же активный кабинет).
            </li>
        </ol>
    </ToolGuideFaq>
</template>
