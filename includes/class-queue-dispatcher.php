<?php
/**
 * Asynchronous Queue Dispatcher
 *
 * STATUS: DIAMANT VGT SUPREME
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

use Throwable;

defined('ABSPATH') || exit;

final class QueueDispatcher {
    public const HOOK_META_DISPATCH = 'vgt_proxy_dispatch_meta';
    public const HOOK_GA4_DISPATCH  = 'vgt_proxy_dispatch_ga4';

    public static function registerHooks(): void {
        add_action(self::HOOK_META_DISPATCH, [self::class, 'executeMetaDispatch'], 10, 2);
        add_action(self::HOOK_GA4_DISPATCH, [self::class, 'executeGa4Dispatch'], 10, 2);
    }

    /**
     * Enqueued Events in den WP Action Scheduler zur asynchronen Hintergrundverarbeitung.
     *
     * @param array<string, mixed> $payload
     */
    public static function enqueue(string $eventName, array $payload): void {
        if (!function_exists('as_enqueue_async_action')) {
            wp_schedule_single_event(time(), self::HOOK_META_DISPATCH, [$eventName, $payload]);
            wp_schedule_single_event(time() + 2, self::HOOK_GA4_DISPATCH, [$eventName, $payload]);
            return;
        }

        if ((int) Config::get('meta_enabled') === 1) {
            as_enqueue_async_action(self::HOOK_META_DISPATCH, [$eventName, $payload], 'vgt-tracking');
        }

        if ((int) Config::get('ga4_enabled') === 1) {
            as_enqueue_async_action(self::HOOK_GA4_DISPATCH, [$eventName, $payload], 'vgt-tracking');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function executeMetaDispatch(string $eventName, array $payload): void {
        try {
            $client = new MetaCapiClient();
            $client->dispatchEvent($eventName, $payload);
        } catch (ValidationException $e) {
            error_log('[VGT_VALIDATION] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
        } catch (SecurityException $e) {
            error_log('[SEC] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Request rejected for security reasons.'];
        } catch (StorageException $e) {
            error_log('[STORAGE] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'A server error occurred.'];
        } catch (Throwable $e) {
            error_log('[FATAL] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Critical system fault.'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function executeGa4Dispatch(string $eventName, array $payload): void {
        try {
            $client = new Ga4MpClient();
            $client->dispatchEvent($eventName, $payload);
        } catch (ValidationException $e) {
            error_log('[VGT_VALIDATION] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => $e->getMessage()];
        } catch (SecurityException $e) {
            error_log('[SEC] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Request rejected for security reasons.'];
        } catch (StorageException $e) {
            error_log('[STORAGE] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'A server error occurred.'];
        } catch (Throwable $e) {
            error_log('[FATAL] ' . $e->getMessage());
            $response = ['status' => 'error', 'message' => 'Critical system fault.'];
        }
    }
}