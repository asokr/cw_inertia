<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\CouponController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\Api\Admin\WidgetsController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\Admin\PaymentsController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\Admin\SentEmailController;
use App\Http\Controllers\Api\Admin\user\UserController;
use App\Http\Controllers\Api\Subscriber\Ai\AiMediaController;
use App\Http\Controllers\Api\Subscriber\Ai\GeminiController;
use App\Http\Controllers\Api\Subscriber\Ai\GrokVideoController;
use App\Http\Controllers\Api\Admin\PermissionsController;
use App\Http\Controllers\Api\Subscriber\PaymentController;
use App\Http\Controllers\Api\FullfilmentSettingsController;
use App\Http\Controllers\Api\Subscriber\FullfilmentController;
use App\Http\Controllers\Api\Admin\subscribers\PlansController;
use App\Http\Controllers\Api\Subscriber\User\ProfileController;
use App\Http\Controllers\Api\Admin\wb\WbApiUsageStatsController;
use App\Http\Controllers\Api\Subscriber\User\UserPlansController;
use App\Http\Controllers\Api\Subscriber\User\ExtraLimitsController;
use App\Http\Controllers\Api\Admin\subscribers\SubscribersController;
use App\Http\Controllers\Api\Admin\subscribers\SubscriptionsController;
use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksController;
use App\Http\Controllers\Api\Admin\services\repricer\AdminRepricerController;
use App\Http\Controllers\Api\Admin\services\ai\AdminAiMediaController;
use App\Http\Controllers\Api\Admin\services\ai\AdminAiMarketplaceLogsController;
use App\Http\Controllers\Api\Admin\services\ai\AdminAiCostsController;
use App\Http\Controllers\Api\Admin\Blog\PostController;
use App\Http\Controllers\Api\Admin\Blog\TagController;
use App\Http\Controllers\Api\Admin\Blog\CategoryController;
use App\Http\Controllers\Api\Admin\Blog\BlogMediaController;
use App\Http\Controllers\Api\Subscriber\Blog\BlogPostController as SubscriberBlogPostController;
use App\Http\Controllers\Api\Subscriber\Blog\SitemapController as SubscriberBlogSitemapController;
use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksStatController;
use App\Http\Controllers\Api\Subscriber\Wb\RePricer\RepricerStocksController;
use App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc\CabinetsController as OzPriceCalcCabinetsController;
use App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc\FboController as OzPriceCalcFboController;
use App\Http\Controllers\Api\Admin\services\feedbacks\AdminFeedbacksController;
use App\Http\Controllers\Api\Admin\services\aicabinetanalyzer\AdminAiCabinetAnalyzerController;
use App\Http\Controllers\Api\Subscriber\Wb\RePricer\RepricerCabinetsController;
use App\Http\Controllers\Api\Subscriber\Wb\RePricer\RepricerSettingsController;
use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksClientsController;
use App\Http\Controllers\Api\Subscriber\Wb\Profitability\ProfitabilityController;
use App\Http\Controllers\Api\Subscriber\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysesController;
use App\Http\Controllers\Api\Subscriber\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReportsController;
use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksTemplatesController;
use App\Http\Controllers\Api\Subscriber\Wb\RePricer\RepricerCompetitorsController;
use App\Http\Controllers\Api\Subscriber\Wb\PromoCalculator\PromoCalculatorController;
use App\Http\Controllers\Api\Dashboard\User\ProfileController as DashboardUserProfile;
use App\Http\Controllers\Api\Subscriber\CouponController as SubscriberCouponController;
use App\Http\Controllers\Api\Subscriber\Wb\PriceCalculation\PriceCalcCabinetsController;
use App\Http\Controllers\Api\Subscriber\Wb\PriceCalculation\PriceCalculationV3Controller;
use App\Http\Controllers\Api\Subscriber\Wb\Profitability\ProfitabilityCabinetsController;
use App\Http\Controllers\Api\Subscriber\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinetsController;
use App\Http\Controllers\Api\Admin\subscribers\ExtraLimitsController as AdminExtraLimitsController;
use App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks\FeedbacksController as OzFeedbacksController;
use App\Http\Controllers\Api\Subscriber\User\SubscriptionsController as UserSubscriptionsController;
use App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks\FeedbacksClientsController as OzFeedbacksClientsController;
use App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc\FbsController as OzPriceCalcFbsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// Route::get('test', [FeedbacksTemplatesController::class, "test"]);

