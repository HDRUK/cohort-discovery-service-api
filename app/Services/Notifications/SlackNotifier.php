<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackNotifier
{
    public function send(string $text): void
    {
        $url = config('services.slack_webhook.url');
        if (!$url) {
            Log::warning('SlackNotifier: no webhook URL configured');
            return;
        }

        try {
            Http::post($url, ['text' => $text]);
        } catch (\Throwable $e) {
            Log::error('SlackNotifier: failed to send message', ['error' => $e->getMessage()]);
        }
    }

    public function sendBlocks(array $blocks, string $fallbackText = ''): void
    {
        $url = config('services.slack_webhook.url');
        if (!$url) {
            Log::warning('SlackNotifier: no webhook URL configured');
            return;
        }

        $env = config('session.domain') ?? 'local';
        $blocks[] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => "🌍 *Environment:* {$env}"]],
        ];

        try {
            Http::post($url, array_filter([
                'text' => $fallbackText,
                'blocks' => $blocks,
            ]));
        } catch (\Throwable $e) {
            Log::error('SlackNotifier: failed to send blocks', ['error' => $e->getMessage()]);
        }
    }
}
