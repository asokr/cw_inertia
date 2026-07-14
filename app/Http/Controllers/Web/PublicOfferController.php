<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class PublicOfferController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('PublicOffer/Index');
    }
}