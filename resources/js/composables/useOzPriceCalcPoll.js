import { usePage } from "@inertiajs/vue3";
import { ref, watch } from "vue";
import { useFileDownload } from "@/composables/useFileDownload";
import { useToolPoll } from "@/composables/useToolPoll";

function isJobActive(jobStatus = {}) {
    return Boolean(
        jobStatus.is_syncing
        || jobStatus.is_calculating
        || jobStatus.is_importing
        || jobStatus.is_exporting,
    );
}

/**
 * @param {import('vue').Ref<string>|string} modeRef
 * @param {import('vue').Ref<string>|string} exportDownloadUrlRef
 */
export function useOzPriceCalcPoll(modeRef, exportDownloadUrlRef) {
    const page = usePage();
    const { downloadGet } = useFileDownload();

    const prevJobStatus = ref({ ...page.props.jobStatus });
    const wasExporting = ref(Boolean(page.props.jobStatus?.is_exporting));

    const poll = useToolPoll(7000, {
        requestOptions: () => ({
            only: ["rows", "rowsMeta", "columns", "jobStatus"],
            preserveState: true,
            preserveScroll: true,
            data: {
                mode: typeof modeRef === "object" && modeRef !== null && "value" in modeRef
                    ? modeRef.value
                    : modeRef,
            },
        }),
        isComplete: (props) => !isJobActive(props.jobStatus),
        onComplete: async () => {
            if (wasExporting.value) {
                const url = typeof exportDownloadUrlRef === "object" && exportDownloadUrlRef !== null && "value" in exportDownloadUrlRef
                    ? exportDownloadUrlRef.value
                    : exportDownloadUrlRef;
                const mode = typeof modeRef === "object" && modeRef !== null && "value" in modeRef
                    ? modeRef.value
                    : modeRef;

                try {
                    await downloadGet(url, `ozon-${mode}-${page.props.cabinet?.id ?? "export"}.xlsx`);
                } catch {
                    // export file may not exist yet
                }
            }

            wasExporting.value = false;
        },
    });

    watch(
        () => page.props.jobStatus,
        (next) => {
            if (next?.is_exporting) {
                wasExporting.value = true;
            }

            if (isJobActive(next)) {
                poll.start();
            }

            prevJobStatus.value = { ...next };
        },
        { deep: true, immediate: true },
    );

    return poll;
}