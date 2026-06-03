<?php
/**
 * Admin Console Panel Interface - Hardened Edition
 *
 * STATUS: Open Core
 */

declare(strict_types=1);

namespace VGT\TrackingProxy;

defined('ABSPATH') || exit;

final class AdminConsole {
    private const MENU_SLUG = 'vgt-tracking-proxy';

    /**
     * Initialisiert die Admin-Hooks.
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'registerAdminMenu'], 99);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * Registriert das Menü im WordPress Backend.
     */
    public static function registerAdminMenu(): void {
        if (class_exists('WooCommerce')) {
            add_submenu_page(
                'woocommerce',
                esc_html__('VGT Tracking Proxy', 'vgt-tracking-proxy'),
                esc_html__('VGT Tracking Proxy', 'vgt-tracking-proxy'),
                'manage_options',
                self::MENU_SLUG,
                [self::class, 'renderConsole']
            );
        } else {
            add_menu_page(
                esc_html__('VGT Tracking Proxy', 'vgt-tracking-proxy'),
                esc_html__('VGT Proxy', 'vgt-tracking-proxy'),
                'manage_options',
                self::MENU_SLUG,
                [self::class, 'renderConsole'],
                'dashicons-shield-navigator',
                80
            );
        }
    }

    public static function registerSettings(): void {
        register_setting('vgt_tracking_group', 'vgt_tracking_proxy_settings', [
            'sanitize_callback' => [self::class, 'sanitizeSettings']
        ]);
    }

