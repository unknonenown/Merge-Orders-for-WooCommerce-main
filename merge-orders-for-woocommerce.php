<?php
/**
 * Plugin Name: Merge Orders for WooCommerce (Sorted & Grouped, 2+)
 * Description: Merges multiple eligible WooCommerce orders from the same customer into one. Excludes card-paid/card-gateway orders and keeps only one shipping charge by default. Includes date filter, pagination and optional line-item consolidation.
 * Version:     3.2.0
 * Author:      Hostify
 * Text Domain: merge-orders-for-woocommerce-sorted-grouped
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.4.3
 */

defined('ABSPATH') || exit;

if (!class_exists('Hostify_Merge_Orders_For_WooCommerce')) {

	final class Hostify_Merge_Orders_For_WooCommerce {

		public const VERSION      = '3.2.0';
		public const TEXT_DOMAIN  = 'merge-orders-for-woocommerce-sorted-grouped';
		public const MIN_WC_VER   = '8.0';

		public const MENU_SLUG    = 'merge-orders';
		public const CONFIRM_SLUG = 'merge-orders-confirmation';

		public const NONCE_ACTION = 'merge_orders_nonce_action';
		public const NONCE_NAME   = 'merge_orders_nonce_name';

		// Query param names (admin filter/pagination)
		private const QP_FROM      = 'mo_from';      // Y-m-d
		private const QP_TO        = 'mo_to';        // Y-m-d
		private const QP_PAGED     = 'mo_paged';     // int
		private const QP_PER_PAGE  = 'mo_per_page';  // int (groups per page)

		public static function init(): void {
			add_action('before_woocommerce_init', array(__CLASS__, 'declare_hpos_compatibility'));
			add_action('plugins_loaded', array(__CLASS__, 'load_textdomain'));

			add_action('init', array(__CLASS__, 'register_merged_order_status'));
			add_filter('wc_order_statuses', array(__CLASS__, 'add_merged_to_order_statuses'));

			add_action('admin_menu', array(__CLASS__, 'register_menu'));
			add_action('admin_notices', array(__CLASS__, 'admin_notices'));

			add_action('admin_post_merge_selected_orders', array(__CLASS__, 'handle_merge_orders_submission'));

			add_action('yith_wca_before_auction_status_changed', array(__CLASS__, 'handle_auction_won'), 10, 3);
		}

		public static function load_textdomain(): void {
			load_plugin_textdomain(
				self::TEXT_DOMAIN,
				false,
				dirname(plugin_basename(__FILE__)) . '/languages'
			);
		}

		public static function declare_hpos_compatibility(): void {
			if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
					'custom_order_tables',
					__FILE__,
					true
				);
			}
		}

		private static function wc_ready(): bool {
			return class_exists('WooCommerce') && defined('WC_VERSION') && version_compare(WC_VERSION, self::MIN_WC_VER, '>=');
		}

		public static function admin_notices(): void {
			if (!is_admin() || !current_user_can('manage_woocommerce')) {
				return;
			}

			if (!class_exists('WooCommerce')) {
				echo '<div class="notice notice-error"><p>' .
					esc_html__('Merge Orders: WooCommerce is not active.', self::TEXT_DOMAIN) .
				'</p></div>';
				return;
			}

			if (!defined('WC_VERSION') || version_compare(WC_VERSION, self::MIN_WC_VER, '<')) {
				echo '<div class="notice notice-error"><p>' .
					esc_html(sprintf(
						/* translators: %s WooCommerce minimum version */
						__('Merge Orders: WooCommerce %s or higher is required.', self::TEXT_DOMAIN),
						self::MIN_WC_VER
					)) .
				'</p></div>';
				return;
			}

			if (!empty($_GET['merge_error'])) {
				$msg = sanitize_text_field(wp_unslash($_GET['merge_error']));
				echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
			}

			if (!empty($_GET['merge_info'])) {
				$msg = sanitize_text_field(wp_unslash($_GET['merge_info']));
				echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>';
			}
		}

		/**
		 * Custom status for source orders after merge.
		 */
		public static function register_merged_order_status(): void {
			register_post_status('wc-merged', array(
				'label'                     => _x('Merged', 'Order status', self::TEXT_DOMAIN),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'Merged <span class="count">(%s)</span>',
					'Merged <span class="count">(%s)</span>',
					self::TEXT_DOMAIN
				),
			));
		}

		public static function add_merged_to_order_statuses(array $order_statuses): array {
			$new = array();

			foreach ($order_statuses as $key => $label) {
				$new[$key] = $label;
				if ('wc-processing' === $key) {
					$new['wc-merged'] = _x('Merged', 'Order status', self::TEXT_DOMAIN);
				}
			}

			if (!isset($new['wc-merged'])) {
				$new['wc-merged'] = _x('Merged', 'Order status', self::TEXT_DOMAIN);
			}

			return $new;
		}

		public static function register_menu(): void {
			if (!self::wc_ready()) {
				return;
			}

			add_submenu_page(
				'woocommerce',
				__('Merge Orders', self::TEXT_DOMAIN),
				__('Merge Orders', self::TEXT_DOMAIN),
				'manage_woocommerce',
				self::MENU_SLUG,
				array(__CLASS__, 'render_admin_page')
			);

			add_submenu_page(
				null,
				__('Merge Orders Confirmation', self::TEXT_DOMAIN),
				__('Merge Orders Confirmation', self::TEXT_DOMAIN),
				'manage_woocommerce',
				self::CONFIRM_SLUG,
				array(__CLASS__, 'render_confirmation_page')
			);
		}

		/**
		 * Minimum eligible orders per customer group to show on admin page.
		 * Default 2 (merge makes sense only for 2+ orders).
		 *
		 * add_filter('hostify_merge_orders_min_group_size', fn() => 4);
		 */
		private static function min_group_size(): int {
			$min = (int) apply_filters('hostify_merge_orders_min_group_size', 2);
			return max(2, $min);
		}

		/**
		 * Eligible statuses (without wc- prefix).
		 */
		private static function eligible_statuses(): array {
			$statuses = (array) apply_filters(
				'hostify_merge_orders_eligible_statuses',
				array('on-hold', 'pending', 'processing')
			);

			$clean = array();
			foreach ($statuses as $s) {
				$s = sanitize_key((string) $s);
				if ($s !== '') {
					$clean[] = $s;
				}
			}

			return array_values(array_unique($clean));
		}

		/**
		 * Max orders to fetch for grouping (performance safety).
		 * Use date filter to reduce.
		 */
		private static function query_limit(): int {
			$limit = (int) apply_filters('hostify_merge_orders_query_limit', 5000);
			return max(100, min(20000, $limit));
		}

		/**
		 * Pagination: groups per page default + options.
		 */
		private static function groups_per_page_default(): int {
			$pp = (int) apply_filters('hostify_merge_orders_groups_per_page_default', 10);
			return max(1, min(100, $pp));
		}

		private static function groups_per_page_options(): array {
			$opts = (array) apply_filters('hostify_merge_orders_groups_per_page_options', array(5, 10, 20, 50));
			$out  = array();

			foreach ($opts as $v) {
				$v = (int) $v;
				if ($v > 0 && $v <= 100) {
					$out[] = $v;
				}
			}

			if (empty($out)) {
				$out = array(5, 10, 20, 50);
			}

			$out = array_values(array_unique($out));
			sort($out);

			return $out;
		}

		private static function sanitize_ymd(string $date): string {
			$date = trim($date);
			if ($date === '') {
				return '';
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				return '';
			}
			$parts = explode('-', $date);
			if (count($parts) !== 3) {
				return '';
			}
			$y = (int) $parts[0];
			$m = (int) $parts[1];
			$d = (int) $parts[2];
			if (!checkdate($m, $d, $y)) {
				return '';
			}
			return sprintf('%04d-%02d-%02d', $y, $m, $d);
		}

		private static function parse_filters_from_request(): array {
			$from = isset($_GET[self::QP_FROM]) ? self::sanitize_ymd((string) wp_unslash($_GET[self::QP_FROM])) : '';
			$to   = isset($_GET[self::QP_TO])   ? self::sanitize_ymd((string) wp_unslash($_GET[self::QP_TO]))   : '';

			// Normalizuj range ako je obrnuto.
			if ($from !== '' && $to !== '' && $from > $to) {
				$tmp  = $from;
				$from = $to;
				$to   = $tmp;
			}

			$paged = isset($_GET[self::QP_PAGED]) ? (int) $_GET[self::QP_PAGED] : 1;
			$paged = max(1, $paged);

			$per_page = isset($_GET[self::QP_PER_PAGE]) ? (int) $_GET[self::QP_PER_PAGE] : self::groups_per_page_default();
			$per_page = max(1, min(100, $per_page));

			return array(
				'from'     => $from,
				'to'       => $to,
				'paged'    => $paged,
				'per_page' => $per_page,
			);
		}

		private static function build_admin_url(array $args = array()): string {
			$base = admin_url('admin.php');
			$defaults = array('page' => self::MENU_SLUG);

			$args = array_merge($defaults, $args);

			// remove empties
			foreach ($args as $k => $v) {
				if ($v === '' || $v === null) {
					unset($args[$k]);
				}
			}

			return add_query_arg($args, $base);
		}

		private static function order_timestamp(\WC_Order $order): int {
			$dt = $order->get_date_created();
			return $dt ? (int) $dt->getTimestamp() : 0;
		}

		private static function customer_group_key(\WC_Order $order): string {
			$user_id = (int) $order->get_user_id();
			if ($user_id > 0) {
				return 'user_' . $user_id;
			}

			$email = strtolower(trim((string) $order->get_billing_email()));
			if ($email !== '' && is_email($email)) {
				return 'guest_' . $email;
			}

			// Bez email-a -> nikad ne grupišemo (sigurnije).
			return 'guest_noemail_' . $order->get_id();
		}

		/**
		 * Fetch orders (with optional date filter) and group them by customer.
		 * Returns:
		 * [
		 *   group_key => [
		 *     'orders' => WC_Order[],
		 *     'latest_ts' => int
		 *   ],
		 *   ...
		 * ]
		 */
		private static function get_grouped_orders_to_merge(string $from, string $to): array {
			$args = array(
				'status'  => self::eligible_statuses(),
				'return'  => 'objects',
				'limit'   => self::query_limit(),
				'orderby' => 'date',
				'order'   => 'DESC',
			);

			// Date filtering (Woo supports ranges like "YYYY-MM-DD...YYYY-MM-DD")
			if ($from !== '' && $to !== '') {
				$args['date_created'] = $from . '...' . $to;
			} elseif ($from !== '') {
				$args['date_created'] = '>' . $from;
			} elseif ($to !== '') {
				$args['date_created'] = '<' . $to;
			}

			$args = apply_filters('hostify_merge_orders_query_args', $args, $from, $to);

			$orders = wc_get_orders($args);

			$grouped = array();

			foreach ($orders as $order) {
				if (!$order instanceof \WC_Order) {
					continue;
				}

				// Card-paid/card-gateway orders must not enter the merge candidate list.
				if (!self::is_order_merge_candidate($order)) {
					continue;
				}

				$key = self::customer_group_key($order);

				if (!isset($grouped[$key])) {
					$grouped[$key] = array(
						'orders'    => array(),
						'latest_ts' => self::order_timestamp($order), // first encountered is latest because query is DESC
					);
				}

				$grouped[$key]['orders'][] = $order;
			}

			// Ensure each group's orders are sorted DESC by created date (defensive)
			foreach ($grouped as $k => $data) {
				if (!isset($data['orders']) || !is_array($data['orders'])) {
					continue;
				}
				usort($grouped[$k]['orders'], function($a, $b) {
					if (!$a instanceof \WC_Order || !$b instanceof \WC_Order) {
						return 0;
					}
					return self::order_timestamp($b) <=> self::order_timestamp($a);
				});
			}

			return $grouped;
		}

		private static function get_customer_display_name_for_group(\WC_Order $first_order): string {
			$user_id = (int) $first_order->get_user_id();

			$first_name = (string) $first_order->get_billing_first_name();
			$last_name  = (string) $first_order->get_billing_last_name();

			$display_name = trim($first_name . ' ' . $last_name);

			if ($user_id > 0) {
				$ud = get_userdata($user_id);
				if ($ud && !empty($ud->display_name)) {
					$display_name = (string) $ud->display_name;
				}
			}

			if ($display_name === '') {
				$display_name = $user_id > 0 ? ('User #' . $user_id) : __('Guest', self::TEXT_DOMAIN);
			}

			return $display_name;
		}

		private static function get_customer_name_for_row(\WC_Order $order): string {
			$fn = (string) $order->get_billing_first_name();
			$ln = (string) $order->get_billing_last_name();
			$name = trim($fn . ' ' . $ln);

			if ($name !== '') {
				return $name;
			}

			$u = $order->get_user();
			if ($u instanceof \WP_User) {
				return (string) $u->user_login; // kao u tvom originalu
			}

			return __('Guest', self::TEXT_DOMAIN);
		}

		public static function render_admin_page(): void {
			if (!current_user_can('manage_woocommerce')) {
				return;
			}

			if (!self::wc_ready()) {
				echo '<div class="wrap"><h1>' . esc_html__('Merge Orders', self::TEXT_DOMAIN) . '</h1>';
				echo '<p>' . esc_html__('WooCommerce is not available or does not meet minimum version.', self::TEXT_DOMAIN) . '</p></div>';
				return;
			}

			$filters   = self::parse_filters_from_request();
			$from      = $filters['from'];
			$to        = $filters['to'];
			$paged     = $filters['paged'];
			$per_page  = $filters['per_page'];

			$min_group = self::min_group_size();

			// Fetch + group
			$grouped = self::get_grouped_orders_to_merge($from, $to);

			// Keep only groups that meet min size
			$eligible_groups = array();
			foreach ($grouped as $group_key => $data) {
				$orders = isset($data['orders']) && is_array($data['orders']) ? $data['orders'] : array();
				if (count($orders) < $min_group) {
					continue;
				}

				$eligible_groups[$group_key] = array(
					'orders'    => $orders,
					'latest_ts' => isset($data['latest_ts']) ? (int) $data['latest_ts'] : 0,
				);
			}

			// Sort groups by latest order DESC
			uasort($eligible_groups, function($a, $b) {
				$ta = isset($a['latest_ts']) ? (int) $a['latest_ts'] : 0;
				$tb = isset($b['latest_ts']) ? (int) $b['latest_ts'] : 0;
				return $tb <=> $ta;
			});

			$total_groups = count($eligible_groups);
			$total_pages  = $per_page > 0 ? (int) ceil($total_groups / $per_page) : 1;
			$total_pages  = max(1, $total_pages);

			if ($paged > $total_pages) {
				$paged = $total_pages;
			}

			$offset = ($paged - 1) * $per_page;

			// Slice groups for current page
			$groups_page = array_slice($eligible_groups, $offset, $per_page, true);

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__('Merge Orders', self::TEXT_DOMAIN) . '</h1>';

			echo '<div class="notice notice-warning" style="padding:10px 12px;margin-top:12px;">';
			echo '<p><strong>' . esc_html__('Important:', self::TEXT_DOMAIN) . '</strong> ';
			echo esc_html__('If you include Processing orders in a merge, review your stock/payment workflow carefully.', self::TEXT_DOMAIN);
			echo '</p>';
			echo '<p>' . esc_html__('Orders paid by card/card gateways are hidden from this list and are blocked server-side.', self::TEXT_DOMAIN) . '</p>';
			echo '</div>';

			// Filter form (date range + groups per page)
			echo '<form method="get" style="margin:16px 0 8px;padding:12px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">';
			echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';

			echo '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">';

			echo '<div>';
			echo '<label for="mo_from" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('From date', self::TEXT_DOMAIN) . '</label>';
			echo '<input id="mo_from" type="date" name="' . esc_attr(self::QP_FROM) . '" value="' . esc_attr($from) . '">';
			echo '</div>';

			echo '<div>';
			echo '<label for="mo_to" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('To date', self::TEXT_DOMAIN) . '</label>';
			echo '<input id="mo_to" type="date" name="' . esc_attr(self::QP_TO) . '" value="' . esc_attr($to) . '">';
			echo '</div>';

			$options = self::groups_per_page_options();
			echo '<div>';
			echo '<label for="mo_per_page" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html__('Groups per page', self::TEXT_DOMAIN) . '</label>';
			echo '<select id="mo_per_page" name="' . esc_attr(self::QP_PER_PAGE) . '">';
			foreach ($options as $opt) {
				$selected = selected($per_page, (int) $opt, false);
				echo '<option value="' . esc_attr((int) $opt) . '" ' . $selected . '>' . esc_html((int) $opt) . '</option>';
			}
			echo '</select>';
			echo '</div>';

			echo '<div>';
			echo '<button type="submit" class="button button-primary">' . esc_html__('Apply Filters', self::TEXT_DOMAIN) . '</button> ';
			echo '<a class="button" href="' . esc_url(self::build_admin_url()) . '">' . esc_html__('Reset', self::TEXT_DOMAIN) . '</a>';
			echo '</div>';

			echo '</div>'; // flex
			echo '</form>';

			// Summary line
			$range_text = '';
			if ($from !== '' || $to !== '') {
				$range_text = sprintf(
					/* translators: 1: from date 2: to date */
					__('Date range: %1$s – %2$s', self::TEXT_DOMAIN),
					$from !== '' ? $from : '…',
					$to !== '' ? $to : '…'
				);
			}

			echo '<p style="margin:10px 0 16px;">';
			echo esc_html(sprintf(
				/* translators: %d min group size */
				__('Showing only customers with %d+ eligible orders.', self::TEXT_DOMAIN),
				$min_group
			));

			if ($range_text !== '') {
				echo ' <span style="color:#666;">' . esc_html($range_text) . '</span>';
			}
			echo '</p>';

			// Pagination (top)
			if ($total_pages > 1) {
				echo self::render_groups_pagination($paged, $total_pages, $from, $to, $per_page, $total_groups);
			}

			if (empty($groups_page)) {
				echo '<p>' . esc_html__('No customer group has enough eligible orders for the current filters.', self::TEXT_DOMAIN) . '</p>';
				echo '</div>';
				return;
			}

			foreach ($groups_page as $group_key => $data) {
				$orders = $data['orders'];

				/** @var WC_Order $first */
				$first = $orders[0];

				$user_id = (int) $first->get_user_id();
				$email   = (string) $first->get_billing_email();

				$display_name = self::get_customer_display_name_for_group($first);

				$subtitle_bits = array();
				if ($user_id > 0) {
					$subtitle_bits[] = 'User ID: ' . $user_id;
				}
				if (!empty($email)) {
					$subtitle_bits[] = $email;
				}

				echo '<hr style="margin:24px 0 16px;">';
				echo '<h2 style="margin:0 0 6px;">' .
					esc_html($display_name) .
					' <span style="font-weight:normal;color:#666;">(' . esc_html(count($orders)) . ')</span>' .
				'</h2>';

				if (!empty($subtitle_bits)) {
					echo '<p style="margin:0 0 12px;color:#666;">' . esc_html(implode(' • ', $subtitle_bits)) . '</p>';
				}

				// Per-group form: prevents cross-customer merge
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
				echo '<input type="hidden" name="action" value="merge_selected_orders">';
				wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr>';
				echo '<th style="width:70px;">' . esc_html__('Select', self::TEXT_DOMAIN) . '</th>';
				echo '<th>' . esc_html__('Order', self::TEXT_DOMAIN) . '</th>';
				echo '<th>' . esc_html__('Customer Name', self::TEXT_DOMAIN) . '</th>'; // added back
				echo '<th>' . esc_html__('Date', self::TEXT_DOMAIN) . '</th>';
				echo '<th>' . esc_html__('Status', self::TEXT_DOMAIN) . '</th>';
				echo '<th>' . esc_html__('Payment', self::TEXT_DOMAIN) . '</th>';
				echo '<th style="width:140px;">' . esc_html__('Total', self::TEXT_DOMAIN) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ($orders as $order) {
					if (!$order instanceof \WC_Order) {
						continue;
					}

					$order_id     = $order->get_id();
					$order_number = $order->get_order_number();
					$status_label = wc_get_order_status_name($order->get_status());

					$date_created = $order->get_date_created();
					$date_str     = $date_created ? $date_created->date_i18n('Y-m-d H:i') : '';

					$pm_title = $order->get_payment_method_title();
					if (empty($pm_title)) {
						$pm_title = $order->get_payment_method() ? $order->get_payment_method() : '—';
					}

					$customer_name = self::get_customer_name_for_row($order);

					echo '<tr>';
					echo '<td><input type="checkbox" name="order_ids[]" value="' . esc_attr($order_id) . '"></td>';

					$edit_url = self::get_order_edit_url($order_id);
					echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html('#' . $order_number) . '</a></td>';

					echo '<td>' . esc_html($customer_name) . '</td>';
					echo '<td>' . esc_html($date_str) . '</td>';
					echo '<td>' . esc_html($status_label) . '</td>';
					echo '<td>' . esc_html($pm_title) . '</td>';
					echo '<td>' . wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';

				echo '<p style="margin-top:12px;">';
				echo '<button type="submit" class="button button-primary">' . esc_html__('Merge / Process Selected Orders', self::TEXT_DOMAIN) . '</button>';
				echo '</p>';

				echo '</form>';
			}

			// Pagination (bottom)
			if ($total_pages > 1) {
				echo self::render_groups_pagination($paged, $total_pages, $from, $to, $per_page, $total_groups);
			}

			echo '<p style="margin-top:24px;color:#666;">';
			echo wp_kses_post(sprintf(
				__('Powered by <a href="%s" target="_blank" rel="noopener">Hostify</a>.', self::TEXT_DOMAIN),
				'https://hostify.co.za'
			));
			echo '</p>';

			echo '</div>';
		}

		private static function render_groups_pagination(
			int $current_page,
			int $total_pages,
			string $from,
			string $to,
			int $per_page,
			int $total_groups
		): string {
			$start = ($current_page - 1) * $per_page + 1;
			$end   = min($total_groups, $current_page * $per_page);

			$base_args = array(
				'page' => self::MENU_SLUG,
				self::QP_FROM => $from !== '' ? $from : null,
				self::QP_TO   => $to !== '' ? $to : null,
				self::QP_PER_PAGE => $per_page,
			);

			$base_url = self::build_admin_url($base_args);
			$base_url = remove_query_arg(self::QP_PAGED, $base_url);

			$links = paginate_links(array(
				'base'      => add_query_arg(self::QP_PAGED, '%#%', $base_url),
				'format'    => '',
				'current'   => $current_page,
				'total'     => $total_pages,
				'prev_text' => __('« Previous', self::TEXT_DOMAIN),
				'next_text' => __('Next »', self::TEXT_DOMAIN),
				'type'      => 'array',
			));

			$html = '<div style="margin:10px 0 16px;padding:10px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">';
			$html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">';

			$html .= '<div style="color:#666;">' . esc_html(sprintf(
				/* translators: 1: start 2: end 3: total */
				__('Showing groups %1$d–%2$d of %3$d', self::TEXT_DOMAIN),
				$start,
				$end,
				$total_groups
			)) . '</div>';

			if (is_array($links) && !empty($links)) {
				$html .= '<div class="tablenav-pages" style="margin:0;">' . implode(' ', array_map('wp_kses_post', $links)) . '</div>';
			}

			$html .= '</div></div>';

			return $html;
		}

		public static function render_confirmation_page(): void {
			if (!current_user_can('manage_woocommerce')) {
				return;
			}

			$new_order_id = isset($_GET['new_order_id']) ? absint($_GET['new_order_id']) : 0;

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__('Order Merge Result', self::TEXT_DOMAIN) . '</h1>';

			if (!$new_order_id) {
				echo '<p>' . esc_html__('No order ID provided.', self::TEXT_DOMAIN) . '</p></div>';
				return;
			}

			$order = wc_get_order($new_order_id);
			if (!$order) {
				echo '<p>' . esc_html__('The order could not be found.', self::TEXT_DOMAIN) . '</p></div>';
				return;
			}

			$edit_url = self::get_order_edit_url($new_order_id);

			echo '<div class="notice notice-success"><p>' . esc_html__('Order merged successfully.', self::TEXT_DOMAIN) . '</p></div>';
			echo '<p>' . esc_html__('Created order:', self::TEXT_DOMAIN) . ' ';
			echo '<a href="' . esc_url($edit_url) . '">' . esc_html('#' . $order->get_order_number()) . '</a></p>';

			echo '</div>';
		}

		private static function get_order_edit_url(int $order_id): string {
			if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
				return (string) \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($order_id);
			}

			$link = get_edit_post_link($order_id, '');
			return $link ? (string) $link : admin_url('post.php?post=' . $order_id . '&action=edit');
		}

		public static function handle_merge_orders_submission(): void {
			if (!current_user_can('manage_woocommerce')) {
				wp_die(esc_html__('You do not have permission to merge orders.', self::TEXT_DOMAIN));
			}

			check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

			$order_ids = isset($_POST['order_ids']) ? (array) $_POST['order_ids'] : array();
			$order_ids = array_values(array_filter(array_map('absint', $order_ids)));

			if (empty($order_ids)) {
				self::redirect_back_with_error(__('No orders were selected.', self::TEXT_DOMAIN));
			}

			$result = self::merge_orders($order_ids);

			if (is_wp_error($result)) {
				self::redirect_back_with_error($result->get_error_message());
			}

			$new_order_id = (int) $result;
			wp_safe_redirect(admin_url('admin.php?page=' . self::CONFIRM_SLUG . '&new_order_id=' . $new_order_id));
			exit;
		}

		private static function redirect_back_with_error(string $message): void {
			$ref = wp_get_referer();
			if (!$ref) {
				$ref = admin_url('admin.php?page=' . self::MENU_SLUG);
			}

			$url = add_query_arg(
				array('merge_error' => rawurlencode($message)),
				$ref
			);

			wp_safe_redirect($url);
			exit;
		}

		/**
		 * Production-grade feature #2:
		 * Consolidate identical line items (same product_id + variation_id + tax_class + meta fingerprint)
		 * into a single line with summed qty/totals/taxes.
		 *
		 * You can disable via:
		 * add_filter('hostify_merge_orders_consolidate_line_items', fn() => false);
		 */
		private static function consolidate_line_items_enabled(): bool {
			return (bool) apply_filters('hostify_merge_orders_consolidate_line_items', true);
		}

		/**
		 * Merge orders core.
		 */
		public static function merge_orders(array $order_ids) {
			if (!self::wc_ready()) {
				return new \WP_Error('merge_orders_wc_not_ready', __('WooCommerce is not available.', self::TEXT_DOMAIN));
			}

			$order_ids = array_values(array_unique(array_filter(array_map('absint', (array) $order_ids))));
			if (empty($order_ids)) {
				return new \WP_Error('merge_orders_no_orders', __('No valid orders selected for merging.', self::TEXT_DOMAIN));
			}

			$orders = array();
			foreach ($order_ids as $order_id) {
				$order = wc_get_order($order_id);
				if ($order instanceof \WC_Order) {
					$orders[$order_id] = $order;
				}
			}

			if (empty($orders)) {
				return new \WP_Error('merge_orders_no_orders', __('No valid orders selected for merging.', self::TEXT_DOMAIN));
			}

			$eligible_statuses = self::eligible_statuses();

			// Validate: same customer, same currency, eligible status, no card payments
			$group_key              = null;
			$currency               = '';
			$customer_id            = 0;
			$customer_email         = '';
			$billing_address        = array();
			$shipping_address       = array();

			$has_processing          = false;
			$primary_payment_method  = '';
			$primary_payment_title   = '';
			$payment_methods_seen    = array();

			$any_stock_reduced        = false;
			$stock_reduced_source_ids = array();

			foreach ($orders as $order_id => $order) {
				$status = $order->get_status();

				if (!in_array($status, $eligible_statuses, true)) {
					return new \WP_Error(
						'merge_orders_invalid_status',
						sprintf(
							/* translators: %s order status */
							__('Selected order has ineligible status: %s', self::TEXT_DOMAIN),
							wc_get_order_status_name($status)
						)
					);
				}

				if (self::is_card_payment($order)) {
					return new \WP_Error(
						'merge_orders_card_payment',
						__('Merging is not allowed for orders paid by card.', self::TEXT_DOMAIN)
					);
				}

				if ('processing' === $status) {
					$has_processing = true;
				}

				$key = self::customer_group_key($order);
				if ($group_key === null) {
					$group_key = $key;
				} elseif ($key !== $group_key) {
					return new \WP_Error(
						'merge_orders_mixed_customers',
						__('Selected orders must belong to the same customer.', self::TEXT_DOMAIN)
					);
				}

				$order_currency = (string) $order->get_currency();
				if ($currency === '') {
					$currency = $order_currency;
				} elseif ($currency !== $order_currency) {
					return new \WP_Error(
						'merge_orders_mixed_currency',
						__('Selected orders must have the same currency.', self::TEXT_DOMAIN)
					);
				}

				if ($customer_id === 0) {
					$uid = (int) $order->get_user_id();
					if ($uid > 0) {
						$customer_id = $uid;
					}
				}

				if ($customer_email === '') {
					$customer_email = (string) $order->get_billing_email();
				}
				if (empty($billing_address)) {
					$billing_address = (array) $order->get_address('billing');
				}
				if (empty($shipping_address)) {
					$shipping_address = (array) $order->get_address('shipping');
				}

				$pm = (string) $order->get_payment_method();
				if ($pm !== '') {
					$payment_methods_seen[$pm] = (string) $order->get_payment_method_title();

					if ($pm === 'cod') {
						$primary_payment_method = 'cod';
						$primary_payment_title  = (string) $order->get_payment_method_title();
					} elseif ($primary_payment_method === '') {
						$primary_payment_method = $pm;
						$primary_payment_title  = (string) $order->get_payment_method_title();
					}
				}

				$stock_reduced = (string) $order->get_meta('_order_stock_reduced', true);
				if ('yes' === $stock_reduced) {
					$any_stock_reduced = true;
					$stock_reduced_source_ids[] = (int) $order_id;
				}
			}

			// Single order “process/resend” behavior
			if (count($orders) === 1) {
				/** @var WC_Order $single */
				$single = reset($orders);

				$target_status = ('processing' === $single->get_status()) ? 'processing' : 'pending';

				if ($single->get_status() !== $target_status) {
					$single->update_status(
						$target_status,
						__('Order processed via Merge Orders tool.', self::TEXT_DOMAIN),
						false
					);
				}

				// Explicit resend/notify
				if ('pending' === $target_status) {
					self::trigger_customer_invoice_email($single);
				} elseif ('processing' === $target_status) {
					self::trigger_customer_processing_email($single);
				}

				return $single->get_id();
			}

			// Result status: processing if any source is processing; else pending
			$final_status = $has_processing ? 'processing' : 'pending';
			$final_status = (string) apply_filters('hostify_merge_orders_result_status', $final_status, $orders);

			// Stock policy:
			// If we need to reduce stock on new merged order, we can safely:
			//   - increase stock back for source orders that reduced stock
			//   - then reduce once for the new order
			//
			// If the final status should NOT reduce stock but some sources already reduced -> abort to prevent stock mismatch.
			$reduce_stock_statuses = (array) apply_filters('hostify_merge_orders_reduce_stock_statuses', array('processing', 'completed'));
			$reduce_stock_statuses = array_map('sanitize_key', $reduce_stock_statuses);

			$should_reduce_stock = in_array(sanitize_key($final_status), $reduce_stock_statuses, true);

			if ($any_stock_reduced && !$should_reduce_stock) {
				return new \WP_Error(
					'merge_orders_stock_policy_block',
					__('Cannot merge: some source orders already reduced stock, but the resulting status would not reduce stock. Please adjust statuses or configuration.', self::TEXT_DOMAIN)
				);
			}

			// Create merged order
			try {
				$new_order = wc_create_order(array(
					'customer_id' => $customer_id > 0 ? $customer_id : 0,
					'status'      => 'pending',
				));
			} catch (\Throwable $e) {
				return new \WP_Error('merge_orders_create_failed', __('Failed to create new order.', self::TEXT_DOMAIN));
			}

			if (!$new_order instanceof \WC_Order) {
				return new \WP_Error('merge_orders_create_failed', __('Failed to create new order.', self::TEXT_DOMAIN));
			}

			if ($currency !== '') {
				$new_order->set_currency($currency);
			}

			$new_order->set_created_via('hostify_merge_orders');

			if ($customer_email !== '') {
				$new_order->set_billing_email($customer_email);
			}
			if (!empty($billing_address)) {
				$new_order->set_address($billing_address, 'billing');
			}
			if (!empty($shipping_address)) {
				$new_order->set_address($shipping_address, 'shipping');
			}

			if ($primary_payment_method !== '') {
				$new_order->set_payment_method($primary_payment_method);
				if ($primary_payment_title !== '') {
					$new_order->set_payment_method_title($primary_payment_title);
				}
			}

			$new_order->update_meta_data('_hostify_merged_from_order_ids', array_keys($orders));

			// Copy all items; with consolidation for line items
			self::copy_all_items_from_orders($orders, $new_order);

			// Order-level meta copy: default from first only (safer).
			$copy_meta_all = (bool) apply_filters('hostify_merge_orders_copy_meta_from_all_orders', false);
			$is_first = true;
			foreach ($orders as $src_order) {
				if ($copy_meta_all || $is_first) {
					self::copy_order_level_meta($src_order, $new_order, $is_first);
				}
				$is_first = false;
			}

			// Note if multiple payment methods
			if (count($payment_methods_seen) > 1) {
				$list = array();
				foreach ($payment_methods_seen as $pm_id => $title) {
					$list[] = $pm_id . ($title ? (' (' . $title . ')') : '');
				}
				$new_order->add_order_note(
					sprintf(
						/* translators: %s payment method list */
						__('Merged orders had multiple payment methods: %s', self::TEXT_DOMAIN),
						implode(', ', $list)
					),
					false
				);
			}

			// Totals: preserve taxes by default (no recalc)
			$and_taxes = (bool) apply_filters('hostify_merge_orders_calculate_totals_and_taxes', false, $orders, $new_order);
			$new_order->calculate_totals($and_taxes);

			// BEX: if COD, set otkup = total
			if ('cod' === $new_order->get_payment_method()) {
				$new_order->update_meta_data('_bex_pay_to_sender', (float) $new_order->get_total());
			}

			$final_note = ('processing' === $final_status)
				? __('New merged order created (at least one source order was processing).', self::TEXT_DOMAIN)
				: __('New merged order created (pending payment).', self::TEXT_DOMAIN);

			$new_order->set_status($final_status, $final_note);
			$new_order->save();

			// Stock correction (only if should reduce stock):
			// - reverse stock reduced in source orders
			// - reduce once for new order
			if ($should_reduce_stock) {
				if (!empty($stock_reduced_source_ids) && function_exists('wc_increase_stock_levels')) {
					foreach ($stock_reduced_source_ids as $src_id) {
						$src = $orders[$src_id] ?? null;
						if ($src instanceof \WC_Order) {
							// Return stock for that source
							wc_increase_stock_levels($src_id);
							// Mark source as not reduced (so future cancels won't increase again)
							$src->delete_meta_data('_order_stock_reduced');
						}
					}
				}

				// Ensure merged order reduces stock once (if not already)
				if (function_exists('wc_maybe_reduce_stock_levels')) {
					wc_maybe_reduce_stock_levels($new_order->get_id());
				} elseif (function_exists('wc_reduce_stock_levels')) {
					// Fallback
					wc_reduce_stock_levels($new_order->get_id());
				}
			}

			// Update source orders -> merged + link
			foreach ($orders as $src_order) {
				$src_order->update_meta_data('_hostify_merged_into_order_id', $new_order->get_id());

				$src_order->update_status(
					'merged',
					sprintf(
						/* translators: %d merged order id */
						__('Order merged into order #%d', self::TEXT_DOMAIN),
						$new_order->get_id()
					),
					false
				);

				$src_order->save();
			}

			// Email: invoice for pending (payment link)
			if ('pending' === $final_status) {
				self::trigger_customer_invoice_email($new_order);
			}

			do_action(
				'hostify_merge_orders_after_merge',
				$new_order->get_id(),
				array_keys($orders),
				$new_order,
				$orders
			);

			return $new_order->get_id();
		}

		/**
		 * Copy items from multiple orders into target:
		 * - line items: consolidated if enabled
		 * - shipping: one charge by default (prevents duplicate fixed delivery fees)
		 * - fees/coupons: copied as-is per source
		 */
		private static function copy_all_items_from_orders(array $source_orders, \WC_Order $target): void {
			$consolidate = self::consolidate_line_items_enabled();

			// Accumulator for consolidated line items.
			$line_agg = array();

			// Shipping lines are collected first so duplicate fixed delivery fees can be collapsed to one charge.
			$shipping_items = array();

			foreach ($source_orders as $source) {
				if (!$source instanceof \WC_Order) {
					continue;
				}

				// Line items
				foreach ($source->get_items('line_item') as $item) {
					if (!$item instanceof \WC_Order_Item_Product) {
						continue;
					}

					if (!$consolidate) {
						$new_item = self::clone_product_item($source, $item);
						$target->add_item($new_item);
						continue;
					}

					$key_data = self::line_item_consolidation_key_and_meta($item);
					$key      = $key_data['key'];
					$meta     = $key_data['meta'];
					$trace    = self::get_line_item_source_trace($source, $item);

					if (!isset($line_agg[$key])) {
						$line_agg[$key] = array(
							'name'         => $item->get_name(),
							'product_id'   => $item->get_product_id(),
							'variation_id' => $item->get_variation_id(),
							'tax_class'    => $item->get_tax_class(),
							'quantity'     => (int) $item->get_quantity(),

							'subtotal'     => (string) $item->get_subtotal(),
							'total'        => (string) $item->get_total(),
							'subtotal_tax' => (string) $item->get_subtotal_tax(),
							'total_tax'    => (string) $item->get_total_tax(),
							'taxes'        => (array) $item->get_taxes(),

							'meta'         => $meta,
							'source_trace' => $trace,
						);
					} else {
						$line_agg[$key]['quantity'] += (int) $item->get_quantity();

						$line_agg[$key]['subtotal']     = self::sum_money($line_agg[$key]['subtotal'], $item->get_subtotal());
						$line_agg[$key]['total']        = self::sum_money($line_agg[$key]['total'], $item->get_total());
						$line_agg[$key]['subtotal_tax'] = self::sum_money($line_agg[$key]['subtotal_tax'], $item->get_subtotal_tax());
						$line_agg[$key]['total_tax']    = self::sum_money($line_agg[$key]['total_tax'], $item->get_total_tax());

						$line_agg[$key]['taxes'] = self::sum_taxes_arrays($line_agg[$key]['taxes'], (array) $item->get_taxes());
						$line_agg[$key]['source_trace'] = self::merge_source_line_item_traces(
							$line_agg[$key]['source_trace'] ?? array(),
							$trace
						);
					}
				}

				// Shipping (added after the loop according to the configured shipping merge policy).
				foreach ($source->get_items('shipping') as $ship_item) {
					if ($ship_item instanceof \WC_Order_Item_Shipping) {
						$shipping_items[] = self::clone_shipping_item($ship_item);
					}
				}

				// Fees
				foreach ($source->get_items('fee') as $fee_item) {
					if ($fee_item instanceof \WC_Order_Item_Fee) {
						$target->add_item(self::clone_fee_item($fee_item));
					}
				}

				// Coupons
				foreach ($source->get_items('coupon') as $coupon_item) {
					if ($coupon_item instanceof \WC_Order_Item_Coupon) {
						$target->add_item(self::clone_coupon_item($coupon_item));
					}
				}
			}

			// Add shipping after all source orders are inspected. By default, this keeps only one fixed delivery charge.
			self::add_shipping_items_to_target($target, $shipping_items);

			// Add consolidated line items last (order of items is not critical)
			if ($consolidate && !empty($line_agg)) {
				foreach ($line_agg as $row) {
					$new_item = new \WC_Order_Item_Product();
					$new_item->set_props(array(
						'name'         => (string) $row['name'],
						'product_id'   => (int) $row['product_id'],
						'variation_id' => (int) $row['variation_id'],
						'quantity'     => (int) $row['quantity'],

						'subtotal'     => $row['subtotal'],
						'total'        => $row['total'],
						'subtotal_tax' => $row['subtotal_tax'],
						'total_tax'    => $row['total_tax'],
						'taxes'        => $row['taxes'],
						'tax_class'    => $row['tax_class'],
					));

					// Restore meta (exact values from item)
					if (!empty($row['meta']) && is_array($row['meta'])) {
						foreach ($row['meta'] as $m) {
							if (!isset($m['key'])) {
								continue;
							}
							$k = (string) $m['key'];
							if ($k === '') {
								continue;
							}
							$new_item->add_meta_data($k, $m['value'] ?? '', false);
						}
					}

					self::add_source_trace_meta_to_line_item(
						$new_item,
						isset($row['source_trace']) && is_array($row['source_trace']) ? $row['source_trace'] : array()
					);

					$target->add_item($new_item);
				}
			}
		}

		private static function sum_money($a, $b): string {
			$decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
			$sum = (float) $a + (float) $b;

			if (function_exists('wc_format_decimal')) {
				return (string) wc_format_decimal($sum, $decimals);
			}
			return number_format($sum, $decimals, '.', '');
		}

		private static function sum_taxes_arrays(array $t1, array $t2): array {
			$out = $t1;

			foreach (array('total', 'subtotal') as $bucket) {
				if (!isset($out[$bucket]) || !is_array($out[$bucket])) {
					$out[$bucket] = array();
				}
				$add = isset($t2[$bucket]) && is_array($t2[$bucket]) ? $t2[$bucket] : array();

				foreach ($add as $tax_id => $amount) {
					$tax_id = (string) $tax_id;
					$out[$bucket][$tax_id] = (float) ($out[$bucket][$tax_id] ?? 0) + (float) $amount;
				}
			}

			return $out;
		}

		/**
		 * Build a safe consolidation key for a product item:
		 * - product_id, variation_id, tax_class
		 * - meta fingerprint (sorted key/value pairs)
		 *
		 * Also returns meta list to re-apply after consolidation.
		 */
		private static function line_item_consolidation_key_and_meta(\WC_Order_Item_Product $item): array {
			$ignore_keys = array_merge(
				self::provenance_meta_keys(),
				(array) apply_filters('hostify_merge_orders_line_item_meta_ignore_keys', array(), $item)
			);
			$ignore_keys = array_map('strval', $ignore_keys);

			$fingerprint_pairs = array();
			$meta_list = array();

			foreach ($item->get_meta_data() as $meta) {
				$k = isset($meta->key) ? (string) $meta->key : '';
				if ($k === '') {
					continue;
				}
				if (in_array($k, $ignore_keys, true)) {
					continue;
				}

				$v = $meta->value;

				$meta_list[] = array(
					'key'   => $k,
					'value' => $v,
				);

				$fingerprint_pairs[] = array(
					$k,
					maybe_serialize($v),
				);
			}

			usort($fingerprint_pairs, function($a, $b) {
				$ak = (string) ($a[0] ?? '');
				$bk = (string) ($b[0] ?? '');
				if ($ak === $bk) {
					return strcmp((string) ($a[1] ?? ''), (string) ($b[1] ?? ''));
				}
				return strcmp($ak, $bk);
			});

			$hash = md5(wp_json_encode($fingerprint_pairs));

			$key = implode(':', array(
				(int) $item->get_product_id(),
				(int) $item->get_variation_id(),
				(string) $item->get_tax_class(),
				$hash,
			));

			return array(
				'key'  => $key,
				'meta' => $meta_list,
			);
		}

		private static function clone_product_item(\WC_Order $source_order, \WC_Order_Item_Product $item): \WC_Order_Item_Product {
			$new_item = new \WC_Order_Item_Product();
			$new_item->set_props(array(
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),

				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'subtotal_tax' => $item->get_subtotal_tax(),
				'total_tax'    => $item->get_total_tax(),
				'taxes'        => $item->get_taxes(),
				'tax_class'    => $item->get_tax_class(),
			));

			foreach ($item->get_meta_data() as $meta) {
				if (!empty($meta->key) && !in_array((string) $meta->key, self::provenance_meta_keys(), true)) {
					$new_item->add_meta_data($meta->key, $meta->value, false);
				}
			}

			self::add_source_trace_meta_to_line_item($new_item, self::get_line_item_source_trace($source_order, $item));

			return $new_item;
		}

		private static function provenance_meta_keys(): array {
			return array(
				'_hostify_source_order_ids',
				'_hostify_source_order_item_ids',
				'_hostify_source_line_items',
			);
		}

		private static function get_line_item_source_trace(\WC_Order $source_order, \WC_Order_Item_Product $item): array {
			$trace = self::normalize_source_line_items($item->get_meta('_hostify_source_line_items', true));
			if (!empty($trace)) {
				return $trace;
			}

			$order_id      = (int) $source_order->get_id();
			$order_item_id = (int) $item->get_id();
			$quantity      = (float) $item->get_quantity();

			return self::normalize_source_line_items(array(
				array(
					'order_id'      => $order_id,
					'order_item_id' => $order_item_id,
					'quantity'      => $quantity,
				),
			));
		}

		private static function normalize_source_line_items($value): array {
			if (!is_array($value)) {
				return array();
			}

			$out  = array();
			$seen = array();

			foreach ($value as $row) {
				if (!is_array($row)) {
					continue;
				}

				$order_id      = isset($row['order_id']) ? (int) $row['order_id'] : 0;
				$order_item_id = isset($row['order_item_id']) ? (int) $row['order_item_id'] : 0;

				if ($order_id <= 0 || $order_item_id <= 0) {
					continue;
				}

				if (!isset($row['quantity']) || !is_numeric($row['quantity'])) {
					continue;
				}
				$quantity = (float) $row['quantity'];

				$pair_key = $order_id . ':' . $order_item_id;
				if (isset($seen[$pair_key])) {
					continue;
				}

				$seen[$pair_key] = true;
				$out[] = array(
					'order_id'      => $order_id,
					'order_item_id' => $order_item_id,
					'quantity'      => $quantity,
				);
			}

			return $out;
		}

		private static function merge_source_line_item_traces(array $existing, array $incoming): array {
			return self::normalize_source_line_items(array_merge($existing, $incoming));
		}

		private static function add_source_trace_meta_to_line_item(\WC_Order_Item_Product $item, array $trace): void {
			$trace = self::normalize_source_line_items($trace);
			if (empty($trace)) {
				return;
			}

			$order_ids = array();
			$item_ids  = array();

			foreach ($trace as $row) {
				$order_ids[] = (int) $row['order_id'];
				$item_ids[]  = (int) $row['order_item_id'];
			}

			$item->update_meta_data('_hostify_source_order_ids', array_values(array_unique($order_ids)));
			$item->update_meta_data('_hostify_source_order_item_ids', array_values(array_unique($item_ids)));
			$item->update_meta_data('_hostify_source_line_items', $trace);
		}

		private static function single_shipping_charge_enabled(): bool {
			return (bool) apply_filters('hostify_merge_orders_single_shipping_charge', true);
		}

		private static function add_shipping_items_to_target(\WC_Order $target, array $shipping_items): void {
			$shipping_items = array_values(array_filter($shipping_items, function($item) {
				return $item instanceof \WC_Order_Item_Shipping;
			}));

			if (empty($shipping_items)) {
				return;
			}

			if (!self::single_shipping_charge_enabled()) {
				foreach ($shipping_items as $ship_item) {
					$target->add_item($ship_item);
				}
				return;
			}

			$selected = self::select_shipping_item_to_keep($shipping_items);
			if ($selected instanceof \WC_Order_Item_Shipping) {
				$target->add_item($selected);
			}

			$omitted = count($shipping_items) - 1;
			if ($omitted > 0) {
				$target->add_order_note(
					sprintf(
						/* translators: %d number of omitted shipping lines */
						__('Multiple source orders had shipping charges. Merge policy kept one shipping charge and omitted %d additional shipping line(s).', self::TEXT_DOMAIN),
						$omitted
					),
					false
				);
			}
		}

		private static function select_shipping_item_to_keep(array $shipping_items): ?\WC_Order_Item_Shipping {
			$best = null;
			$best_score = null;

			foreach ($shipping_items as $ship_item) {
				if (!$ship_item instanceof \WC_Order_Item_Shipping) {
					continue;
				}

				$score = self::shipping_item_score($ship_item);
				if (!$best instanceof \WC_Order_Item_Shipping || $score > (float) $best_score) {
					$best = $ship_item;
					$best_score = $score;
				}
			}

			return $best;
		}

		private static function shipping_item_score(\WC_Order_Item_Shipping $ship_item): float {
			$score = (float) $ship_item->get_total();
			$taxes = (array) $ship_item->get_taxes();

			if (isset($taxes['total']) && is_array($taxes['total'])) {
				foreach ($taxes['total'] as $amount) {
					$score += (float) $amount;
				}
			}

			return $score;
		}

		private static function clone_shipping_item(\WC_Order_Item_Shipping $ship_item): \WC_Order_Item_Shipping {
			$new_ship = new \WC_Order_Item_Shipping();
			$new_ship->set_method_title($ship_item->get_method_title());
			$new_ship->set_method_id($ship_item->get_method_id());
			$new_ship->set_total($ship_item->get_total());
			$new_ship->set_taxes($ship_item->get_taxes());

			foreach ($ship_item->get_meta_data() as $meta) {
				if (!empty($meta->key)) {
					$new_ship->add_meta_data($meta->key, $meta->value, false);
				}
			}

			return $new_ship;
		}

		private static function clone_fee_item(\WC_Order_Item_Fee $fee_item): \WC_Order_Item_Fee {
			$new_fee = new \WC_Order_Item_Fee();
			$new_fee->set_name($fee_item->get_name());
			$new_fee->set_tax_class($fee_item->get_tax_class());
			$new_fee->set_tax_status($fee_item->get_tax_status());

			$new_fee->set_total($fee_item->get_total());
			$new_fee->set_total_tax($fee_item->get_total_tax());
			$new_fee->set_taxes($fee_item->get_taxes());

			foreach ($fee_item->get_meta_data() as $meta) {
				if (!empty($meta->key)) {
					$new_fee->add_meta_data($meta->key, $meta->value, false);
				}
			}

			return $new_fee;
		}

		private static function clone_coupon_item(\WC_Order_Item_Coupon $coupon_item): \WC_Order_Item_Coupon {
			$new_coupon = new \WC_Order_Item_Coupon();
			$new_coupon->set_code($coupon_item->get_code());
			$new_coupon->set_discount($coupon_item->get_discount());
			$new_coupon->set_discount_tax($coupon_item->get_discount_tax());

			foreach ($coupon_item->get_meta_data() as $meta) {
				if (!empty($meta->key)) {
					$new_coupon->add_meta_data($meta->key, $meta->value, false);
				}
			}

			return $new_coupon;
		}

		private static function copy_order_level_meta(\WC_Order $source, \WC_Order $target, bool $is_first_source): void {
			$skip_meta_keys = array(
				'_order_currency',
				'_order_key',
				'_order_total',
				'_order_tax',
				'_order_shipping',
				'_order_shipping_tax',
				'_cart_hash',
				'_edit_lock',
				'_edit_last',
				'_date_created',
				'_date_completed',
				'_date_paid',
				'_paid_date',
				'_transaction_id',
				'_order_version',
				'_customer_user',
				'_order_stock_reduced',

				'_payment_method',
				'_payment_method_title',
			);

			$skip_meta_keys = (array) apply_filters('hostify_merge_orders_skip_meta_keys', $skip_meta_keys, $source, $target);

			foreach ($source->get_meta_data() as $meta) {
				$key = (string) $meta->key;
				if ($key === '') {
					continue;
				}

				if (strpos($key, '_billing_') === 0 || strpos($key, '_shipping_') === 0) {
					continue;
				}

				if (in_array($key, $skip_meta_keys, true)) {
					continue;
				}

				if (!$is_first_source) {
					continue;
				}

				$existing = $target->get_meta($key, true);
				if ($existing !== '' && $existing !== null) {
					continue;
				}

				$target->update_meta_data($key, $meta->value);
			}
		}

		private static function is_order_merge_candidate(\WC_Order $order): bool {
			$is_candidate = !self::is_card_payment($order);

			return (bool) apply_filters('hostify_merge_orders_is_order_candidate', $is_candidate, $order);
		}

		private static function normalize_gateway_text(string $value): string {
			$value = trim($value);
			if ($value === '') {
				return '';
			}

			return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
		}

		private static function is_card_payment(\WC_Order $order): bool {
			$method_raw = (string) $order->get_payment_method();
			$method     = sanitize_key($method_raw);
			$title      = self::normalize_gateway_text((string) $order->get_payment_method_title());
			$method_lc  = self::normalize_gateway_text($method_raw);

			if ($method === '' && $title === '') {
				return false;
			}

			$default = array(
				'stripe',
				'stripe_cc',
				'woocommerce_payments',
				'woocommerce_payments_card',
				'wc_payments',
				'braintree',
				'authorizenet',
				'paypal_pro',
				'nestpay',
				'monri',
				'wspay',
				'corvuspay',
				'payten',
				'bankart',
				'chipcard',
			);

			// Backward compat.
			$card_methods = (array) apply_filters('merge_orders_card_payment_methods', $default);
			$card_methods = (array) apply_filters('hostify_merge_orders_card_payment_methods', $card_methods, $order);
			$card_methods = array_values(array_unique(array_filter(array_map('sanitize_key', $card_methods))));

			if ($method !== '' && in_array($method, $card_methods, true)) {
				return true;
			}

			$default_prefixes = array(
				'stripe',
				'wc_payments',
				'woocommerce_payments',
				'braintree',
				'authorizenet',
				'nestpay',
				'monri',
				'wspay',
				'corvuspay',
				'payten',
				'bankart',
				'chipcard',
			);
			$prefixes = (array) apply_filters('hostify_merge_orders_card_payment_method_prefixes', $default_prefixes, $order);
			$prefixes = array_values(array_unique(array_filter(array_map('sanitize_key', $prefixes))));

			foreach ($prefixes as $prefix) {
				if ($prefix !== '' && $method !== '' && strpos($method, $prefix) === 0) {
					return true;
				}
			}

			$default_keywords = array(
				'card',
				'credit card',
				'debit card',
				'kartic',
				'kartica',
				'kreditna',
				'debitna',
				'visa',
				'mastercard',
				'maestro',
				'amex',
			);
			$keywords = (array) apply_filters('hostify_merge_orders_card_payment_keywords', $default_keywords, $order);
			$haystack = trim($method_lc . ' ' . $title);

			foreach ($keywords as $keyword) {
				$keyword = self::normalize_gateway_text((string) $keyword);
				if ($keyword !== '' && $haystack !== '' && strpos($haystack, $keyword) !== false) {
					return true;
				}
			}

			if (method_exists($order, 'get_payment_tokens')) {
				foreach ((array) $order->get_payment_tokens() as $token) {
					if ((is_int($token) || ctype_digit((string) $token)) && class_exists('WC_Payment_Tokens')) {
						$token = \WC_Payment_Tokens::get((int) $token);
					}

					if ($token instanceof \WC_Payment_Token_CC) {
						return true;
					}

					if (is_object($token) && method_exists($token, 'get_type') && 'CC' === (string) $token->get_type()) {
						return true;
					}
				}
			}

			return false;
		}

		private static function trigger_customer_invoice_email(\WC_Order $order): void {
			$mailer = \WC()->mailer();
			if (!$mailer) {
				return;
			}

			$mails = $mailer->get_emails();
			if (isset($mails['WC_Email_Customer_Invoice'])) {
				$mails['WC_Email_Customer_Invoice']->trigger($order->get_id());
			}
		}

		private static function trigger_customer_processing_email(\WC_Order $order): void {
			$mailer = \WC()->mailer();
			if (!$mailer) {
				return;
			}

			$mails = $mailer->get_emails();
			if (isset($mails['WC_Email_Customer_Processing_Order'])) {
				$mails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
			}
		}

		/**
		 * YITH Auctions: won => on-hold.
		 */
		public static function handle_auction_won($auction_id, $old_status, $new_status): void {
			if ($new_status !== 'won') {
				return;
			}

			if (!function_exists('wc_get_product')) {
				return;
			}

			$auction_product = wc_get_product($auction_id);
			if (!$auction_product || !$auction_product->is_type('auction')) {
				return;
			}

			$order_id = get_post_meta($auction_product->get_id(), '_order_id', true);
			$order_id = absint($order_id);
			if (!$order_id) {
				return;
			}

			$order = wc_get_order($order_id);
			if ($order instanceof \WC_Order) {
				$order->update_status('on-hold', __('Auction won, order placed on hold.', self::TEXT_DOMAIN), false);
				$order->save();
			}
		}
	}

	Hostify_Merge_Orders_For_WooCommerce::init();
}
