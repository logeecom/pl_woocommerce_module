<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce;

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\TaskRunnerStatusStorageUnavailableException;
use Packlink\BusinessLogic\ShippingMethod\Interfaces\ShopShippingMethodService;
use Packlink\WooCommerce\Components\Bootstrap_Component;
use Packlink\WooCommerce\Components\Checkout\Checkout_Handler;
use Packlink\WooCommerce\Components\Order\Order_Details_Helper;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Packlink_Shipping_Method;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\ShippingMethod\Shop_Shipping_Method_Service;
use Packlink\WooCommerce\Components\Utility\Database;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use Packlink\WooCommerce\Components\Utility\Task_Queue;
use Packlink\WooCommerce\Components\Utility\Version_File_Reader;
use Packlink\WooCommerce\Controllers\Packlink_Order_Details_Controller;
use Packlink\WooCommerce\Controllers\Packlink_Order_Overview_Controller;
use wpdb;

/**
 * Class Plugin
 *
 * @package Packlink\WooCommerce
 */
class Plugin {
	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	protected static $instance;
	/**
	 * WordPress database session.
	 *
	 * @var wpdb
	 */
	public $db;
	/**
	 * Configuration service instance.
	 *
	 * @var Config_Service
	 */
	private $config_service;
	/**
	 * Plugin file.
	 *
	 * @var string
	 */
	private $packlink_plugin_file;

	/**
	 * Plugin constructor.
	 *
	 * @param wpdb $wpdb WordPress database session.
	 * @param string $packlink_plugin_file Plugin file.
	 */
	public function __construct( $wpdb, $packlink_plugin_file ) {
		$this->db                   = $wpdb;
		$this->packlink_plugin_file = $packlink_plugin_file;
	}

	/**
	 * Returns singleton instance of the plugin.
	 *
	 * @param wpdb $wpdb WordPress database session.
	 * @param string $packlink_plugin_file Plugin file.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function instance( $wpdb, $packlink_plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $wpdb, $packlink_plugin_file );
		}

		self::$instance->initialize();

		return self::$instance;
	}

	/**
	 * Plugin activation function.
	 *
	 * @param bool $is_network_wide Is plugin network wide.
	 */
	public function activate( $is_network_wide ) {
		if ( ! Shop_Helper::is_curl_enabled() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html(
					__(
						'cURL is not installed or enabled in your PHP installation. This is required for background task to work. Please install it and then refresh this page.',
						'packlink-pro-shipping'
					)
				),
				'Plugin dependency check',
				array( 'back_link' => true )
			);
		}