// Пополнение через Юкасса
Route::post('/payments/yoo/callback', [PaymentController::class, "callback"])->name('payment.callback');
// Route::get('test', [AuthController::class, "test"]);
Route::post('send-message', [AuthController::class, "sendMessage"]);
Route::post('services/wb-search/webhook', [RepricerCompetitorsController::class, 'webhook']);

Route::middleware(['auth:api'])->group(function () {
    // Роуты для клиента NUXT
    Route::get('client/me', [AuthController::class, "nuxtUserInfo"]);
});


Route::post('register', [AuthController::class, "register"]);
Route::post('login', [AuthController::class, "login"]);
Route::post('auth/vk', [\App\Http\Controllers\Api\VkAuthController::class, 'login']);
Route::post('auth/yandex', [\App\Http\Controllers\Api\YandexAuthController::class, 'login']);

Route::get('get-permissions', [AuthController::class, "getPermissions"]);
Route::post('forgot-password', [ForgotPasswordController::class, "sendResetLinkMail"]);
Route::post('reset-password', [ResetPasswordController::class, "reset"]);
Route::get('email/verify/{id}', [VerificationController::class, "verify"])->name('api.verification.verify');
Route::post('email/resend', [VerificationController::class, "resend"])->name('api.verification.resend');
Route::post('check-coupon', [SubscriberCouponController::class, "checkCoupon"]);


Route::get('fullfilment', [FullfilmentController::class, "index"]);

Route::prefix('subscriber/blog')->group(function () {
    Route::get('posts', [SubscriberBlogPostController::class, 'index']);
    Route::get('posts/{slug}', [SubscriberBlogPostController::class, 'show']);
    Route::post('posts/{slug}/view', [SubscriberBlogPostController::class, 'incrementView'])->middleware('throttle:5,1');
    Route::get('sitemap', [SubscriberBlogSitemapController::class, 'index']);
});


