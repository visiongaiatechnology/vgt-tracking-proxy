<?php
/**
 * Meta Conversions API (CAPI) Client
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class MetaCapiClient {
    private string $pixelId;
    private string $apiToken;

    public function __construct() {
        $this->pixelId  = (string) Config::get('meta_pixel_id');
        $this->apiToken = Cryptor::decrypt((string) Config::get('meta_api_token'));
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function dispatchEvent(string $eventName, array $eventData): void {
        if (empty($this->pixelId) || empty($this->apiToken)) {
            throw new ValidationException('Konfiguration der Meta CAPI Zugangsdaten fehlt oder ist ungültig.');
        }

        $endpoint = "https://graph.facebook.com/v20.0/{$this->pixelId}/events?access_token={$this->apiToken}";

        $isWebhook = !empty($eventData['is_webhook']) || empty($eventData['user_agent']);
        $actionSource = $isWebhook ? 'system_generated' : 'website';

        $payload = [
            'data' => [
                [
                    'event_name'       => $eventName,
                    'event_time'       => time(),
                    'event_source_url' => esc_url_raw($eventData['source_url'] ?? home_url()),
                    'action_source'    => $actionSource,
                    'user_data'        => array_filter([
                        'em'                => !empty($eventData['hashed_email']) ? [$eventData['hashed_email']] : null,
                        'ph'                => !empty($eventData['hashed_phone']) ? [$eventData['hashed_phone']] : null,
                        'client_ip_address' => $eventData['anonymized_ip'] ?? '0.0.0.0',
                        'client_user_agent' => sanitize_text_field($eventData['user_agent'] ?? ''),
                        'fbp'               => !empty($eventData['fbp']) ? sanitize_text_field((string)$eventData['fbp']) : null,
                        'fbc'               => !empty($eventData['fbc']) ? sanitize_text_field((string)$eventData['fbc']) : null,
                    ]),
                    'custom_data'      => array_filter([
                        'value'        => isset($eventData['value']) ? (float) $eventData['value'] : null,
                        'currency'     => sanitize_text_field($eventData['currency'] ?? 'EUR'),
                        'content_type' => 'product',
                        'contents'     => $eventData['contents'] ?? [],
                    ]),
                ]
            ]
        ];

        $jsonPayload = (string) wp_json_encode($payload);

        $response = wp_remote_post($endpoint, [
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => $jsonPayload,
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            $errorMsg = $response->get_error_message();
            AuditLogger::log($eventName, 'Meta CAPI', 0, $jsonPayload, $errorMsg);
            throw new SecurityException('Meta CAPI API Verbindungsfehler: ' . $errorMsg);
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        AuditLogger::log($eventName, 'Meta CAPI', $statusCode, $jsonPayload, $responseBody);

        if ($statusCode !== 200) {
            error_log('[SEC] Meta API HTTP Error ' . $statusCode . ' | Response-Laenge: ' . strlen($responseBody));
            throw new SecurityException("Meta API wies Anfrage ab. HTTP Status: {$statusCode}.");
        }
    }
}