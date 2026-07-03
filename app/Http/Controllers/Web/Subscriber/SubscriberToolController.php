<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Subscriber\Concerns\HandlesApiResponses;

abstract class SubscriberToolController extends Controller
{
    use HandlesApiResponses;
}