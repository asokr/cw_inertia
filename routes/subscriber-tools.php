<?php

/*
|--------------------------------------------------------------------------
| Subscriber tool routes (Phase 3b)
|--------------------------------------------------------------------------
|
| Platform routes live in routes/subscriber.php.
| Each instrument module is added here as it is migrated from Nuxt.
|
| Planned modules:
| - 3b.1  Public blog (routes in web.php, not under /panel)
| - 3b.2  WB Feedbacks       → /panel/wb/feedbacks
| - 3b.3  Ozon Feedbacks     → /panel/oz/feedbacks
| - 3b.4  WB Price Calc V3   → /panel/wb/price-calc
| - 3b.5  Ozon Price Calc    → /panel/oz/price-calc
| - 3b.6  WB Repricer        → /panel/wb/repricer
| - 3b.7  WB Profitability   → /panel/wb/profitability
| - 3b.8  AI Cabinet Analyzer → /panel/wb/ai-cabinet-analyzer
| - 3b.9  WB Promo Calculator → /panel/wb/promocalculator
| - 3b.10 AI Marketplace     → /panel/ai
| - 3b.11 WB A/B Testing     → /panel/wb/ab-testing
|
*/

use App\Http\Controllers\Web\Subscriber\Oz\Feedbacks\ClientsController as OzFeedbacksClientsController;
use App\Http\Controllers\Web\Subscriber\Oz\Feedbacks\FeedbacksController as OzFeedbacksController;
use App\Http\Controllers\Web\Subscriber\Oz\PriceCalc\CabinetsController as OzPriceCalcCabinetsController;
use App\Http\Controllers\Web\Subscriber\Oz\PriceCalc\WorkspaceController as OzPriceCalcWorkspaceController;
use App\Http\Controllers\Web\Subscriber\Wb\PriceCalc\CabinetsController as WbPriceCalcCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\PriceCalc\WorkspaceController as WbPriceCalcWorkspaceController;
use App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer\AiAnalysesController as WbAiCabinetAnalyzerAiAnalysesController;
use App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer\CabinetsController as WbAiCabinetAnalyzerCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer\WorkspaceController as WbAiCabinetAnalyzerWorkspaceController;
use App\Http\Controllers\Web\Subscriber\Wb\Profitability\CabinetsController as WbProfitabilityCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\Profitability\ReportController as WbProfitabilityReportController;
use App\Http\Controllers\Web\Subscriber\Wb\AbTesting\WorkspaceController as WbAbTestingWorkspaceController;
use App\Http\Controllers\Web\Subscriber\Wb\PromoCalculator\PromoCalculatorController as WbPromoCalculatorController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\CabinetsController as WbRepricerCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\StocksController as WbRepricerStocksController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\StrategyHubController as WbRepricerStrategyHubController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\TimeSettingsController as WbRepricerTimeSettingsController;
use App\Http\Controllers\Web\Subscriber\Ai\MarketplaceController as AiMarketplaceController;
use App\Http\Controllers\Web\Subscriber\Ai\MediaController as AiMediaController;
use App\Http\Controllers\Web\Subscriber\Wb\Cabinets\CabinetsController as WbCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\Cabinets\MigrationController as WbCabinetsMigrationController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\ClientsController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\FeedbacksController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\StatsController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\TemplatesController;
use Illuminate\Support\Facades\Route;

/*
| Unified WB Cabinets (global for all WB tools)
*/
Route::prefix('wb/cabinets')
    ->name('subscriber.wb.cabinets.')
    ->group(function () {
        Route::get('/migration', [WbCabinetsMigrationController::class, 'show'])->name('migration');
        Route::post('/migration/cabinets', [WbCabinetsMigrationController::class, 'storeCabinet'])->name('migration.cabinets.store');
        Route::post('/migration/run', [WbCabinetsMigrationController::class, 'run'])->name('migration.run');

        // Index page removed — manage cabinets from the header dropdown.
        Route::redirect('/', '/panel')->name('index');
        Route::post('/', [WbCabinetsController::class, 'store'])->name('store');
        Route::put('/{cabinet}', [WbCabinetsController::class, 'update'])->name('update');
        Route::delete('/{cabinet}', [WbCabinetsController::class, 'destroy'])->name('destroy');
        Route::post('/select', [WbCabinetsController::class, 'select'])->name('select');
    });