		if ( ! Shop_Helper::is_woocommerce_active() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html( __( 'Please install and activate WooCommerce.', 'packlink-pro-shipping' ) ),
				'Plugin dependency check',
				array( 'back_link' => true )
			);
		}

		if ( $this->plugin_already_initialized() ) {
			Task_Queue::wakeup();
			Shipping_Method_Helper::enable_packlink_shipping_methods();
		} elseif ( $is_network_wide && is_multisite() ) {
			foreach ( get_sites() as $site ) {
				switch_to_blog( $site->blog_id );
				/** @noinspection DisconnectedForeachInstructionInspection */
				$this->init_database();
				/** @noinspection DisconnectedForeachInstructionInspection */
				$this->init_config();
				restore_current_blog();
			}
		} else {
			$this->init_database();
			$this->init_config();
		}
	}

	/**
	 * Plugin deactivation function.
	 *
	 * @param bool $is_network_wide Is plugin network wide.
	 */
	public function deactivate( $is_network_wide ) {
		if ( $is_network_wide && is_multisite() ) {
			foreach ( get_sites() as $site ) {
				switch_to_blog( $site->blog_id );
				Shipping_Method_Helper::disable_packlink_shipping_methods();
				restore_current_blog();
			}
		} else {
			Shipping_Method_Helper::disable_packlink_shipping_methods();
		}
	}

	/**
	 * Plugin update method.
	 *
	 * @param \WP_Upgrader $updater_object Updater object.
	 * @param array $options Options with information regarding plugins for update.
	 */
	public function update( $updater_object, $options ) {
		if ( $updater_object && $this->validate_if_plugin_update_is_for_our_plugin( $options ) ) {
			if ( is_multisite() ) {
				$site_ids = get_sites();
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id->blog_id );
					/** @noinspection DisconnectedForeachInstructionInspection */
					$this->update_plugin_on_single_site();
					restore_current_blog();
				}
			} else {
				$this->update_plugin_on_single_site();
			}
		}
	}

	/**
	 * Plugin uninstall method.
	 */
	public function uninstall() {
		if ( is_multisite() ) {
			$sites = get_sites();
			foreach ( $sites as $site ) {
				Shipping_Method_Helper::remove_packlink_shipping_methods();
				$this->switch_to_site_and_uninstall_plugin( $site->blog_id );
			}
		} else {
			Shipping_Method_Helper::remove_packlink_shipping_methods();
			$this->uninstall_plugin_from_site();
		}
	}

	/**
	 * Initializes base Packlink PRO Shipping tables and values if plugin is accessed from a new site.
	 */
	public function initialize_new_site() {
		$db = new Database( $this->db );
		if ( ! $db->plugin_already_initialized() ) {
			$this->init_database();
			$this->init_config();
		}
	}

	/**
	 * Loads plugin translations.
	 */
	public function load_plugin_text_domain() {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$locale = get_user_locale();
			$locale = apply_filters( 'plugin_locale', $locale, 'packlink-pro-shipping' );

			load_textdomain(
				'packlink-pro-shipping',
				WP_LANG_DIR . '/packlink-pro-shipping/packlink-pro-shipping-' . $locale . '.mo'
			);
			load_plugin_textdomain(
				'packlink-pro-shipping',
				false,
				plugin_basename( dirname( $this->packlink_plugin_file ) ) . '/languages'
			);
		}
	}

	/**
	 * Hook that triggers when network site is deleted and removes plugin data related to that site from the network.
	 *
	 * @param int $site_id Site identifier.
	 */
	public function uninstall_plugin_from_deleted_site( $site_id ) {
		$this->switch_to_site_and_uninstall_plugin( $site_id );
	}

	/**
	 * Creates Packlink PRO Shipping item in administrator menu.
	 */
	public function create_admin_submenu() {
		add_submenu_page(
			'woocommerce',
			'Packlink PRO',
			'Packlink PRO',
			'manage_options',
			'packlink-pro-shipping/index.php'
		);
	}

	/**
	 * Show action links on the plugin screen.
	 *
	 * @param array $links Plugin Action links.
	 *
	 * @return array
	 */
	public function create_configuration_link( array $links ) {
		$action_links = array(
			'configuration' => '<a href="' . admin_url( 'admin.php?page=packlink-pro-shipping/index.php' ) . '" aria-label="' . esc_attr__( 'View Packlink configuration', 'packlink-pro-shipping' ) . '">' . esc_html__( 'Configuration', 'packlink-pro-shipping' ) . '</a>',
		);

		return array_merge( $action_links, $links );
	}

	/**
	 * Adds Packlink PRO Shipping method to the list of all shipping methods.
	 *
	 * @param array $methods List of all shipping methods.
	 *
	 * @return array List of all shipping methods.
	 */
	public function add_shipping_method( array $methods ) {
		$methods['packlink_shipping_method'] = Packlink_Shipping_Method::CLASS_NAME;

		return $methods;
	}

	/**
	 * Adds active shipping methods to newly created shipping zone.
	 *
	 * @param \WC_Shipping_Zone $zone Shipping zone.
	 * @param \WC_Data_Store $data_store Shipping zone data store.
	 */
	public function on_zone_create( $zone, $data_store ) {
		if ( null !== $zone->get_id() ) {
			return;
		}

		$data_store->create( $zone );

		if ( $zone->get_id() ) {
			/** @var Shop_Shipping_Method_Service $service */
			$service = ServiceRegister::getService( ShopShippingMethodService::CLASS_NAME );
			$service->add_active_methods_to_zone( $zone );
		}
	}

	/**
	 * Adds Packlink PRO Shipping meta post box.
	 *
	 * @param string $page Current page type.
	 */
	public function add_packlink_shipping_box( $page ) {
		if ( 'shop_order' === $page ) {
			$controller = new Packlink_Order_Details_Controller();
			add_meta_box(
				'packlink-shipping-modal',
				__( 'Packlink PRO Shipping', 'packlink-pro-shipping' ),
				array( $controller, 'render' ),
				'shop_order',
				'side',
				'core'
			);
		}
	}

	/**
	 * Initializes the plugin.
	 */
	private function initialize() {
		Bootstrap_Component::init();
		$this->load_plugin_init_hooks();
		if ( Shop_Helper::is_plugin_enabled() ) {
			$this->add_settings_link();
			$this->load_admin_menu();
			$this->shipping_method_hooks_and_actions();
			$this->load_plugin_text_domain();
			$this->order_hooks_and_actions();
			$this->checkout_hooks_and_actions();
		}
	}

	/**
	 * Registers install and uninstall hook.
	 */
	private function load_plugin_init_hooks() {
		register_activation_hook( $this->packlink_plugin_file, array( $this, 'activate' ) );
		register_deactivation_hook( $this->packlink_plugin_file, array( $this, 'deactivate' ) );
		add_action( 'upgrader_process_complete', array( $this, 'update' ), 100, 2 );
		add_action( 'admin_init', array( $this, 'initialize_new_site' ) );
		add_action( 'plugins_loaded', array( $this, 'load_plugin_text_domain' ) );
		if ( is_multisite() ) {
			add_action( 'delete_blog', array( $this, 'uninstall_plugin_from_deleted_site' ) );
		}
	}

	/**
	 * Initializes plugin database.
	 */
	private function init_database() {
		$installer = new Database( $this->db );
		$installer->install();
	}

	/**
	 * Initializes default configuration values.
	 */
	private function init_config() {
		try {
			$this->get_config_service()->setTaskRunnerStatus( '', null );
			$statuses = array(
				'pending'    => 'wc-pending',
				'processing' => 'wc-processing',
				'delivered'  => 'wc-completed',
			);

			$this->get_config_service()->setOrderStatusMappings( $statuses );
		} catch ( TaskRunnerStatusStorageUnavailableException $e ) {
			Logger::logError( $e->getMessage(), 'Integration' );
		}

		$version = Shop_Helper::get_plugin_version();
		$this->get_config_service()->set_database_version( $version );
		if ( version_compare( $version, '2.0.0', '<=' ) ) {
			require_once __DIR__ . '/database/migrations/migration.v.1.0.2.php';
		}
	}

	/**
	 * Checks if plugin was already installed and initialized.
	 *
	 * @return bool Plugin initialized flag.
	 */
	private function plugin_already_initialized() {
		$handler = new Database( $this->db );

		return $handler->plugin_already_initialized();
	}

	/**
	 * Validates if update is for our plugin.
	 *
	 * @param array $options Options with information regarding plugins for update.
	 *
	 * @return bool Plugin valid for update.
	 */
	private function validate_if_plugin_update_is_for_our_plugin( $options ) {
		$wc_plugin = plugin_basename( Shop_Helper::PLUGIN_ID );
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] && isset( $options['plugins'] ) ) {
			foreach ( $options['plugins'] as $plugin ) {
				if ( $plugin === $wc_plugin ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Updates plugin on single WordPress site.
	 */
	private function update_plugin_on_single_site() {
		if ( Shop_Helper::is_plugin_active_for_current_site() ) {
			$version_file_reader = new Version_File_Reader(
				__DIR__ . '/database/migrations',
				$this->get_config_service()->get_database_version()
			);

			$installer = new Database( $this->db );
			$installer->update( $version_file_reader );
		}

		$this->get_config_service()->set_database_version( Shop_Helper::get_plugin_version() );
	}

	/**
	 * Switches to site with provided ID and removes plugin from that site.
	 *
	 * @param int $site_id Site identifier.
	 */
	private function switch_to_site_and_uninstall_plugin( $site_id ) {
		switch_to_blog( $site_id );

		$this->uninstall_plugin_from_site();

		restore_current_blog();
	}

	/**
	 * Removes plugin tables and configuration from the current site.
	 */
	private function uninstall_plugin_from_site() {
		$installer = new Database( $this->db );
		$installer->uninstall();
	}

	/**
	 * Retrieves config service.
	 *
	 * @return Config_Service Configuration service.
	 */
	private function get_config_service() {
		if ( null === $this->config_service ) {
			$this->config_service = ServiceRegister::getService( Config_Service::CLASS_NAME );
		}

		return $this->config_service;
	}

	/**
	 * Adds Packlink PRO Shipping item to backend administrator menu.
	 */
	private function load_admin_menu() {
		if ( is_admin() && ! is_network_admin() ) {
			add_action( 'admin_menu', array( $this, 'create_admin_submenu' ) );
		}
	}

	/**
	 * Adds Packlink PRO Shipping shipping method.
	 */
	private function shipping_method_hooks_and_actions() {
		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_shipping_method' ) );
		add_action( 'woocommerce_before_shipping_zone_object_save', array( $this, 'on_zone_create' ), 10, 2 );
	}

	/**
	 * Registers actions and filters for extending orders overview and details page.
	 */
	private function order_hooks_and_actions() {
		$handler = new Packlink_Order_Overview_Controller();

		add_action( 'add_meta_boxes', array( $this, 'add_packlink_shipping_box' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $handler, 'add_packlink_order_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $handler, 'populate_packlink_column' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $handler, 'add_packlink_bulk_action' ) );
		add_filter( 'admin_action_packlink_print_labels', array( $handler, 'bulk_print_labels' ) );
		add_action( 'admin_enqueue_scripts', array( $handler, 'load_scripts' ) );

		add_action( 'woocommerce_init', function () {
			foreach ( \wc_get_is_paid_statuses() as $paid_status ) {
				add_action( 'woocommerce_order_status_' . $paid_status, array(
					Order_Details_Helper::CLASS_NAME,
					'queue_draft'
				), 10, 2 );
			}
		} );
	}

	/**
	 * Registers actions for extending checkout process.
	 */
	private function checkout_hooks_and_actions() {
		$handler = new Checkout_Handler();

		add_filter( 'woocommerce_package_rates', array( $handler, 'check_additional_packlink_rate' ) );
		add_action( 'woocommerce_after_shipping_rate', array( $handler, 'after_shipping_rate' ), 10, 2 );
		add_action( 'woocommerce_after_shipping_calculator', array( $handler, 'after_shipping_calculator' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( $handler, 'after_shipping' ) );
		add_action( 'woocommerce_checkout_process', array( $handler, 'checkout_process' ) );
		add_action( 'woocommerce_checkout_create_order', array( $handler, 'checkout_update_shipping_address' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $handler, 'checkout_update_order_meta' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $handler, 'load_scripts' ) );
	}

	/**
	 * Register filter for links on the plugin screen.
	 */
	private function add_settings_link() {
		add_filter( 'plugin_action_links_' . plugin_basename( Shop_Helper::PLUGIN_ID ), array(
			$this,
			'create_configuration_link'
		) );
	}
}