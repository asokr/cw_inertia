<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;

class VerificationController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:api')->except(['verify', 'resend']);
    }

    /**
     * Verify email
     *
     * @param $user_id
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function verify($user_id, Request $request)
    {
        if (!$request->hasValidSignature()) {
            return response()->json(['success' => false, "messages" => ["Ссылка для подтверждения Email не верная, или просрочена."]], 200);
        }

        $user = User::findOrFail($user_id);

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json(['success' => true, "messages" => ["Ваш Email подтверждён"]], 200);
    }

    /**
     * Resend email verification link
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function resend(Request $request)
    {
        $user = User::find($request->id);
        if ($user->hasVerifiedEmail()) {
            return response()->json(['success' => true, "messages" => ["Ваш Email уже подтверждён"]], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['success' => true, "messages" => ["Ссылка для подтверждения Email, отправлена к вам на почту"]], 200);

    }
}
