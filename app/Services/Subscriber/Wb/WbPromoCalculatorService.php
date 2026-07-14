<?php

namespace App\Services\Subscriber\Wb;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Http\Traits\WBadvTrait;
use App\Http\Traits\WBApiTrait;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV2Data;

class WbPromoCalculatorService
{

    use WBadvTrait;
    use WBApiTrait;

    public function upload(Request $request)
    {
        $messages = [
            'file.required' => 'Прикрепите отчёт',
            'file.mimes'    => 'Загрузка данного типа запрещена. Используйте формат Office 2007 (.xlsx)',
        ];

        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx',
        ], $messages);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if ($request->file()) {

            $file     = $request->file;
            $filename = Str::random(20) . '.' . File::extension($file->getClientOriginalName());
            $path     = "wb/promocalculator/" . $filename;
            Storage::disk('public')->put($path, file_get_contents($file));

            return response()->json([
                "success"  => true,
                "messages" => ["Файл загружен"],
                "data"     => [
                    "file" => $path,
                ],
            ], 200);
        } else {
            return response()->json(["success" => false, "messages" => ["Ошибка при загрузке файла"]], 200);
        }
    }

    public function calculate(Request $request)
    {
        $messages = [
            'file.required'       => 'Нет отчёта по акциям. Загрузите его.',
            'cabinet_id.required' => 'Вы не выбрали кабинет из инструмента Ценообразования',
        ];

        $validator = Validator::make($request->all(), [
            'file'       => 'required|',
            'cabinet_id' => 'required|',

        ], $messages);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $priceCalcData = PriceCalculationV2Data::where('cabinet_id', $request->cabinet_id)
            ->whereNotNull('nm_id')
            ->selectRaw('nm_id, AVG(cost_price) as cost_price, AVG(fulfillment_fee) as fulfillment_fee, AVG(wb_commission_percent) as wb_commission_percent, AVG(total_logistics) as total_logistics, AVG(min_price_promo) as min_price_promo, AVG(tax_percent) as tax_percent, AVG(advertising_percent) as advertising_percent, AVG(acquiring_percent) as acquiring_percent, AVG(maintenance_percent) as maintenance_percent')
            ->groupBy('nm_id')
            ->get()
            ->toArray();

        if (! $priceCalcData) {
            return response()->json(["success" => false, "messages" => ["Нет данных в ценообразовании для выбранного кабинета"]], 200);
        }

        if (Storage::disk('public')->exists($request->file)) {
            $data = $this->readReportFile($request->file);
            if (! $data) {
                return response()->json(["success" => false, "messages" => ["Не удалось прочитать файл. Убедитесь что файл верный и перезагрузите его."]], 200);
            }
        } else {
            return response()->json(["success" => false, "messages" => ["Отчёт по акциям не найден. Попробуйте перезагрузить файл."]], 200);
        }

        // Найдём нужные колонки
        $headerRowIndex = null;
        foreach ($data as $rowIndex => $rowValues) {
            if (false !== array_search('Артикул WB', $rowValues, true)) {
                $headerRowIndex = $rowIndex;
                break;
            }
        }

        if ($headerRowIndex === null) {
            return response()->json(["success" => false, "messages" => ["Колонка 'Артикул WB' не найдена."]], 200);
        }
        $names = $data[$headerRowIndex];
        foreach ($names as $i => $value) {
            if ($value == 'Артикул поставщика')
                $vendor_art_column = $i;

            if ($value == 'Плановая цена для акции')
                $planPrice_column = $i;

            if ($value == 'Артикул WB')
                $nm_id_column = $i;

            if ($value == 'Текущая розничная цена')
                $current_price_column = $i;

            if ($value == 'Остаток товара на складах Wb (шт.)')
                $fbo_stocks = $i;

            if ($value == 'Остаток товара на складе продавца Wb (шт.)')
                $fbs_stocks = $i;

            if ($value == 'Загружаемая скидка для участия в акции')
                $wb_discount = $i;
        }
        if (!isset($nm_id_column)) {
            return response()->json(["success" => false, "messages" => ["В файле не найдена колонка 'Артикул WB'. Проверьте файл."]], 200);
        }

        if (!isset($vendor_art_column, $planPrice_column, $current_price_column, $fbo_stocks, $fbs_stocks, $wb_discount)) {
            return response()->json(["success" => false, "messages" => ["В файле отсутствуют обязательные колонки отчёта по акциям."]], 200);
        }

        $priceIndex = array_column($priceCalcData, null, 'nm_id');

        $newData = [];
        foreach ($data as $key => $item) {
            // если артикула нет или нет в индексе — пропускаем
            if (!isset($nm_id_column) || !isset($item[$nm_id_column])) {
                continue;
            }

            $nmId = (int) $item[$nm_id_column];
            if ($nmId <= 0 || !array_key_exists($nmId, $priceIndex)) {
                continue;
            }

            $pc = $priceIndex[$nmId];

            $planPrice = $this->toFloat($item[$planPrice_column] ?? 0);
            $currentPrice = $this->toFloat($item[$current_price_column] ?? 0);
            $fboStock = $this->toFloat($item[$fbo_stocks] ?? 0);
            $fbsStock = $this->toFloat($item[$fbs_stocks] ?? 0);
            $wbDiscountValue = $this->toFloat($item[$wb_discount] ?? 0);

            $taxPercent = $this->toFloat($pc['tax_percent'] ?? 0);
            $advertisingPercent = $this->toFloat($pc['advertising_percent'] ?? 0);
            $acquiringPercent = $this->toFloat($pc['acquiring_percent'] ?? 0);
            $maintenancePercent = $this->toFloat($pc['maintenance_percent'] ?? 0);
            $wbCommissionPercent = $this->toFloat($pc['wb_commission_percent'] ?? 0);

            $costPrice = $this->toFloat($pc['cost_price'] ?? 0);
            $fulfillmentFee = $this->toFloat($pc['fulfillment_fee'] ?? 0);
            $totalLogistics = $this->toFloat($pc['total_logistics'] ?? 0);
            $minPricePromo = $this->toFloat($pc['min_price_promo'] ?? 0);

            $nalog = round($planPrice / 100 * $taxPercent, 2);
            $advertising = round($planPrice / 100 * $advertisingPercent, 2);
            $acquiring = round($planPrice / 100 * $acquiringPercent, 2);
            $maintenance = round($planPrice / 100 * $maintenancePercent, 2);
            $wb_commission = round($planPrice / 100 * $wbCommissionPercent, 2);

            $margin = $planPrice - $nalog - $advertising - $wb_commission - $acquiring - $maintenance - $totalLogistics - $fulfillmentFee - $costPrice;
            $profit = $costPrice == 0.0 ? 0 : $margin / $costPrice * 100;

            $fractional = $currentPrice > 0 ? (1 - $planPrice / $currentPrice) * 100 : 0;
            $minDiscount = (int) ceil($fractional);
            $change_discount = false;
            if ($wbDiscountValue > $minDiscount) {
                $change_discount = true;
            }

            $newData[] = [
                'vendor_art'        => $data[$key][$vendor_art_column],
                'nm_id'             => $nmId,
                'plan_price'        => $planPrice,
                'current_price'     => $currentPrice,
                'min_price'         => round($minPricePromo), // минимальная цена из кабинета
                'wb_discount'       => $data[$key][$wb_discount], // загружаемая скидка для участия в акции
                'margin'            => round($margin, 2), // маржа в рублях
                'profit'            => round($profit, 2), // рентабельность акции
                'stock'             => $fbsStock + $fboStock, // остаток товара
                'change_discount'   => $change_discount ? $minDiscount : false, // нужно ли менять скидку и на какую
            ];
        }

        if (count($newData) <= 1) {
            return response()->json(["success" => false, "messages" => ["Не найдено ни одной номенклатуры в кабинете из файла акций."]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Расчёт рентабельности акций получен"], "data" => $newData], 200);
    }

    private function toFloat($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_string($value)) {
            $value = str_replace([' ', ','], ['', '.'], trim($value));
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function readReportFile($file)
    {
        try {
            $report = Storage::disk('public')->path($file);
            $reader = new Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet      = $reader->load($report);
            $worksheet        = $spreadsheet->getActiveSheet();
            $spreadsheetArray = $worksheet->toArray();
            return $spreadsheetArray;
        } catch (\Throwable $th) {
            return false;
        }
    }

    public function getPromoXlsx(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'data' => 'required|array',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        // Настройки
        $data     = $request->data;
        $filename = Carbon::now()->format('d-m-y-H-i') . '_' . Str::random(4) . '.xlsx';
        $path     = "wb/promocalculator/";

        $styleArray = [
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            ],
        ];

        $spreadsheet = new Spreadsheet();
        // Стандартные настройки
        $spreadsheet->getDefaultStyle()->getFont()->setName('Segoe UI');

        // Создаем и делаем активным новый лист
        $myWorkSheet = new Worksheet($spreadsheet, 'Рентабельность акции');
        $spreadsheet->addSheet($myWorkSheet, 0);
        $sheet = $spreadsheet->setActiveSheetIndex(0);

        // Станадартные настройки для листа
        $sheet->getDefaultColumnDimension()->setWidth(5, 'cm');
        $sheet->getDefaultRowDimension()->setRowHeight(0.80, 'cm');
        $sheet->getStyle('A1:G1')->applyFromArray($styleArray);


        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $num = 2;
        foreach ($data as $i => $item) {
            if (! $i) {
                $sheet->setCellValue('A1', 'Артикул поставщика');
                $sheet->setCellValue('B1', 'Артикул WB');
                $sheet->setCellValue('C1', 'Плановая цена акции');
                $sheet->setCellValue('D1', 'Текущая розничная цена');
                $sheet->setCellValue('E1', 'Остатки');
                $sheet->setCellValue('F1', 'Рентабельность');
                $sheet->setCellValue('G1', 'Загружаемая скидка для участия в акции');
            }

            $sheet->setCellValue('A' . $num, $item['vendor_art']);
            $sheet->setCellValue('B' . $num, $item['nm_id']);
            $sheet->setCellValue('C' . $num, $item['plan_price']);
            $sheet->setCellValue('D' . $num, $item['current_price']);
            $sheet->setCellValue('E' . $num, $item['stock']);
            $sheet->setCellValue('F' . $num, $item['profit']);
            if ($item['change_discount']) {
                $sheet->setCellValue('G' . $num, $item['change_discount']);
            } else {
                $sheet->setCellValue('G' . $num, $item['wb_discount']);
            }



            if ($item['profit'] > 0) {
                $sheet->getStyle('A' . $num . ':' . 'G' . $num)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('7dd981');
            } else if ($item['profit'] < 0) {
                $sheet->getStyle('A' . $num . ':' . 'G' . $num)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('f99790');
            } else {
                $sheet->getStyle('A' . $num . ':' . 'G' . $num)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('efe46f');
            }

            $sheet->getRowDimension($num)->setRowHeight(0.70, 'cm');
            $sheet->getStyle('A' . $num . ':' . 'G' . $num)->applyFromArray($styleArray);
            $num++;
        }

        $writer = new XlsxWriter($spreadsheet);
        $writer->save(Storage::disk('public')->path($path . $filename));

        $link = config('app.url') . '/storage/' . $path . $filename;

        return response()->json(["success" => true, "messages" => ["Отчёт сформирован"], "data" => $link], 200);
    }

    public function sendToRepricer(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'data'       => 'required|array',
            'dates'      => 'required|array',
            'cabinet_id' => 'required',
        ], [
            'required' => 'Не хватает данных для передачи в репрайсер',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (! $belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета не существует"]], 200);
        }

        foreach ($request->data as $item) {
            $nmId = $this->resolveRepricerNmId($item);
            $planPrice = $this->resolveRepricerPlanPrice($item);

            if ($nmId <= 0 || $planPrice <= 0) {
                continue;
            }

            $base = $this->getBaseValues($cabinet->apikey, $nmId);
            if (! $base) {
                return response()->json(["success" => false, 'type' => 'bigError', "messages" => ["Для одной из номенклатур не удалось получить текущую цену. Попробуйте позже. Некоторые номенклатуры могли попасть в репрайсер."]], 200);
            }
            $terms = [
                'start' => $this->convertToMoscow($request->dates['start']),
                'end'   => $this->convertToMoscow($request->dates['end']),
                'value' => round($planPrice / (1 - $base['discount'] / 100)),
            ];

            RepricerSettings::updateOrCreate(
                ['nmID' => $nmId, 'cabinet_id' => $request->cabinet_id],
                [
                    'name'                  => "Акция_{$terms['start']}_по_{$terms['end']}",
                    'base_value'            => $base['price'],
                    'base_discount'         => $base['discount'],
                    'price_type'            => 'PRICE',
                    'strategy'              => 'TIME',
                    'pricing_modifier_type' => 'FIXED',
                    'terms'                 => $terms,
                    'active'                => 0,
                    'status'                => 1,
                ]
            );
        }

        return response()->json(["success" => true, "messages" => ["Номенклатуры переданы в репрайсер"]], 200);
    }

    // public function getQtyFromWb(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'nmID' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
    //     }

    //     $card = $this->publicGetNmData($request->nmID);
    //     $qty = 0;
    //     if (isset($card['data'])) {
    //         if (isset($card['data']->products[0]->sizes)) {

    //             foreach ($card['data']->products[0]->sizes as $i => $size) {

    //                 foreach ($size->stocks as $stock) {
    //                     $qty += (int)$stock->qty;
    //                 }
    //             }
    //         }
    //     }


    //     return response()->json(["success" => true, "messages" => ["Размеры получены"], "data" => $qty], 200);
    // }


    /**
     * Преобразовать дату в формат Y-m-d H:i:s и часовую зону Moscow
     */
    protected function convertToMoscow($dateTime)
    {
        return Carbon::parse($dateTime)
            ->setTimezone('Europe/Moscow')
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $item
     */
    private function resolveRepricerNmId(array $item): int
    {
        if (isset($item['nm_id'])) {
            return (int) $item['nm_id'];
        }

        return (int) ($item[5] ?? 0);
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $item
     */
    private function resolveRepricerPlanPrice(array $item): float
    {
        if (isset($item['plan_price'])) {
            return $this->toFloat($item['plan_price']);
        }

        return $this->toFloat($item[11] ?? 0);
    }

    protected function getBaseValues($apikey, $nmID)
    {

        $params = [
            'limit'      => 1,
            'filterNmID' => $nmID,
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($apikey, $params));

        if (! $response['success'] && isset($response['code'])) {
            return false;
        }

        $card = $response['data']['data']["listGoods"][0];

        return [
            'price'    => $card["sizes"][0]["price"],
            'discount' => $card["discount"],
        ];
    }
}
