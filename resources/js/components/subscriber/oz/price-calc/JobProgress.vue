<script setup>
import { computed } from "vue";
import { useFlashToast } from "@/composables/useFlashToast";

const props = defineProps({
    jobStatus: {
        type: Object,
        default: () => ({}),
    },
});

const activeJobs = computed(() => {
    const jobs = [];

    if (props.jobStatus.is_syncing) {
        jobs.push("Синхронизация номенклатуры…");
    }
    if (props.jobStatus.is_calculating) {
        jobs.push("Расчёт цен…");
    }
    if (props.jobStatus.is_importing) {
        jobs.push("Импорт Excel…");
    }
    if (props.jobStatus.is_exporting) {
        jobs.push("Экспорт Excel…");
    }

    return jobs;
});

const { watchPropToast } = useFlashToast();
watchPropToast(() => props.jobStatus.last_error);
</script>

<template>
    <div v-if="activeJobs.length" class="space-y-2 rounded-md border border-primary/20 bg-primary/5 p-3">
        <p
            v-for="job in activeJobs"
            :key="job"
            class="text-sm text-primary"
        >
            {{ job }}
        </p>
    </div>
</template>