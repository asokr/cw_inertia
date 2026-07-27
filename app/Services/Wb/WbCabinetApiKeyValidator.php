<?php

namespace App\Services\Wb;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

class WbCabinetApiKeyValidator
{
    public const PING_URL = 'https://common-api.wildberries.ru/ping';

    /**
     * @return array{
     *     valid: bool,
     *     message: ?string,
     *     http_code: int,
     *     permission_warnings: list<string>
     * }
     */
    public function validate(string $apiKey, bool $probePermissions = true): array
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            return [
                'valid' => false,
                'message' => 'Укажите API-ключ Wildberries.',
                'http_code' => 0,
                'permission_warnings' => [],
            ];
        }

        $ping = $this->ping($apiKey);

        if (! $ping['valid']) {
            return [
                'valid' => false,
                'message' => $ping['message'],
                'http_code' => $ping['http_code'],
                'permission_warnings' => [],
            ];
        }

        $warnings = $probePermissions
            ? $this->probePermissions($apiKey)
            : [];

        return [
            'valid' => true,
            'message' => null,
            'http_code' => $ping['http_code'],
            'permission_warnings' => $warnings,
        ];
    }

    /**
     * @return array{valid: bool, message: ?string, http_code: int}
     */
    public function ping(string $apiKey): array
    {
        try {
            $client = new Client([
                'http_errors' => false,
                'timeout' => 15,
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $apiKey,
                ],
            ]);

            $response = $client->get(self::PING_URL);
            $code = $response->getStatusCode();
            $body = (string) $response->getBody();
            $json = json_decode($body, true);

            if ($code === 401 || $code === 403) {
                return [
                    'valid' => false,
                    'message' => 'API-ключ недействителен или не имеет доступа. Проверьте ключ в личном кабинете Wildberries.',
                    'http_code' => $code,
                ];
            }

            if ($code < 200 || $code >= 300) {
                return [
                    'valid' => false,
                    'message' => 'Не удалось проверить API-ключ (код ответа '.$code.'). Попробуйте позже.',
                    'http_code' => $code,
                ];
            }

            $status = is_array($json) ? (string) Arr::get($json, 'Status', '') : '';
            if (strtoupper($status) !== 'OK') {
                return [
                    'valid' => false,
                    'message' => 'API-ключ не прошёл проверку Wildberries. Убедитесь, что ключ корректный и активный.',
                    'http_code' => $code,
                ];
            }

            return [
                'valid' => true,
                'message' => null,
                'http_code' => $code,
            ];
        } catch (GuzzleException $e) {
            return [
                'valid' => false,
                'message' => 'Не удалось связаться с API Wildberries для проверки ключа. Попробуйте позже.',
                'http_code' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'message' => 'Ошибка при проверке API-ключа. Попробуйте позже.',
                'http_code' => 0,
            ];
        }
    }

    /**
     * Best-effort permission probes. Do not block cabinet save.
     *
     * @return list<string>
     */
    private function probePermissions(string $apiKey): array
    {
        $warnings = [];
        $client = new Client([
            'http_errors' => false,
            'timeout' => 10,
            'headers' => [
                'accept' => 'application/json',
                'Authorization' => $apiKey,
            ],
        ]);

        $probes = [
            [
                'label' => 'Отзывы (Feedbacks API)',
                'url' => 'https://feedbacks-api.wildberries.ru/ping',
            ],
            [
                'label' => 'Контент (Content API)',
                'url' => 'https://content-api.wildberries.ru/ping',
            ],
            [
                'label' => 'Статистика (Statistics API)',
                'url' => 'https://statistics-api.wildberries.ru/ping',
            ],
            [
                'label' => 'Цены и скидки (Discounts Prices API)',
                'url' => 'https://discounts-prices-api.wildberries.ru/ping',
            ],
        ];

        foreach ($probes as $probe) {
            try {
                $response = $client->get($probe['url']);
                $code = $response->getStatusCode();
                if (in_array($code, [401, 403], true)) {
                    $warnings[] = 'Ограничен доступ: '.$probe['label'].'. Часть инструментов может работать некорректно.';
                }
            } catch (\Throwable) {
                // Ignore probe failures — ping already succeeded.
            }
        }

        return $warnings;
    }
}
