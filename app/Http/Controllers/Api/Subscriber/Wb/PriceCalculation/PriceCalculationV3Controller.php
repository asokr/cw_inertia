<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\PriceCalculation;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use App\Exports\Wb\PriceCalc\PriceCalcV3Export;
use App\Services\Wb\WbPriceCalculationService;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Settings;

class PriceCalculationV3Controller extends Controller
{
    public function __construct(private readonly WbPriceCalculationService $wbPriceCalculationService) {}

    /**
     * Получить все данные по кабинету
     */
    public function index(Request $request, int $cabinetId): JsonResponse
    {
        $cabinet = $this->getCabinet($cabinetId);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $perPage = max(1, min(100, $request->integer('per_page', 25)));

        $settings = PriceCalculationV2Settings::firstOrCreate(['cabinet_id' => $cabinet->id]);

        $query = PriceCalculationV3Data::where('cabinet_id', $cabinet->id);

        if ($settings->hide_sizes) {
            $query->selectRaw('MIN(id) as id, cabinet_id, brand, subject_name, vendor_code, nm_id, MIN(size) as size, MIN(barcode) as barcode, MIN(volume_liters) as volume_liters, MIN(extra_liters) as extra_liters, MIN(cost_price) as cost_price, MIN(margin_percent) as margin_percent, MIN(fulfillment_fee) as fulfillment_fee, MIN(maintenance_percent) as maintenance_percent, MIN(stop_price) as stop_price, MIN(avg_base_logistics) as avg_base_logistics, MIN(avg_extra_liter_logistics) as avg_extra_liter_logistics, MIN(localization_index) as localization_index, MIN(avg_logistics) as avg_logistics, MIN(reverse_logistics_cost_gt_1_0_l) as reverse_logistics_cost_gt_1_0_l, MIN(reverse_logistics_cost_0_801_1_0_l) as reverse_logistics_cost_0_801_1_0_l, MIN(reverse_logistics_cost_0_601_0_8_l) as reverse_logistics_cost_0_601_0_8_l, MIN(reverse_logistics_cost_0_401_0_6_l) as reverse_logistics_cost_0_401_0_6_l, MIN(reverse_logistics_cost_0_201_0_4_l) as reverse_logistics_cost_0_201_0_4_l, MIN(reverse_logistics_cost_0_001_0_2_l) as reverse_logistics_cost_0_001_0_2_l, MIN(return_rate_gt_1_1_l) as return_rate_gt_1_1_l, MIN(return_rate_0_801_1_0_l) as return_rate_0_801_1_0_l, MIN(return_rate_0_601_0_8_l) as return_rate_0_601_0_8_l, MIN(return_rate_0_401_0_6_l) as return_rate_0_401_0_6_l, MIN(return_rate_0_201_0_4_l) as return_rate_0_201_0_4_l, MIN(return_rate_0_001_0_2_l) as return_rate_0_001_0_2_l, MIN(return_cost) as return_cost, MIN(buyout_percent) as buyout_percent, MIN(total_logistics) as total_logistics, MIN(storage_cost) as storage_cost, MIN(sales_count) as sales_count, MIN(storage_per_sale) as storage_per_sale, MIN(advertising_percent) as advertising_percent, MIN(wb_commission_percent) as wb_commission_percent, MIN(options_constructor_percent_sales) as options_constructor_percent_sales, MIN(options_constructor_percent_transfer) as options_constructor_percent_transfer, MIN(acquiring_percent) as acquiring_percent, MIN(tax_percent) as tax_percent, MIN(maintenance_percent_sales) as maintenance_percent_sales, MIN(irp) as irp, MIN(commission_plus_acquiring) as commission_plus_acquiring, MIN(standard_discount_percent) as standard_discount_percent, MIN(promotion_percent) as promotion_percent, MIN(min_price_promo) as min_price_promo, MIN(standard_price) as standard_price, MIN(price_before_discount) as price_before_discount')
                ->groupBy('nm_id', 'cabinet_id', 'brand', 'subject_name', 'vendor_code');
        }

        // Сортировка
        $sortKey = $request->input('sort_key');
        $sortDir = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

        if ($sortKey) {
            $query->orderBy($sortKey, $sortDir);
        } else {
            $query->orderBy('nm_id');
        }

        // Поиск
        $search = trim((string) $request->input('search'));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('vendor_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('nm_id', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('subject_name', 'like', "%{$search}%");
            });
        }

        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'messages' => ['Данные получены'],
            'data' => $items,
        ], 200);
    }

    /**
     * Загрузить/обновить номенклатуру из WB API
     * Номенклатуры, которые не вернулись из API — уходят в soft delete
     */
    public function syncCards(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:wb_price_cabinets,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet($request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $settings = PriceCalculationV2Settings::firstOrCreate(
            ['cabinet_id' => $cabinet->id],
            ['hide_sizes' => true]
        );

        $hideSizes = (bool) $settings->hide_sizes;

        $apiKey = $cabinet->apikey;

        // Получаем все карточки из WB API (с учётом размеров)
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

        $cardsResponse = $this->wbPriceCalculationService->getAllCards($apiKey, $params);
        $cardsResult = $this->wbPriceCalculationService->parseApiResponse($cardsResponse, 'getAllCards');

        if (!$cardsResult['success']) {
            $message = $cardsResult['code'] === 401
                ? 'Неверный ключ API'
                : (is_string($cardsResult['data']) ? $cardsResult['data'] : 'Не удалось получить данные из API');

            return response()->json(['success' => false, 'messages' => [$message]], 200);
        }

        $cards = data_get($cardsResult['data'], 'cards', []);

        // Собираем ключи синхронизированных записей для soft delete старых строк
        $syncedBarcodes = [];
        $syncedNmIds = [];
        foreach ($cards as $card) {
            $nmId = (int) ($card['nmID'] ?? 0);

            $sizes = is_array($card['sizes'] ?? null) ? $card['sizes'] : [];
            if (empty($sizes)) {
                continue;
            }

            if ($hideSizes) {
                $firstSize = $sizes[0];
                $barcode = $firstSize['skus'][0] ?? null;
                if (!$barcode || $nmId <= 0) {
                    continue;
                }

                $syncedNmIds[] = $nmId;

                // В режиме скрытых размеров одна запись = одна nm_id.
                $existing = PriceCalculationV3Data::withTrashed()
                    ->where('cabinet_id', $cabinet->id)
                    ->where('nm_id', $nmId)
                    ->orderBy('id')
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }

                    $existing->update([
                        'brand'         => $card['brand'] ?? null,
                        'subject_name'  => $card['subjectName'] ?? null,
                        'vendor_code'   => $card['vendorCode'] ?? null,
                        'nm_id'         => $card['nmID'] ?? null,
                        'size'          => $firstSize['wbSize'] ?? null,
                        'barcode'       => $barcode,
                    ]);
                } else {
                    PriceCalculationV3Data::create([
                        'cabinet_id'    => $cabinet->id,
                        'brand'         => $card['brand'] ?? null,
                        'subject_name'  => $card['subjectName'] ?? null,
                        'vendor_code'   => $card['vendorCode'] ?? null,
                        'nm_id'         => $card['nmID'] ?? null,
                        'size'          => $firstSize['wbSize'] ?? null,
                        'barcode'       => $barcode,
                    ]);
                }

                // Поддерживаем ровно одну активную запись на nm_id в режиме скрытых размеров.
                $actualId = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                    ->where('nm_id', $nmId)
                    ->orderBy('id')
                    ->value('id');

                if ($actualId) {
                    PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                        ->where('nm_id', $nmId)
                        ->where('id', '!=', $actualId)
                        ->delete();
                }

                continue;
            }

            foreach ($sizes as $size) {
                $barcode = $size['skus'][0] ?? null;
                if (!$barcode) {
                    continue;
                }

                $syncedBarcodes[] = $barcode;

                // Восстанавливаем soft-deleted запись если она была удалена
                $existing = PriceCalculationV3Data::withTrashed()
                    ->where('cabinet_id', $cabinet->id)
                    ->where('barcode', $barcode)
                    ->first();

                if ($existing && $existing->trashed()) {
                    $existing->restore();
                }

                PriceCalculationV3Data::updateOrCreate(
                    [
                        'cabinet_id' => $cabinet->id,
                        'barcode' => $barcode,
                    ],
                    [
                        'brand'         => $card['brand'] ?? null,
                        'subject_name'  => $card['subjectName'] ?? null,
                        'vendor_code'   => $card['vendorCode'] ?? null,
                        'nm_id'         => $card['nmID'] ?? null,
                        'size'          => $size['wbSize'] ?? null,
                    ]
                );
            }
        }

        // Soft delete номенклатур, которые не пришли из API
        if ($hideSizes && !empty($syncedNmIds)) {
            PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                ->whereNotIn('nm_id', array_unique($syncedNmIds))
                ->delete();
        } elseif (!empty($syncedBarcodes)) {
            PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                ->whereNotIn('barcode', $syncedBarcodes)
                ->delete();
        }

        $data = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
            ->orderBy('nm_id')
            ->get();

        return response()->json([
            'success' => true,
            'messages' => ['Номенклатура загружена'],
            'data' => $data,
        ], 200);
    }

    /**
     * Получить настройки кабинета v2
     */
    public function getSettings(int $cabinetId): JsonResponse
    {
        $cabinet = $this->getCabinet($cabinetId);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $settings = PriceCalculationV2Settings::firstOrCreate(
            ['cabinet_id' => $cabinet->id]
        );

        return response()->json([
            'success' => true,
            'messages' => ['Настройки получены'],
            'data' => $settings,
        ], 200);
    }

    /**
     * Сохранить настройки кабинета v2
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id'              => 'required|exists:wb_price_cabinets,id',
            'maintenance_type'        => 'sometimes|in:transfer,sales',
            'buyout_scope'            => 'sometimes|in:cabinet,article',
            'use_localization_index'  => 'sometimes|boolean',
            'use_storage'             => 'sometimes|boolean',
            'use_irp'                 => 'sometimes|boolean',
            'commission_source'       => 'sometimes|in:fbs,fbo,reports,manual',
            'acquiring_source'        => 'sometimes|in:reports,manual',
            'hide_sizes'              => 'sometimes|boolean',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet($request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $settings = PriceCalculationV2Settings::updateOrCreate(
            ['cabinet_id' => $cabinet->id],
            $request->only([
                'maintenance_type',
                'buyout_scope',
                'use_localization_index',
                'use_storage',
                'use_irp',
                'commission_source',
                'acquiring_source',
                'hide_sizes',
            ])
        );

        return response()->json([
            'success' => true,
            'messages' => ['Настройки сохранены'],
            'data' => $settings,
        ], 200);
    }

    /**
     * Экспорт данных в Excel для заполнения пользователем
     */
    public function exportExcel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:wb_price_cabinets,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet($request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $settings = PriceCalculationV2Settings::firstOrCreate(['cabinet_id' => $cabinet->id]);

        $rows = PriceCalculationV3Data::where('cabinet_id', $cabinet->id);

        if ($settings->hide_sizes) {
            $rows->selectRaw('MIN(id) as id, cabinet_id, brand, subject_name, vendor_code, nm_id, MIN(size) as size, MIN(barcode) as barcode, MIN(volume_liters) as volume_liters, MIN(extra_liters) as extra_liters, MIN(cost_price) as cost_price, MIN(margin_percent) as margin_percent, MIN(fulfillment_fee) as fulfillment_fee, MIN(maintenance_percent) as maintenance_percent, MIN(stop_price) as stop_price, MIN(avg_base_logistics) as avg_base_logistics, MIN(avg_extra_liter_logistics) as avg_extra_liter_logistics, MIN(localization_index) as localization_index, MIN(avg_logistics) as avg_logistics, MIN(reverse_logistics_cost_gt_1_0_l) as reverse_logistics_cost_gt_1_0_l, MIN(reverse_logistics_cost_0_801_1_0_l) as reverse_logistics_cost_0_801_1_0_l, MIN(reverse_logistics_cost_0_601_0_8_l) as reverse_logistics_cost_0_601_0_8_l, MIN(reverse_logistics_cost_0_401_0_6_l) as reverse_logistics_cost_0_401_0_6_l, MIN(reverse_logistics_cost_0_201_0_4_l) as reverse_logistics_cost_0_201_0_4_l, MIN(reverse_logistics_cost_0_001_0_2_l) as reverse_logistics_cost_0_001_0_2_l, MIN(return_rate_gt_1_1_l) as return_rate_gt_1_1_l, MIN(return_rate_0_801_1_0_l) as return_rate_0_801_1_0_l, MIN(return_rate_0_601_0_8_l) as return_rate_0_601_0_8_l, MIN(return_rate_0_401_0_6_l) as return_rate_0_401_0_6_l, MIN(return_rate_0_201_0_4_l) as return_rate_0_201_0_4_l, MIN(return_rate_0_001_0_2_l) as return_rate_0_001_0_2_l, MIN(return_cost) as return_cost, MIN(buyout_percent) as buyout_percent, MIN(total_logistics) as total_logistics, MIN(storage_cost) as storage_cost, MIN(sales_count) as sales_count, MIN(storage_per_sale) as storage_per_sale, MIN(advertising_percent) as advertising_percent, MIN(wb_commission_percent) as wb_commission_percent, MIN(options_constructor_percent_sales) as options_constructor_percent_sales, MIN(options_constructor_percent_transfer) as options_constructor_percent_transfer, MIN(acquiring_percent) as acquiring_percent, MIN(tax_percent) as tax_percent, MIN(maintenance_percent_sales) as maintenance_percent_sales, MIN(irp) as irp, MIN(commission_plus_acquiring) as commission_plus_acquiring, MIN(standard_discount_percent) as standard_discount_percent, MIN(promotion_percent) as promotion_percent, MIN(min_price_promo) as min_price_promo, MIN(standard_price) as standard_price, MIN(price_before_discount) as price_before_discount')
                ->groupBy('nm_id', 'cabinet_id', 'brand', 'subject_name', 'vendor_code')
                ->orderBy('nm_id');
        } else {
            $rows->orderBy('nm_id');
        }

        $items = $rows->get();

        if ($items->isEmpty()) {
            return response()->json(['success' => false, 'messages' => ['Нет данных для экспорта. Сначала загрузите номенклатуру']], 200);
        }

        $columns = $this->getExportColumns($settings);
        $path = "wb/price-calc-v3/{$cabinet->id}/price-data.xlsx";

        Excel::store(new PriceCalcV3Export($items, $columns), $path, 'public');

        $link = config('app.url') . 'storage/' . $path;

        return response()->json([
            'success' => true,
            'messages' => ['Файл сформирован'],
            'data' => $link,
        ], 200);
    }

    /**
     * Импорт Excel с данными от пользователя (по заголовкам первой строки)
     */
    public function importExcel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx',
            'cabinet_id' => 'required|exists:wb_price_cabinets,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'file.required' => 'Прикрепите файл',
            'file.mimes' => 'Загрузка данного типа запрещена. Используйте формат .xlsx',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet($request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        // Используем текущие настройки кабинета (как в V2)
        $settings = PriceCalculationV2Settings::where('cabinet_id', $cabinet->id)->first();
        if (!$settings) {
            return response()->json(['success' => false, 'messages' => ['Сначала сохраните настройки кабинета']], 200);
        }

        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($request->file('file'));
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return response()->json(['success' => false, 'messages' => ['Файл пустой или повреждён']], 200);
        }

        $headers = $rows[0];
        $columns = [
            'nm_id' => $this->findHeaderColumnIndex($headers, ['Артикул WB', 'Артикул ВБ', 'nm_id']),
            'barcode' => $this->findHeaderColumnIndex($headers, ['Баркод', 'barcode']),
            'localization_index' => $this->findHeaderColumnIndex($headers, ['ИЛ', 'Индекс локализации', 'локализации']),
            'cost_price' => $this->findHeaderColumnIndex($headers, ['себес. руб.', 'Себестоимость, руб.', 'Себестоимость, руб', 'себес, руб.']),
            'margin_percent' => $this->findHeaderColumnIndex($headers, ['маржа, %', 'Маржа, %']),
            'fulfillment_fee' => $this->findHeaderColumnIndex($headers, ['услуги фф руб./ед', 'услуги фф, руб./ед.', 'услуги фф, руб./ед', 'Услуги ФФ, руб./ед.', 'Услуги ФФ, руб./ед']),
            'maintenance_percent' => $this->findHeaderColumnIndex($headers, ['% за ведение (от суммы к перечислению на р/с)', '% за ведение']),
            'advertising_percent' => $this->findHeaderColumnIndex($headers, ['ДРР, % от оборота']),
            'wb_commission_percent' => $this->findHeaderColumnIndex($headers, ['комиссия ВБ', 'Комиссия ВБ, %']),
            'options_constructor_percent_sales' => $this->findHeaderColumnIndex($headers, ['% на опции в конструкторе тарифов, от суммы продажи']),
            'options_constructor_percent_transfer' => $this->findHeaderColumnIndex($headers, ['% на опции в конструкторе тарифов, от перечисления']),
            'acquiring_percent' => $this->findHeaderColumnIndex($headers, ['эквайринг', 'Эквайринг, %']),
            'tax_percent' => $this->findHeaderColumnIndex($headers, ['налог, % от продажи', '% налога']),
            'maintenance_percent_sales' => $this->findHeaderColumnIndex($headers, ['% за ведение, если считается от суммы продажи', '% за ведение, если  считается от суммы продаж)', '% за ведение, если считается от суммы продаж']),
            'irp' => $this->findHeaderColumnIndex($headers, ['ИРП', 'irp']),
            'storage_cost' => $this->findHeaderColumnIndex($headers, ['хранение руб.', 'хранение, руб.', 'Хранение, руб.', 'Хранение руб.']),
            'standard_discount_percent' => $this->findHeaderColumnIndex($headers, ['стандартная скидка для покупателя, %', 'Стандартная скидка для покупателя, %']),
            'promotion_percent' => $this->findHeaderColumnIndex($headers, ['% на участие в акции']),
        ];

        $hideSizes = (bool) $settings->hide_sizes;
        if ($hideSizes && $columns['nm_id'] === null) {
            return response()->json(['success' => false, 'messages' => ['Не найдена обязательная колонка "Артикул WB"']], 200);
        }

        if (!$hideSizes && $columns['barcode'] === null) {
            return response()->json(['success' => false, 'messages' => ['Не найдена обязательная колонка "Баркод"']], 200);
        }

        $updated = 0;
        $notFound = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }

            $targetQuery = null;

            if ($hideSizes) {
                $nmId = (int) ($row[$columns['nm_id']] ?? 0);
                if ($nmId <= 0) {
                    $skipped++;
                    continue;
                }

                $targetQuery = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                    ->where('nm_id', $nmId);
            } else {
                $barcode = $columns['barcode'] !== null
                    ? $this->normalizeBarcode($row[$columns['barcode']] ?? null)
                    : '';

                if ($barcode === '') {
                    $skipped++;
                    continue;
                }

                $targetQuery = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                    ->where('barcode', $barcode);
            }

            if (!(clone $targetQuery)->exists()) {
                $notFound++;
                continue;
            }

            $updateData = [];

            if ($columns['localization_index'] !== null) {
                $value = $this->parseNumericValue($row[$columns['localization_index']] ?? null);
                if ($value >= 0) {
                    $updateData['localization_index'] = $value;
                }
            }

            if ($columns['cost_price'] !== null) {
                $value = $this->parseNumericValue($row[$columns['cost_price']] ?? null);
                if ($value >= 0) {
                    $updateData['cost_price'] = $value;
                }
            }

            if ($columns['margin_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['margin_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['margin_percent'] = $value;
                }
            }

            if ($columns['fulfillment_fee'] !== null) {
                $value = $this->parseNumericValue($row[$columns['fulfillment_fee']] ?? null);
                if ($value >= 0) {
                    $updateData['fulfillment_fee'] = $value;
                }
            }

            if ($columns['maintenance_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['maintenance_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['maintenance_percent'] = $value;
                }
            }

            if ($columns['advertising_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['advertising_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['advertising_percent'] = $value;
                }
            }

            if ($settings->commission_source === 'manual' && $columns['wb_commission_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['wb_commission_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['wb_commission_percent'] = $value;
                }
            }

            if ($settings->acquiring_source === 'manual' && $columns['acquiring_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['acquiring_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['acquiring_percent'] = $value;
                }
            }

            if ($columns['tax_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['tax_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['tax_percent'] = $value;
                }
            }

            if ($columns['maintenance_percent_sales'] !== null) {
                $value = $this->parseNumericValue($row[$columns['maintenance_percent_sales']] ?? null);
                if ($value >= 0) {
                    $updateData['maintenance_percent_sales'] = $value;
                }
            }

            if ($settings->use_irp && $columns['irp'] !== null) {
                $value = $this->parseNumericValue($row[$columns['irp']] ?? null);
                if ($value >= 0) {
                    $updateData['irp'] = $value;
                }
            }

            if ($settings->use_storage && $columns['storage_cost'] !== null) {
                $value = $this->parseNumericValue($row[$columns['storage_cost']] ?? null);
                if ($value >= 0) {
                    $updateData['storage_cost'] = $value;
                }
            }

            if ($columns['standard_discount_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['standard_discount_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['standard_discount_percent'] = $value;
                }
            }

            if ($columns['promotion_percent'] !== null) {
                $value = $this->parseNumericValue($row[$columns['promotion_percent']] ?? null);
                if ($value >= 0) {
                    $updateData['promotion_percent'] = $value;
                }
            }

            // Если в файле нет опций или значение невалидное, фиксируем 0 по ТЗ.
            $optionsSales = 0.0;
            if ($columns['options_constructor_percent_sales'] !== null) {
                $parsed = $this->parseNumericValue($row[$columns['options_constructor_percent_sales']] ?? null);
                $optionsSales = $parsed >= 0 ? $parsed : 0.0;
            }
            $updateData['options_constructor_percent_sales'] = $optionsSales;

            $optionsTransfer = 0.0;
            if ($columns['options_constructor_percent_transfer'] !== null) {
                $parsed = $this->parseNumericValue($row[$columns['options_constructor_percent_transfer']] ?? null);
                $optionsTransfer = $parsed >= 0 ? $parsed : 0.0;
            }
            $updateData['options_constructor_percent_transfer'] = $optionsTransfer;

            if (empty($updateData)) {
                $skipped++;
                continue;
            }

            $targetQuery->update($updateData);

            // После импорта пересчитываем stop_price по формуле со скрина,
            // чтобы данные были консистентны до запуска calculate.
            $recordsForRecalculate = (clone $targetQuery)->get([
                'id',
                'cost_price',
                'margin_percent',
                'fulfillment_fee',
                'maintenance_percent',
                'maintenance_percent_sales',
            ]);

            foreach ($recordsForRecalculate as $record) {
                $stopPrice = $this->calculateStopPrice(
                    (float) ($record->cost_price ?? -1),
                    (float) ($record->margin_percent ?? -1),
                    (float) ($record->fulfillment_fee ?? 0),
                    (float) ($record->maintenance_percent ?? 0),
                    $settings
                );

                PriceCalculationV3Data::where('id', $record->id)
                    ->update(['stop_price' => $stopPrice]);
            }

            $updated++;
        }

        // После импорта выполняем полный перерасчёт всех формул, а не только stop_price.
        $calculateResponse = $this->calculate(new Request([
            'cabinet_id' => (int) $cabinet->id,
        ]));

        $calculatePayload = $calculateResponse->getData(true);
        if (! (bool) ($calculatePayload['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'messages' => array_merge(
                    ["Данные загружены. Обновлено строк: {$updated}"],
                    (array) ($calculatePayload['messages'] ?? ['Не удалось выполнить полный пересчёт после импорта'])
                ),
                'data' => [
                    'import' => [
                        'updated' => $updated,
                        'not_found' => $notFound,
                        'skipped' => $skipped,
                    ],
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ["Данные загружены. Обновлено строк: {$updated}", 'Полный пересчёт выполнен'],
            'data' => [
                'import' => [
                    'updated' => $updated,
                    'not_found' => $notFound,
                    'skipped' => $skipped,
                ],
                'calculate' => $calculatePayload['data'] ?? null,
            ],
        ], 200);
    }

    /**
     * Импорт объёма из XLSX по колонкам "Баркод" и "Объем, л."
     */
    public function importVolumes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx,zip',
            'cabinet_id' => 'required|exists:wb_price_cabinets,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'file.required' => 'Прикрепите файл',
            'file.mimes' => 'Загрузка данного типа запрещена. Используйте формат .xlsx или .zip',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet((int) $request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $uploadedFile = $request->file('file');
        if (! $uploadedFile instanceof UploadedFile) {
            return response()->json(['success' => false, 'messages' => ['Файл пустой или повреждён']], 200);
        }

        $extractedXlsxPath = null;

        try {
            $extension = mb_strtolower((string) $uploadedFile->getClientOriginalExtension());

            if ($extension === 'zip') {
                $extractedXlsxPath = $this->extractSingleXlsxFromZip($uploadedFile);
                $xlsxPath = $extractedXlsxPath;
            } else {
                $xlsxPath = $uploadedFile->getRealPath() ?: '';
            }

            if ($xlsxPath === '') {
                return response()->json(['success' => false, 'messages' => ['Файл пустой или повреждён']], 200);
            }

            $rows = $this->loadXlsxRows($xlsxPath);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'messages' => [$e->getMessage()]], 200);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'messages' => ['Не удалось обработать файл']], 200);
        } finally {
            if ($extractedXlsxPath !== null && is_file($extractedXlsxPath)) {
                @unlink($extractedXlsxPath);
            }
        }

        if (empty($rows) || !is_array($rows[0] ?? null)) {
            return response()->json(['success' => false, 'messages' => ['Файл пустой или повреждён']], 200);
        }

        $headers = $rows[0];
        $barcodeCol = $this->findHeaderColumnIndex($headers, ['Баркод']);
        $volumeCol = $this->findHeaderColumnIndex($headers, ['Объем, л.', 'Объём, л.', 'Объем, л', 'Объём, л']);
        $nmIdCol = $this->findHeaderColumnIndex($headers, ['Артикул WB', 'nm_id']);

        if ($barcodeCol === null || $volumeCol === null) {
            return response()->json([
                'success' => false,
                'messages' => ['Не найдены обязательные колонки: "Баркод" и/или "Объем, л."'],
            ], 200);
        }

        $processed = 0;
        $updated = 0;
        $markedAsInvalid = 0;
        $notFound = 0;
        $skipped = 0;
        $matchedByNmId = 0;

        $settings = PriceCalculationV2Settings::firstOrCreate(['cabinet_id' => $cabinet->id]);
        $hideSizes = (bool) $settings->hide_sizes;

        DB::transaction(function () use (
            $rows,
            $cabinet,
            $barcodeCol,
            $volumeCol,
            $nmIdCol,
            $hideSizes,
            &$processed,
            &$updated,
            &$markedAsInvalid,
            &$notFound,
            &$skipped,
            &$matchedByNmId
        ) {
            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex === 0) {
                    continue;
                }

                $rawBarcode = $row[$barcodeCol] ?? null;
                $rawVolume = $row[$volumeCol] ?? null;

                if (($rawBarcode === null || $rawBarcode === '') && ($rawVolume === null || $rawVolume === '')) {
                    $skipped++;
                    continue;
                }

                $processed++;

                $barcode = $this->normalizeBarcode($rawBarcode);
                if ($barcode === '') {
                    $skipped++;
                    continue;
                }

                $query = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                    ->where('barcode', $barcode);

                $exists = (clone $query)->exists();

                if (! $exists && $hideSizes && $nmIdCol !== null) {
                    $nmId = (int) ($row[$nmIdCol] ?? 0);
                    if ($nmId > 0) {
                        $query = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
                            ->where('nm_id', $nmId);
                        $exists = (clone $query)->exists();

                        if ($exists) {
                            $matchedByNmId++;
                        }
                    }
                }

                if (! $exists) {
                    $notFound++;
                    continue;
                }

                $volume = $this->parseNumericValue($rawVolume);
                if ($volume < 0) {
                    $affected = $query->update([
                        'volume_liters' => -1,
                        'extra_liters' => -1,
                    ]);

                    if ($affected > 0) {
                        $markedAsInvalid += $affected;
                    }

                    continue;
                }

                $affected = $query->update([
                    'volume_liters' => round($volume, 3),
                    'extra_liters' => max(0, round($volume - 1, 3)),
                ]);

                if ($affected > 0) {
                    $updated += $affected;
                }
            }
        });

        return response()->json([
            'success' => true,
            'messages' => ['Импорт объёма завершён'],
            'data' => [
                'processed' => $processed,
                'updated' => $updated,
                'marked_as_invalid' => $markedAsInvalid,
                'not_found' => $notFound,
                'skipped' => $skipped,
                'matched_by_nm_id' => $matchedByNmId,
            ],
        ], 200);
    }

    private function loadXlsxRows(string $xlsxPath): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($xlsxPath);

        return $spreadsheet->getActiveSheet()->toArray();
    }

    private function extractSingleXlsxFromZip(UploadedFile $zipFile): string
    {
        $zipPath = $zipFile->getRealPath() ?: '';
        if ($zipPath === '') {
            throw new \RuntimeException('Файл пустой или повреждён');
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \RuntimeException('Не удалось открыть ZIP архив');
        }

        $xlsxEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);

            if ($entryName === '' || str_ends_with($entryName, '/')) {
                continue;
            }

            if (mb_strtolower(pathinfo($entryName, PATHINFO_EXTENSION)) === 'xlsx') {
                $xlsxEntries[] = $entryName;
            }
        }

        if (count($xlsxEntries) === 0) {
            $zip->close();
            throw new \RuntimeException('В архиве не найден файл .xlsx');
        }

        if (count($xlsxEntries) > 1) {
            $zip->close();
            throw new \RuntimeException('В архиве должен быть ровно один файл .xlsx');
        }

        $xlsxContent = $zip->getFromName($xlsxEntries[0]);
        $zip->close();

        if ($xlsxContent === false || $xlsxContent === '') {
            throw new \RuntimeException('Не удалось извлечь .xlsx из архива');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wb_v3_vol_');
        if ($tempPath === false) {
            throw new \RuntimeException('Не удалось подготовить временный файл для импорта');
        }

        $writtenBytes = file_put_contents($tempPath, $xlsxContent);
        if ($writtenBytes === false) {
            @unlink($tempPath);
            throw new \RuntimeException('Не удалось извлечь .xlsx из архива');
        }

        return $tempPath;
    }

    /**
     * Рассчитать логистику и связанные поля для кабинета
     */
    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:wb_price_cabinets,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = $this->getCabinet((int) $request->cabinet_id);
        if (!$cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета не существует']], 200);
        }

        $settings = PriceCalculationV2Settings::firstOrCreate(['cabinet_id' => $cabinet->id]);

        $products = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)->get();
        if ($products->isEmpty()) {
            return response()->json(['success' => false, 'messages' => ['Нет данных для расчёта. Сначала загрузите номенклатуру']], 200);
        }

        $salesResult = $this->getWarehouseSalesPercent($cabinet->apikey);
        if (! $salesResult['success']) {
            return response()->json(['success' => false, 'messages' => [$salesResult['message']]], 200);
        }

        $tariffsResult = $this->getAverageLogisticsByWarehouseShare(
            $cabinet->apikey,
            $salesResult['warehouse_percent'],
            $salesResult['warehouse_sales'] ?? [],
            $salesResult['total_sales'] ?? 0
        );
        if (! $tariffsResult['success']) {
            return response()->json(['success' => false, 'messages' => [$tariffsResult['message']]], 200);
        }

        $avgBaseLogistics = $tariffsResult['avg_base_logistics'];
        $avgExtraLiterLogistics = $tariffsResult['avg_extra_liter_logistics'];

        $articleStats = [];
        $cabinetBuyoutPercent = null;

        // Всегда собираем статистику по артикулам, так как нам нужны данные о продажах (выкупах)
        $articleStatsResult = $this->getArticleStats($cabinet->apikey, true);
        if (! $articleStatsResult['success']) {
            return response()->json(['success' => false, 'messages' => [$articleStatsResult['message']]], 200);
        }

        $articleStats = $articleStatsResult['stats'];
        $cabinetBuyoutPercent = $articleStatsResult['cabinet_buyout_percent'];
        $cabinetOrders = $articleStatsResult['cabinet_orders'] ?? 0;
        $cabinetBuyouts = $articleStatsResult['cabinet_buyouts'] ?? 0;

        // Получаем комиссии, если выбран источник fbs или fbo
        $commissionsLookup = [];
        if (in_array($settings->commission_source, ['fbs', 'fbo'])) {
            $wbTariffsResponse = $this->wbPriceCalculationService->getWBTariffs($cabinet->apikey);
            $wbTariffs = $this->wbPriceCalculationService->parseApiResponse($wbTariffsResponse, 'getWBTariffs');

            if (! $wbTariffs['success'] || empty(data_get($wbTariffs['data'], 'report'))) {
                sleep(2);
                $wbTariffsResponse = $this->wbPriceCalculationService->getWBTariffs($cabinet->apikey);
                $wbTariffs = $this->wbPriceCalculationService->parseApiResponse($wbTariffsResponse, 'getWBTariffs');
            }

            if ($wbTariffs['success'] && !empty(data_get($wbTariffs['data'], 'report'))) {
                foreach (data_get($wbTariffs['data'], 'report', []) as $item) {
                    $subjectName = $item['subjectName'] ?? null;
                    if ($subjectName) {
                        $commissionsLookup[$subjectName] = $item;
                    }
                }
            } else {
                return response()->json(['success' => false, 'messages' => ['Не удалось получить комиссии WB для данного кабинета. Попробуйте позже.']], 200);
            }
        }

        // Получаем комиссии из отчетов, если выбран источник reports
        $avgReportCommission = 0;
        $avgReportAcquiring = 0;
        if ($settings->commission_source === 'reports' || $settings->acquiring_source === 'reports') {
            $reportsResult = $this->getReportsCommissions($cabinet->apikey);
            if (!$reportsResult['success']) {
                return response()->json(['success' => false, 'messages' => [$reportsResult['message']]], 200);
            }
            $avgReportCommission = $reportsResult['avg_commission'];
            $avgReportAcquiring = $reportsResult['avg_acquiring'];
        }

        DB::transaction(function () use (
            $products,
            $settings,
            $articleStats,
            $cabinetBuyoutPercent,
            $cabinetOrders,
            $cabinetBuyouts,
            $avgBaseLogistics,
            $avgExtraLiterLogistics,
            $commissionsLookup,
            $avgReportCommission,
            $avgReportAcquiring
        ) {
            foreach ($products as $product) {
                $extraLiters = max(0, (float) ($product->extra_liters ?? 0));

                $localizationIndex = 1.0;
                if ($settings->use_localization_index) {
                    $localizationIndex = (float) ($product->localization_index ?? 1);
                    if ($localizationIndex <= 0) {
                        $localizationIndex = 1.0;
                    }
                }

                $avgLogistics = ($avgBaseLogistics + ($extraLiters * $avgExtraLiterLogistics)) * $localizationIndex;

                $buyoutPercent = null;
                if ($settings->buyout_scope === 'cabinet') {
                    $buyoutPercent = $cabinetBuyoutPercent ?? 40;
                } else {
                    $nmId = (int) ($product->nm_id ?? 0);
                    $buyoutPercent = (float) data_get($articleStats, $nmId . '.buyout_percent', 0);

                    // Если процент выкупа по товару не найден или равен 0, берем средний по кабинету
                    if ($buyoutPercent <= 0 || $buyoutPercent > 100) {
                        $buyoutPercent = $cabinetBuyoutPercent ?? 40;
                    }
                }

                // Финальная проверка на случай, если и по кабинету нет данных
                if ($buyoutPercent <= 0 || $buyoutPercent > 100) {
                    $buyoutPercent = 40;
                }

                // Заполняем константы обратной логистики по диапазонам объема.
                $product->reverse_logistics_cost_0_001_0_2_l = 23;
                $product->reverse_logistics_cost_0_201_0_4_l = 26;
                $product->reverse_logistics_cost_0_401_0_6_l = 29;
                $product->reverse_logistics_cost_0_601_0_8_l = 30;
                $product->reverse_logistics_cost_0_801_1_0_l = 32;
                $product->reverse_logistics_cost_gt_1_0_l = 46;
                $volumeLiters = (float) ($product->volume_liters ?? -1);
                $returnCost = $this->calculateReverseLogisticsCost(
                    $volumeLiters,
                    $extraLiters
                );

                // Для визуализации в Excel заполняем только колонку соответствующего диапазона.
                $returnRateDistribution = $this->getReturnRateDistributionByVolume($volumeLiters, $returnCost);
                $product->return_rate_gt_1_1_l = $returnRateDistribution['return_rate_gt_1_1_l'];
                $product->return_rate_0_801_1_0_l = $returnRateDistribution['return_rate_0_801_1_0_l'];
                $product->return_rate_0_601_0_8_l = $returnRateDistribution['return_rate_0_601_0_8_l'];
                $product->return_rate_0_401_0_6_l = $returnRateDistribution['return_rate_0_401_0_6_l'];
                $product->return_rate_0_201_0_4_l = $returnRateDistribution['return_rate_0_201_0_4_l'];
                $product->return_rate_0_001_0_2_l = $returnRateDistribution['return_rate_0_001_0_2_l'];

                $storagePerSale = null;
                if ($settings->use_storage) {
                    $storageCost = (float) ($product->storage_cost ?? 0);
                    // Формула: =ОКРУГЛВВЕРХ(V4/W4;0) (хранение / продажи)
                    // Где продажи - это выкупы по всему кабинету (cabinetBuyouts)
                    $storagePerSale = $cabinetBuyouts > 0 ? ceil($storageCost / $cabinetBuyouts) : null;
                }

                $totalLogistics = (($avgLogistics * 100) + ((100 - $buyoutPercent) * $returnCost)) / $buyoutPercent;

                // Расчет комиссии WB
                if ($settings->commission_source === 'fbs') {
                    $subjectName = $product->subject_name;
                    if ($subjectName && isset($commissionsLookup[$subjectName]['kgvpMarketplace'])) {
                        $product->wb_commission_percent = (float) $commissionsLookup[$subjectName]['kgvpMarketplace'];
                    }
                } elseif ($settings->commission_source === 'fbo') {
                    $subjectName = $product->subject_name;
                    if ($subjectName && isset($commissionsLookup[$subjectName]['paidStorageKgvp'])) {
                        $product->wb_commission_percent = (float) $commissionsLookup[$subjectName]['paidStorageKgvp'];
                    }
                } elseif ($settings->commission_source === 'reports') {
                    if ($avgReportCommission > 0) {
                        $product->wb_commission_percent = (float) $avgReportCommission;
                    }
                }

                // Расчет эквайринга
                if ($settings->acquiring_source === 'reports') {
                    if ($avgReportAcquiring > 0) {
                        $product->acquiring_percent = (float) $avgReportAcquiring;
                    }
                }

                $commissionPlusAcquiring = $this->calculateTotalWbExpensePercent($product, $settings);

                $calculatedStopPrice = $this->calculateStopPrice(
                    (float) ($product->cost_price ?? -1),
                    (float) ($product->margin_percent ?? -1),
                    (float) ($product->fulfillment_fee ?? 0),
                    (float) ($product->maintenance_percent ?? 0),
                    $settings
                );

                // Расчет итоговых цен
                // Минимальная цена (для акций) =
                // (СТОП-ЦЕНА + ИТОГОВАЯ ЛОГИСТИКА + Хранение/1 продажа + (% за ведение от суммы продажи / 100 * СТОП-ЦЕНА))
                // / ((100 - Общий % расходов WB) / 100)
                // M = stop_price, AF = total_logistics, AI = storage_per_sale, AP = maintenance_percent_sales, AR = commission_plus_acquiring
                $storagePerSaleVal = (float) ($storagePerSale ?? 0);
                $maintenancePercentSales = $settings->maintenance_type === 'sales'
                    ? (float) ($product->maintenance_percent_sales ?? 0)
                    : 0.0;

                $minPricePromo = null;
                $denominatorMinPrice = (100 - $commissionPlusAcquiring) / 100;
                if ($calculatedStopPrice !== null && $denominatorMinPrice > 0) {
                    $minPricePromo = (
                        $calculatedStopPrice
                        + $totalLogistics
                        + $storagePerSaleVal
                        + (($maintenancePercentSales / 100) * $calculatedStopPrice)
                    ) / $denominatorMinPrice;
                }

                // Стандартная цена (без акций) = Минимальная цена / ((100 - % на участие в акции) / 100)
                // AF = Минимальная цена (min_price_promo)
                // AE = % на участие в акции (promotion_percent)
                $promotionPercent = (float) ($product->promotion_percent ?? 0);
                $standardPrice = null;
                if ($minPricePromo !== null) {
                    $denominatorStandardPrice = (100 - $promotionPercent) / 100;
                    if ($denominatorStandardPrice > 0) {
                        $standardPrice = $minPricePromo / $denominatorStandardPrice;
                    }
                }

                // Цена до скидки = Стандартная цена / ((100 - Стандартная скидка для покупателя) / 100)
                // AV = Стандартная цена (standard_price)
                // AS = Стандартная скидка для покупателя (standard_discount_percent)
                $standardDiscountPercent = (float) ($product->standard_discount_percent ?? 0);
                $priceBeforeDiscount = null;
                if ($standardPrice !== null) {
                    $denominatorPriceBeforeDiscount = (100 - $standardDiscountPercent) / 100;
                    if ($denominatorPriceBeforeDiscount > 0) {
                        $priceBeforeDiscount = $standardPrice / $denominatorPriceBeforeDiscount;
                    }
                }

                $product->avg_base_logistics = round($avgBaseLogistics, 2);
                $product->avg_extra_liter_logistics = round($avgExtraLiterLogistics, 2);
                $product->localization_index = $localizationIndex;
                $product->avg_logistics = round($avgLogistics, 2);
                $product->return_cost = round($returnCost, 2);
                $product->buyout_percent = round($buyoutPercent, 2);
                $product->sales_count = $cabinetBuyouts; // В ТЗ "продажи" - это выкупы по всему кабинету
                $product->storage_per_sale = $storagePerSale;
                $product->total_logistics = round($totalLogistics, 2);
                $product->commission_plus_acquiring = round($commissionPlusAcquiring, 2);
                $product->stop_price = $calculatedStopPrice;
                $product->min_price_promo = $minPricePromo !== null ? round($minPricePromo, 2) : null;
                $product->standard_price = $standardPrice !== null ? round($standardPrice, 2) : null;
                $product->price_before_discount = $priceBeforeDiscount !== null ? round($priceBeforeDiscount, 2) : null;
                $product->save();
            }
        });

        $data = PriceCalculationV3Data::where('cabinet_id', $cabinet->id)
            ->orderBy('nm_id')
            ->get();

        return response()->json([
            'success' => true,
            'messages' => ['Рассчет произведен'],
            'data' => $data,
        ], 200);
    }

    /**
     * Парсинг числового значения из ячейки Excel
     * Если значение не является числом — возвращаем -1
     */
    private function parseNumericValue($value): float
    {
        if ($value === null || $value === '') {
            return -1;
        }

        // Заменяем запятую на точку (на случай русской локали)
        if (is_string($value)) {
            $value = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($value));

            // Поддержка значений, введенных как текст с процентом (например, "100%" или "12,5 %")
            if (str_contains($value, '%')) {
                $value = str_replace('%', '', $value);
            }
        }

        if (!is_numeric($value)) {
            return -1;
        }

        return (float) $value;
    }

    private function findHeaderColumnIndex(array $headers, array $aliases): ?int
    {
        $normalizedAliases = array_map(function ($alias) {
            return $this->normalizeHeaderText((string) $alias);
        }, $aliases);

        $comparableAliases = array_map(function ($alias) {
            return $this->normalizeHeaderComparable((string) $alias);
        }, $aliases);

        foreach ($headers as $index => $header) {
            $normalizedHeader = $this->normalizeHeaderText((string) $header);
            if (in_array($normalizedHeader, $normalizedAliases, true)) {
                return $index;
            }

            // Fallback: сравнение без пунктуации/спецсимволов для устойчивости к мелким отличиям заголовков.
            $comparableHeader = $this->normalizeHeaderComparable((string) $header);
            if ($comparableHeader === '') {
                continue;
            }

            foreach ($comparableAliases as $comparableAlias) {
                if ($comparableAlias === '') {
                    continue;
                }

                if (
                    $comparableHeader === $comparableAlias
                    || str_contains($comparableHeader, $comparableAlias)
                    || str_contains($comparableAlias, $comparableHeader)
                ) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function normalizeHeaderText(string $value): string
    {
        $normalized = str_replace(["\r", "\n", "\t", "\u{00A0}"], ' ', $value);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    private function normalizeHeaderComparable(string $value): string
    {
        $normalized = $this->normalizeHeaderText($value);

        // Удаляем знаки препинания и спецсимволы, оставляя буквы/цифры/пробел.
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function normalizeBarcode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) || is_int($value)) {
            return sprintf('%.0f', (float) $value);
        }

        $barcode = trim((string) $value);
        $barcode = str_replace(["\u{00A0}", ' ', "\t", "\r", "\n", "'"], '', $barcode);

        if ($barcode === '') {
            return '';
        }

        if (stripos($barcode, 'e') !== false && is_numeric($barcode)) {
            return sprintf('%.0f', (float) $barcode);
        }

        if (str_ends_with($barcode, '.0') && is_numeric($barcode)) {
            return substr($barcode, 0, -2);
        }

        return $barcode;
    }

    /**
     * Расчёт стоп-цены по формуле
     *
     * Логика расчёта stop_price по настройке maintenance_type:
     * - transfer: (H + H*I% + J) / ((100 - K) / 100), где K = maintenance_percent
     * - sales:    (H + H*I% + J)
     */
    private function calculateStopPrice(
        float $costPrice,
        float $margin,
        float $fulfillment,
        float $maintenancePercent,
        PriceCalculationV2Settings $settings
    ): ?float {
        // Если какое-то из значений ошибочное (-1), не считаем
        if ($costPrice < 0 || $margin < 0 || $fulfillment < 0) {
            return null;
        }

        $base = $costPrice + ($costPrice * $margin / 100) + $fulfillment;

        if ($settings->maintenance_type === 'transfer') {
            if ($maintenancePercent < 0 || $maintenancePercent >= 100) {
                return null;
            }

            return round($base / ((100 - $maintenancePercent) / 100), 2);
        }

        return round($base, 2);
    }

    /**
     * Расчёт итоговой стоимости обратной логистики по объёму товара.
     */
    private function calculateReverseLogisticsCost(float $volumeLiters, float $extraLiters): float
    {
        // Для невалидного объема сохраняем fallback-значение.
        if ($volumeLiters <= 0) {
            return 50.0;
        }

        if ($volumeLiters <= 0.2) {
            return 23.0;
        }

        if ($volumeLiters <= 0.4) {
            return 26.0;
        }

        if ($volumeLiters <= 0.6) {
            return 29.0;
        }

        if ($volumeLiters <= 0.8) {
            return 30.0;
        }

        if ($volumeLiters <= 1.0) {
            return 32.0;
        }

        return 46.0 + (max(0, $extraLiters) * 14.0);
    }

    /**
     * Распределяем итоговую стоимость возврата по одной колонке диапазона для Excel.
     */
    private function getReturnRateDistributionByVolume(float $volumeLiters, float $returnCost): array
    {
        $distribution = [
            'return_rate_gt_1_1_l' => 0.0,
            'return_rate_0_801_1_0_l' => 0.0,
            'return_rate_0_601_0_8_l' => 0.0,
            'return_rate_0_401_0_6_l' => 0.0,
            'return_rate_0_201_0_4_l' => 0.0,
            'return_rate_0_001_0_2_l' => 0.0,
        ];

        if ($volumeLiters <= 0) {
            return $distribution;
        }

        if ($volumeLiters <= 0.2) {
            $distribution['return_rate_0_001_0_2_l'] = round($returnCost, 2);
            return $distribution;
        }

        if ($volumeLiters <= 0.4) {
            $distribution['return_rate_0_201_0_4_l'] = round($returnCost, 2);
            return $distribution;
        }

        if ($volumeLiters <= 0.6) {
            $distribution['return_rate_0_401_0_6_l'] = round($returnCost, 2);
            return $distribution;
        }

        if ($volumeLiters <= 0.8) {
            $distribution['return_rate_0_601_0_8_l'] = round($returnCost, 2);
            return $distribution;
        }

        if ($volumeLiters <= 1.0) {
            $distribution['return_rate_0_801_1_0_l'] = round($returnCost, 2);
            return $distribution;
        }

        $distribution['return_rate_gt_1_1_l'] = round($returnCost, 2);
        return $distribution;
    }

    /**
     * Общий % расходов с каждой продажи (колонка AR).
     * Базовая формула: AJ + AK + AL + AN + AO + AP + AQ.
     * С учётом настроек:
     * - options constructor: AL для sales, AM для transfer;
     * - AP учитываем только для sales;
     * - AQ учитываем только при use_irp=true.
     */
    private function calculateTotalWbExpensePercent(
        PriceCalculationV3Data $product,
        PriceCalculationV2Settings $settings
    ): float {
        $advertisingPercent = (float) ($product->advertising_percent ?? 0); // AJ
        $wbCommissionPercent = (float) ($product->wb_commission_percent ?? 0); // AK
        $optionsPercent = $settings->maintenance_type === 'transfer'
            ? (float) ($product->options_constructor_percent_transfer ?? 0) // AM
            : (float) ($product->options_constructor_percent_sales ?? 0); // AL
        $acquiringPercent = (float) ($product->acquiring_percent ?? 0); // AN
        $taxPercent = (float) ($product->tax_percent ?? 0); // AO
        $maintenanceSalesPercent = $settings->maintenance_type === 'sales'
            ? (float) ($product->maintenance_percent_sales ?? 0) // AP
            : 0.0;
        $irpPercent = $settings->use_irp
            ? (float) ($product->irp ?? 0) // AQ
            : 0.0;

        return round(
            $advertisingPercent
                + $wbCommissionPercent
                + $optionsPercent
                + $acquiringPercent
                + $taxPercent
                + $maintenanceSalesPercent
                + $irpPercent,
            2
        );
    }

    /**
     * Получаем долю продаж по складам за 30 дней
     */
    private function getWarehouseSalesPercent(string $apiKey): array
    {
        $startDate = Carbon::now()->subMonth()->startOfMonth();
        $endDate = Carbon::now()->subMonth()->endOfMonth();

        $salesResponse = $this->wbPriceCalculationService->getSales($apiKey, $startDate);
        $sales = $this->wbPriceCalculationService->parseApiResponse($salesResponse, 'getSales');

        if (! $sales['success'] || ! is_array($sales['data'])) {
            $message = is_string($sales['data']) ? $sales['data'] : 'Ошибка в получении данных о продажах. Попробуйте позже.';
            return ['success' => false, 'message' => $message];
        }

        $monthSales = $sales['data'];
        $warehouseSales = [];
        $totalSales = 0;

        foreach ($monthSales as $sale) {
            $saleDate = Carbon::parse(data_get($sale, 'date'));
            if ($saleDate->gt($endDate)) {
                continue; // Пропускаем продажи текущего месяца
            }

            $warehouseName = (string) data_get($sale, 'warehouseName', '');
            if ($warehouseName === '') {
                continue;
            }

            $totalSales++;
            $warehouseSales[$warehouseName] = ($warehouseSales[$warehouseName] ?? 0) + 1;
        }

        if ($totalSales <= 0) {
            return ['success' => true, 'warehouse_percent' => []];
        }

        $warehousePercent = [];
        foreach ($warehouseSales as $warehouseName => $salesCount) {
            $warehousePercent[$warehouseName] = round(($salesCount / $totalSales) * 100, 2);
        }

        return [
            'success' => true,
            'warehouse_percent' => $warehousePercent,
            'warehouse_sales' => $warehouseSales,
            'total_sales' => $totalSales,
        ];
    }

    /**
     * Рассчитываем среднюю базовую логистику и среднюю логистику за доп. литр
     */
    private function getAverageLogisticsByWarehouseShare(string $apiKey, array $warehousePercent, array $warehouseSales = [], int $totalSales = 0): array
    {
        $whTariffsResponse = $this->wbPriceCalculationService->getWhTariffs($apiKey);
        $whTariffs = $this->wbPriceCalculationService->parseApiResponse($whTariffsResponse, 'getWhTariffs');

        if (! $whTariffs['success'] || empty(data_get($whTariffs['data'], 'response.data.warehouseList'))) {
            sleep(2);
            $whTariffsResponse = $this->wbPriceCalculationService->getWhTariffs($apiKey);
            $whTariffs = $this->wbPriceCalculationService->parseApiResponse($whTariffsResponse, 'getWhTariffs');
        }

        if (! $whTariffs['success'] || empty(data_get($whTariffs['data'], 'response.data.warehouseList'))) {
            $message = is_string($whTariffs['data']) ? $whTariffs['data'] : 'Ошибка в получении тарифов складов. Попробуйте позже.';
            return ['success' => false, 'message' => $message];
        }

        $whTariffsData = data_get($whTariffs['data'], 'response.data.warehouseList', []);

        $avgBaseLogistics = 0;
        $avgExtraLiterLogistics = 0;

        foreach ($whTariffsData as $warehouse) {
            $warehouseName = (string) data_get($warehouse, 'warehouseName', '');
            if ($warehouseName === '' || ! isset($warehousePercent[$warehouseName])) {
                continue;
            }

            $percent = (float) $warehousePercent[$warehouseName];

            $baseRaw = data_get($warehouse, 'boxDeliveryBase', 0);
            $base = is_string($baseRaw) ? (float) str_replace(',', '.', $baseRaw) : (float) $baseRaw;

            $extraLiterRaw = data_get($warehouse, 'boxDeliveryLiter', 0);
            $extraLiter = is_string($extraLiterRaw) ? (float) str_replace(',', '.', $extraLiterRaw) : (float) $extraLiterRaw;

            $avgBaseLogistics += ($percent / 100) * $base;
            $avgExtraLiterLogistics += ($percent / 100) * $extraLiter;
        }

        return [
            'success' => true,
            'avg_base_logistics' => round($avgBaseLogistics, 2),
            'avg_extra_liter_logistics' => round($avgExtraLiterLogistics, 2),
        ];
    }

    /**
     * Получить комиссии из финансовых отчетов за последнюю неделю прошлого месяца
     */
    private function getReportsCommissions(string $apiKey): array
    {
        $dateTo = Carbon::now()->subMonth()->endOfMonth();
        $dateFrom = $dateTo->copy()->subDays(6)->startOfDay();

        $response = $this->wbPriceCalculationService->getReportDetailByPeriod($apiKey, $dateFrom, $dateTo, 100000, 0);
        $result = $this->wbPriceCalculationService->parseApiResponse($response, 'getReportDetailByPeriod');

        if (!$result['success']) {
            $message = is_string($result['data']) ? $result['data'] : 'Ошибка в получении финансовых отчетов. Попробуйте позже.';
            return ['success' => false, 'message' => $message];
        }

        // В API WB данные могут лежать в разных контейнерах в зависимости от версии endpoint.
        $allReports = $this->extractReportRows($result['data']);

        $totalCommissionSum = 0;
        $totalCommissionCount = 0;

        $totalAcquiringSum = 0;
        $totalAcquiringCount = 0;

        foreach ($allReports as $report) {
            $sellerOperName = (string) $this->rowValue($report, 'sellerOperName', 'supplier_oper_name', '');
            if ($sellerOperName === 'Продажа') {
                $commissionPercent = (float) $this->rowValue($report, 'commissionPercent', 'commission_percent', 0);

                if ($commissionPercent > 0) {
                    $totalCommissionSum += $commissionPercent;
                    $totalCommissionCount++;
                }

                $acquiringPercent = (float) $this->rowValue($report, 'acquiringPercent', 'acquiring_percent', 0);
                if ($acquiringPercent > 0) {
                    $totalAcquiringSum += $acquiringPercent;
                    $totalAcquiringCount++;
                }
            }
        }

        $avgCommission = 0;
        if ($totalCommissionCount > 0) {
            $avgCommission = round($totalCommissionSum / $totalCommissionCount, 2);
        }

        $avgAcquiring = 0;
        if ($totalAcquiringCount > 0) {
            $avgAcquiring = round($totalAcquiringSum / $totalAcquiringCount, 2);
        }

        \Illuminate\Support\Facades\Log::info('Reports Commissions Debug', [
            'dateFrom' => $dateFrom->toIso8601String(),
            'dateTo' => $dateTo->toIso8601String(),
            'reports_count' => count($allReports),
            'sales_count' => $totalCommissionCount,
            'avg_commission' => $avgCommission,
            'avg_acquiring' => $avgAcquiring
        ]);

        return [
            'success' => true,
            'avg_commission' => $avgCommission,
            'avg_acquiring' => $avgAcquiring,
        ];
    }

    /**
     * Получить статистику продаж и % выкупа.
     * При $collectArticleStats=false считаем только агрегат по кабинету.
     */
    private function getArticleStats(string $apiKey, bool $collectArticleStats = true): array
    {
        $startDate = Carbon::now()->subMonth()->startOfMonth();
        $endDate = Carbon::now()->subMonth()->endOfMonth();

        $limit = 999;
        $offset = 0;
        $stats = [];
        $cabinetOrders = 0;
        $cabinetBuyouts = 0;
        $cabinetBuyoutSum = 0;
        $cabinetProductsCount = 0;

        while (true) {
            $analyticsResponse = $this->wbPriceCalculationService->getSalesFunnelProducts($apiKey, $startDate, $endDate, [
                'limit' => $limit,
                'offset' => $offset,
                'skipDeletedNm' => true,
            ]);

            $analytics = $this->wbPriceCalculationService->parseApiResponse($analyticsResponse, 'getSalesFunnelProducts');

            if (! $analytics['success']) {
                $message = is_string($analytics['data']) ? $analytics['data'] : 'Ошибка в получении аналитики продаж. Попробуйте позже.';
                return ['success' => false, 'message' => $message];
            }

            $products = data_get($analytics['data'], 'data.products', []);
            $products = is_array($products) ? $products : [];

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                $nmId = (int) data_get($product, 'product.nmId');
                if ($nmId <= 0) {
                    continue;
                }

                $orders = (int) data_get($product, 'statistic.selected.orderCount', 0);
                $buyouts = (int) data_get($product, 'statistic.selected.buyoutCount', 0);
                $buyoutPercent = (int) data_get($product, 'statistic.selected.conversions.buyoutPercent', 0);

                $cabinetOrders += $orders;
                $cabinetBuyouts += $buyouts;

                if ($buyoutPercent > 0) {
                    $cabinetBuyoutSum += $buyoutPercent;
                    $cabinetProductsCount++;
                }

                if (! $collectArticleStats) {
                    continue;
                }

                $stats[$nmId] = [
                    'orders' => $orders,
                    'buyouts' => $buyouts,
                    'buyout_percent' => $buyoutPercent > 0 ? $buyoutPercent : null,
                ];
            }

            if (count($products) < $limit) {
                break;
            }

            sleep(1);
            $offset += $limit;
        }

        $cabinetBuyoutPercent = $cabinetProductsCount > 0
            ? round($cabinetBuyoutSum / $cabinetProductsCount, 2)
            : null;


        return [
            'success' => true,
            'stats' => $stats,
            'cabinet_orders' => $cabinetOrders,
            'cabinet_buyouts' => $cabinetBuyouts,
            'cabinet_buyout_percent' => $cabinetBuyoutPercent,
        ];
    }

    private function extractReportRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        $candidates = [
            data_get($data, 'data'),
            data_get($data, 'data.items'),
            data_get($data, 'items'),
            data_get($data, 'result'),
            data_get($data, 'result.items'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate[0]) && is_array($candidate[0])) {
                return $candidate;
            }
        }

        return [];
    }

    private function rowValue(array $row, string $newKey, string $legacyKey, mixed $default = null): mixed
    {
        if (array_key_exists($newKey, $row) && $row[$newKey] !== null) {
            return $row[$newKey];
        }

        if (array_key_exists($legacyKey, $row) && $row[$legacyKey] !== null) {
            return $row[$legacyKey];
        }

        return $default;
    }

    /**
     * Конфигурация столбцов для Excel файла
     * Цвета соответствуют ТЗ:
     * - Зелёный (#E2EFDA) — данные из API (read-only)
     * - Жёлтый (#FFF2CC) — заполняется пользователем
     * - Тёмно-зелёный (#C4D79B) — расчётные поля (read-only)
     */
    private function getExportColumns(PriceCalculationV2Settings $settings): array
    {
        $colorApi = '#EAF3E3';
        $colorInput = '#FFF2CC';
        $colorLogistics = '#F3E4DA';
        $colorBlue = '#DEE8F5';
        $colorWhite = '#FFFFFF';
        $colorYellow = '#FFFF00';

        $isManualCommission = $settings->commission_source === 'manual';
        $isManualAcquiring = $settings->acquiring_source === 'manual';
        $isSalesMaintenance = $settings->maintenance_type === 'sales';

        $columns = [
            ['key' => 'brand', 'title' => 'Бренд', 'color' => $colorApi],
            ['key' => 'subject_name', 'title' => 'Предмет', 'color' => $colorApi],
            ['key' => 'vendor_code', 'title' => 'Артикул продавца', 'color' => $colorApi],
            ['key' => 'nm_id', 'title' => 'Артикул WB', 'color' => $colorApi],
            ['key' => 'volume_liters', 'title' => 'Объем, л.', 'color' => $colorApi],
            ['key' => 'extra_liters', 'title' => "Литры\nсвыше\n1 литра", 'color' => $colorWhite],

            ['key' => 'cost_price', 'title' => 'себес. руб.', 'color' => $colorInput],
            ['key' => 'margin_percent', 'title' => 'маржа, %', 'color' => $colorInput],
            ['key' => 'fulfillment_fee', 'title' => 'услуги фф руб./ед', 'color' => $colorInput],
            ['key' => 'maintenance_percent', 'title' => "% за\nведение\n(от суммы к\nперечислению\nна р/с)", 'color' => $colorInput],

            ['key' => 'stop_price', 'title' => "СТОП-ЦЕНА,\nруб. (если\nведение\nсчитается от\nсуммы к\nперечислению)", 'color' => $colorWhite],

            ['key' => 'avg_base_logistics', 'title' => "ср. ст-ть\nпрямой\nлогистики\nза 1 л", 'color' => $colorLogistics],
            ['key' => 'avg_extra_liter_logistics', 'title' => "ср. ст-ть\nпрямой\nлогистики\nза доп. л", 'color' => $colorLogistics],
            ['key' => 'localization_index', 'title' => 'ИЛ', 'color' => $colorInput],
            ['key' => 'avg_logistics', 'title' => "итог. ст-ть\nпрямой\nлогистики,\nруб.", 'color' => $colorWhite],
            ['key' => 'reverse_logistics_cost_gt_1_0_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n>1 л.", 'color' => $colorLogistics],
            ['key' => 'reverse_logistics_cost_0_801_1_0_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n0,801-1л", 'color' => $colorLogistics],
            ['key' => 'reverse_logistics_cost_0_601_0_8_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n0,601-0,8л", 'color' => $colorLogistics],
            ['key' => 'reverse_logistics_cost_0_401_0_6_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n0,401-0,6л", 'color' => $colorLogistics],
            ['key' => 'reverse_logistics_cost_0_201_0_4_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n0,201-0,4л", 'color' => $colorLogistics],
            ['key' => 'reverse_logistics_cost_0_001_0_2_l', 'title' => "ст-ть обр.\nлогистики\nдля\nтоваров\n0,001-0,2л", 'color' => $colorLogistics],

            ['key' => 'return_rate_gt_1_1_l', 'title' => 'ВОЗВРАТ >1.1 л.', 'color' => $colorWhite],
            ['key' => 'return_rate_0_801_1_0_l', 'title' => 'ВОЗВРАТ 0,801-1л', 'color' => $colorWhite],
            ['key' => 'return_rate_0_601_0_8_l', 'title' => 'ВОЗВРАТ 0,601-0,8л', 'color' => $colorWhite],
            ['key' => 'return_rate_0_401_0_6_l', 'title' => 'ВОЗВРАТ 0,401-0,6л', 'color' => $colorWhite],
            ['key' => 'return_rate_0_201_0_4_l', 'title' => 'ВОЗВРАТ 0,201-0,4л', 'color' => $colorWhite],
            ['key' => 'return_rate_0_001_0_2_l', 'title' => 'ВОЗВРАТ 0,001-0,2л', 'color' => $colorWhite],

            ['key' => 'return_cost', 'title' => "Итог. ст-ть\nвозврата", 'color' => $colorWhite],
            ['key' => 'buyout_percent', 'title' => '% ВЫКУПА', 'color' => $colorLogistics],

            ['key' => 'total_logistics', 'title' => "ИТОГОВАЯ\nЛОГИСТИКА, руб.", 'color' => $colorWhite],
            ['key' => 'storage_cost', 'title' => "хранение\nруб.", 'color' => $colorInput],
            ['key' => 'sales_count', 'title' => "продажи,\nшт.", 'color' => $colorBlue],
            ['key' => 'storage_per_sale', 'title' => "хранение/1\nпродажа,\nруб.", 'color' => $colorBlue],

            ['key' => 'advertising_percent', 'title' => "ДРР, % от\nоборота", 'color' => $colorInput],
            ['key' => 'wb_commission_percent', 'title' => 'комиссия ВБ', 'color' => $isManualCommission ? $colorInput : $colorWhite],
            ['key' => 'options_constructor_percent_sales', 'title' => "% на опции\nв\nконструкторе\nтарифов,\nот суммы\nпродажи", 'color' => $colorInput],
            ['key' => 'options_constructor_percent_transfer', 'title' => "% на опции\nв\nконструкторе\nтарифов,\nот\nперечисления", 'color' => $colorInput],
            ['key' => 'acquiring_percent', 'title' => 'эквайринг', 'color' => $isManualAcquiring ? $colorInput : $colorWhite],
            ['key' => 'tax_percent', 'title' => "налог, % от\nпродажи", 'color' => $colorInput],
            ['key' => 'maintenance_percent_sales', 'title' => "% за ведение,\nесли считается\nот суммы\nпродажи", 'color' => $colorInput],

            ['key' => 'irp', 'title' => 'ИРП', 'color' => $colorInput],
            ['key' => 'commission_plus_acquiring', 'title' => "общий % с\nкаждой\nпроданной\nед. на\nрасходы WB", 'color' => $colorWhite],
            ['key' => 'standard_discount_percent', 'title' => "стандартная\nскидка для\nпокупателя, %", 'color' => $colorInput],
            ['key' => 'promotion_percent', 'title' => "% на\nучастие\nв акции", 'color' => $colorInput],
            ['key' => 'min_price_promo', 'title' => "MIN\nЦЕНА\nДЛЯ\nАКЦИЙ", 'color' => $colorYellow],
            ['key' => 'standard_price', 'title' => "ЦЕНА\nБЕЗ\nАКЦИИ", 'color' => $colorYellow],
            ['key' => 'price_before_discount', 'title' => "ЦЕНА\nДО\nСКИДКИ", 'color' => $colorYellow],
        ];

        if (!$settings->use_storage) {
            $columns = array_values(array_filter($columns, static fn(array $column) => $column['key'] !== 'storage_cost'));
        }

        if (!$isManualCommission) {
            $columns = array_values(array_filter($columns, static fn(array $column) => $column['key'] !== 'wb_commission_percent'));
        }

        if (!$isManualAcquiring) {
            $columns = array_values(array_filter($columns, static fn(array $column) => $column['key'] !== 'acquiring_percent'));
        }

        if ($isSalesMaintenance) {
            $columns = array_values(array_filter($columns, static fn(array $column) => $column['key'] !== 'options_constructor_percent_transfer'));
        } else {
            $columns = array_values(array_filter($columns, static fn(array $column) => !in_array($column['key'], [
                'options_constructor_percent_sales',
                'maintenance_percent_sales',
            ], true)));
        }

        if (!$settings->use_irp) {
            $columns = array_values(array_filter($columns, static fn(array $column) => $column['key'] !== 'irp'));
        }

        // Размер выгружаем только если размеры не скрыты в настройках кабинета.
        if (!$settings->hide_sizes) {
            array_splice($columns, 4, 0, [[
                'key' => 'size',
                'title' => 'Размер',
                'color' => $colorApi,
            ]]);
        }

        return $columns;
    }

    /**
     * Проверка принадлежности кабинета текущему юзеру
     */
    private function getCabinet(int $cabinetId): ?PriceCalculationCabinets
    {
        $cabinet = PriceCalculationCabinets::find($cabinetId);

        if (!$cabinet || (int) $cabinet->user_id !== (int) auth('api')->id()) {
            return null;
        }

        return $cabinet;
    }
}
