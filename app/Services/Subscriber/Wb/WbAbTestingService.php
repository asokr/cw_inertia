<?php

namespace App\Services\Subscriber\Wb;

use App\Enums\WbAbTestStatus;
use App\Models\Subscribers\Wb\AbTesting\AbProduct;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\WbPriceCalculationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WbAbTestingService
{
    public function __construct(
        private readonly WbPriceCalculationService $wbPriceCalculationService,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listProducts(int $cabinetId, Request $request): array
    {
        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $query = AbProduct::query()
            ->where('cabinet_id', $cabinetId);

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('vendor_code', 'like', "%{$search}%")
                    ->orWhere('nm_id', 'like', "%{$search}%");
            });
        }

        $query->orderBy('nm_id');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage)->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (AbProduct $product) => $this->mapProductRow($product))
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, synced?: int}
     */
    public function syncProducts(WbCabinet $cabinet): array
    {
        $params = [
            'settings' => [
                'cursor' => [
                    'limit' => 100,
                ],
                'filter' => [
                    'withPhoto' => -1,
                ],
            ],
        ];

        $cardsResponse = $this->wbPriceCalculationService->getAllCards($cabinet->apikey, $params);
        $cardsResult = $this->wbPriceCalculationService->parseApiResponse($cardsResponse, 'getAllCards');

        if (! ($cardsResult['success'] ?? false)) {
            $message = ($cardsResult['code'] ?? null) === 401
                ? 'Неверный ключ API'
                : (is_string($cardsResult['data'] ?? null)
                    ? $cardsResult['data']
                    : 'Не удалось получить карточки из API Wildberries');

            return [
                'success' => false,
                'messages' => [$message],
            ];
        }

        $cards = data_get($cardsResult['data'], 'cards', []);
        if (! is_array($cards)) {
            $cards = [];
        }

        $syncedNmIds = [];
        $now = now();

        DB::transaction(function () use ($cabinet, $cards, &$syncedNmIds, $now) {
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $nmId = (int) ($card['nmID'] ?? 0);
                if ($nmId <= 0) {
                    continue;
                }

                $syncedNmIds[] = $nmId;

                AbProduct::query()->updateOrCreate(
                    [
                        'cabinet_id' => $cabinet->id,
                        'nm_id' => $nmId,
                    ],
                    [
                        'vendor_code' => $this->nullableString($card['vendorCode'] ?? null),
                        'title' => $this->nullableString($card['title'] ?? null),
                        'brand' => $this->nullableString($card['brand'] ?? null),
                        'subject_name' => $this->nullableString($card['subjectName'] ?? null),
                        'photo_url' => $this->extractPhotoUrl($card['photos'] ?? null),
                        // Prices/rating can be enriched later; Content API does not provide them.
                        'rating' => null,
                        'updated_at' => $now,
                    ]
                );
            }

            $deleteQuery = AbProduct::query()->where('cabinet_id', $cabinet->id);

            if ($syncedNmIds !== []) {
                $deleteQuery->whereNotIn('nm_id', array_unique($syncedNmIds));
            }

            $deleteQuery->delete();
        });

        $count = count(array_unique($syncedNmIds));

        return [
            'success' => true,
            'messages' => [
                $count > 0
                    ? "Список товаров обновлён. Загружено позиций: {$count}."
                    : 'Список товаров обновлён. Товары не найдены — проверьте API-ключ кабинета.',
            ],
            'synced' => $count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProductRow(AbProduct $product): array
    {
        return [
            'id' => $product->id,
            'nm_id' => $product->nm_id,
            'vendor_code' => $product->vendor_code,
            'title' => $product->title,
            'brand' => $product->brand,
            'subject_name' => $product->subject_name,
            'photo_url' => $product->photo_url,
            'price' => $product->price,
            'rating' => $product->rating,
            // Experiment storage comes later — UI shows architecture placeholders.
            'test_status' => WbAbTestStatus::NotCreated->value,
            'test_status_label' => WbAbTestStatus::NotCreated->label(),
            'test_created_at' => null,
        ];
    }

    /**
     * @param  mixed  $photos
     */
    private function extractPhotoUrl($photos): ?string
    {
        if (! is_array($photos) || $photos === []) {
            return null;
        }

        $first = $photos[0];
        if (! is_array($first)) {
            return null;
        }

        foreach (['c246x328', 'square', 'tm', 'big'] as $key) {
            $url = $first[$key] ?? null;
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
