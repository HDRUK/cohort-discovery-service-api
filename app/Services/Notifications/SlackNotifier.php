<?php

namespace App\Services\Notifications;

use App\Models\Collection;
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

    public function sendBlocks(array $blocks, string $fallbackText = '', ?string $color = null): void
    {
        $url = config('services.slack_webhook.url');
        if (!$url) {
            Log::warning('SlackNotifier: no webhook URL configured');
            return;
        }

        $envName = config('session.domain') ?? 'local';
        $blocks[] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => "🌍 *Environment:* {$envName}"]],
        ];

        $payload = array_filter(['text' => $fallbackText]);

        if ($color !== null) {
            $payload['attachments'] = [['color' => $color, 'blocks' => $blocks]];
        } else {
            $payload['blocks'] = $blocks;
        }

        try {
            Http::post($url, $payload);
        } catch (\Throwable $e) {
            Log::error('SlackNotifier: failed to send blocks', ['error' => $e->getMessage()]);
        }
    }

    public function collectionSuspended(Collection $c): void
    {
        $minutes = (int) config('system.collection_inactivity_minutes', 30);

        $this->sendBlocks([
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => '🚨 Collection Suspended', 'emoji' => true],
            ],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Collection:*\n{$c->name}"],
                    ['type' => 'mrkdwn', 'text' => "*Status:*\n⛔ Suspended"],
                    ['type' => 'mrkdwn', 'text' => "*Custodian:*\n{$c->custodian->name}"],
                    ['type' => 'mrkdwn', 'text' => "*Reason:*\nNo activity in {$minutes} minutes"],
                ],
            ],
        ], "🚨 Collection suspended due to inactivity: {$c->name}", '#E01E5A');
    }

    public function collectionBackOnline(Collection $c): void
    {
        $this->sendBlocks([
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => '✅ Collection Back Online', 'emoji' => true],
            ],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Collection:*\n{$c->name}"],
                    ['type' => 'mrkdwn', 'text' => "*Status:*\n✅ Active"],
                    ['type' => 'mrkdwn', 'text' => "*Custodian:*\n{$c->custodian->name}"],
                ],
            ],
        ], "✅ Collection back online: {$c->name}", '#2EB67D');
    }
}
