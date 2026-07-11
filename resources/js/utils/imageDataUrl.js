export async function urlToDataUrl(url) {
    if (!url || typeof url !== "string") {
        return "";
    }

    if (url.startsWith("data:image/")) {
        return url;
    }

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error("Не удалось загрузить изображение");
    }

    const blob = await response.blob();

    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(typeof reader.result === "string" ? reader.result : "");
        reader.onerror = () => reject(new Error("Не удалось прочитать изображение"));
        reader.readAsDataURL(blob);
    });
}