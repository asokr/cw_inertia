<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\HomeRedirect;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Home/Index', [
            'authenticated' => (bool) $user,
            'userName' => $user?->name,
            'homeUrl' => HomeRedirect::forUser($user),
            'cabinetLabel' => HomeRedirect::cabinetLabel($user),
            'isSubscriber' => (bool) $user?->hasRole('Подписчик'),
        ]);
    }
}