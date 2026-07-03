# Блог

## Права доступа

### Admin API

- `blog.view` — чтение постов, категорий, тегов
- `blog.create` — создание и загрузка изображений
- `blog.update` — редактирование
- `blog.delete` — удаление
- `blog.publish` — публикация (через поле `status` поста)

Middleware: `auth:api`, `verified` + permission на операцию.

### Public API

Без авторизации. Доступны только посты со `status = published` и `published_at <= now()`.

## Назначение

Управление контентом блога в Vue-админке и публичная выдача для Nuxt-сайта: посты, категории, теги, SEO-поля, медиа, sitemap.

## Ключевые файлы

- `app/Http/Controllers/Api/Admin/Blog/PostController.php`
- `app/Http/Controllers/Api/Admin/Blog/CategoryController.php`
- `app/Http/Controllers/Api/Admin/Blog/TagController.php`
- `app/Http/Controllers/Api/Admin/Blog/BlogMediaController.php`
- `app/Http/Controllers/Api/Subscriber/Blog/BlogPostController.php`
- `app/Http/Controllers/Api/Subscriber/Blog/SitemapController.php`
- `app/Services/Blog/BlogCacheService.php`
- `app/Services/Blog/BlogSlugService.php`

## Admin API

Префикс: `/api/admin/blog`

| Метод | URL | Permission |
|-------|-----|------------|
| GET | `/posts` | blog.view |
| POST | `/posts` | blog.create |
| GET | `/posts/{post}` | blog.view |
| PUT/PATCH | `/posts/{post}` | blog.update |
| DELETE | `/posts/{post}` | blog.delete |
| POST | `/posts/{id}/increment-view` | blog.update |
| GET/POST | `/categories` | view/create |
| PUT/PATCH/DELETE | `/categories/{category}` | update/delete |
| GET/POST | `/tags` | view/create |
| PUT/PATCH/DELETE | `/tags/{tag}` | update/delete |
| POST | `/upload-image` | blog.create |

## Public API (Nuxt)

Префикс: `/api/subscriber/blog`

### `GET /posts`

Список опубликованных постов с пагинацией.

Query: `category_id`, `tag_id`, `search` (min 3 символа), `per_page` (1..100), `page`.

### `GET /posts/{slug}`

Карточка поста. `views_count` в ответе увеличивается только для UI, в БД не пишется.

### `POST /posts/{slug}/view`

Атомарная запись просмотра в БД. Throttle: 5 запросов/мин.

### `GET /sitemap`

XML sitemap опубликованных постов (`Content-Type: text/xml`).

## Поля изображений

- `cover_image_key` — ключ в storage
- `cover_image_url` — `/media/{key}`
- `cover_image` — legacy alias ключа

## Технические детали

- Кеширование списков, карточек и sitemap через `BlogCacheService`
- Slug-генерация через `BlogSlugService`
- Медиа доступно по `/media/{key}`