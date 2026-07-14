<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Feedbacks;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresFeedbacksClientOwnership;
use App\Services\Subscriber\Wb\WbFeedbacksClientsService;
use App\Services\Subscriber\Wb\WbFeedbacksTemplatesService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreTemplateRequest;
use App\Http\Requests\Web\Subscriber\UpdateBotStatusRequest;
use App\Http\Requests\Web\Subscriber\UpdateTemplateRequest;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TemplatesController extends SubscriberToolController
{
    use EnsuresFeedbacksClientOwnership;

    public function __construct(
        private readonly WbFeedbacksTemplatesService $templatesService,
        private readonly WbFeedbacksClientsService $clientsService,
    ) {
    }

    public function index(Request $request, FeedbacksClients $client): Response
    {
        $this->ensureClientOwnership($client);

        $templatesResponse = $this->templatesService->showAll(
            $request->duplicate(['client_id' => $client->id])
        );
        $templatesPayload = $this->decodeApiResponse($templatesResponse);

        $botResponse = $this->clientsService->getBotStatus(
            $request->duplicate(['client_id' => $client->id])
        );
        $botPayload = $this->decodeApiResponse($botResponse);

        $templates = [];
        $templatesError = null;

        if (($templatesPayload['success'] ?? false) === true) {
            foreach ($templatesPayload['data'] ?? [] as $template) {
                $row = is_array($template) ? $template : $template->toArray();
                $rating = $row['rating'] ?? [1, 5];
                if (is_string($rating)) {
                    $rating = array_map('intval', explode('-', $rating));
                } elseif (is_array($rating)) {
                    $rating = array_map('intval', $rating);
                }
                $templates[] = [
                    'id' => $row['id'],
                    'text' => $row['text'],
                    'minRating' => (int) ($rating[0] ?? 1),
                    'maxRating' => (int) ($rating[1] ?? 5),
                ];
            }
        } else {
            $templatesError = $this->apiMessage($templatesPayload, 'Не удалось загрузить шаблоны');
        }

        return Inertia::render('Subscriber/Wb/Feedbacks/Templates/Index', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'templates' => $templates,
            'templatesError' => $templatesError,
            'botStatus' => ($botPayload['success'] ?? false) ? (int) ($botPayload['data'] ?? 0) : 0,
        ]);
    }

    public function store(StoreTemplateRequest $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->templatesService->store(
            $this->apiRequestWith($request, [
                'client_id' => $client->id,
                'text' => $request->validated('text'),
                'minRating' => $request->validated('minRating'),
                'maxRating' => $request->validated('maxRating'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'text' => $this->apiMessage($payload, 'Не удалось добавить шаблон'),
            ]);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.templates.index', $client)
            ->with('success', $this->apiMessage($payload, 'Шаблон добавлен'));
    }

    public function update(UpdateTemplateRequest $request, FeedbacksClients $client, FeedbacksTemplates $template): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        if ((int) $template->client_id !== (int) $client->id) {
            abort(404);
        }

        $response = $this->templatesService->update(
            $this->apiRequestWith($request, [
                'text' => $request->validated('text'),
                'minRating' => $request->validated('minRating'),
                'maxRating' => $request->validated('maxRating'),
            ]),
            (string) $template->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'text' => $this->apiMessage($payload, 'Не удалось обновить шаблон'),
            ]);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.templates.index', $client)
            ->with('success', $this->apiMessage($payload, 'Шаблон обновлён'));
    }

    public function destroy(FeedbacksClients $client, FeedbacksTemplates $template): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        if ((int) $template->client_id !== (int) $client->id) {
            abort(404);
        }

        $response = $this->templatesService->destroy((string) $template->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->withErrors(['text' => $this->apiMessage($payload, 'Не удалось удалить шаблон')]);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.templates.index', $client)
            ->with('success', $this->apiMessage($payload, 'Шаблон удалён'));
    }

    public function updateBotStatus(UpdateBotStatusRequest $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->clientsService->updateBotStatus(
            $this->apiRequestWith($request, [
                'client_id' => $client->id,
                'bot_status' => $request->validated('bot_status'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->withErrors(['bot_status' => $this->apiMessage($payload, 'Не удалось изменить статус автоответчика')]);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.templates.index', $client)
            ->with('success', $this->apiMessage($payload, 'Статус автоответчика изменён'));
    }
}