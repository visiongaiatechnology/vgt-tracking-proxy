<?php
/**
 * GA4 Measurement Protocol Client
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class Ga4MpClient {
    private string $measurementId;
    private string $apiSecret;

    public function __construct() {
        $this->measurementId = (string) Config::get('ga4_measurement_id');
        $this->apiSecret     = Cryptor::decrypt((string) Config::get('ga4_api_secret'));
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function dispatchEvent(string $eventName, array $eventData): void {
        if (empty($this->measurementId) || empty($this->apiSecret)) {
            throw new ValidationException('GA4 Measurement Protocol Konfiguration unvollständig.');
        }

        $endpoint = "https://www.google-analytics.com/mp/collect?measurement_id={$this->measurementId}&api_secret={$this->apiSecret}";

        $gaEventName = match ($eventName) {
            'AddToCart'        => 'add_to_cart',
            'Purchase'         => 'purchase',
            'ViewContent'      => 'view_item',
            'InitiateCheckout' => 'begin_checkout',
            default            => mb_strtolower($eventName),
        };

        $items = [];
        if (!empty($eventData['contents']) && is_array($eventData['contents'])) {
            foreach ($eventData['contents'] as $content) {
                $items[] = [
                    'item_id'   => sanitize_text_field((string)$content['id']),
                    'price'     => (float)($content['price'] ?? 0.0),
                    'quantity'  => (int)($content['quantity'] ?? 1),
                ];
            }
        }

        $payload = [
            'client_id' => sanitize_text_field($eventData['client_id'] ?? Anonymizer::getGaClientId()),
            'events'    => [
                [
                    'name'   => $gaEventName,
                    'params' => array_filter([
                        'currency'       => sanitize_text_field($eventData['currency'] ?? 'EUR'),
                        'value'          => isset($eventData['value']) ? (float) $eventData['value'] : null,
                        'transaction_id' => !empty($eventData['transaction_id']) ? sanitize_text_field($eventData['transaction_id']) : null,
                        'session_id'     => !empty($eventData['ga_session_id']) ? sanitize_text_field((string)$eventData['ga_session_id']) : null,
                        'items'          => !empty($items) ? $items : null,
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
            AuditLogger::log($eventName, 'GA4 MP', 0, $jsonPayload, $errorMsg);
            throw new SecurityException('GA4 MP API Verbindungsfehler: ' . $errorMsg);
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);

        AuditLogger::log($eventName, 'GA4 MP', $statusCode, $jsonPayload, $responseBody);

        if ($statusCode !== 200 && $statusCode !== 204) {
            error_log('[SEC] GA4 MP API HTTP Error ' . $statusCode . ' | Response-Laenge: ' . strlen($responseBody));
            throw new SecurityException("GA4 MP API wies Anfrage ab. HTTP Status: {$statusCode}.");
        }
    }
}