Route::middleware(['auth:api', 'verified', 'role:Подписчик'])->group(function () {
    Route::post('subscriber/user/profile', [ProfileController::class, "update"]);


    // Планы
    Route::post('subscriber/user/plans/', [UserPlansController::class, "availablePlans"]); //Планы по типу
    Route::get('subscriber/user/available-plans', [ProfileController::class, "availablePlans"]); // Все планы в профиль
    Route::post('subscriber/user/remaining-limits', [ProfileController::class, "remainingLimits"]);
    Route::post('subscriber/user/tour-seen', [ProfileController::class, "tourSeen"]);

    // Подписки
    Route::get('subscriber/user/subscriptions', [UserSubscriptionsController::class, "index"]);
    Route::post('subscriber/user/unsubscribe', [UserSubscriptionsController::class, "unsubscribe"]);
    Route::post('subscriber/user/resubscribe', [UserSubscriptionsController::class, "resubscribe"]);
    Route::post('subscriber/user/change-plan', [UserSubscriptionsController::class, "changePlan"]);

    // Дополнительные лимиты
    Route::get('subscriber/user/extra-limits/all', [ExtraLimitsController::class, "index"]);
    Route::get('subscriber/user/extra-limits', [ExtraLimitsController::class, "userExtraLimits"]);
    Route::post('subscriber/user/extra-limits', [ExtraLimitsController::class, "buyExtraLimits"]);



    // Работа со средствами клиентов
    Route::get('payments/history', [PaymentController::class, "history"]);

    // Пополнение через Юкасса
    Route::post('/payments/yoo/create', [PaymentController::class, "create"])->name('payment.create');

    //Просчёт акций WB
    Route::middleware(['permission:subscriber wb promo calculator'])->group(function () {
        Route::post('subscriber/wb/promo-calculator/upload', [PromoCalculatorController::class, "upload"]);
        Route::post('subscriber/wb/promo-calculator/calc', [PromoCalculatorController::class, "calculate"]);
        Route::post('subscriber/wb/promo-calculator/xlsx', [PromoCalculatorController::class, "getPromoXlsx"]);
        Route::post('subscriber/wb/promo-calculator/repricer', [PromoCalculatorController::class, "sendToRepricer"]);
    });

    // Ответы на отзывы WB
    Route::middleware(['permission:subscriber wb feedbacks'])->prefix('subscriber/wb/feedbacks')->group(function () {

        Route::post('list', [FeedbacksController::class, "getFeedbacksList"]);
        Route::post('send', [FeedbacksController::class, "sendFeedbackToWb"]);

        // Статус автоответов с помощью шаблонов
        Route::get('client/bot-status', [FeedbacksClientsController::class, "getBotStatus"]);
        Route::post('client/bot-status', [FeedbacksClientsController::class, "updateBotStatus"]);

        Route::resource('client', FeedbacksClientsController::class);

        // Статус автоответов с помощью ИИ
        Route::get('client/ai/data', [FeedbacksClientsController::class, "getAiData"]);
        Route::post('client/ai/data', [FeedbacksClientsController::class, "updateAiData"]);

        Route::post('templates/all', [FeedbacksTemplatesController::class, "showAll"]);
        Route::resource('templates', FeedbacksTemplatesController::class)->except(['index']);

        // Статистика отзывов
        Route::get('widget/stats', [FeedbacksStatController::class, 'stats']);
        Route::get('widget/answered', [FeedbacksStatController::class, 'answeredReviews']);

        Route::get('stats/product', [FeedbacksStatController::class, 'productStatistics']);
    });

    // Ответы на отзывы Ozon
    Route::middleware(['permission:subscriber oz feedbacks'])->group(function () {
        Route::post('subscriber/oz/feedbacks/list', [OzFeedbacksController::class, "getFeedbacksList"]);
        Route::post('subscriber/oz/feedbacks/send', [OzFeedbacksController::class, "answerFeedback"]);
        Route::post('subscriber/oz/feedbacks/count', [OzFeedbacksController::class, "countFeedbacks"]);

        // Статус автоответов с помощью ИИ
        Route::get('subscriber/oz/feedbacks/cabinets/ai/data/{cabinet_id}', [OzFeedbacksClientsController::class, "getAiData"]);
        Route::post('subscriber/oz/feedbacks/cabinets/ai/data', [OzFeedbacksClientsController::class, "updateAiData"]);

        // Статус автоответов с помощью шаблонов
        Route::get('subscriber/oz/feedbacks/cabinets/bot-status', [OzFeedbacksClientsController::class, "getBotStatus"]);
        Route::post('subscriber/oz/feedbacks/cabinets/bot-status', [OzFeedbacksClientsController::class, "updateBotStatus"]);

        Route::resource('subscriber/oz/feedbacks/cabinets', OzFeedbacksClientsController::class);

        // Route::post('subscriber/wb/feedbacks/templates/all', [FeedbacksTemplatesController::class, "showAll"]);
        // Route::resource('subscriber/wb/feedbacks/templates', FeedbacksTemplatesController::class)->except(['index']);
    });

    // Рассчёт рентабельности Ozon
    Route::middleware(['permission:subscriber oz price calc'])->group(function () {
        Route::apiResource('subscriber/oz/price-calc/cabinets', OzPriceCalcCabinetsController::class)
            ->names('oz.price-calc.cabinets');
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbo', [OzPriceCalcFboController::class, 'index']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbo/status', [OzPriceCalcFboController::class, 'status']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbo/calculate-status', [OzPriceCalcFboController::class, 'calculateStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/sync', [OzPriceCalcFboController::class, 'sync']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/export', [OzPriceCalcFboController::class, 'export']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/export-status', [OzPriceCalcFboController::class, 'exportStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/import', [OzPriceCalcFboController::class, 'import']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/import-status', [OzPriceCalcFboController::class, 'importStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/calculate', [OzPriceCalcFboController::class, 'calculate']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs', [OzPriceCalcFbsController::class, 'index']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/status', [OzPriceCalcFbsController::class, 'status']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/calculate-status', [OzPriceCalcFbsController::class, 'calculateStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/sync', [OzPriceCalcFbsController::class, 'sync']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/export', [OzPriceCalcFbsController::class, 'export']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/export-status', [OzPriceCalcFbsController::class, 'exportStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/import', [OzPriceCalcFbsController::class, 'import']);
        Route::get('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/import-status', [OzPriceCalcFbsController::class, 'importStatus']);
        Route::post('subscriber/oz/price-calc/cabinets/{cabinetId}/fbs/calculate', [OzPriceCalcFbsController::class, 'calculate']);
    });


    // ИИ
    Route::middleware([
        'permission:subscriber oz feedbacks|subscriber wb feedbacks|subscriber wb ai',
    ])->group(function () {

        // Текстовые задачи маркетплейса: GeminiController.
        Route::post('subscriber/ai/marketplace', [GeminiController::class, 'marketplace']);
        Route::get('subscriber/ai/media/{path}', [AiMediaController::class, 'show'])->where('path', '.*');
        Route::post('subscriber/ai/image/start', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageController::class, 'start']);
        Route::post('subscriber/ai/image-gen', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageController::class, 'start']);
        Route::get('subscriber/ai/image/generations', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageGenerationController::class, 'index']);
        Route::post('subscriber/ai/image/generations', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageGenerationController::class, 'store']);
        Route::get('subscriber/ai/image/generations/{id}', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageGenerationController::class, 'show']);
        Route::delete('subscriber/ai/image/generations/{id}', [\App\Http\Controllers\Api\Subscriber\Ai\AiImageGenerationController::class, 'destroy']);
        Route::post('subscriber/ai/video/start', [GrokVideoController::class, 'start']);
        Route::post('subscriber/ai/video/reference/start', [GrokVideoController::class, 'referenceStart']);
        Route::get('subscriber/ai/video/status/{request_id}', [GrokVideoController::class, 'status'])->withoutMiddleware('throttle:api');
        Route::get('subscriber/ai/video/generations', [\App\Http\Controllers\Api\Subscriber\Ai\AiVideoGenerationController::class, 'index']);
        Route::post('subscriber/ai/video/generations', [\App\Http\Controllers\Api\Subscriber\Ai\AiVideoGenerationController::class, 'store']);
        Route::get('subscriber/ai/video/generations/{id}', [\App\Http\Controllers\Api\Subscriber\Ai\AiVideoGenerationController::class, 'show']);
        Route::delete('subscriber/ai/video/generations/{id}', [\App\Http\Controllers\Api\Subscriber\Ai\AiVideoGenerationController::class, 'destroy']);
    });


    //Ценообразование V3
    Route::middleware(['permission:subscriber wb price calculator'])->group(function () {
        Route::resource('subscriber/wb/price-calculation/cabinets', PriceCalcCabinetsController::class);
        Route::get('subscriber/wb/price-calculation-v3/cards/{cabinet_id}', [PriceCalculationV3Controller::class, 'index']);
        Route::post('subscriber/wb/price-calculation-v3/cards/sync', [PriceCalculationV3Controller::class, 'syncCards']);
        Route::post('subscriber/wb/price-calculation-v3/import-volume', [PriceCalculationV3Controller::class, 'importVolumes']);
        Route::get('subscriber/wb/price-calculation-v3/settings/{cabinet_id}', [PriceCalculationV3Controller::class, 'getSettings']);
        Route::post('subscriber/wb/price-calculation-v3/settings', [PriceCalculationV3Controller::class, 'saveSettings']);
        Route::post('subscriber/wb/price-calculation-v3/calculate', [PriceCalculationV3Controller::class, 'calculate']);
        Route::post('subscriber/wb/price-calculation-v3/export-excel', [PriceCalculationV3Controller::class, 'exportExcel']);
        Route::post('subscriber/wb/price-calculation-v3/import-excel', [PriceCalculationV3Controller::class, 'importExcel']);
    });


    //Репрайсер
    Route::middleware(['permission:subscriber wb repricer'])->group(function () {

        Route::post('subscriber/wb/repricer/cabinets/logs', [RepricerCabinetsController::class, "getLogs"]);
        Route::resource('subscriber/wb/repricer/cabinets', RepricerCabinetsController::class);

        // От остатков
        Route::post('subscriber/wb/repricer/stocks/mass/', [RepricerStocksController::class, "getDataFromWb"]);
        Route::put('subscriber/wb/repricer/stocks/mass/', [RepricerStocksController::class, "bulkUpdate"]);
        Route::delete('subscriber/wb/repricer/stocks/mass/', [RepricerStocksController::class, "bulkDestroy"]);
        Route::post('subscriber/wb/repricer/stocks/sizes/', [RepricerStocksController::class, "getSizesFromWb"]);
        Route::post('subscriber/wb/repricer/stocks/{stock}/reset', [RepricerStocksController::class, 'reset']);

        Route::resource('subscriber/wb/repricer/stocks', RepricerStocksController::class, ['except' => ['index']]);

        // По времени
        Route::post('subscriber/wb/repricer/mass/', [RepricerSettingsController::class, "getDataFromWb"]);
        Route::put('subscriber/wb/repricer/mass/', [RepricerSettingsController::class, "bulkUpdate"]);
        Route::delete('subscriber/wb/repricer/mass/', [RepricerSettingsController::class, "bulkDestroy"]);

        // По конкурентам
        Route::get('subscriber/wb/repricer/competitors/search', [RepricerCompetitorsController::class, 'search']);
        Route::get('subscriber/wb/repricer/competitors/search/status', [RepricerCompetitorsController::class, 'searchStatus']);
        Route::post('subscriber/wb/repricer/competitors/info', [RepricerCompetitorsController::class, 'bulkCompetitors']);
        Route::patch('subscriber/wb/repricer/competitors/{competitor}/status', [RepricerCompetitorsController::class, 'toggleStatus']);
        Route::post('subscriber/wb/repricer/competitors/nm-data', [RepricerCompetitorsController::class, "getNmdata"]);
        Route::apiResource('subscriber/wb/repricer/competitors', RepricerCompetitorsController::class);

        Route::resource('subscriber/wb/repricer', RepricerSettingsController::class, ['except' => ['index']]);
    });

    //Рентабельность
    Route::middleware(['permission:subscriber wb profitability'])->group(function () {

        Route::resource('subscriber/wb/profitability/cabinets', ProfitabilityCabinetsController::class);

        Route::get('subscriber/wb/profitability/{cabinet}', [ProfitabilityController::class, 'show']);
        Route::get('subscriber/wb/profitability/status/{cabinet}', [ProfitabilityController::class, 'status'])->withoutMiddleware('throttle:api');

        Route::get('subscriber/wb/profitability/{cabinet}/export', [ProfitabilityController::class, 'exportXlsx']);
        Route::get('subscriber/wb/profitability/{cabinet}/widget', [ProfitabilityController::class, 'widget']);

        Route::resource('subscriber/wb/profitability', ProfitabilityController::class);
    });

    // AiCabinet Analyzer
    Route::middleware(['permission:subscriber wb ai cabinet analyzer'])->group(function () {
        Route::apiResource('subscriber/wb/ai-cabinet-analyzer/cabinets', AiCabinetAnalyzerCabinetsController::class);

        Route::post('subscriber/wb/ai-cabinet-analyzer/reports/start', [AiCabinetAnalyzerReportsController::class, 'start']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/latest/{cabinet_id}', [AiCabinetAnalyzerReportsController::class, 'latestByCabinet']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures', [AiCabinetAnalyzerReportsController::class, 'nomenclatures']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures/search', [AiCabinetAnalyzerReportsController::class, 'searchNomenclatures']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/{report}', [AiCabinetAnalyzerReportsController::class, 'show']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/{report}/status', [AiCabinetAnalyzerReportsController::class, 'status'])->withoutMiddleware('throttle:api');

        Route::get('subscriber/wb/ai-cabinet-analyzer/ai-templates', [AiCabinetAnalyzerAiAnalysesController::class, 'templates']);
        Route::post('subscriber/wb/ai-cabinet-analyzer/ai-analyses/start', [AiCabinetAnalyzerAiAnalysesController::class, 'start']);
        Route::post('subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}/regenerate', [AiCabinetAnalyzerAiAnalysesController::class, 'regenerate']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/reports/{report}/ai-analyses', [AiCabinetAnalyzerAiAnalysesController::class, 'indexByReport']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}', [AiCabinetAnalyzerAiAnalysesController::class, 'show']);
        Route::get('subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}/download', [AiCabinetAnalyzerAiAnalysesController::class, 'download']);
    });

});



