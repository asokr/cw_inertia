<?php

namespace App\Exceptions;

use App\Support\HomeRedirect;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e): Response
    {
        $response = parent::render($request, $e);

        if ($request->expectsJson() || $request->is('api/*')) {
            return $response;
        }

        return match ($response->getStatusCode()) {
            403 => $this->renderForbidden($request),
            404 => $this->renderNotFound($request),
            default => $response,
        };
    }

    private function renderNotFound(Request $request): Response
    {
        return $this->renderErrorPage($request, 'Errors/NotFound', 404);
    }

    private function renderForbidden(Request $request): Response
    {
        return $this->renderErrorPage($request, 'Errors/Forbidden', 403);
    }

    private function renderErrorPage(Request $request, string $component, int $status): Response
    {
        $user = $request->user();

        return Inertia::render($component, [
            'authenticated' => (bool) $user,
            'userName' => $user?->name,
            'homeUrl' => HomeRedirect::forUser($user),
            'isSubscriber' => (bool) $user?->hasRole('Подписчик'),
            'isAdmin' => $user ? HomeRedirect::isAdmin($user) : false,
            'canAccessPanel' => $user ? HomeRedirect::canAccessPanel($user) : false,
        ])
            ->toResponse($request)
            ->setStatusCode($status);
    }
}