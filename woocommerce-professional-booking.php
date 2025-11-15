<?php
/**
 * Plugin Name: WooCommerce Professional Booking & Checkout
 * Plugin URI: https://woosabad.com
 * Description: افزونه حرفه‌ای ووکامرس برای دکمه‌های رزرو زیبا، سبد خرید و صفحه پرداخت حرفه‌ای با قابلیت تنظیمات کامل
 * Version: 1.0.0
 * Author: WoosaBad Team
 * Author URI: https://woosabad.com
 * Text Domain: wc-pro-booking
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// بررسی فعال بودن ووکامرس
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>افزونه WooCommerce Professional Booking</strong> نیاز به فعال بودن افزونه WooCommerce دارد.</p></div>';
    });
    return;
}

class WC_Professional_Booking {

    private static $instance = null;
    private $option_name = 'wc_pro_booking_settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // هوک‌های اصلی
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // دکمه‌های رزرو در صفحه محصول
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        add_action('woocommerce_single_product_summary', array($this, 'custom_booking_button'), 30);

        // سفارشی‌سازی سبد خرید
        add_action('woocommerce_before_cart', array($this, 'custom_cart_header'));
        add_filter('woocommerce_cart_item_name', array($this, 'custom_cart_item_display'), 10, 3);
        add_action('woocommerce_after_cart', array($this, 'custom_cart_footer'));
        add_filter('woocommerce_cart_item_thumbnail', array($this, 'custom_cart_item_thumbnail'), 10, 3);

        // سفارشی‌سازی صفحه پرداخت
        add_action('woocommerce_before_checkout_form', array($this, 'custom_checkout_header'));
        add_filter('woocommerce_checkout_fields', array($this, 'customize_checkout_fields'));
        add_action('woocommerce_after_checkout_form', array($this, 'custom_checkout_footer'));

        // اضافه کردن متا باکس رزرو به محصولات
        add_action('add_meta_boxes', array($this, 'add_booking_meta_box'));
        add_action('save_post', array($this, 'save_booking_meta'));

        // AJAX handlers
        add_action('wp_ajax_get_available_times', array($this, 'get_available_times'));
        add_action('wp_ajax_nopriv_get_available_times', array($this, 'get_available_times'));
        add_action('wp_ajax_save_booking', array($this, 'save_booking'));
        add_action('wp_ajax_nopriv_save_booking', array($this, 'save_booking'));

        // ذخیره اطلاعات رزرو در سفارش
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_booking_to_order'), 10, 4);

        // نمایش اطلاعات رزرو در سفارشات
        add_filter('woocommerce_order_item_meta_end', array($this, 'display_booking_in_order'), 10, 3);
    }

    public function init() {
        load_plugin_textdomain('wc-pro-booking', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    // تنظیمات پیش‌فرض
    private function get_default_settings() {
        return array(
            'primary_color' => '#4CAF50',
            'secondary_color' => '#2196F3',
            'accent_color' => '#FF9800',
            'text_color' => '#333333',
            'button_text' => 'رزرو کنید',
            'enable_booking' => 'yes',
            'enable_cart_styling' => 'yes',
            'enable_checkout_styling' => 'yes',
            'booking_duration' => '60',
            'time_slot_interval' => '30',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'working_days' => array('1', '2', '3', '4', '5'),
            'max_advance_booking' => '30',
            'enable_animations' => 'yes',
            'button_style' => 'modern',
            'cart_layout' => 'card',
            'checkout_layout' => 'modern',
            'enable_rtl' => 'yes',
        );
    }

    private function get_settings() {
        $settings = get_option($this->option_name, array());
        return wp_parse_args($settings, $this->get_default_settings());
    }

    // منوی مدیریت
    public function add_admin_menu() {
        add_menu_page(
            'تنظیمات رزرو حرفه‌ای',
            'رزرو حرفه‌ای',
            'manage_options',
            'wc-pro-booking',
            array($this, 'settings_page'),
            'dashicons-calendar-alt',
            56
        );
    }

    public function register_settings() {
        register_setting('wc_pro_booking_group', $this->option_name);
    }

    // صفحه تنظیمات مدیریت
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();

        if (isset($_POST['submit']) && check_admin_referer('wc_pro_booking_settings')) {
            $new_settings = array(
                'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? $settings['primary_color']),
                'secondary_color' => sanitize_hex_color($_POST['secondary_color'] ?? $settings['secondary_color']),
                'accent_color' => sanitize_hex_color($_POST['accent_color'] ?? $settings['accent_color']),
                'text_color' => sanitize_hex_color($_POST['text_color'] ?? $settings['text_color']),
                'button_text' => sanitize_text_field($_POST['button_text'] ?? $settings['button_text']),
                'enable_booking' => isset($_POST['enable_booking']) ? 'yes' : 'no',
                'enable_cart_styling' => isset($_POST['enable_cart_styling']) ? 'yes' : 'no',
                'enable_checkout_styling' => isset($_POST['enable_checkout_styling']) ? 'yes' : 'no',
                'booking_duration' => absint($_POST['booking_duration'] ?? $settings['booking_duration']),
                'time_slot_interval' => absint($_POST['time_slot_interval'] ?? $settings['time_slot_interval']),
                'start_time' => sanitize_text_field($_POST['start_time'] ?? $settings['start_time']),
                'end_time' => sanitize_text_field($_POST['end_time'] ?? $settings['end_time']),
                'working_days' => isset($_POST['working_days']) ? array_map('sanitize_text_field', $_POST['working_days']) : array(),
                'max_advance_booking' => absint($_POST['max_advance_booking'] ?? $settings['max_advance_booking']),
                'enable_animations' => isset($_POST['enable_animations']) ? 'yes' : 'no',
                'button_style' => sanitize_text_field($_POST['button_style'] ?? $settings['button_style']),
                'cart_layout' => sanitize_text_field($_POST['cart_layout'] ?? $settings['cart_layout']),
                'checkout_layout' => sanitize_text_field($_POST['checkout_layout'] ?? $settings['checkout_layout']),
                'enable_rtl' => isset($_POST['enable_rtl']) ? 'yes' : 'no',
            );

            update_option($this->option_name, $new_settings);
            $settings = $new_settings;
            echo '<div class="notice notice-success"><p>تنظیمات با موفقیت ذخیره شد!</p></div>';
        }

        ?>
        <div class="wrap wc-pro-booking-settings">
            <h1>⚙️ تنظیمات افزونه رزرو حرفه‌ای ووکامرس</h1>

            <form method="post" action="">
                <?php wp_nonce_field('wc_pro_booking_settings'); ?>

                <div class="wc-pro-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#general" class="nav-tab nav-tab-active">تنظیمات کلی</a>
                        <a href="#colors" class="nav-tab">رنگ‌ها و ظاهر</a>
                        <a href="#booking" class="nav-tab">تنظیمات رزرو</a>
                        <a href="#styling" class="nav-tab">استایل‌ها</a>
                    </nav>

                    <!-- تب تنظیمات کلی -->
                    <div id="general" class="tab-content active">
                        <h2>⚡ تنظیمات عمومی</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">فعال‌سازی دکمه رزرو</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_booking" value="1" <?php checked($settings['enable_booking'], 'yes'); ?>>
                                        فعال‌سازی دکمه‌های رزرو در صفحه محصول
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">استایل سبد خرید</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_cart_styling" value="1" <?php checked($settings['enable_cart_styling'], 'yes'); ?>>
                                        فعال‌سازی استایل حرفه‌ای سبد خرید
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">استایل صفحه پرداخت</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_checkout_styling" value="1" <?php checked($settings['enable_checkout_styling'], 'yes'); ?>>
                                        فعال‌سازی استایل حرفه‌ای صفحه پرداخت
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">انیمیشن‌ها</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_animations" value="1" <?php checked($settings['enable_animations'], 'yes'); ?>>
                                        فعال‌سازی انیمیشن‌های زیبا
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">پشتیبانی از راست‌چین</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_rtl" value="1" <?php checked($settings['enable_rtl'], 'yes'); ?>>
                                        فعال‌سازی پشتیبانی کامل از زبان‌های راست‌چین
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">متن دکمه رزرو</th>
                                <td>
                                    <input type="text" name="button_text" value="<?php echo esc_attr($settings['button_text']); ?>" class="regular-text">
                                    <p class="description">متنی که روی دکمه رزرو نمایش داده می‌شود</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- تب رنگ‌ها -->
                    <div id="colors" class="tab-content">
                        <h2>🎨 رنگ‌ها و ظاهر</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">رنگ اصلی</th>
                                <td>
                                    <input type="color" name="primary_color" value="<?php echo esc_attr($settings['primary_color']); ?>">
                                    <p class="description">رنگ اصلی دکمه‌ها و عناصر مهم</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">رنگ ثانویه</th>
                                <td>
                                    <input type="color" name="secondary_color" value="<?php echo esc_attr($settings['secondary_color']); ?>">
                                    <p class="description">رنگ ثانویه برای لینک‌ها و عناصر جانبی</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">رنگ تاکیدی</th>
                                <td>
                                    <input type="color" name="accent_color" value="<?php echo esc_attr($settings['accent_color']); ?>">
                                    <p class="description">رنگ تاکیدی برای نمایش اطلاعات مهم</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">رنگ متن</th>
                                <td>
                                    <input type="color" name="text_color" value="<?php echo esc_attr($settings['text_color']); ?>">
                                    <p class="description">رنگ اصلی متن‌ها</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">استایل دکمه</th>
                                <td>
                                    <select name="button_style">
                                        <option value="modern" <?php selected($settings['button_style'], 'modern'); ?>>مدرن</option>
                                        <option value="gradient" <?php selected($settings['button_style'], 'gradient'); ?>>گرادیانت</option>
                                        <option value="minimal" <?php selected($settings['button_style'], 'minimal'); ?>>مینیمال</option>
                                        <option value="rounded" <?php selected($settings['button_style'], 'rounded'); ?>>گرد</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">لایه سبد خرید</th>
                                <td>
                                    <select name="cart_layout">
                                        <option value="card" <?php selected($settings['cart_layout'], 'card'); ?>>کارتی</option>
                                        <option value="minimal" <?php selected($settings['cart_layout'], 'minimal'); ?>>مینیمال</option>
                                        <option value="elegant" <?php selected($settings['cart_layout'], 'elegant'); ?>>الگانت</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">لایه صفحه پرداخت</th>
                                <td>
                                    <select name="checkout_layout">
                                        <option value="modern" <?php selected($settings['checkout_layout'], 'modern'); ?>>مدرن</option>
                                        <option value="classic" <?php selected($settings['checkout_layout'], 'classic'); ?>>کلاسیک</option>
                                        <option value="split" <?php selected($settings['checkout_layout'], 'split'); ?>>دو ستونه</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- تب تنظیمات رزرو -->
                    <div id="booking" class="tab-content">
                        <h2>📅 تنظیمات رزرو</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">مدت زمان رزرو (دقیقه)</th>
                                <td>
                                    <input type="number" name="booking_duration" value="<?php echo esc_attr($settings['booking_duration']); ?>" min="15" max="480" step="15">
                                    <p class="description">مدت زمان هر رزرو به دقیقه</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">فاصله زمانی (دقیقه)</th>
                                <td>
                                    <input type="number" name="time_slot_interval" value="<?php echo esc_attr($settings['time_slot_interval']); ?>" min="15" max="120" step="15">
                                    <p class="description">فاصله بین هر بازه زمانی</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">ساعت شروع</th>
                                <td>
                                    <input type="time" name="start_time" value="<?php echo esc_attr($settings['start_time']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">ساعت پایان</th>
                                <td>
                                    <input type="time" name="end_time" value="<?php echo esc_attr($settings['end_time']); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">روزهای کاری</th>
                                <td>
                                    <?php
                                    $days = array(
                                        '0' => 'یکشنبه',
                                        '1' => 'دوشنبه',
                                        '2' => 'سه‌شنبه',
                                        '3' => 'چهارشنبه',
                                        '4' => 'پنج‌شنبه',
                                        '5' => 'جمعه',
                                        '6' => 'شنبه',
                                    );
                                    foreach ($days as $key => $day) {
                                        $checked = in_array($key, $settings['working_days']) ? 'checked' : '';
                                        echo "<label style='margin-left: 15px;'><input type='checkbox' name='working_days[]' value='{$key}' {$checked}> {$day}</label>";
                                    }
                                    ?>
                                    <p class="description">روزهای قابل رزرو را انتخاب کنید</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">حداکثر رزرو پیشاپیش (روز)</th>
                                <td>
                                    <input type="number" name="max_advance_booking" value="<?php echo esc_attr($settings['max_advance_booking']); ?>" min="1" max="365">
                                    <p class="description">حداکثر تعداد روز برای رزرو از قبل</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- تب استایل‌ها -->
                    <div id="styling" class="tab-content">
                        <h2>💅 پیش‌نمایش استایل‌ها</h2>
                        <div class="styling-preview">
                            <div class="preview-section">
                                <h3>دکمه رزرو</h3>
                                <button type="button" class="wc-pro-booking-btn preview" style="background: <?php echo esc_attr($settings['primary_color']); ?>">
                                    <?php echo esc_html($settings['button_text']); ?>
                                </button>
                            </div>
                            <div class="preview-section">
                                <h3>کارت محصول</h3>
                                <div class="product-card-preview" style="border-color: <?php echo esc_attr($settings['secondary_color']); ?>">
                                    <div class="card-content">
                                        <h4>نمونه محصول</h4>
                                        <p class="price" style="color: <?php echo esc_attr($settings['accent_color']); ?>">1,250,000 تومان</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="💾 ذخیره تنظیمات">
                </p>
            </form>

            <style>
                .wc-pro-booking-settings .nav-tab-wrapper {
                    margin: 20px 0;
                    border-bottom: 1px solid #ccc;
                }
                .wc-pro-booking-settings .tab-content {
                    display: none;
                    padding: 20px;
                    background: #fff;
                    border: 1px solid #ccc;
                    border-top: 0;
                }
                .wc-pro-booking-settings .tab-content.active {
                    display: block;
                }
                .styling-preview {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-top: 20px;
                }
                .preview-section {
                    padding: 20px;
                    background: #f9f9f9;
                    border-radius: 8px;
                }
                .wc-pro-booking-btn.preview {
                    padding: 12px 30px;
                    border: none;
                    border-radius: 5px;
                    color: #fff;
                    font-size: 16px;
                    cursor: pointer;
                    transition: all 0.3s;
                }
                .product-card-preview {
                    padding: 20px;
                    background: #fff;
                    border: 2px solid;
                    border-radius: 8px;
                }
            </style>

            <script>
            jQuery(document).ready(function($) {
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).attr('href');
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.tab-content').removeClass('active');
                    $(target).addClass('active');
                });
            });
            </script>
        </div>
        <?php
    }

    // متا باکس برای محصولات
    public function add_booking_meta_box() {
        add_meta_box(
            'wc_pro_booking_meta',
            'تنظیمات رزرو محصول',
            array($this, 'booking_meta_box_callback'),
            'product',
            'side',
            'default'
        );
    }

    public function booking_meta_box_callback($post) {
        wp_nonce_field('wc_pro_booking_meta', 'wc_pro_booking_meta_nonce');
        $enable = get_post_meta($post->ID, '_enable_booking', true);
        $max_capacity = get_post_meta($post->ID, '_booking_max_capacity', true) ?: 1;
        ?>
        <p>
            <label>
                <input type="checkbox" name="_enable_booking" value="1" <?php checked($enable, '1'); ?>>
                فعال‌سازی رزرو برای این محصول
            </label>
        </p>
        <p>
            <label>ظرفیت رزرو:</label>
            <input type="number" name="_booking_max_capacity" value="<?php echo esc_attr($max_capacity); ?>" min="1" style="width: 100%;">
        </p>
        <?php
    }

    public function save_booking_meta($post_id) {
        if (!isset($_POST['wc_pro_booking_meta_nonce']) || !wp_verify_nonce($_POST['wc_pro_booking_meta_nonce'], 'wc_pro_booking_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        update_post_meta($post_id, '_enable_booking', isset($_POST['_enable_booking']) ? '1' : '0');
        update_post_meta($post_id, '_booking_max_capacity', absint($_POST['_booking_max_capacity'] ?? 1));
    }

    // دکمه رزرو سفارشی
    public function custom_booking_button() {
        global $product;

        if (!$product) {
            return;
        }

        $settings = $this->get_settings();
        $enable_booking = get_post_meta($product->get_id(), '_enable_booking', true);

        if ($settings['enable_booking'] !== 'yes' && $enable_booking !== '1') {
            woocommerce_template_single_add_to_cart();
            return;
        }

        ?>
        <div class="wc-pro-booking-wrapper">
            <div class="booking-calendar-section">
                <label class="booking-label">📅 انتخاب تاریخ:</label>
                <input type="text" id="booking-date" class="booking-date-picker" placeholder="تاریخ رزرو را انتخاب کنید" readonly>
            </div>

            <div class="booking-time-section" style="display: none;">
                <label class="booking-label">⏰ انتخاب ساعت:</label>
                <div id="time-slots" class="time-slots-container"></div>
            </div>

            <div class="booking-info-section" style="display: none;">
                <div class="selected-booking-info">
                    <span class="info-icon">ℹ️</span>
                    <span class="booking-details"></span>
                </div>
            </div>

            <div class="booking-actions">
                <button type="button" class="wc-pro-booking-btn" data-product-id="<?php echo esc_attr($product->get_id()); ?>">
                    <span class="btn-icon">🎯</span>
                    <span class="btn-text"><?php echo esc_html($settings['button_text']); ?></span>
                </button>
            </div>
        </div>
        <?php
    }

    // هدر سبد خرید
    public function custom_cart_header() {
        $settings = $this->get_settings();
        if ($settings['enable_cart_styling'] !== 'yes') {
            return;
        }
        ?>
        <div class="wc-pro-cart-header">
            <div class="cart-header-content">
                <h2 class="cart-title">🛒 سبد خرید شما</h2>
                <p class="cart-subtitle">بررسی و مدیریت سفارش‌های خود</p>
            </div>
            <div class="cart-progress-bar">
                <div class="progress-step active">
                    <div class="step-icon">1️⃣</div>
                    <span>سبد خرید</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon">2️⃣</div>
                    <span>پرداخت</span>
                </div>
                <div class="progress-step">
                    <div class="step-icon">3️⃣</div>
                    <span>تکمیل</span>
                </div>
            </div>
        </div>
        <?php
    }

    // نمایش سفارشی آیتم‌های سبد
    public function custom_cart_item_display($name, $cart_item, $cart_item_key) {
        $settings = $this->get_settings();
        if ($settings['enable_cart_styling'] !== 'yes') {
            return $name;
        }

        $booking_date = isset($cart_item['booking_date']) ? $cart_item['booking_date'] : '';
        $booking_time = isset($cart_item['booking_time']) ? $cart_item['booking_time'] : '';

        $output = '<div class="cart-item-custom">';
        $output .= '<h4 class="item-name">' . $name . '</h4>';

        if ($booking_date && $booking_time) {
            $output .= '<div class="booking-meta">';
            $output .= '<span class="meta-item">📅 ' . esc_html($booking_date) . '</span>';
            $output .= '<span class="meta-item">⏰ ' . esc_html($booking_time) . '</span>';
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    // تصویر سفارشی آیتم سبد
    public function custom_cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        $settings = $this->get_settings();
        if ($settings['enable_cart_styling'] !== 'yes') {
            return $thumbnail;
        }

        return '<div class="wc-pro-cart-thumbnail">' . $thumbnail . '</div>';
    }

    // فوتر سبد خرید
    public function custom_cart_footer() {
        $settings = $this->get_settings();
        if ($settings['enable_cart_styling'] !== 'yes') {
            return;
        }
        ?>
        <div class="wc-pro-cart-footer">
            <div class="cart-features">
                <div class="feature-item">
                    <span class="feature-icon">✅</span>
                    <span class="feature-text">ضمانت اصالت کالا</span>
                </div>
                <div class="feature-item">
                    <span class="feature-icon">🚚</span>
                    <span class="feature-text">ارسال سریع</span>
                </div>
                <div class="feature-item">
                    <span class="feature-icon">💯</span>
                    <span class="feature-text">پشتیبانی ۲۴ ساعته</span>
                </div>
            </div>
        </div>
        <?php
    }

    // هدر صفحه پرداخت
    public function custom_checkout_header() {
        $settings = $this->get_settings();
        if ($settings['enable_checkout_styling'] !== 'yes') {
            return;
        }
        ?>
        <div class="wc-pro-checkout-header">
            <div class="checkout-header-content">
                <h2 class="checkout-title">💳 تکمیل خرید</h2>
                <p class="checkout-subtitle">یک قدم تا تکمیل سفارش</p>
            </div>
            <div class="checkout-security-badge">
                <span class="security-icon">🔒</span>
                <span>پرداخت امن</span>
            </div>
        </div>
        <?php
    }

    // سفارشی‌سازی فیلدهای پرداخت
    public function customize_checkout_fields($fields) {
        $settings = $this->get_settings();
        if ($settings['enable_checkout_styling'] !== 'yes') {
            return $fields;
        }

        // اضافه کردن کلاس‌های سفارشی
        foreach ($fields as $field_group => $field_array) {
            foreach ($field_array as $key => $field) {
                $fields[$field_group][$key]['class'][] = 'wc-pro-field';
            }
        }

        return $fields;
    }

    // فوتر صفحه پرداخت
    public function custom_checkout_footer() {
        $settings = $this->get_settings();
        if ($settings['enable_checkout_styling'] !== 'yes') {
            return;
        }
        ?>
        <div class="wc-pro-checkout-footer">
            <div class="checkout-trust-badges">
                <div class="trust-badge">
                    <span class="badge-icon">🔐</span>
                    <div class="badge-content">
                        <strong>پرداخت امن</strong>
                        <span>SSL Certificate</span>
                    </div>
                </div>
                <div class="trust-badge">
                    <span class="badge-icon">💳</span>
                    <div class="badge-content">
                        <strong>درگاه معتبر</strong>
                        <span>بانک‌های معتبر</span>
                    </div>
                </div>
                <div class="trust-badge">
                    <span class="badge-icon">✨</span>
                    <div class="badge-content">
                        <strong>حریم خصوصی</strong>
                        <span>محافظت اطلاعات</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // AJAX: دریافت ساعت‌های موجود
    public function get_available_times() {
        check_ajax_referer('wc_pro_booking_nonce', 'nonce');

        $date = sanitize_text_field($_POST['date'] ?? '');
        $product_id = absint($_POST['product_id'] ?? 0);

        if (!$date || !$product_id) {
            wp_send_json_error('داده‌های نامعتبر');
        }

        $settings = $this->get_settings();
        $start_time = $settings['start_time'];
        $end_time = $settings['end_time'];
        $interval = $settings['time_slot_interval'];

        $times = array();
        $start = strtotime($start_time);
        $end = strtotime($end_time);

        while ($start < $end) {
            $time_slot = date('H:i', $start);

            // بررسی رزرو شده بودن
            $is_booked = $this->is_time_slot_booked($date, $time_slot, $product_id);

            $times[] = array(
                'time' => $time_slot,
                'display' => date('H:i', $start),
                'available' => !$is_booked
            );

            $start = strtotime('+' . $interval . ' minutes', $start);
        }

        wp_send_json_success($times);
    }

    // بررسی رزرو شده بودن زمان
    private function is_time_slot_booked($date, $time, $product_id) {
        global $wpdb;

        $max_capacity = get_post_meta($product_id, '_booking_max_capacity', true) ?: 1;

        // جستجو در سفارشات
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oim.order_item_id = oi.order_item_id
            INNER JOIN {$wpdb->prefix}posts p ON oi.order_id = p.ID
            WHERE oim.meta_key = '_booking_datetime'
            AND oim.meta_value = %s
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-pending')
            AND oi.order_item_type = 'line_item'",
            $date . ' ' . $time
        ));

        return $count >= $max_capacity;
    }

    // AJAX: ذخیره رزرو
    public function save_booking() {
        check_ajax_referer('wc_pro_booking_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $quantity = absint($_POST['quantity'] ?? 1);

        if (!$product_id || !$date || !$time) {
            wp_send_json_error('داده‌های نامعتبر');
        }

        // بررسی موجود بودن
        if ($this->is_time_slot_booked($date, $time, $product_id)) {
            wp_send_json_error('این زمان رزرو شده است');
        }

        // افزودن به سبد
        $cart_item_data = array(
            'booking_date' => $date,
            'booking_time' => $time,
            'booking_datetime' => $date . ' ' . $time
        );

        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);

        if ($cart_item_key) {
            wp_send_json_success(array(
                'message' => 'محصول با موفقیت به سبد اضافه شد',
                'cart_url' => wc_get_cart_url()
            ));
        } else {
            wp_send_json_error('خطا در افزودن به سبد');
        }
    }

    // ذخیره اطلاعات رزرو در سفارش
    public function save_booking_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['booking_datetime'])) {
            $item->add_meta_data('_booking_datetime', $values['booking_datetime'], true);
            $item->add_meta_data('تاریخ رزرو', $values['booking_date'], true);
            $item->add_meta_data('ساعت رزرو', $values['booking_time'], true);
        }
    }

    // نمایش اطلاعات رزرو در سفارش
    public function display_booking_in_order($item_id, $item, $order) {
        $booking_date = $item->get_meta('تاریخ رزرو');
        $booking_time = $item->get_meta('ساعت رزرو');

        if ($booking_date && $booking_time) {
            echo '<div class="booking-order-meta">';
            echo '<strong>📅 ' . esc_html($booking_date) . '</strong> | ';
            echo '<strong>⏰ ' . esc_html($booking_time) . '</strong>';
            echo '</div>';
        }
    }

    // بارگذاری استایل و اسکریپت فرانت‌اند
    public function enqueue_frontend_assets() {
        // فقط در صفحات ووکامرس
        if (!is_cart() && !is_checkout() && !is_product() && !is_woocommerce()) {
            return;
        }

        $settings = $this->get_settings();

        // ثبت و لود CSS سفارشی با اولویت بالا
        wp_register_style('wc-pro-booking-style', false);
        wp_enqueue_style('wc-pro-booking-style');
        wp_add_inline_style('wc-pro-booking-style', $this->get_custom_css($settings));

        // JavaScript فقط در صفحه محصول
        if (is_product()) {
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');

            wp_add_inline_script('jquery', $this->get_custom_js($settings));

            wp_localize_script('jquery', 'wcProBooking', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_pro_booking_nonce'),
                'settings' => $settings
            ));
        }
    }

    // CSS سفارشی
    private function get_custom_css($settings) {
        $primary = $settings['primary_color'];
        $secondary = $settings['secondary_color'];
        $accent = $settings['accent_color'];
        $text = $settings['text_color'];

        $animations = $settings['enable_animations'] === 'yes' ? '
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @keyframes pulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.05);
                }
            }

            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        ' : '';

        return "
        {$animations}

        /* دکمه رزرو */
        .wc-pro-booking-wrapper {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin: 20px 0;
            animation: fadeInUp 0.5s ease-out;
        }

        .booking-label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: {$text};
            font-size: 16px;
        }

        .booking-date-picker {
            width: 100%;
            padding: 15px;
            border: 2px solid {$secondary};
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: #fff;
        }

        .booking-date-picker:focus {
            outline: none;
            border-color: {$primary};
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .booking-time-section {
            margin-top: 20px;
            animation: fadeInUp 0.5s ease-out;
        }

        .time-slots-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .time-slot {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fff;
            font-weight: 500;
        }

        .time-slot:hover {
            border-color: {$primary};
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .time-slot.selected {
            background: {$primary};
            color: #fff;
            border-color: {$primary};
        }

        .time-slot.booked {
            background: #f5f5f5;
            color: #999;
            cursor: not-allowed;
            opacity: 0.5;
        }

        .booking-info-section {
            margin-top: 20px;
            animation: slideIn 0.5s ease-out;
        }

        .selected-booking-info {
            background: linear-gradient(135deg, {$secondary} 0%, {$primary} 100%);
            color: #fff;
            padding: 15px 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-icon {
            font-size: 24px;
        }

        .wc-pro-booking-btn {
            width: 100%;
            padding: 18px 30px;
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .wc-pro-booking-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            animation: pulse 1s infinite;
        }

        .wc-pro-booking-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-icon {
            font-size: 24px;
        }

        /* سبد خرید */
        .wc-pro-cart-header {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.5s ease-out;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .cart-header-content {
            text-align: center;
        }

        .cart-title {
            font-size: 36px;
            margin: 0 0 10px 0;
            font-weight: 800;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .cart-subtitle {
            font-size: 18px;
            opacity: 0.95;
            margin: 0;
        }

        .cart-progress-bar {
            display: flex;
            justify-content: space-around;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid rgba(255,255,255,0.3);
        }

        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            opacity: 0.5;
            transition: all 0.4s;
        }

        .progress-step.active {
            opacity: 1;
            transform: scale(1.1);
        }

        .step-icon {
            font-size: 28px;
            background: rgba(255,255,255,0.2);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .progress-step.active .step-icon {
            background: #fff;
            color: {$primary};
            box-shadow: 0 5px 20px rgba(255,255,255,0.5);
        }

        /* جدول سبد خرید */
        .woocommerce-cart-form {
            background: #fff !important;
            border-radius: 20px !important;
            padding: 30px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08) !important;
            margin-bottom: 30px !important;
        }

        .woocommerce-cart-form table.cart {
            border: none !important;
        }

        .woocommerce-cart-form table.cart thead {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%) !important;
            color: #fff !important;
        }

        .woocommerce-cart-form table.cart thead th {
            padding: 20px 15px !important;
            border: none !important;
            font-weight: 600 !important;
            font-size: 16px !important;
            text-align: center !important;
        }

        .woocommerce-cart-form table.cart tbody tr {
            background: #fff;
            transition: all 0.3s;
            border-bottom: 1px solid #f0f0f0;
        }

        .woocommerce-cart-form table.cart tbody tr:hover {
            background: #f9f9f9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .woocommerce-cart-form table.cart tbody td {
            padding: 25px 15px;
            border: none;
            vertical-align: middle;
        }

        /* تصویر محصول */
        .wc-pro-cart-thumbnail img {
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .wc-pro-cart-thumbnail:hover img {
            transform: scale(1.05);
        }

        .cart-item-custom {
            padding: 10px 0;
        }

        .item-name {
            margin: 0 0 10px 0;
            font-size: 18px;
            font-weight: 600;
            color: {$text};
        }

        .booking-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .meta-item {
            background: linear-gradient(135deg, {$accent} 0%, #ff6b35 100%);
            color: #fff;
            padding: 6px 14px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 3px 10px rgba(255, 152, 0, 0.3);
        }

        /* فیلد تعداد - استایل زیبا */
        .woocommerce-cart-form .quantity {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .woocommerce-cart-form .quantity input.qty {
            width: 80px !important;
            height: 45px;
            text-align: center;
            border: 2px solid {$secondary} !important;
            border-radius: 10px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            color: {$text} !important;
            background: #f8f9fa !important;
            transition: all 0.3s;
            padding: 0 10px !important;
        }

        .woocommerce-cart-form .quantity input.qty:focus {
            outline: none !important;
            border-color: {$primary} !important;
            background: #fff !important;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1) !important;
        }

        /* دکمه‌های + و - برای quantity */
        .woocommerce-cart-form .quantity {
            position: relative;
        }

        /* قیمت */
        .woocommerce-cart-form .product-price,
        .woocommerce-cart-form .product-subtotal {
            font-size: 20px;
            font-weight: 700;
            color: {$accent};
        }

        /* دکمه حذف */
        .woocommerce-cart-form .product-remove a {
            color: #ff4444 !important;
            background: #fff0f0;
            width: 35px;
            height: 35px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            font-size: 20px;
        }

        .woocommerce-cart-form .product-remove a:hover {
            background: #ff4444;
            color: #fff !important;
            transform: rotate(90deg);
        }

        /* دکمه به‌روزرسانی سبد */
        .woocommerce-cart-form button[name='update_cart'] {
            background: linear-gradient(135deg, {$secondary} 0%, {$primary} 100%);
            color: #fff;
            border: none;
            padding: 15px 35px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.3);
        }

        .woocommerce-cart-form button[name='update_cart']:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(33, 150, 243, 0.5);
        }

        /* جمع کل سبد */
        .cart-collaterals {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }

        .cart-collaterals h2 {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 25px;
        }

        .cart-collaterals table.shop_table {
            border: none;
            background: #f8f9fa;
            border-radius: 15px;
            overflow: hidden;
        }

        .cart-collaterals table.shop_table th,
        .cart-collaterals table.shop_table td {
            padding: 18px 20px;
            border: none;
            border-bottom: 1px solid #e9ecef;
        }

        .cart-collaterals table.shop_table tr.order-total th,
        .cart-collaterals table.shop_table tr.order-total td {
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
        }

        .cart-collaterals .wc-proceed-to-checkout a {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            padding: 20px 40px;
            border-radius: 15px;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            display: block;
            transition: all 0.3s;
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.4);
            border: none;
            text-decoration: none;
        }

        .cart-collaterals .wc-proceed-to-checkout a:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(76, 175, 80, 0.6);
        }

        /* فوتر سبد خرید */
        .wc-pro-cart-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 30px;
            border-radius: 20px;
            margin-top: 30px;
        }

        .cart-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #fff;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .feature-icon {
            font-size: 32px;
        }

        .feature-text {
            font-size: 15px;
            font-weight: 600;
            color: {$text};
        }

        /* صفحه پرداخت */
        .wc-pro-checkout-header {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: fadeInUp 0.5s ease-out;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .checkout-title {
            font-size: 36px;
            margin: 0 0 8px 0;
            font-weight: 800;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .checkout-subtitle {
            margin: 0;
            opacity: 0.95;
            font-size: 18px;
        }

        .checkout-security-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.25);
            padding: 15px 25px;
            border-radius: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .security-icon {
            font-size: 28px;
        }

        /* فرم checkout */
        .woocommerce-checkout {
            background: #fff !important;
            border-radius: 20px !important;
            padding: 40px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08) !important;
        }

        .woocommerce-billing-fields h3,
        .woocommerce-shipping-fields h3,
        .woocommerce-additional-fields h3 {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%) !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            background-clip: text !important;
            font-size: 24px !important;
            font-weight: 800 !important;
            margin-bottom: 25px !important;
            padding-bottom: 15px !important;
            border-bottom: 3px solid #f0f0f0 !important;
        }

        .wc-pro-field {
            margin-bottom: 25px;
        }

        .wc-pro-field label {
            font-weight: 600;
            color: {$text};
            margin-bottom: 8px;
            display: block;
            font-size: 15px;
        }

        .wc-pro-field input,
        .wc-pro-field select,
        .wc-pro-field textarea {
            width: 100%;
            border: 2px solid #e0e0e0 !important;
            border-radius: 12px !important;
            padding: 15px 18px !important;
            transition: all 0.3s;
            font-size: 15px;
            background: #f8f9fa !important;
        }

        .wc-pro-field input:focus,
        .wc-pro-field select:focus,
        .wc-pro-field textarea:focus {
            border-color: {$primary} !important;
            background: #fff !important;
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1) !important;
            outline: none !important;
        }

        .wc-pro-field input::placeholder {
            color: #999;
        }

        /* جدول سفارش */
        #order_review {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 30px;
            margin-top: 30px;
        }

        #order_review_heading {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 25px;
        }

        .woocommerce-checkout-review-order-table {
            background: #fff;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }

        .woocommerce-checkout-review-order-table thead {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
        }

        .woocommerce-checkout-review-order-table thead th {
            padding: 20px;
            font-weight: 600;
            border: none;
        }

        .woocommerce-checkout-review-order-table tbody td,
        .woocommerce-checkout-review-order-table tfoot td,
        .woocommerce-checkout-review-order-table tfoot th {
            padding: 18px 20px;
            border-bottom: 1px solid #f0f0f0;
        }

        .woocommerce-checkout-review-order-table .order-total th,
        .woocommerce-checkout-review-order-table .order-total td {
            font-size: 22px;
            font-weight: 800;
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            padding: 25px 20px;
        }

        /* روش پرداخت */
        .woocommerce-checkout-payment {
            background: #fff;
            border-radius: 20px;
            padding: 30px;
            margin-top: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }

        .wc_payment_methods {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .wc_payment_method {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border: 2px solid transparent;
            transition: all 0.3s;
        }

        .wc_payment_method:hover {
            border-color: {$secondary};
            background: #fff;
        }

        .wc_payment_method input[type='radio']:checked + label {
            color: {$primary};
            font-weight: 700;
        }

        /* دکمه ثبت سفارش */
        #place_order {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
            color: #fff;
            padding: 22px 50px;
            border: none;
            border-radius: 15px;
            font-size: 22px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.4s;
            width: 100%;
            box-shadow: 0 10px 30px rgba(76, 175, 80, 0.4);
            margin-top: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        #place_order:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(76, 175, 80, 0.6);
        }

        #place_order:active {
            transform: translateY(-2px);
        }

        /* فوتر checkout */
        .wc-pro-checkout-footer {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 40px;
            border-radius: 20px;
            margin-top: 30px;
        }

        .checkout-trust-badges {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
        }

        .trust-badge {
            display: flex;
            align-items: center;
            gap: 20px;
            background: #fff;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }

        .trust-badge:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .badge-icon {
            font-size: 40px;
        }

        .badge-content strong {
            display: block;
            font-size: 16px;
            font-weight: 700;
            color: {$text};
            margin-bottom: 5px;
        }

        .badge-content span {
            font-size: 13px;
            color: #666;
        }

        .booking-order-meta {
            margin-top: 12px;
            padding: 15px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            border-right: 4px solid {$accent};
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }

        /* ریسپانسیو */
        @media (max-width: 768px) {
            .wc-pro-cart-header,
            .wc-pro-checkout-header {
                flex-direction: column;
                text-align: center;
            }

            .cart-progress-bar {
                flex-direction: column;
                gap: 15px;
            }

            .time-slots-container {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            }
        }

        /* RTL Support */
        [dir='rtl'] .wc-pro-booking-wrapper,
        [dir='rtl'] .woocommerce-cart-form,
        [dir='rtl'] .woocommerce-checkout {
            direction: rtl;
            text-align: right;
        }
        ";
    }

    // JavaScript سفارشی
    private function get_custom_js($settings) {
        $working_days = json_encode($settings['working_days']);
        $max_days = $settings['max_advance_booking'];

        return "
        jQuery(document).ready(function($) {
            // Datepicker
            var workingDays = {$working_days};
            var maxDays = {$max_days};

            $('#booking-date').datepicker({
                minDate: 0,
                maxDate: maxDays,
                dateFormat: 'yy-mm-dd',
                beforeShowDay: function(date) {
                    var day = date.getDay();
                    var isWorking = workingDays.indexOf(day.toString()) !== -1;
                    return [isWorking, isWorking ? '' : 'ui-state-disabled'];
                },
                onSelect: function(dateText) {
                    loadTimeSlots(dateText);
                }
            });

            function loadTimeSlots(date) {
                var productId = $('.wc-pro-booking-btn').data('product-id');

                $.ajax({
                    url: wcProBooking.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'get_available_times',
                        nonce: wcProBooking.nonce,
                        date: date,
                        product_id: productId
                    },
                    success: function(response) {
                        if (response.success) {
                            displayTimeSlots(response.data, date);
                        }
                    }
                });
            }

            function displayTimeSlots(times, date) {
                var container = $('#time-slots');
                container.empty();

                times.forEach(function(slot) {
                    var slotClass = 'time-slot';
                    if (!slot.available) {
                        slotClass += ' booked';
                    }

                    var slotHtml = '<div class=\"' + slotClass + '\" data-time=\"' + slot.time + '\" data-date=\"' + date + '\">' + slot.display + '</div>';
                    container.append(slotHtml);
                });

                $('.booking-time-section').slideDown();

                $('.time-slot:not(.booked)').on('click', function() {
                    $('.time-slot').removeClass('selected');
                    $(this).addClass('selected');

                    var selectedDate = $(this).data('date');
                    var selectedTime = $(this).data('time');

                    $('.booking-details').html('<strong>' + selectedDate + '</strong> در ساعت <strong>' + selectedTime + '</strong>');
                    $('.booking-info-section').slideDown();
                    $('.wc-pro-booking-btn').prop('disabled', false);
                });
            }

            $('.wc-pro-booking-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var selectedSlot = $('.time-slot.selected');

                if (!selectedSlot.length) {
                    alert('لطفا تاریخ و ساعت را انتخاب کنید');
                    return;
                }

                var date = selectedSlot.data('date');
                var time = selectedSlot.data('time');

                btn.prop('disabled', true).html('<span class=\"btn-icon\">⏳</span><span class=\"btn-text\">در حال افزودن...</span>');

                $.ajax({
                    url: wcProBooking.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'save_booking',
                        nonce: wcProBooking.nonce,
                        product_id: productId,
                        date: date,
                        time: time,
                        quantity: 1
                    },
                    success: function(response) {
                        if (response.success) {
                            btn.html('<span class=\"btn-icon\">✅</span><span class=\"btn-text\">اضافه شد!</span>');
                            setTimeout(function() {
                                window.location.href = response.data.cart_url;
                            }, 1000);
                        } else {
                            alert(response.data);
                            btn.prop('disabled', false).html('<span class=\"btn-icon\">🎯</span><span class=\"btn-text\">' + wcProBooking.settings.button_text + '</span>');
                        }
                    },
                    error: function() {
                        alert('خطا در برقراری ارتباط');
                        btn.prop('disabled', false).html('<span class=\"btn-icon\">🎯</span><span class=\"btn-text\">' + wcProBooking.settings.button_text + '</span>');
                    }
                });
            });
        });
        ";
    }

    // بارگذاری استایل ادمین
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_wc-pro-booking' && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
}

// راه‌اندازی افزونه
function wc_professional_booking_init() {
    return WC_Professional_Booking::get_instance();
}

add_action('plugins_loaded', 'wc_professional_booking_init');

// فعال‌سازی افزونه
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('این افزونه نیاز به نصب و فعال‌سازی WooCommerce دارد.', 'خطا', array('back_link' => true));
    }

    flush_rewrite_rules();
});

// غیرفعال‌سازی افزونه
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