Route::group(['middleware' => ['auth:api', 'verified']], function () {

    // Получим данные авторизованного юзера
    Route::get('get-current-user', [AuthController::class, "getUserDetails"]);

    // Profile
    // Route::get('dashboard/user/profile/', [ProfileController::class, "index"]);
    // Route::post('dashboard/user/profile/', [ProfileController::class, "update"]);
});

// ADMIN
// Super-admin
// Route::middleware(['auth:api', 'verified', 'role:Супер-Админ'])->group(function () {
Route::middleware(['auth:api', 'verified', 'role:Супер-Админ|super-admin'])->group(function () {

    Route::get('admin/sent-emails', [SentEmailController::class, 'index'])
        ->name('sent-emails.index');

    // Просмотр одного письма по ID
    Route::get('admin/sent-emails/{sentEmail}', [SentEmailController::class, 'show'])
        ->name('sent-emails.show');


    // Услуги
    // Отзывы
    Route::get('admin/services/feedbacks/cabinets', [AdminFeedbacksController::class, "cabinetsList"]);
    Route::get('admin/services/feedbacks/cabinets/{id}/stats', [AdminFeedbacksController::class, "cabinetStats"]);
    Route::get('admin/services/feedbacks/cabinets/{id}/answered', [AdminFeedbacksController::class, "cabinetAnsweredReviews"]);
    Route::post('admin/services/feedbacks/cabinets/{id}/recalculate', [AdminFeedbacksController::class, "recalculateStats"]);
    Route::get('admin/services/feedbacks/ai-answers', [AdminFeedbacksController::class, "aiAnswerLogs"]);

    // Репрайсер
    Route::post('admin/services/repricer/logs', [AdminRepricerController::class, "getLogs"]);
    Route::post('admin/services/repricer/nmids', [AdminRepricerController::class, "getNmIds"]);
    Route::post('admin/services/repricer/cabinets', [AdminRepricerController::class, "getCabinets"]);

    // AiCabinet Analyzer
    Route::get('admin/services/ai-cabinet-analyzer/cabinets', [AdminAiCabinetAnalyzerController::class, "cabinetsList"]);
    Route::get('admin/services/ai-cabinet-analyzer/templates', [AdminAiCabinetAnalyzerController::class, "templatesList"]);
    Route::post('admin/services/ai-cabinet-analyzer/templates', [AdminAiCabinetAnalyzerController::class, "storeTemplate"]);
    Route::put('admin/services/ai-cabinet-analyzer/templates/{id}', [AdminAiCabinetAnalyzerController::class, "updateTemplate"]);
    Route::delete('admin/services/ai-cabinet-analyzer/templates/{id}', [AdminAiCabinetAnalyzerController::class, "destroyTemplate"]);

    // ИИ запросы
    Route::get('admin/services/ai/marketplace-logs', [AdminAiMarketplaceLogsController::class, 'index']);
    Route::get('admin/services/ai/media/{path}', [AdminAiMediaController::class, 'show'])->where('path', '.*');
    Route::get('admin/ai/costs/today', [AdminAiCostsController::class, 'today']);
    Route::get('admin/ai/costs/archive', [AdminAiCostsController::class, 'archive']);

    // Планы для подписчиков
    Route::post('admin/subscribers/plans/status', [PlansController::class, "status"]);
    Route::apiResource('admin/subscribers/plans', PlansController::class)->except(['destroy']);

    // Экстра-лимиты
    Route::apiResource('admin/subscribers/extra-limits', AdminExtraLimitsController::class)->except(['show']);

    // Подписчики
    Route::post('admin/subscribers/search', [SubscribersController::class, "findSubscriber"]);
    Route::post('admin/subscribers/list', [SubscribersController::class, "listSubscribers"]);
    Route::post('admin/subscribers/{subscriber}/deposit', [SubscribersController::class, 'deposit']);
    Route::post('admin/subscribers/{subscriber}/withdraw', [SubscribersController::class, 'withdraw']);
    Route::post('admin/subscribers/{subscriber}/transactions/{transaction}/reverse', [SubscribersController::class, 'reverseTransaction']);
    Route::apiResource('admin/subscribers', SubscribersController::class)->except(['destroy', 'index']);

    // Оплаты
    Route::post('admin/subscribers/payments', [PaymentsController::class, "payments"]);

    // Подписка
    Route::post('admin/subscribers/subscription/status', [SubscriptionsController::class, "changeStatus"]);
    Route::post('admin/subscribers/subscription/renew', [SubscriptionsController::class, "renewSubcription"]);
    Route::delete('admin/subscribers/subscription/{id}', [SubscriptionsController::class, "destroy"]);

    // Купоны
    Route::apiResource('admin/coupons', CouponController::class)->except(['show']);

    // Виджеты
    Route::post('admin/widgets/last-subscriptions', [WidgetsController::class, "lastSubscriptions"]);
    Route::post('admin/widgets/last-registered', [WidgetsController::class, "lastRegistered"]);

    // ADMIN Permissions
    Route::post('admin/get-user-permissions', [PermissionsController::class, "getUserPermissions"]);
    Route::post('admin/get-permissions', [PermissionsController::class, "getPermissions"]);
    Route::post('admin/set-user-permissions', [PermissionsController::class, "setUserPermissions"]);
    Route::post('admin/get-roles', [RoleController::class, "index"]);
    Route::post('admin/create-role', [RoleController::class, "create"]);
    Route::post('admin/edit-role', [RoleController::class, "update"]);
    Route::post('admin/delete-role', [RoleController::class, "delete"]);
    Route::post('admin/set-user-roles', [RoleController::class, "setUserRoles"]);
    Route::post('admin/get-users-by-role', [RoleController::class, "getUsersByRole"]);



    // Users
    Route::get('dashboard/user/profile/', [DashboardUserProfile::class, "index"]);
    Route::post('dashboard/user/profile/', [DashboardUserProfile::class, "update"]);
    Route::post('admin/users', [UserController::class, "index"]);
    Route::post('admin/users/edit', [UserController::class, "edit"]);
    Route::post('admin/users/delete', [UserController::class, "destroy"]);
    Route::post('admin/users/findUser', [UserController::class, "findUser"]);
    Route::get('admin/users/last-registered-users', [UserController::class, "getLastRegisteredUsers"]);

    // Отзывы WB список клиентов
    // Route::get('admin/wb/feedbacks', [AdminFeedbacksController::class, "index"]);
    // Route::post('admin/wb/feedbacks/del', [AdminFeedbacksController::class, "destroy"]);


    Route::get('admin/wb/api-usage-stats', [WbApiUsageStatsController::class, 'index']);
    Route::get('admin/wb/api-usage-stats/{sellerId}/logs', [WbApiUsageStatsController::class, 'requestLogs']);
});

