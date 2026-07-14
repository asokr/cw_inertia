<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { MdEditor, config as mdEditorConfig, en_US } from "md-editor-v3";
import "md-editor-v3/lib/style.css";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Textarea from "@/components/ui/Textarea.vue";
import Select from "@/components/ui/Select.vue";
import Card from "@/components/ui/Card.vue";
import Label from "@/components/ui/Label.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { useBlogMediaApi } from "@/composables/useBlogMediaApi";
import { conversionBlockTemplates, getConversionTemplateById } from "@/utils/conversionBlockTemplates";
import { translitToSlug } from "@/utils/slug";
import { getPostCoverUrl } from "@/utils/blogMedia";

mdEditorConfig({
    editorConfig: {
        languageUserDefined: {
            "ru-RU": {
                ...en_US,
                toolbarTips: {
                    ...en_US.toolbarTips,
                    bold: "Жирный",
                    underline: "Подчеркнутый",
                    italic: "Курсив",
                    strikeThrough: "Зачеркнутый",
                    title: "Заголовок",
                    quote: "Цитата",
                    unorderedList: "Маркированный список",
                    orderedList: "Нумерованный список",
                    link: "Ссылка",
                    image: "Изображение",
                    table: "Таблица",
                    revoke: "Отменить",
                    next: "Повторить",
                    preview: "Предпросмотр",
                    fullscreen: "Полный экран",
                },
            },
        },
    },
});

const props = defineProps({
    post: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
});

const { uploadImage } = useBlogMediaApi();

const isEdit = computed(() => !!props.post?.id);
const mdEditorRef = ref(null);
const coverInputRef = ref(null);
const uploadingCover = ref(false);
const coverPreviewLocalUrl = ref("");
const showConversionBlocksDialog = ref(false);
const keywordInput = ref("");
const markdownToolbarsExclude = ["mermaid", "katex"];
const ctaBaseUrl = "/register";

const form = useForm({
    title: props.post?.title ?? "",
    slug: props.post?.slug ?? "",
    excerpt: props.post?.excerpt ?? "",
    content: props.post?.content ?? "",
    cover_image: props.post?.cover_image ?? "",
    status: props.post?.status ?? "draft",
    published_at: props.post?.published_at ?? new Date().toISOString(),
    seo_title: props.post?.seo_title ?? "",
    seo_description: props.post?.seo_description ?? "",
    seo_keywords: Array.isArray(props.post?.seo_keywords) ? [...props.post.seo_keywords] : [],
    categories: props.post?.categories?.map((c) => c.id) ?? [],
    tags: props.post?.tags?.map((t) => t.id) ?? [],
});

const slugPreview = computed(() => translitToSlug(form.title));
const ctaSourcePreview = computed(() => slugPreview.value || "blog-post");

const publishedAtInput = computed({
    get() {
        if (!form.published_at) {
            return "";
        }

        const date = new Date(form.published_at);
        if (Number.isNaN(date.getTime())) {
            return "";
        }

        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 16);
    },
    set(value) {
        form.published_at = value ? new Date(value).toISOString() : null;
    },
});

const coverPreviewUrl = computed(() => {
    if (coverPreviewLocalUrl.value) {
        return coverPreviewLocalUrl.value;
    }

    return getPostCoverUrl({ cover_image: form.cover_image }) || "";
});

function submit() {
    if (isEdit.value) {
        form.put(`/cw-page/blog/posts/${props.post.id}`);
    } else {
        form.post("/cw-page/blog/posts");
    }
}

function toggleId(field, id) {
    const set = new Set(form[field]);
    if (set.has(id)) set.delete(id);
    else set.add(id);
    form[field] = [...set];
}

function openCoverSelect() {
    coverInputRef.value?.click();
}

async function onCoverSelected(event) {
    const file = event.target?.files?.[0];
    if (!file) return;

    if (coverPreviewLocalUrl.value?.startsWith("blob:")) {
        URL.revokeObjectURL(coverPreviewLocalUrl.value);
    }

    coverPreviewLocalUrl.value = URL.createObjectURL(file);
    uploadingCover.value = true;

    const result = await uploadImage(file);
    uploadingCover.value = false;
    event.target.value = "";

    if (!result.success) {
        return;
    }

    form.cover_image = result.data?.path || "";

    if (result.data?.url) {
        if (coverPreviewLocalUrl.value?.startsWith("blob:")) {
            URL.revokeObjectURL(coverPreviewLocalUrl.value);
        }

        coverPreviewLocalUrl.value = result.data.public_path || result.data.url;
    }
}

async function onMarkdownUpload(files, callback) {
    const uploadedUrls = [];

    for (const file of files) {
        const result = await uploadImage(file);

        const imageUrl = result.data?.public_path || result.data?.url;

        if (result.success && imageUrl) {
            uploadedUrls.push(imageUrl);
        }
    }

    if (uploadedUrls.length) {
        callback(uploadedUrls);
    }
}

