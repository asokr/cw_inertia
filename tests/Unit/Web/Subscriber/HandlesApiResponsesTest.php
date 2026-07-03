<?php

namespace Tests\Unit\Web\Subscriber;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Tests\TestCase;

class HandlesApiResponsesTest extends TestCase
{
    public function test_decodes_legacy_api_messages_for_flash(): void
    {
        $controller = new class extends SubscriberToolController
        {
            public function success(): RedirectResponse
            {
                $response = new HttpResponse(json_encode([
                    'success' => true,
                    'messages' => ['Кабинет сохранён'],
                ]));

                return $this->backWithApiSuccess($response);
            }

            public function error(): RedirectResponse
            {
                $response = new HttpResponse(json_encode([
                    'success' => false,
                    'messages' => ['Неверный API-ключ'],
                ]));

                return $this->backWithApiError($response);
            }
        };

        $success = $controller->success();
        $error = $controller->error();

        $this->assertSame('Кабинет сохранён', $success->getSession()->get('success'));
        $this->assertSame('Неверный API-ключ', $error->getSession()->get('error'));
    }
}