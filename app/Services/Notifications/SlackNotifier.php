<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;

class SlackNotifier
{
    public function send(string $text): void
    {
        $url = config('services.slack_webhook.url');

        if (!$url) {
            return;
        }

        try {
            Http::post($url, ['text' => $text]);
        } catch (\Throwable) {
            // Slack failure must never break the calling flow
        }
    }
}