function removeCover() {
    if (coverPreviewLocalUrl.value?.startsWith("blob:")) {
        URL.revokeObjectURL(coverPreviewLocalUrl.value);
    }

    coverPreviewLocalUrl.value = "";
    form.cover_image = "";
}

function onCoverError() {
    coverPreviewLocalUrl.value = "";
}

function addKeyword() {
    const value = keywordInput.value.trim();
    if (!value || form.seo_keywords.includes(value)) {
        keywordInput.value = "";
        return;
    }

    form.seo_keywords = [...form.seo_keywords, value];
    keywordInput.value = "";
}

function removeKeyword(index) {
    form.seo_keywords = form.seo_keywords.filter((_, i) => i !== index);
}

function insertHtmlToCursor(htmlBlock) {
    const editor = mdEditorRef.value;
    if (!editor || typeof editor.insert !== "function") {
        return false;
    }

    editor.insert(() => ({
        targetValue: `\n${String(htmlBlock || "").trim()}\n`,
        select: false,
    }));

    return true;
}

function appendHtmlBlock(baseContent, htmlBlock) {
    const normalizedBase = String(baseContent || "").trimEnd();
    const normalizedBlock = String(htmlBlock || "").trim();

    if (!normalizedBlock) {
        return normalizedBase;
    }

    if (!normalizedBase) {
        return `${normalizedBlock}\n`;
    }

    return `${normalizedBase}\n\n${normalizedBlock}\n`;
}

function insertConversionBlock(templateId) {
    const template = getConversionTemplateById(templateId);
    if (!template) {
        return;
    }

    const content = String(form.content || "");
    if (template.dedupeKey && content.includes(template.dedupeKey)) {
        const allowDuplicate = window.confirm("Такой блок уже встречается в тексте. Добавить ещё раз?");
        if (!allowDuplicate) {
            return;
        }
    }

    const htmlBlock = template.htmlBuilder({
        slugPreview: ctaSourcePreview.value,
        ctaBaseUrl,
        campaignParams: {
            utm_source: ctaSourcePreview.value,
            utm_medium: "blog",
            utm_campaign: "blog_conversion_blocks",
        },
    });

    const insertedByCursor = insertHtmlToCursor(htmlBlock);

    if (!insertedByCursor) {
        form.content = appendHtmlBlock(form.content, htmlBlock);
    }

    showConversionBlocksDialog.value = false;
}

onMounted(() => {
    if (!isEdit.value && !form.published_at) {
        form.published_at = new Date().toISOString();
    }
});

onBeforeUnmount(() => {
    if (coverPreviewLocalUrl.value?.startsWith("blob:")) {
        URL.revokeObjectURL(coverPreviewLocalUrl.value);
    }
});
</script>