Route::middleware(['auth:api', 'verified'])->prefix('admin/blog')->group(function () {
    Route::middleware(['permission:blog.view'])->group(function () {
        Route::get('posts', [PostController::class, 'index']);
        Route::get('posts/{post}', [PostController::class, 'show']);
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('tags', [TagController::class, 'index']);
    });

    Route::middleware(['permission:blog.create'])->group(function () {
        Route::post('posts', [PostController::class, 'store']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::post('tags', [TagController::class, 'store']);
        Route::post('upload-image', [BlogMediaController::class, 'uploadImage']);
    });

    Route::middleware(['permission:blog.update'])->group(function () {
        Route::put('posts/{post}', [PostController::class, 'update']);
        Route::patch('posts/{post}', [PostController::class, 'update']);
        Route::post('posts/{id}/increment-view', [PostController::class, 'incrementView']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::patch('categories/{category}', [CategoryController::class, 'update']);
        Route::put('tags/{tag}', [TagController::class, 'update']);
        Route::patch('tags/{tag}', [TagController::class, 'update']);
    });

    Route::middleware(['permission:blog.delete'])->group(function () {
        Route::delete('posts/{post}', [PostController::class, 'destroy']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
        Route::delete('tags/{tag}', [TagController::class, 'destroy']);
    });
});


// Установка цен для фулфилмента
Route::middleware(['auth:api', 'verified', 'permission:manager fullfilment'])->group(function () {

    Route::resource('/admin/fullfilment', FullfilmentSettingsController::class)->except('store', 'destroy');
});


