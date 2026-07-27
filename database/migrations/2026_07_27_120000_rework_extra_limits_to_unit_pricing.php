<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Каталог доп. лимитов после миграции.
     * price — стоимость в ₽ за 1 единицу лимита (заполните вручную).
     * name — русское название для UI (source of truth после migrate — колонка name в БД).
     *
     * @var array<int, array{slug: string, name: string, price: float|int, order: int}>
     */
    private array $catalog = [
        [
            'slug' => 'ai_text_query',
            'name' => 'Текстовые запросы к ИИ',
            'price' => 2, // TODO: указать ₽ за 1
            'order' => 10,
        ],
        [
            'slug' => 'feedbacks_gpt_query',
            'name' => 'Запросы к ИИ для отзывов',
            'price' => 1.5, // TODO: указать ₽ за 1
            'order' => 20,
        ],
        [
            'slug' => 'ai_image_query',
            'name' => 'Генерация изображений ИИ',
            'price' => 10, // TODO: указать ₽ за 1
            'order' => 30,
        ],
        [
            'slug' => 'ai_video_query',
            'name' => 'Генерация видео ИИ',
            'price' => 8, // TODO: указать ₽ за 1
            'order' => 40,
        ],
        // Добавьте сюда другие лимиты при необходимости:
        // [
        //     'slug' => 'example_limit',
        //     'name' => 'Пример лимита',
        //     'price' => 1.5,
        //     'order' => 50,
        // ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('extra_limits')) {
            Schema::create('extra_limits', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->decimal('price', 12, 4)->default(0);
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
            });

            $this->seedCatalog();

            return;
        }

        $hasLimitName = Schema::hasColumn('extra_limits', 'limit_name');
        $hasQuantity = Schema::hasColumn('extra_limits', 'quantity');
        $hasSlug = Schema::hasColumn('extra_limits', 'slug');

        // Schema already converted — only re-seed prices/names from $catalog
        if ($hasSlug && ! $hasLimitName && ! $hasQuantity) {
            $this->seedCatalog();

            return;
        }

        if (! Schema::hasColumn('extra_limits', 'name')) {
            Schema::table('extra_limits', function (Blueprint $table) {
                $table->string('name')->nullable();
            });
        }

        if (! $hasSlug) {
            Schema::table('extra_limits', function (Blueprint $table) {
                $table->string('slug')->nullable();
            });
        }

        // Drop package columns first, then seed clean catalog from $catalog (no price math)
        Schema::table('extra_limits', function (Blueprint $table) use ($hasLimitName, $hasQuantity) {
            if ($hasQuantity && Schema::hasColumn('extra_limits', 'quantity')) {
                $table->dropColumn('quantity');
            }
            if ($hasLimitName && Schema::hasColumn('extra_limits', 'limit_name')) {
                $table->dropColumn('limit_name');
            }
        });

        try {
            Schema::table('extra_limits', function (Blueprint $table) {
                $table->unique('slug');
            });
        } catch (\Throwable) {
            // Index may already exist
        }

        $this->seedCatalog();
    }

    public function down(): void
    {
        if (! Schema::hasTable('extra_limits')) {
            return;
        }

        if (! Schema::hasColumn('extra_limits', 'limit_name')) {
            Schema::table('extra_limits', function (Blueprint $table) {
                $table->string('limit_name')->nullable();
                $table->unsignedInteger('quantity')->default(1);
            });
        }

        if (Schema::hasColumn('extra_limits', 'slug')) {
            $rows = DB::table('extra_limits')->orderBy('id')->get();
            foreach ($rows as $row) {
                DB::table('extra_limits')->where('id', $row->id)->update([
                    'limit_name' => $row->slug,
                    'quantity' => 1,
                ]);
            }

            try {
                Schema::table('extra_limits', function (Blueprint $table) {
                    $table->dropUnique(['slug']);
                });
            } catch (\Throwable) {
                // ignore
            }

            Schema::table('extra_limits', function (Blueprint $table) {
                $table->dropColumn(['slug', 'name']);
            });
        }
    }

    /**
     * Полностью заменяет содержимое extra_limits значениями из $catalog. 
     */
    private function seedCatalog(): void
    {
        $now = now();

        DB::table('extra_limits')->delete();

        foreach ($this->catalog as $item) {
            DB::table('extra_limits')->insert([
                'slug' => $item['slug'],
                'name' => $item['name'],
                'price' => $item['price'],
                'order' => $item['order'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
