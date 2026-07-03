import { usePage } from "@inertiajs/vue3";
import { onMounted, watch } from "vue";
import { useToolPoll } from "@/composables/useToolPoll";

function hasProcessingAnalyses(analyses = []) {
    return analyses.some((item) => item?.status === "processing");
}

export function useAiCabinetAnalysesPoll() {
    const page = usePage();

    const poll = useToolPoll(5000, {
        requestOptions: () => ({
            only: ["analyses", "analysesMeta"],
            preserveState: true,
            preserveScroll: true,
            data: {
                report_id: page.props.report?.id,
            },
        }),
        isComplete: (props) => !hasProcessingAnalyses(props.analyses),
    });

    function maybeStartPolling() {
        if (hasProcessingAnalyses(page.props.analyses)) {
            poll.start();
        }
    }

    onMounted(maybeStartPolling);

    watch(
        () => page.props.analyses,
        () => {
            if (hasProcessingAnalyses(page.props.analyses)) {
                poll.start();
            }
        },
        { deep: true },
    );

    return poll;
}