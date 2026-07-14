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
use App\Http\Controllers\Web\Subscriber\Wb\PromoCalculator\PromoCalculatorController as WbPromoCalculatorController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\CabinetsController as WbRepricerCabinetsController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\StocksController as WbRepricerStocksController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\StrategyHubController as WbRepricerStrategyHubController;
use App\Http\Controllers\Web\Subscriber\Wb\Repricer\TimeSettingsController as WbRepricerTimeSettingsController;
use App\Http\Controllers\Web\Subscriber\Ai\MarketplaceController as AiMarketplaceController;
use App\Http\Controllers\Web\Subscriber\Ai\MediaController as AiMediaController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\ClientsController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\FeedbacksController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\StatsController;
use App\Http\Controllers\Web\Subscriber\Wb\Feedbacks\TemplatesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['permission:subscriber wb feedbacks'])
    ->prefix('wb/feedbacks')
    ->name('subscriber.wb.feedbacks.')
    ->group(function () {
        Route::get('/', [ClientsController::class, 'index'])->name('index');

        Route::post('/clients', [ClientsController::class, 'store'])->name('clients.store');
        Route::put('/clients/{client}', [ClientsController::class, 'update'])->name('clients.update');
        Route::delete('/clients/{client}', [ClientsController::class, 'destroy'])->name('clients.destroy');

        Route::get('/clients/{client}', [FeedbacksController::class, 'show'])->name('clients.show');
        Route::post('/clients/{client}/feedbacks', [FeedbacksController::class, 'refresh'])->name('clients.feedbacks.refresh');
        Route::post('/clients/{client}/feedbacks/send', [FeedbacksController::class, 'send'])->name('clients.feedbacks.send');
        Route::post('/clients/{client}/ai', [FeedbacksController::class, 'updateAi'])->name('clients.ai.update');
        Route::post('/clients/{client}/ai/generate', [FeedbacksController::class, 'generateAi'])->name('clients.ai.generate');

        Route::get('/clients/{client}/templates', [TemplatesController::class, 'index'])->name('clients.templates.index');
        Route::post('/clients/{client}/templates', [TemplatesController::class, 'store'])->name('clients.templates.store');
        Route::put('/clients/{client}/templates/{template}', [TemplatesController::class, 'update'])->name('clients.templates.update');
        Route::delete('/clients/{client}/templates/{template}', [TemplatesController::class, 'destroy'])->name('clients.templates.destroy');
        Route::post('/clients/{client}/bot-status', [TemplatesController::class, 'updateBotStatus'])->name('clients.bot-status.update');

        Route::get('/clients/{client}/products/{product}', [StatsController::class, 'product'])->name('clients.products.stats');
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
        Route::get('/', [WbPriceCalcCabinetsController::class, 'index'])->name('index');

        Route::post('/cabinets', [WbPriceCalcCabinetsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [WbPriceCalcCabinetsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [WbPriceCalcCabinetsController::class, 'destroy'])->name('cabinets.destroy');

        Route::get('/cabinets/{cabinet}', [WbPriceCalcWorkspaceController::class, 'show'])->name('cabinets.show');
        Route::post('/cabinets/{cabinet}/sync', [WbPriceCalcWorkspaceController::class, 'sync'])->name('cabinets.sync');
        Route::post('/cabinets/{cabinet}/settings', [WbPriceCalcWorkspaceController::class, 'saveSettings'])->name('cabinets.settings.save');
        Route::post('/cabinets/{cabinet}/import-volume', [WbPriceCalcWorkspaceController::class, 'importVolume'])->name('cabinets.import-volume');
        Route::post('/cabinets/{cabinet}/import-excel', [WbPriceCalcWorkspaceController::class, 'importExcel'])->name('cabinets.import-excel');
        Route::post('/cabinets/{cabinet}/export-excel', [WbPriceCalcWorkspaceController::class, 'exportExcel'])->name('cabinets.export-excel');
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
        Route::get('/', [WbRepricerCabinetsController::class, 'index'])->name('index');

        Route::post('/cabinets', [WbRepricerCabinetsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [WbRepricerCabinetsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [WbRepricerCabinetsController::class, 'destroy'])->name('cabinets.destroy');
        Route::post('/cabinets/{cabinet}/logs', [WbRepricerCabinetsController::class, 'logs'])->name('cabinets.logs');

        Route::get('/cabinets/{cabinet}', [WbRepricerStrategyHubController::class, 'show'])->name('cabinets.show');

        Route::get('/cabinets/{cabinet}/time', [WbRepricerTimeSettingsController::class, 'index'])->name('cabinets.time.index');
        Route::post('/cabinets/{cabinet}/time', [WbRepricerTimeSettingsController::class, 'store'])->name('cabinets.time.store');
        Route::put('/cabinets/{cabinet}/time/{setting}', [WbRepricerTimeSettingsController::class, 'update'])->name('cabinets.time.update');
        Route::delete('/cabinets/{cabinet}/time/{setting}', [WbRepricerTimeSettingsController::class, 'destroy'])->name('cabinets.time.destroy');

        Route::get('/cabinets/{cabinet}/stocks', [WbRepricerStocksController::class, 'index'])->name('cabinets.stocks.index');
        Route::post('/cabinets/{cabinet}/stocks', [WbRepricerStocksController::class, 'store'])->name('cabinets.stocks.store');
        Route::put('/cabinets/{cabinet}/stocks/{stock}', [WbRepricerStocksController::class, 'update'])->name('cabinets.stocks.update');
        Route::delete('/cabinets/{cabinet}/stocks/{stock}', [WbRepricerStocksController::class, 'destroy'])->name('cabinets.stocks.destroy');
        Route::post('/cabinets/{cabinet}/stocks/sizes', [WbRepricerStocksController::class, 'loadSizes'])->name('cabinets.stocks.sizes');
        Route::post('/cabinets/{cabinet}/stocks/{stock}/reset', [WbRepricerStocksController::class, 'reset'])->name('cabinets.stocks.reset');
    });

Route::middleware(['permission:subscriber wb profitability'])
    ->prefix('wb/profitability')
    ->name('subscriber.wb.profitability.')
    ->group(function () {
        Route::get('/', [WbProfitabilityCabinetsController::class, 'index'])->name('index');

        Route::post('/cabinets', [WbProfitabilityCabinetsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [WbProfitabilityCabinetsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [WbProfitabilityCabinetsController::class, 'destroy'])->name('cabinets.destroy');

        Route::get('/cabinets/{cabinet}', [WbProfitabilityReportController::class, 'show'])->name('cabinets.show');
        Route::post('/cabinets/{cabinet}/report', [WbProfitabilityReportController::class, 'store'])->name('cabinets.report.store');
        Route::get('/cabinets/{cabinet}/export', [WbProfitabilityReportController::class, 'export'])->name('cabinets.export');
    });

Route::middleware(['permission:subscriber wb ai cabinet analyzer'])
    ->prefix('wb/ai-cabinet-analyzer')
    ->name('subscriber.wb.ai-cabinet-analyzer.')
    ->group(function () {
        Route::get('/', [WbAiCabinetAnalyzerCabinetsController::class, 'index'])->name('index');

        Route::post('/cabinets', [WbAiCabinetAnalyzerCabinetsController::class, 'store'])->name('cabinets.store');
        Route::put('/cabinets/{cabinet}', [WbAiCabinetAnalyzerCabinetsController::class, 'update'])->name('cabinets.update');
        Route::delete('/cabinets/{cabinet}', [WbAiCabinetAnalyzerCabinetsController::class, 'destroy'])->name('cabinets.destroy');

        Route::get('/cabinets/{cabinet}', [WbAiCabinetAnalyzerWorkspaceController::class, 'show'])->name('cabinets.show');
        Route::post('/cabinets/{cabinet}/reports', [WbAiCabinetAnalyzerWorkspaceController::class, 'startReport'])->name('cabinets.reports.store');

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