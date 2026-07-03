<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResendVerificationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:190'],
            ]);

            $user = User::where('email', $request->input('email'))->first();

            if (! $user) {
                return back()->with('error', 'Пользователь с таким email не найден');
            }
        }

        if ($user->hasVerifiedEmail()) {
            return back()->with('success', 'Ваш Email уже подтверждён');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('success', 'Ссылка для подтверждения отправлена на вашу почту');
    }
}