Route::middleware(['permission:subscriber wb feedbacks'])
    ->prefix('wb/feedbacks')
    ->name('subscriber.wb.feedbacks.')
    ->group(function () {
        // Legacy URLs → flat workspace (cabinet lives in header)
        Route::redirect('/clients/{client}', '/panel/wb/feedbacks');
        Route::redirect('/clients/{client}/templates', '/panel/wb/feedbacks/templates');
        Route::get('/clients/{client}/products/{product}', function (string $client, string $product) {
            return redirect("/panel/wb/feedbacks/products/{$product}");
        });

        Route::get('/', [FeedbacksController::class, 'show'])->name('index');
        Route::get('/answered', [FeedbacksController::class, 'answered'])->name('answered');
        Route::post('/feedbacks', [FeedbacksController::class, 'refresh'])->name('feedbacks.refresh');
        Route::post('/feedbacks/send', [FeedbacksController::class, 'send'])->name('feedbacks.send');
        Route::post('/ai', [FeedbacksController::class, 'updateAi'])->name('ai.update');
        Route::post('/ai/generate', [FeedbacksController::class, 'generateAi'])->name('ai.generate');

        Route::get('/templates', [TemplatesController::class, 'index'])->name('templates.index');
        Route::post('/templates', [TemplatesController::class, 'store'])->name('templates.store');
        Route::put('/templates/{template}', [TemplatesController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [TemplatesController::class, 'destroy'])->name('templates.destroy');
        Route::post('/bot-status', [TemplatesController::class, 'updateBotStatus'])->name('bot-status.update');

        Route::get('/products/{product}', [StatsController::class, 'product'])->name('products.stats');
    });

Route::middleware(['permission:subscriber oz feedbacks'])
    ->prefix('oz/feedbacks')
    ->name('subscriber.oz.feedbacks.')
    ->group(function () {
        Route::get('/', [OzFeedbacksClientsController::class, 'index'])->name('index');

        Route::post('/cabinets', [OzFeedbacksClientsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [OzFeedbacksClientsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [OzFeedbacksClientsController::class, 'destroy'])->name('cabinets.destroy');

        Route::get('/cabinets/{cabinet}', [OzFeedbacksController::class, 'show'])->name('cabinets.show');
        Route::post('/cabinets/{cabinet}/feedbacks', [OzFeedbacksController::class, 'refresh'])->name('cabinets.feedbacks.refresh');
        Route::post('/cabinets/{cabinet}/feedbacks/send', [OzFeedbacksController::class, 'send'])->name('cabinets.feedbacks.send');
        Route::post('/cabinets/{cabinet}/ai', [OzFeedbacksController::class, 'updateAi'])->name('cabinets.ai.update');
        Route::post('/cabinets/{cabinet}/ai/generate', [OzFeedbacksController::class, 'generateAi'])->name('cabinets.ai.generate');
    });

Route::middleware(['permission:subscriber wb price calculator'])
    ->prefix('wb/price-calc')
    ->name('subscriber.wb.price-calc.')
    ->group(function () {
        Route::redirect('/cabinets/{cabinet}', '/panel/wb/price-calc');

        Route::get('/', [WbPriceCalcWorkspaceController::class, 'show'])->name('index');
        Route::post('/sync', [WbPriceCalcWorkspaceController::class, 'sync'])->name('sync');
        Route::post('/settings', [WbPriceCalcWorkspaceController::class, 'saveSettings'])->name('settings.save');
        Route::post('/import-volume', [WbPriceCalcWorkspaceController::class, 'importVolume'])->name('import-volume');
        Route::post('/import-excel', [WbPriceCalcWorkspaceController::class, 'importExcel'])->name('import-excel');
        Route::post('/export-excel', [WbPriceCalcWorkspaceController::class, 'exportExcel'])->name('export-excel');
    });

Route::middleware(['permission:subscriber oz price calc'])
    ->prefix('oz/price-calc')
    ->name('subscriber.oz.price-calc.')
    ->group(function () {
        Route::get('/', [OzPriceCalcCabinetsController::class, 'index'])->name('index');

        Route::post('/cabinets', [OzPriceCalcCabinetsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [OzPriceCalcCabinetsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [OzPriceCalcCabinetsController::class, 'destroy'])->name('cabinets.destroy');

        Route::get('/cabinets/{cabinet}', [OzPriceCalcWorkspaceController::class, 'show'])->name('cabinets.show');

        Route::post('/cabinets/{cabinet}/sync', [OzPriceCalcWorkspaceController::class, 'syncFbo'])->name('cabinets.sync');
        Route::post('/cabinets/{cabinet}/calculate', [OzPriceCalcWorkspaceController::class, 'calculateFbo'])->name('cabinets.calculate');
        Route::post('/cabinets/{cabinet}/import', [OzPriceCalcWorkspaceController::class, 'importFbo'])->name('cabinets.import');
        Route::post('/cabinets/{cabinet}/export', [OzPriceCalcWorkspaceController::class, 'exportFbo'])->name('cabinets.export');
        Route::get('/cabinets/{cabinet}/export-download', [OzPriceCalcWorkspaceController::class, 'exportDownloadFbo'])->name('cabinets.export-download');

        Route::post('/cabinets/{cabinet}/fbs/sync', [OzPriceCalcWorkspaceController::class, 'syncFbs'])->name('cabinets.fbs.sync');
        Route::post('/cabinets/{cabinet}/fbs/calculate', [OzPriceCalcWorkspaceController::class, 'calculateFbs'])->name('cabinets.fbs.calculate');
        Route::post('/cabinets/{cabinet}/fbs/import', [OzPriceCalcWorkspaceController::class, 'importFbs'])->name('cabinets.fbs.import');
        Route::post('/cabinets/{cabinet}/fbs/export', [OzPriceCalcWorkspaceController::class, 'exportFbs'])->name('cabinets.fbs.export');
        Route::get('/cabinets/{cabinet}/fbs/export-download', [OzPriceCalcWorkspaceController::class, 'exportDownloadFbs'])->name('cabinets.fbs.export-download');
    });

Route::middleware(['permission:subscriber wb repricer'])
    ->prefix('wb/repricer')
    ->name('subscriber.wb.repricer.')
    ->group(function () {
        Route::redirect('/cabinets/{cabinet}', '/panel/wb/repricer');
        Route::redirect('/cabinets/{cabinet}/time', '/panel/wb/repricer/time');
        Route::redirect('/cabinets/{cabinet}/stocks', '/panel/wb/repricer/stocks');

        Route::get('/', [WbRepricerStrategyHubController::class, 'show'])->name('index');
        Route::post('/logs', [WbRepricerCabinetsController::class, 'logs'])->name('logs');

        Route::get('/time', [WbRepricerTimeSettingsController::class, 'index'])->name('time.index');
        Route::post('/time', [WbRepricerTimeSettingsController::class, 'store'])->name('time.store');
        Route::put('/time/{setting}', [WbRepricerTimeSettingsController::class, 'update'])->name('time.update');
        Route::delete('/time/{setting}', [WbRepricerTimeSettingsController::class, 'destroy'])->name('time.destroy');

        Route::get('/stocks', [WbRepricerStocksController::class, 'index'])->name('stocks.index');
        Route::post('/stocks', [WbRepricerStocksController::class, 'store'])->name('stocks.store');
        Route::put('/stocks/{stock}', [WbRepricerStocksController::class, 'update'])->name('stocks.update');
        Route::delete('/stocks/{stock}', [WbRepricerStocksController::class, 'destroy'])->name('stocks.destroy');
        Route::post('/stocks/sizes', [WbRepricerStocksController::class, 'loadSizes'])->name('stocks.sizes');
        Route::post('/stocks/{stock}/reset', [WbRepricerStocksController::class, 'reset'])->name('stocks.reset');
    });

Route::middleware(['permission:subscriber wb profitability'])
    ->prefix('wb/profitability')
    ->name('subscriber.wb.profitability.')
    ->group(function () {
        Route::redirect('/cabinets/{cabinet}', '/panel/wb/profitability');

        Route::get('/', [WbProfitabilityReportController::class, 'show'])->name('index');
        Route::get('/items', [WbProfitabilityReportController::class, 'items'])->name('items');
        Route::post('/report', [WbProfitabilityReportController::class, 'store'])->name('report.store');
        Route::post('/export', [WbProfitabilityReportController::class, 'exportStart'])->name('export.start');
        Route::get('/export/status', [WbProfitabilityReportController::class, 'exportStatus'])->name('export.status');
        Route::get('/export/download', [WbProfitabilityReportController::class, 'exportDownload'])->name('export.download');
    });

Route::middleware(['permission:subscriber wb ai cabinet analyzer'])
    ->prefix('wb/ai-cabinet-analyzer')
    ->name('subscriber.wb.ai-cabinet-analyzer.')
    ->group(function () {
        Route::redirect('/cabinets/{cabinet}', '/panel/wb/ai-cabinet-analyzer');

        Route::get('/', [WbAiCabinetAnalyzerWorkspaceController::class, 'show'])->name('index');
        Route::post('/reports', [WbAiCabinetAnalyzerWorkspaceController::class, 'startReport'])->name('reports.store');

        Route::post('/ai-analyses/start', [WbAiCabinetAnalyzerAiAnalysesController::class, 'start'])->name('ai-analyses.start');
        Route::post('/ai-analyses/{analysis}/regenerate', [WbAiCabinetAnalyzerAiAnalysesController::class, 'regenerate'])->name('ai-analyses.regenerate');
        Route::get('/ai-analyses/{analysis}', [WbAiCabinetAnalyzerAiAnalysesController::class, 'show'])->name('ai-analyses.show');
        Route::get('/ai-analyses/{analysis}/download', [WbAiCabinetAnalyzerAiAnalysesController::class, 'download'])->name('ai-analyses.download');
    });

Route::middleware(['permission:subscriber wb promo calculator'])
    ->prefix('wb/promocalculator')
    ->name('subscriber.wb.promocalculator.')
    ->group(function () {
        Route::get('/', [WbPromoCalculatorController::class, 'index'])->name('index');
        Route::post('/upload', [WbPromoCalculatorController::class, 'upload'])->name('upload');
        Route::post('/calculate', [WbPromoCalculatorController::class, 'calculate'])->name('calculate');
        Route::post('/export', [WbPromoCalculatorController::class, 'export'])->name('export');
        Route::post('/repricer', [WbPromoCalculatorController::class, 'sendToRepricer'])->name('repricer');
    });

Route::middleware(['permission:subscriber wb ab testing'])
    ->prefix('wb/ab-testing')
    ->name('subscriber.wb.ab-testing.')
    ->group(function () {
        Route::get('/', [WbAbTestingWorkspaceController::class, 'show'])->name('index');
        Route::post('/sync', [WbAbTestingWorkspaceController::class, 'sync'])->name('sync');
    });

Route::prefix('ai')
    ->name('subscriber.ai.')
    ->group(function () {
        Route::get('/media/{path}', [AiMediaController::class, 'show'])
            ->where('path', '.*')
            ->withoutMiddleware(['verified', 'panel.access'])
            ->name('media');

        Route::middleware(['permission:subscriber ai'])->group(function () {
        Route::get('/', [AiMarketplaceController::class, 'index'])->name('index');
        Route::get('/text', [AiMarketplaceController::class, 'text'])->name('text');
        Route::get('/image', [AiMarketplaceController::class, 'image'])->name('image');
        Route::get('/image/history', [AiMarketplaceController::class, 'imageHistory'])->name('image.history');
        Route::get('/image/{uuid}', [AiMarketplaceController::class, 'imageGeneration'])
            ->whereUuid('uuid')
            ->name('image.generation');
        Route::get('/video', [AiMarketplaceController::class, 'video'])->name('video');
        Route::get('/video/history', [AiMarketplaceController::class, 'videoHistory'])->name('video.history');
        Route::get('/video/{uuid}', [AiMarketplaceController::class, 'videoGeneration'])
            ->whereUuid('uuid')
            ->name('video.generation');
        Route::post('/marketplace', [AiMarketplaceController::class, 'marketplace'])->name('marketplace');
        Route::post('/image/start', [AiMarketplaceController::class, 'imageStart'])->name('image.start');
        Route::get('/image/generations', [AiMarketplaceController::class, 'imageGenerationsIndex'])->name('image.generations.index');
        Route::post('/image/generations', [AiMarketplaceController::class, 'imageGenerationsStore'])->name('image.generations.store');
        Route::get('/image/generations/{uuid}', [AiMarketplaceController::class, 'imageGenerationsShow'])
            ->whereUuid('uuid')
            ->name('image.generations.show');
        Route::delete('/image/generations/{uuid}', [AiMarketplaceController::class, 'imageGenerationsDestroy'])
            ->whereUuid('uuid')
            ->name('image.generations.destroy');
        Route::post('/video/start', [AiMarketplaceController::class, 'videoStart'])->name('video.start');
        Route::post('/video/reference/start', [AiMarketplaceController::class, 'videoReferenceStart'])->name('video.reference.start');
        Route::get('/video/status/{requestId}', [AiMarketplaceController::class, 'videoStatus'])
            ->withoutMiddleware('throttle:api')
            ->name('video.status');
        Route::get('/video/generations', [AiMarketplaceController::class, 'videoGenerationsIndex'])->name('video.generations.index');
        Route::post('/video/generations', [AiMarketplaceController::class, 'videoGenerationsStore'])->name('video.generations.store');
        Route::get('/video/generations/{uuid}', [AiMarketplaceController::class, 'videoGenerationsShow'])
            ->whereUuid('uuid')
            ->name('video.generations.show');
        Route::delete('/video/generations/{uuid}', [AiMarketplaceController::class, 'videoGenerationsDestroy'])
            ->whereUuid('uuid')
            ->name('video.generations.destroy');
        Route::post('/limits', [AiMarketplaceController::class, 'refreshLimits'])->name('limits');
        });
    });