<template>
    <Head :title="isEdit ? 'Редактирование поста' : 'Создание поста'" />

    <AdminLayout
        :title="isEdit ? 'Редактирование' : 'Создание'"
        :breadcrumbs="[
            { label: 'Админка', href: '/cw-page' },
            { label: 'Посты', href: '/cw-page/blog/posts' },
            { label: isEdit ? 'Редактирование' : 'Создание' },
        ]"
    >
        <PageHeader :title="isEdit ? 'Редактирование поста' : 'Создание поста'" description="Контент, SEO и публикация">
            <template #actions>
                <Button variant="outline" as="a" href="/cw-page/blog/posts">Назад</Button>
                <Button :disabled="form.processing" @click="submit">Сохранить</Button>
            </template>
        </PageHeader>

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-4 lg:col-span-2">
                <Card class="space-y-4 p-4">
                    <div>
                        <Label>Заголовок</Label>
                        <Input v-model="form.title" :error="!!form.errors.title" />
                        <p v-if="form.errors.title" class="mt-1 text-xs text-destructive">{{ form.errors.title }}</p>
                    </div>
                    <div>
                        <Label>URL</Label>
                        <Input :model-value="slugPreview" readonly class="bg-muted" />
                    </div>
                    <div>
                        <Label>Краткое описание</Label>
                        <Textarea v-model="form.excerpt" :rows="3" />
                    </div>
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <Label class="mb-0">Основной текст</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="showConversionBlocksDialog = true"
                            >
                                Добавить конверсионный блок
                            </Button>
                        </div>
                        <MdEditor
                            ref="mdEditorRef"
                            v-model="form.content"
                            language="ru-RU"
                            :toolbars-exclude="markdownToolbarsExclude"
                            :preview="true"
                            :auto-detect-code="true"
                            class="blog-md-editor"
                            @onUploadImg="onMarkdownUpload"
                        />
                        <p v-if="form.errors.content" class="mt-1 text-xs text-destructive">{{ form.errors.content }}</p>
                    </div>
                </Card>

                <Card class="space-y-4 p-4">
                    <h3 class="text-sm font-semibold">SEO</h3>
                    <div>
                        <Label>SEO Title</Label>
                        <Input v-model="form.seo_title" />
                    </div>
                    <div>
                        <Label>SEO Description</Label>
                        <Textarea v-model="form.seo_description" :rows="3" />
                    </div>
                    <div>
                        <Label>SEO Keywords</Label>
                        <div class="flex gap-2">
                            <Input
                                v-model="keywordInput"
                                placeholder="Введите ключевое слово"
                                @keyup.enter.prevent="addKeyword"
                            />
                            <Button type="button" variant="outline" @click="addKeyword">Добавить</Button>
                        </div>
                        <div v-if="form.seo_keywords.length" class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="(keyword, index) in form.seo_keywords"
                                :key="`${keyword}-${index}`"
                                type="button"
                                class="rounded-full border bg-muted px-3 py-1 text-xs hover:bg-accent"
                                @click="removeKeyword(index)"
                            >
                                {{ keyword }} ×
                            </button>
                        </div>
                    </div>
                </Card>
            </div>

            <div class="space-y-4">
                <Card class="space-y-4 p-4">
                    <Label>Обложка</Label>
                    <div class="rounded-lg border border-dashed p-4">
                        <img
                            v-if="coverPreviewUrl"
                            :src="coverPreviewUrl"
                            alt="cover"
                            class="mb-3 w-full rounded-lg border"
                            @error="onCoverError"
                        />
                        <p v-else class="mb-3 text-sm text-muted-foreground">Обложка пока не выбрана</p>
                        <div class="flex gap-2">
                            <Button type="button" :disabled="uploadingCover" @click="openCoverSelect">
                                {{ uploadingCover ? "Загрузка…" : "Загрузить" }}
                            </Button>
                            <Button
                                v-if="form.cover_image"
                                type="button"
                                variant="outline"
                                @click="removeCover"
                            >
                                Удалить
                            </Button>
                        </div>
                    </div>
                </Card>

                <Card class="space-y-4 p-4">
                    <h3 class="text-sm font-semibold">Настройки</h3>
                    <div>
                        <Label>Статус</Label>
                        <Select v-model="form.status">
                            <option value="draft">Черновик</option>
                            <option value="published">Опубликован</option>
                            <option value="hidden">Скрыт</option>
                        </Select>
                    </div>
                    <div>
                        <Label>Дата публикации</Label>
                        <Input v-model="publishedAtInput" type="datetime-local" />
                        <p v-if="form.errors.published_at" class="mt-1 text-xs text-destructive">{{ form.errors.published_at }}</p>
                    </div>
                </Card>

                <Card class="space-y-3 p-4">
                    <Label>Категории</Label>
                    <label v-for="cat in categories" :key="cat.id" class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            :checked="form.categories.includes(cat.id)"
                            @change="toggleId('categories', cat.id)"
                        />
                        {{ cat.name }}
                    </label>
                </Card>

                <Card class="space-y-3 p-4">
                    <Label>Теги</Label>
                    <label v-for="tag in tags" :key="tag.id" class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            :checked="form.tags.includes(tag.id)"
                            @change="toggleId('tags', tag.id)"
                        />
                        {{ tag.name }}
                    </label>
                </Card>
            </div>
        </form>

        <Dialog
            :open="showConversionBlocksDialog"
            title="Добавление конверсионного блока"
            class="max-w-3xl"
            @update:open="showConversionBlocksDialog = $event"
        >
            <p class="mb-4 text-sm text-muted-foreground">
                Будет вставлен HTML-блок в текущую позицию курсора. CTA формируется как
                <strong>/register?utm_source={{ ctaSourcePreview }}</strong>.
            </p>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="templateItem in conversionBlockTemplates"
                    :key="templateItem.id"
                    class="flex flex-col gap-3 rounded-lg border p-3"
                >
                    <div>
                        <h4 class="text-sm font-semibold">{{ templateItem.title }}</h4>
                        <p class="mt-1 text-sm text-muted-foreground">{{ templateItem.description }}</p>
                    </div>
                    <p class="text-xs text-muted-foreground">Категория: {{ templateItem.category }}</p>
                    <Button type="button" size="sm" @click="insertConversionBlock(templateItem.id)">
                        Вставить
                    </Button>
                </div>
            </div>
        </Dialog>

        <input
            ref="coverInputRef"
            type="file"
            accept="image/png,image/jpeg,image/jpg,image/webp"
            class="hidden"
            @change="onCoverSelected"
        />
    </AdminLayout>
</template>

<style scoped>
:deep(.blog-md-editor) {
    border-radius: 0.5rem;
    overflow: hidden;
}
</style>