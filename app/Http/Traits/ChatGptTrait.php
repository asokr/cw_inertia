<?php

namespace App\Http\Traits;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Exception\ClientException;
use App\Models\Dashboard\chatGPT\GptLogsModel;

trait ChatGptTrait
{

    private $proxy = null;
    private $ver = 'v1';
    private $baseUri = 'https://api.openai.com/';
    private $model = 'gpt-4.1';

    private function getProxy()
    {
        if ($this->proxy === null) {
            $this->proxy = config('services.proxy');
        }
        return $this->proxy;
    }

    private function askToChatGpt($type, $promt)
    {
        $result = $this->askToChatGptWithMeta($type, $promt);

        return $result['success'] ? $result['text'] : false;
    }

    private function askToChatGptWithMeta($type, $promt): array
    {
        $httpClient = new Client([
            'base_uri' => $this->baseUri,
            'proxy' => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.gpt.key'),
                'Content-Type' => 'application/json',
            ],
        ]);


        try {
            $response = $httpClient->post($this->ver . '/chat/completions', [
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $type],
                        ['role' => 'user', 'content' => $promt],
                    ],
                ],
            ]);

            if (! in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
                return [
                    'success' => false,
                    'text' => null,
                    'model' => $this->model,
                    'usage' => null,
                ];
            }

            $raw = json_decode($response->getBody(), true);
            $text = (string) ($raw['choices'][0]['message']['content'] ?? '');
            $usage = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];

            GptLogsModel::create([
                'user_id' => Auth::id() ?? 1,
                'type' => $type,
                'promt' => $promt,
                'response' => $text,
                'model' => $this->model,
            ]);

            $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
            $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

            if ($inputTokens <= 0) {
                $inputTokens = $this->estimateTokensByText((string) $type . ' ' . (string) $promt);
            }

            if ($outputTokens <= 0) {
                $outputTokens = $this->estimateTokensByText($text);
            }

            return [
                'success' => $text !== '',
                'text' => $text,
                'model' => (string) ($raw['model'] ?? $this->model),
                'usage' => [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens)),
                    'prompt_tokens' => $inputTokens,
                    'completion_tokens' => $outputTokens,
                ],
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'text' => null,
                'model' => $this->model,
                'usage' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function estimateTokensByText(string $text): int
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($trimmed) / 4));
    }

    private function dialogToChatGpt($messages)
    {
        $httpClient = new Client([
            'base_uri' => $this->baseUri,
            'proxy' => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.gpt.key'),
                'Content-Type' => 'application/json',
            ],
        ]);


        $response = $httpClient->post($this->ver . '/chat/completions', [
            'json' => [
                'model' => $this->model, //gpt-4
                'messages' => $messages,
            ],
        ]);

        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
            $resp = json_decode($response->getBody(), true);
            $resp = $resp['choices'][0]['message']['content'];

            return $resp;
        } else {
            return false;
        }
    }

    private function visionToChatGpt($messages)
    {
        $httpClient = new Client([
            'base_uri' => $this->baseUri,
            'proxy' => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.gpt.key'),
                'Content-Type' => 'application/json',
            ],
        ]);

        $response = $httpClient->post($this->ver . '/chat/completions', [
            'json' => [
                'model' => "gpt-4-vision-preview",
                'messages' => $messages,
                "max_tokens" => 1300,
            ],
        ]);

        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
            $resp = json_decode($response->getBody(), true);
            $resp = $resp['choices'][0]['message']['content'];

            return $resp;
        } else {
            return false;
        }
    }
    private function dalleImageGenerate($params)
    {
        $httpClient = new Client([
            'base_uri' => $this->baseUri,
            'proxy' => $this->getProxy(),
            'headers' => [
                'Authorization' => 'Bearer ' . config('services.gpt.key'),
                'Content-Type' => 'application/json',
            ],
        ]);

        try {
            $response = $httpClient->post($this->ver . '/images/generations', [
                'json' => $params
            ]);

            if (in_array($response->getStatusCode(), [200, 204])) {
                $resp = json_decode($response->getBody(), true);
                $resp = $resp['data'][0];
                $this->saveGeneratedImage($params, $resp['url']);
                return $resp['url'];
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            return [
                'error' => json_decode($response->getBody(), true)
            ];
        }
    }

    private function saveGeneratedImage($params, $url)
    {
        $user_id = Auth::id();

        $file = file_get_contents($url);
        $url = explode('?', $url)[0];
        $filename = basename($url);
        $path = "wb/" . $user_id . "/" . $filename;

        Storage::disk('public')->put($path, $file);

        $data = '<p>Размер: ' . $params['size'];
        if (isset($params['quality']))
            $data .= '<br>Качество: ' . $params['quality'];

        $data .= '</p><p><img width="250px" src="/storage/' . $path . '" download></p>';

        GptLogsModel::create([
            'user_id' => $user_id,
            'type' => 'Генератор изображений',
            'promt' => $params['prompt'],
            'response' => $data,
            'model' => 'dall-e-3'
        ]);
    }
}
