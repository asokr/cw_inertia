<?php

namespace App\Providers;

use App\Mail\EmailVerification;
use App\Services\PaymentService;
use App\Services\Gemini\GeminiApiClient;
use App\Services\Grok\GrokVideoApiClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService();
        });

        $this->app->bind(GeminiApiClient::class, function ($app) {
            return new GeminiApiClient(
                proModel: (string) config('services.gemini.pro_model', 'gemini-3.1-pro-preview'),
                imageModel: (string) config('services.gemini.image_model', 'gemini-3.1-flash-image-preview'),
                apiKey: config('services.gemini.api_key'),
                baseUrl: config('services.gemini.base_url'),
                apiVersion: config('services.gemini.api_version', 'v1beta')
            );
        });

        $this->app->bind(GrokVideoApiClient::class, function ($app) {
            return new GrokVideoApiClient(
                apiKey: config('services.grok.api_key'),
                baseUrl: config('services.grok.base_url'),
                videoModel: config('services.grok.video_model', 'grok-imagine-video')
            );
        });

        // $this->app->bind(SubscriptionService::class, function ($app) {
        //     return new SubscriptionService();
        // });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Override the email notification for verifying email
        VerifyEmail::toMailUsing(function ($notifiable) {
            $verifyUrl = URL::temporarySignedRoute(
                'verification.verify',
                \Illuminate\Support\Carbon::now()->addMinutes(120),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
            return new EmailVerification($verifyUrl, $notifiable);
        });


        Schema::defaultStringLength(191);
    }
}