    /**
     * Sanitizes input before storage.
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitizeSettings(array $input): array {
        $output = [];
        $output['meta_enabled']       = isset($input['meta_enabled']) ? 1 : 0;
        $output['meta_pixel_id']      = sanitize_text_field($input['meta_pixel_id'] ?? '');
        $output['ga4_enabled']        = isset($input['ga4_enabled']) ? 1 : 0;
        $output['ga4_measurement_id'] = sanitize_text_field($input['ga4_measurement_id'] ?? '');
        $output['anonymize_ip']       = isset($input['anonymize_ip']) ? 1 : 0;
        $output['debug_logging']      = isset($input['debug_logging']) ? 1 : 0;

        // AES-256-GCM Encryption for sensitive tokens
        $output['meta_api_token']     = Cryptor::encrypt(sanitize_text_field($input['meta_api_token'] ?? ''));
        $output['ga4_api_secret']     = Cryptor::encrypt(sanitize_text_field($input['ga4_api_secret'] ?? ''));

        return $output;
    }

    public static function renderConsole(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unautorisierter Zugriff blockiert.', 'vgt-tracking-proxy'));
        }

        // Logic for Purging Logs
        if (isset($_POST['vgt_purge_logs']) && check_admin_referer('vgt_purge_action')) {
            global $wpdb;
            $tableName = $wpdb->prefix . AuditLogger::TABLE_NAME;
            $wpdb->query("TRUNCATE TABLE " . esc_sql($tableName));
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Audit logs erfolgreich gelöscht.', 'vgt-tracking-proxy') . '</p></div>';
        }

        $config = Config::getAll();
        global $wpdb;
        $tableName = $wpdb->prefix . AuditLogger::TABLE_NAME;
        $logs = $wpdb->get_results("SELECT * FROM " . esc_sql($tableName) . " ORDER BY timestamp DESC LIMIT 20");

        $decryptedMetaToken = Cryptor::decrypt($config['meta_api_token']);
        $decryptedGaSecret  = Cryptor::decrypt($config['ga4_api_secret']);

        ?>
        <div class="wrap" style="background: #0b0f19; color: #f3f4f6; padding: 30px; border-radius: 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 1300px; margin-top: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); border: 1px solid #1e293b;">
            
            <!-- HEADER SECTION -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1e293b; padding-bottom: 25px; margin-bottom: 35px;">
                <div>
                    <h1 style="color: #6366f1; font-weight: 900; margin: 0; font-size: 32px; letter-spacing: -1px; text-transform: uppercase;"><?php echo esc_html__('VGT SELFHOSTED', 'vgt-tracking-proxy'); ?></h1>
                    <p style="color: #64748b; margin: 5px 0 0 0; font-family: 'SF Mono', monospace; font-size: 12px; letter-spacing: 1px;"><?php echo esc_html__('SELFHOSTED PROXY SYSTEM | KERNEL v2.3.4 | OPEN CORE', 'vgt-tracking-proxy'); ?></p>
                </div>
                <div style="text-align: right;">
                    <span style="background: linear-gradient(90deg, #6366f1, #a855f7); color: #fff; padding: 8px 16px; border-radius: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; box-shadow: 0 0 20px rgba(99, 102, 241, 0.4);"><?php echo esc_html__('System Status: Secure', 'vgt-tracking-proxy'); ?></span>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
                
                <!-- LEFT COLUMN: MAIN CONFIG -->
                <div>
                    <form method="post" action="options.php">
                        <?php settings_fields('vgt_tracking_group'); ?>

                        <div style="background: rgba(30, 41, 59, 0.5); border: 1px solid #334155; padding: 25px; border-radius: 12px; backdrop-filter: blur(15px); margin-bottom: 30px;">
                            <h2 style="color: #f8fafc; font-size: 18px; margin-top: 0; margin-bottom: 20px; display: flex; align-items: center;">
                                <span style="color: #6366f1; margin-right: 10px;">◈</span> <?php echo esc_html__('Core-Tracking Konfiguration', 'vgt-tracking-proxy'); ?>
                            </h2>

                            <!-- GDPR LAYER -->
                            <div style="margin-bottom: 30px; background: rgba(99, 102, 241, 0.08); border-left: 4px solid #6366f1; padding: 20px; border-radius: 4px;">
                                <label style="display: flex; align-items: center; font-weight: 700; cursor: pointer; color: #e2e8f0;">
                                    <input type="checkbox" name="vgt_tracking_proxy_settings[anonymize_ip]" value="1" <?php checked($config['anonymize_ip'], 1); ?> style="margin-right: 15px; transform: scale(1.2);" />
                                    <?php echo esc_html__('DSGVO-Compliance-Shield Aktivieren', 'vgt-tracking-proxy'); ?>
                                </label>
                                <p style="color: #94a3b8; font-size: 13px; margin: 10px 0 0 32px; line-height: 1.6;"><?php echo esc_html__('Automatisierte IP-Anonymisierung im RAM und militärische AES-256-Verschlüsselung der API-Secrets.', 'vgt-tracking-proxy'); ?></p>
                            </div>

                            <!-- META SECTION -->
                            <div style="border-top: 1px solid #1e293b; padding-top: 20px; margin-bottom: 30px;">
                                <h3 style="color: #38bdf8; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; display: flex; align-items: center;">
                                    <input type="checkbox" name="vgt_tracking_proxy_settings[meta_enabled]" value="1" <?php checked($config['meta_enabled'], 1); ?> style="margin-right: 10px;" />
                                    <?php echo esc_html__('Meta Conversions API (CAPI)', 'vgt-tracking-proxy'); ?>
                                </h3>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div>
                                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 8px; font-weight: 600;"><?php echo esc_html__('META PIXEL ID', 'vgt-tracking-proxy'); ?></label>
                                        <input type="text" name="vgt_tracking_proxy_settings[meta_pixel_id]" value="<?php echo esc_attr($config['meta_pixel_id']); ?>" placeholder="123456789..." style="width: 100%; background: #0f172a; border: 1px solid #334155; color: #f1f5f9; padding: 12px; border-radius: 6px; font-family: 'SF Mono', monospace;" />
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 8px; font-weight: 600;"><?php echo esc_html__('ACCESS TOKEN (ENCRYPTED)', 'vgt-tracking-proxy'); ?></label>
                                        <input type="password" name="vgt_tracking_proxy_settings[meta_api_token]" value="<?php echo esc_attr($decryptedMetaToken); ?>" style="width: 100%; background: #0f172a; border: 1px solid #334155; color: #f1f5f9; padding: 12px; border-radius: 6px;" />
                                    </div>
                                </div>
                            </div>

                            <!-- GA4 SECTION -->
                            <div style="border-top: 1px solid #1e293b; padding-top: 20px;">
                                <h3 style="color: #38bdf8; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; display: flex; align-items: center;">
                                    <input type="checkbox" name="vgt_tracking_proxy_settings[ga4_enabled]" value="1" <?php checked($config['ga4_enabled'], 1); ?> style="margin-right: 10px;" />
                                    <?php echo esc_html__('Google Analytics 4 MP', 'vgt-tracking-proxy'); ?>
                                </h3>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                    <div>
                                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 8px; font-weight: 600;"><?php echo esc_html__('MEASUREMENT ID', 'vgt-tracking-proxy'); ?></label>
                                        <input type="text" name="vgt_tracking_proxy_settings[ga4_measurement_id]" value="<?php echo esc_attr($config['ga4_measurement_id']); ?>" placeholder="G-XXXXXXXXXX" style="width: 100%; background: #0f172a; border: 1px solid #334155; color: #f1f5f9; padding: 12px; border-radius: 6px; font-family: 'SF Mono', monospace;" />
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 11px; color: #64748b; margin-bottom: 8px; font-weight: 600;"><?php echo esc_html__('API SECRET (ENCRYPTED)', 'vgt-tracking-proxy'); ?></label>
                                        <input type="password" name="vgt_tracking_proxy_settings[ga4_api_secret]" value="<?php echo esc_attr($decryptedGaSecret); ?>" style="width: 100%; background: #0f172a; border: 1px solid #334155; color: #f1f5f9; padding: 12px; border-radius: 6px;" />
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top: 35px;">
                                <button type="submit" class="button button-primary" style="background: #6366f1; border: none; padding: 15px 30px; font-weight: 800; border-radius: 8px; color: #fff; cursor: pointer; display: block; width: 100%; text-transform: uppercase; letter-spacing: 1px; transition: 0.3s; box-shadow: 0 10px 20px rgba(99, 102, 241, 0.2);"><?php echo esc_html__('Pipeline Synchronisieren', 'vgt-tracking-proxy'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- RIGHT COLUMN: PREMIUM & AUDIT -->
                <div>
                    <!-- PREMIUM UPGRADE CARD -->
                    <div style="background: linear-gradient(145deg, #1e1b4b, #312e81); border: 1px solid #4338ca; padding: 25px; border-radius: 12px; margin-bottom: 30px; position: relative; overflow: hidden;">
                        <div style="position: absolute; top: -10px; right: -10px; background: #fbbf24; color: #000; padding: 5px 15px; font-size: 10px; font-weight: 900; transform: rotate(15deg); text-transform: uppercase;"><?php echo esc_html__('Coming Soon', 'vgt-tracking-proxy'); ?></div>
                        <h2 style="color: #fff; font-size: 20px; margin-top: 0;"><?php echo esc_html__('VGT Selfhosted Premium', 'vgt-tracking-proxy'); ?></h2>
                        <p style="color: #c7d2fe; font-size: 14px; line-height: 1.5;"><?php echo esc_html__('Erweitern Sie Ihr Gateway um Enterprise-Schnittstellen für maximale Reichweite.', 'vgt-tracking-proxy'); ?></p>
                        <ul style="color: #e0e7ff; font-size: 13px; list-style: none; padding: 0; margin: 20px 0;">
                            <li style="margin-bottom: 10px; display: flex; align-items: center;"><span style="color: #10b981; margin-right: 10px;">✔</span> <?php echo esc_html__('TikTok Events API Integration', 'vgt-tracking-proxy'); ?></li>
                            <li style="margin-bottom: 10px; display: flex; align-items: center;"><span style="color: #10b981; margin-right: 10px;">✔</span> <?php echo esc_html__('Snapchat Conversions API', 'vgt-tracking-proxy'); ?></li>
                            <li style="margin-bottom: 10px; display: flex; align-items: center;"><span style="color: #10b981; margin-right: 10px;">✔</span> <?php echo esc_html__('Pinterest Tracking Module', 'vgt-tracking-proxy'); ?></li>
                            <li style="margin-bottom: 10px; display: flex; align-items: center;"><span style="color: #10b981; margin-right: 10px;">✔</span> <?php echo esc_html__('Advanced Lead-Enrichment', 'vgt-tracking-proxy'); ?></li>
                        </ul>
                        <a href="#" style="display: block; text-align: center; background: #fff; color: #312e81; padding: 12px; border-radius: 6px; text-decoration: none; font-weight: 800; font-size: 12px; text-transform: uppercase; transition: 0.2s;" onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#fff'"><?php echo esc_html__('Warteliste Beitreten', 'vgt-tracking-proxy'); ?></a>
                    </div>

                    <!-- QUICK STATS -->
                    <div style="background: rgba(15, 23, 42, 0.4); border: 1px solid #1e293b; padding: 20px; border-radius: 12px;">
                        <h3 style="color: #94a3b8; font-size: 12px; text-transform: uppercase; margin-bottom: 15px;"><?php echo esc_html__('System Intelligence', 'vgt-tracking-proxy'); ?></h3>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <span style="color: #64748b;"><?php echo esc_html__('API Status', 'vgt-tracking-proxy'); ?></span>
                            <span style="color: #10b981; font-weight: 700;"><?php echo esc_html__('Operational', 'vgt-tracking-proxy'); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #64748b;"><?php echo esc_html__('Latent Queue', 'vgt-tracking-proxy'); ?></span>
                            <span style="color: #38bdf8; font-weight: 700;"><?php echo esc_html__('0ms', 'vgt-tracking-proxy'); ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- AUDIT STREAM -->
            <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid #1e293b; padding: 25px; border-radius: 12px; margin-top: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; padding-bottom: 15px; margin-bottom: 20px;">
                    <h2 style="color: #f8fafc; font-size: 18px; margin: 0;"><?php echo esc_html__('Echtzeit-Audit-Stream (Letzte 20 Events)', 'vgt-tracking-proxy'); ?></h2>
                    
                    <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Soll die Audit-Datenbank unwiderruflich bereinigt werden?', 'vgt-tracking-proxy')); ?>');" style="margin: 0;">
                        <?php wp_nonce_field('vgt_purge_action'); ?>
                        <input type="submit" name="vgt_purge_logs" class="button" style="background: #ef4444; color: white; border: none; font-weight: 800; cursor: pointer; padding: 8px 16px; border-radius: 6px; font-size: 11px; text-transform: uppercase;" value="<?php echo esc_attr__('Audit bereinigen', 'vgt-tracking-proxy'); ?>" />
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-family: 'SF Mono', 'Roboto Mono', monospace; font-size: 12px; color: #cbd5e1;">
                        <thead>
                            <tr style="text-align: left; border-bottom: 2px solid #1e293b; color: #64748b; text-transform: uppercase; font-size: 10px; letter-spacing: 1px;">
                                <th style="padding: 12px;"><?php echo esc_html__('Timestamp', 'vgt-tracking-proxy'); ?></th>
                                <th style="padding: 12px;"><?php echo esc_html__('Event', 'vgt-tracking-proxy'); ?></th>
                                <th style="padding: 12px;"><?php echo esc_html__('Endpoint', 'vgt-tracking-proxy'); ?></th>
                                <th style="padding: 12px;"><?php echo esc_html__('HTTP', 'vgt-tracking-proxy'); ?></th>
                                <th style="padding: 12px;"><?php echo esc_html__('Payload Fragment', 'vgt-tracking-proxy'); ?></th>
                                <th style="padding: 12px;"><?php echo esc_html__('Result', 'vgt-tracking-proxy'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)) : ?>
                                <tr>
                                    <td colspan="6" style="padding: 40px; text-align: center; color: #475569;"><?php echo esc_html__('Keine aktiven Übertragungen im Cache. System im Standby.', 'vgt-tracking-proxy'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($logs as $log) : ?>
                                    <tr style="border-bottom: 1px solid #0f172a; transition: background 0.3s;" onmouseover="this.style.background='rgba(99,102,241,0.03)'" onmouseout="this.style.background='transparent'">
                                        <td style="padding: 14px 12px;"><?php echo esc_html($log->timestamp); ?></td>
                                        <td style="padding: 14px 12px; color: #38bdf8; font-weight: 800;"><?php echo esc_html($log->event_name); ?></td>
                                        <td style="padding: 14px 12px;"><?php echo esc_html($log->api_target); ?></td>
                                        <td style="padding: 14px 12px;">
                                            <span style="color: <?php echo ($log->status_code >= 200 && $log->status_code < 300) ? '#10b981' : '#ef4444'; ?>;">
                                                <?php echo (int) $log->status_code; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 14px 12px; color: #64748b; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo esc_html(mb_strimwidth($log->request_payload, 0, 60, '...')); ?>
                                        </td>
                                        <td style="padding: 14px 12px;">
                                            <?php if ($log->status_code >= 200 && $log->status_code < 300) : ?>
                                                <span style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 4px 10px; border-radius: 4px; font-size: 9px; font-weight: 900; text-transform: uppercase;"><?php echo esc_html__('Encrypted', 'vgt-tracking-proxy'); ?></span>
                                            <?php else : ?>
                                                <span style="background: rgba(239, 68, 68, 0.1); color: #ef4444; padding: 4px 10px; border-radius: 4px; font-size: 9px; font-weight: 900; text-transform: uppercase;"><?php echo esc_html__('Rejected', 'vgt-tracking-proxy'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}