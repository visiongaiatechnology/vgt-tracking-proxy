<?php
/**
 * WooCommerce Event Interceptor
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

use Throwable;

defined('ABSPATH') || exit;

final class WooCommerceBridge {
    public static function init(): void {
        // Core Hooks
        add_action('woocommerce_before_single_product', [self::class, 'handleViewContent'], 10);
        add_action('woocommerce_add_to_cart', [self::class, 'handleAddToCart'], 10, 6);
        add_action('woocommerce_before_checkout_form', [self::class, 'handleInitiateCheckout'], 10);
        add_action('woocommerce_payment_complete', [self::class, 'handlePurchase'], 10, 1);
        add_action('woocommerce_thankyou', [self::class, 'handlePurchaseFallback'], 10, 1);

        // State-Persistence Bridge (PayPal/Stripe Webhook Protection)
        add_action('woocommerce_checkout_create_order', [self::class, 'persistTrackingContextToOrder'], 10, 2);
    }

    /**
     * Interceptiert Checkout-Ereignisse im Browserkontext des Nutzers und speichert
     * die aktiven Attributions-IDs direkt im persistenten Datenbankmodell der WooCommerce-Order.
     */
    public static function persistTrackingContextToOrder(\WC_Order $order, array $data): void {
        try {
            $measurementId = (string) Config::get('ga4_measurement_id');

            $gaClientId  = Anonymizer::getGaClientId();
            $gaSessionId = Anonymizer::getGaSessionId($measurementId) ?? '';
            $fbp         = Anonymizer::getFbp() ?? '';
            $fbc         = Anonymizer::getFbc() ?? '';

            $order->update_meta_data('_vgt_ga_client_id', $gaClientId);
            if (!empty($gaSessionId)) {
                $order->update_meta_data('_vgt_ga_session_id', $gaSessionId);
            }
            if (!empty($fbp)) {
                $order->update_meta_data('_vgt_fbp', $fbp);
            }
            if (!empty($fbc)) {
                $order->update_meta_data('_vgt_fbc', $fbc);
            }
        } catch (Throwable $e) {
            error_log('[FATAL] Fehler bei der VGT-Attributionspersistenz in Bestellung: ' . $e->getMessage());
        }
    }

    /**
     * Interceptiert die Produktanzeige (ViewContent / view_item).
     */
    public static function handleViewContent(): void {
        try {
            global $product;
            if (!$product instanceof \WC_Product) {
                return;
            }

            $productId = $product->get_id();
            $user = wp_get_current_user();
            $userEmail = ($user && $user->ID !== 0) ? $user->user_email : '';

            $hashedEmail = !empty($userEmail) ? Anonymizer::hashPII($userEmail, 'email') : '';
            $rawIp       = self::getClientIp();
            $anonymizedIp = (int) Config::get('anonymize_ip') === 1 ? Anonymizer::anonymizeIp($rawIp) : $rawIp;
            $measurementId = (string) Config::get('ga4_measurement_id');

            $payload = [
                'hashed_email'  => $hashedEmail,
                'hashed_phone'  => '',
                'anonymized_ip' => $anonymizedIp,
                'user_agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'source_url'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url(),
                'value'         => (float) $product->get_price(),
                'currency'      => get_woocommerce_currency(),
                'client_id'     => Anonymizer::getGaClientId(),
                'ga_session_id' => Anonymizer::getGaSessionId($measurementId) ?? '',
                'fbp'           => Anonymizer::getFbp() ?? '',
                'fbc'           => Anonymizer::getFbc() ?? '',
                'contents'      => [
                    [
                        'id'       => $productId,
                        'price'    => (float) $product->get_price(),
                        'quantity' => 1,
                    ]
                ]
            ];

            QueueDispatcher::enqueue('ViewContent', $payload);

        } catch (Throwable $e) {
            error_log('[FATAL] WooCommerce view_content handler abgestürzt: ' . $e->getMessage());
        }
    }

    /**
     * Interceptiert den Warenkorb-Vorgang (AddToCart / add_to_cart).
     */
    public static function handleAddToCart(string $cartItemKey, int $productId, int $quantity, int $variationId, ?array $variation, ?array $cartItemData): void {
        try {
            $product = wc_get_product($productId);
            if (!$product) {
                return;
            }

            $user = wp_get_current_user();
            $userEmail = ($user && $user->ID !== 0) ? $user->user_email : '';
            
            $hashedEmail = !empty($userEmail) ? Anonymizer::hashPII($userEmail, 'email') : '';
            $rawIp       = self::getClientIp();
            $anonymizedIp = (int) Config::get('anonymize_ip') === 1 ? Anonymizer::anonymizeIp($rawIp) : $rawIp;

            $measurementId = (string) Config::get('ga4_measurement_id');

            $payload = [
                'hashed_email'  => $hashedEmail,
                'hashed_phone'  => '', 
                'anonymized_ip' => $anonymizedIp,
                'user_agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'source_url'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url(),
                'value'         => (float) $product->get_price() * $quantity,
                'currency'      => get_woocommerce_currency(),
                'client_id'     => Anonymizer::getGaClientId(),
                'ga_session_id' => Anonymizer::getGaSessionId($measurementId) ?? '',
                'fbp'           => Anonymizer::getFbp() ?? '',
                'fbc'           => Anonymizer::getFbc() ?? '',
                'contents'      => [
                    [
                        'id'       => $productId,
                        'price'    => (float) $product->get_price(),
                        'quantity' => $quantity,
                    ]
                ]
            ];

            QueueDispatcher::enqueue('AddToCart', $payload);

        } catch (Throwable $e) {
            error_log('[FATAL] WooCommerce add_to_cart handler abgestürzt: ' . $e->getMessage());
        }
    }

    /**
     * Interceptiert den Checkout-Beginn (InitiateCheckout / begin_checkout).
     */
    public static function handleInitiateCheckout(): void {
        try {
            $cart = WC()->cart;
            if (!$cart) {
                return;
            }

            $user = wp_get_current_user();
            $userEmail = ($user && $user->ID !== 0) ? $user->user_email : '';

            $hashedEmail = !empty($userEmail) ? Anonymizer::hashPII($userEmail, 'email') : '';
            $rawIp       = self::getClientIp();
            $anonymizedIp = (int) Config::get('anonymize_ip') === 1 ? Anonymizer::anonymizeIp($rawIp) : $rawIp;
            $measurementId = (string) Config::get('ga4_measurement_id');

            $contents = [];
            foreach ($cart->get_cart() as $cartItem) {
                $contents[] = [
                    'id'       => $cartItem['product_id'],
                    'price'    => (float) get_post_meta($cartItem['product_id'], '_price', true),
                    'quantity' => $cartItem['quantity'],
                ];
            }

            $payload = [
                'hashed_email'  => $hashedEmail,
                'hashed_phone'  => '',
                'anonymized_ip' => $anonymizedIp,
                'user_agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
                'source_url'    => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url(),
                'value'         => (float) $cart->get_total('edit'),
                'currency'      => get_woocommerce_currency(),
                'client_id'     => Anonymizer::getGaClientId(),
                'ga_session_id' => Anonymizer::getGaSessionId($measurementId) ?? '',
                'fbp'           => Anonymizer::getFbp() ?? '',
                'fbc'           => Anonymizer::getFbc() ?? '',
                'contents'      => $contents
            ];

            QueueDispatcher::enqueue('InitiateCheckout', $payload);

        } catch (Throwable $e) {
            error_log('[FATAL] WooCommerce initiate_checkout handler abgestürzt: ' . $e->getMessage());
        }
    }

    /**
     * Verarbeitet erfolgreiche Zahlungen (Purchase / purchase).
     */
    public static function handlePurchase(int $orderId): void {
        if (get_post_meta($orderId, '_vgt_purchase_tracked', true) === 'yes') {
            return;
        }

        try {
            $order = wc_get_order($orderId);
            if (!$order) {
                return;
            }

            $contents = [];
            foreach ($order->get_items() as $item) {
                $contents[] = [
                    'id'       => $item->get_product_id(),
                    'price'    => (float) $order->get_item_subtotal($item),
                    'quantity' => $item->get_quantity(),
                ];
            }

            $rawEmail    = $order->get_billing_email();
            $rawPhone    = $order->get_billing_phone();
            $rawIp       = $order->get_customer_ip_address();

            $hashedEmail = !empty($rawEmail) ? Anonymizer::hashPII($rawEmail, 'email') : '';
            $hashedPhone = !empty($rawPhone) ? Anonymizer::hashPII($rawPhone, 'phone') : '';
            $anonymizedIp = (int) Config::get('anonymize_ip') === 1 ? Anonymizer::anonymizeIp($rawIp) : $rawIp;

            // RESOLVING THE WEBHOOK BLIND SPOT
            $gaClientId   = $order->get_meta('_vgt_ga_client_id') ?: Anonymizer::getGaClientId();
            $gaSessionId  = $order->get_meta('_vgt_ga_session_id') ?: '';
            $fbp          = $order->get_meta('_vgt_fbp') ?: '';
            $fbc          = $order->get_meta('_vgt_fbc') ?: '';

            $isWebhook = false;
            if (empty($_SERVER['HTTP_USER_AGENT']) || (defined('REST_REQUEST') && REST_REQUEST)) {
                $isWebhook = true;
            }

            $payload = [
                'hashed_email'   => $hashedEmail,
                'hashed_phone'   => $hashedPhone,
                'anonymized_ip'  => $anonymizedIp,
                'user_agent'     => $order->get_customer_user_agent(),
                'source_url'     => home_url(), 
                'value'          => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                'transaction_id' => $order->get_order_number(),
                'client_id'      => $gaClientId,
                'ga_session_id'  => $gaSessionId,
                'fbp'            => $fbp,
                'fbc'            => $fbc,
                'contents'       => $contents,
                'is_webhook'     => $isWebhook,
            ];

            QueueDispatcher::enqueue('Purchase', $payload);
            update_post_meta($orderId, '_vgt_purchase_tracked', 'yes');

        } catch (Throwable $e) {
            error_log('[FATAL] WooCommerce purchase handler abgestürzt: ' . $e->getMessage());
        }
    }

    /**
     * Fallback-Schnittstelle für ältere oder externe Payment Gateways.
     */
    public static function handlePurchaseFallback(int $orderId): void {
        $order = wc_get_order($orderId);
        if ($order && $order->is_paid()) {
            self::handlePurchase($orderId);
        }
    }

    private static function getClientIp(): string {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        return '0.0.0.0';
    }
}