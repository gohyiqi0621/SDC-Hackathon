<?php
/**
 * TelegramWebhookService.php - point a business's bot at this app.
 *
 *   webhook URL:  PUBLIC_APP_URL/telegram/webhook?bot=<telegram_integrations.id>
 *   secret:       telegram_integrations.webhook_secret (random, per bot)
 *
 * Used when Telegram is connected in AI Settings, and by
 * bin/telegram-webhook.php (run it again whenever PUBLIC_APP_URL changes,
 * e.g. a new ngrok address).
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\TelegramIntegration;

final class TelegramWebhookService
{
    public static function urlFor(int $integrationId): string
    {
        return PUBLIC_APP_URL . '/telegram/webhook?bot=' . $integrationId;
    }

    /**
     * @param array $integration a telegram_integrations row
     * @return array{ok: bool, message: string, url: string}
     */
    public function register(array $integration, string $token): array
    {
        $model  = new TelegramIntegration();
        $secret = (string) ($integration['webhook_secret'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $secret)) {
            $secret = bin2hex(random_bytes(24)); // 48 chars, allowed by Telegram
        }

        $url    = self::urlFor((int) $integration['id']);
        $result = (new TelegramService())->setWebhook($token, $url, $secret);

        $model->save((int) $integration['user_id'], [
            'webhook_secret' => $secret,
            'webhook_url'    => $result['ok'] ? $url : null,
            'webhook_set_at' => $result['ok'] ? date('c') : null,
        ]);

        return $result + ['url' => $url];
    }

    public function unregister(array $integration, string $token): void
    {
        (new TelegramService())->deleteWebhook($token);

        (new TelegramIntegration())->save((int) $integration['user_id'], [
            'webhook_url'    => null,
            'webhook_set_at' => null,
        ]);
    }
}
