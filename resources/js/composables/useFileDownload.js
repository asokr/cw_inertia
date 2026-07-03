import { ref } from "vue";

export function useFileDownload() {
    const downloading = ref(false);

    async function downloadPost(url, filename = "download.xlsx") {
        downloading.value = true;

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? "";
            const response = await fetch(url, {
                method: "POST",
                headers: {
                    Accept: "application/octet-stream",
                    "X-CSRF-TOKEN": token,
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            });

            if (!response.ok) {
                throw new Error("Download failed");
            }

            const blob = await response.blob();
            const objectUrl = window.URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(objectUrl);
        } finally {
            downloading.value = false;
        }
    }

    async function downloadGet(url, filename = "download.xlsx") {
        downloading.value = true;

        try {
            const response = await fetch(url, {
                method: "GET",
                credentials: "same-origin",
            });

            if (!response.ok) {
                throw new Error("Download failed");
            }

            const blob = await response.blob();
            const objectUrl = window.URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = objectUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(objectUrl);
        } finally {
            downloading.value = false;
        }
    }

    return { downloading, downloadPost, downloadGet };
}