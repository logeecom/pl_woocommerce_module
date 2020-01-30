<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Logeecom\Infrastructure\Exceptions\BaseException;
use Logeecom\Infrastructure\ORM\Exceptions\RepositoryNotRegisteredException;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Logeecom\Infrastructure\TaskExecution\QueueItem;
use Packlink\BusinessLogic\Configuration;
use Packlink\BusinessLogic\Controllers\AnalyticsController;
use Packlink\BusinessLogic\Controllers\DashboardController;
use Packlink\BusinessLogic\Controllers\DTO\ShippingMethodConfiguration;
use Packlink\BusinessLogic\Controllers\ShippingMethodController;
use Packlink\BusinessLogic\Controllers\UpdateShippingServicesTaskStatusController;
use Packlink\BusinessLogic\Http\DTO\ParcelInfo;
use Packlink\BusinessLogic\Location\LocationService;
use Packlink\BusinessLogic\ShippingMethod\Models\FixedPricePolicy;
use Packlink\BusinessLogic\ShippingMethod\Models\PercentPricePolicy;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\BusinessLogic\Warehouse\WarehouseService;
use Packlink\WooCommerce\Components\Services\Config_Service;
use Packlink\WooCommerce\Components\ShippingMethod\Shipping_Method_Helper;
use Packlink\WooCommerce\Components\Utility\Script_Loader;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;
use Packlink\WooCommerce\Components\Utility\Task_Queue;

/**
 * Class Packlink_Frontend_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Frontend_Controller extends Packlink_Base_Controller {

	/**
	 * List of help URLs for different country codes.
	 *
	 * @var array
	 */
	private static $help_urls = array(
		'ES' => 'https://support-pro.packlink.com/hc/es-es/articles/210158585-Instala-tu-m%C3%B3dulo-WooCommerce-en-5-pasos',
		'DE' => 'https://support-pro.packlink.com/hc/de/articles/210158585-Installieren-Sie-Ihr-WooCommerce-Modul-in-5-Schritten',
		'FR' => 'https://support-pro.packlink.com/hc/fr-fr/articles/210158585-Installez-le-module-WooCommerce-en-5-%C3%A9tapes',
		'IT' => 'https://support-pro.packlink.com/hc/it/articles/210158585-Installa-il-tuo-modulo-WooCommerce-in-5-passi',
	);
	/**
	 * List of terms and conditions URLs for different country codes.
	 *
	 * @var array
	 */
	private static $terms_and_conditions_urls = array(
		'ES' => 'https://pro.packlink.es/terminos-y-condiciones/',
		'DE' => 'https://pro.packlink.de/agb/',
		'FR' => 'https://pro.packlink.fr/conditions-generales/',
		'IT' => 'https://pro.packlink.it/termini-condizioni/',
	);
	/**
	 * List of country names for different country codes.
	 *
	 * @var array
	 */
	private static $country_names = array(
		'ES' => 'Spain',
		'DE' => 'Germany',
		'FR' => 'France',
		'IT' => 'Italy',
	);

	/**
	 * Configuration service instance.
	 *
	 * @var Config_Service
	 */
	private $configuration;

	/**
	 * Packlink_Frontend_Controller constructor.
	 */
	public function __construct() {
		$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
		Task_Queue::wakeup();
	}

	/**
	 * Renders appropriate view.
	 *
	 * @throws QueueStorageUnavailableException If queue storage is unavailable.
	 * @throws RepositoryNotRegisteredException When repository is not registered in bootstrap.
	 */
	public function render() {
		$this->load_scripts();

		/**
		 * Used in included view file.
		 *
		 * @noinspection PhpUnusedLocalVariableInspection
		 */
		$login_failure = false;
		if ( $this->is_post() && ! $this->login() ) {
			/**
			 * Used in included view file.
			 *
			 * @noinspection PhpUnusedLocalVariableInspection
			 */
			$login_failure = true;
		}

		if ( $this->is_user_logged_in() ) {
			include dirname( __DIR__ ) . '/resources/views/dashboard.php';
		} else {
			include dirname( __DIR__ ) . '/resources/views/login.php';
		}
	}

	/**
	 * Logs in user.
	 *
	 * @throws QueueStorageUnavailableException If queue storage is unavailable.
	 * @throws RepositoryNotRegisteredException When repository is not registered in bootstrap.
	 */
	public function login() {
		$result = false;
		$this->validate( 'yes', true );
		$api_key = $this->get_param( 'api_key' );
		if ( $api_key ) {
			/**
			 * User account service.
			 *
			 * @var UserAccountService $user_service
			 */
			$user_service = ServiceRegister::getService( UserAccountService::CLASS_NAME );
			$result       = $user_service->login( $api_key );
		}

		return $result;
	}

	/**
	 * Returns dashboard status.
	 *
	 * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException
	 */
	public function get_dashboard_status() {
		$this->validate( 'no', true );

		/**
		 * Dashboard controller.
		 *
		 * @var DashboardController $dashboard_controller
		 */
		$dashboard_controller = ServiceRegister::getService( DashboardController::CLASS_NAME );
		try {
			$status = $dashboard_controller->getStatus();
		} catch ( \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException $e ) {
			$this->return_validation_errors_response( $e->getValidationErrors() );
		}

		$this->return_json( $status->toArray() );
	}

	/**
	 * Returns debug status.
	 */
	public function get_debug_status() {
		$this->validate( 'no', true );

		$this->return_json( array( 'status' => $this->configuration->isDebugModeEnabled() ) );
	}

	/**
	 * Saves debug status.
	 */
	public function set_debug_status() {
		$this->validate( 'yes', true );
		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! isset( $payload['status'] ) && ! is_bool( $payload['status'] ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$this->configuration->setDebugModeEnabled( $payload['status'] );
		$this->return_json( array( 'status' => $payload['status'] ) );
	}

	/**
	 * Returns default parcel.
	 */
	public function get_default_parcel() {
		$this->validate( 'no', true );

		/**
		 * Configuration service.
		 *
		 * @var Configuration $configuration
		 */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$parcel        = $configuration->getDefaultParcel();

		$this->return_json( $parcel ? $parcel->toArray() : array() );
	}

	/**
	 * Saves default parcel.
	 */
	public function save_default_parcel() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		try {
			$parcel_info = ParcelInfo::fromArray( $payload );
			/** @var Configuration $configuration */
			$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
			$configuration->setDefaultParcel( $parcel_info );
		} catch ( \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException $e ) {
			$this->return_validation_errors_response( $e->getValidationErrors() );
		}

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Returns default warehouse.
	 */
	public function get_default_warehouse() {
		$this->validate( 'no', true );

		/** @var WarehouseService $warehouse_service */
		$warehouse_service = ServiceRegister::getService( WarehouseService::CLASS_NAME );
		$warehouse         = $warehouse_service->getWarehouse();

		$this->return_json( $warehouse ? $warehouse->toArray() : array() );
	}

	/**
	 * Saves default warehouse.
	 *
	 * @throws \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException
	 */
	public function save_default_warehouse() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		/** @var WarehouseService $warehouse_service */
		$warehouse_service = ServiceRegister::getService( WarehouseService::CLASS_NAME );
		try {
			$warehouse_service->setWarehouse( $payload );
		} catch ( \Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException $e ) {
			$this->return_validation_errors_response( $e->getValidationErrors() );
		}

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Performs locations search.
	 */
	public function search_locations() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );

		if ( empty( $payload['query'] ) ) {
			$this->return_json( array() );
		}

		/**
		 * Configuration service.
		 *
		 * @var Configuration $configuration
		 */
		$configuration    = ServiceRegister::getService( Configuration::CLASS_NAME );
		$platform_country = $configuration->getUserInfo()->country;

		/**
		 * Location service.
		 *
		 * @var LocationService $location_service
		 */
		$location_service = ServiceRegister::getService( LocationService::CLASS_NAME );

		try {
			$result          = $location_service->searchLocations( $platform_country, $payload['query'] );
			$result_as_array = array();

			foreach ( $result as $item ) {
				$result_as_array[] = $item->toArray();
			}

			$this->return_json( $result_as_array );
		} catch ( \Exception $e ) {
			$this->return_json( array() );
		}
	}

	/**
	 * Gets the status of the task for updating shipping services.
	 */
	public function get_shipping_methods_task_status() {
		$status = QueueItem::FAILED;
		try {
			$controller = new UpdateShippingServicesTaskStatusController();
			$status     = $controller->getLastTaskStatus();
		} catch ( BaseException $e ) { // phpcs:ignore
			// return failed status.
		}

		$this->return_json( array( 'status' => $status ) );
	}

	/**
	 * Returns all shipping methods.
	 */
	public function get_all_shipping_methods() {
		$this->validate( 'no', true );

		/** @var ShippingMethodController $controller */
		$controller       = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );
		$shipping_methods = $controller->getAll();

		$this->return_dto_entities_response( $shipping_methods );
	}

	/**
	 * Activates shipping method.
	 */
	public function activate_shipping_method() {
		$this->validate( 'yes', true );

		$result = $this->change_shipping_status();

		$message = $result ? __( 'Shipping method successfully selected.', 'packlink-pro-shipping' ) : __( 'Failed to select shipping method.', 'packlink-pro-shipping' );

		$this->return_json(
			array(
				'success' => $result,
				'message' => $message,
			),
			$result ? 200 : 400
		);
	}

	/**
	 * Deactivates shipping method.
	 */
	public function deactivate_shipping_method() {
		$this->validate( 'yes', true );

		$result = $this->change_shipping_status( 'no' );

		$message = $result ? __( 'Shipping method successfully deselected.', 'packlink-pro-shipping' ) : __( 'Failed to deselect shipping method.', 'packlink-pro-shipping' );

		$this->return_json(
			array(
				'success' => $result,
				'message' => $message,
			),
			$result ? 200 : 400
		);
	}

	/**
	 * Returns count of active shop shipping methods.
	 */
	public function get_shipping_method_count() {
		$this->validate( 'no', true );

		$this->return_json( array( 'count' => Shipping_Method_Helper::get_shop_shipping_method_count() ) );
	}

	/**
	 * Disables active shop shipping methods.
	 */
	public function disable_shop_shipping_methods() {
		$this->validate( 'no', true );

		Shipping_Method_Helper::disable_shop_shipping_methods();

		AnalyticsController::sendOtherServicesDisabledEvent();

		$this->return_json(
			array(
				'success' => true,
				'message' => __(
					'Successfully disabled shipping methods.',
					'packlink-pro-shipping'
				),
			)
		);
	}

	/**
	 * Saves shipping method configuration.
	 */
	public function save_shipping_method() {
		$this->validate( 'yes', true );

		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$shipping_method = $this->build_shipping_method( $payload );

		/**
		 * Shipping method controller.
		 *
		 * @var ShippingMethodController $controller
		 */
		$controller = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );
		$result     = $controller->save( $shipping_method );
		if ( $result && ! $result->selected ) {
			$result->selected = $controller->activate( $result->id );
		}

		if ( $result ) {
			$result->logoUrl = Shipping_Method_Helper::get_carrier_logo( $result->carrierName );
			$this->return_json( $result->toArray() );
		} else {
			$this->return_json(
				array(
					'success' => false,
					'message' => __(
						'Failed to save shipping method.',
						'packlink-pro-shipping'
					),
				),
				400
			);
		}
	}

	/**
	 * Returns list of WooCommerce statuses.
	 */
	public function get_system_order_statuses() {
		$this->validate( 'no', true );

		$result = array();
		foreach ( wc_get_order_statuses() as $code => $label ) {
			$result[] = array(
				'code'  => $code,
				'label' => $label,
			);
		}

		$this->return_json( $result );
	}

	/**
	 * Returns map of order and Packlink shipping statuses.
	 */
	public function get_order_status_mappings() {
		$this->validate( 'no', true );

		$this->return_json( $this->configuration->getOrderStatusMappings() ?: array() );
	}

	/**
	 * Saves map of order and Packlink shipping statuses.
	 */
	public function save_order_status_mapping() {
		$this->validate( 'yes', true );

		$raw_json   = $this->get_raw_input();
		$status_map = json_decode( $raw_json, true );
		if ( ! is_array( $status_map ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		$this->configuration->setOrderStatusMappings( $status_map );

		$this->return_json( array( 'success' => true ) );
	}

	/**
	 * Resolves dashboard view arguments.
	 *
	 * @return array Dashboard view arguments.
	 */
	protected function resolve_view_arguments() {
		$user_info = $this->configuration->getUserInfo();
		$locale    = 'ES';
		if ( null !== $user_info && array_key_exists( $user_info->country, self::$help_urls ) ) {
			$locale = null !== $user_info ? $user_info->country : 'ES';
		}

		return array(
			'image_base'        => Shop_Helper::get_plugin_base_url() . 'resources/images/',
			'dashboard_logo'    => Shop_Helper::get_plugin_base_url() . 'resources/images/logo-pl.svg',
			'dashboard_icon'    => Shop_Helper::get_plugin_base_url() . 'resources/images/dashboard.png',
			'terms_url'         => static::$terms_and_conditions_urls[ $locale ],
			'help_url'          => static::$help_urls[ $locale ],
			'plugin_version'    => Shop_Helper::get_plugin_version(),
			'debug_url'         => Shop_Helper::get_controller_url( 'Debug', 'download' ),
			'warehouse_country' => static::$country_names[ $locale ],
		);
	}

	/**
	 * Loads JS and CSS files for the current page.
	 */
	private function load_scripts() {
		Script_Loader::load_css(
			array(
				'css/packlink.css',
				'css/packlink-wp-override.css',
			)
		);
		Script_Loader::load_js(
			array(
				'js/core/packlink-ajax-service.js',
				'js/core/packlink-footer-controller.js',
				'js/core/packlink-default-parcel-controller.js',
				'js/core/packlink-default-warehouse-controller.js',
				'js/core/packlink-order-state-mapping-controller.js',
				'js/core/packlink-page-controller-factory.js',
				'js/core/packlink-shipping-methods-controller.js',
				'js/core/packlink-sidebar-controller.js',
				'js/core/packlink-state-controller.js',
				'js/core/packlink-template-service.js',
				'js/core/packlink-utility-service.js',
			)
		);
	}

	/**
	 * Changes shipping method active status.
	 *
	 * @param string $activate Shipping method should be activated.
	 *
	 * @return bool Status.
	 */
	private function change_shipping_status( $activate = 'yes' ) {
		$raw_json = $this->get_raw_input();
		$payload  = json_decode( $raw_json, true );
		if ( ! array_key_exists( 'id', $payload ) ) {
			$this->return_json( array( 'success' => false ), 400 );
		}

		/**
		 * Shipping method controller.
		 *
		 * @var ShippingMethodController $controller
		 */
		$controller = ServiceRegister::getService( ShippingMethodController::CLASS_NAME );

		return 'yes' === $activate ? $controller->activate( $payload['id'] ) : $controller->deactivate( $payload['id'] );
	}

	/**
	 * Builds and returns shipping method DTO from request payload.
	 *
	 * @param array $payload Request payload.
	 *
	 * @return ShippingMethodConfiguration Shipping method DTO.
	 */
	private function build_shipping_method( array $payload ) {
		$shipping_method                       = new ShippingMethodConfiguration();
		$shipping_method->id                   = $payload['id'];
		$shipping_method->name                 = $payload['name'];
		$shipping_method->showLogo             = $payload['showLogo'];
		$shipping_method->pricePolicy          = $payload['pricePolicy'];
		$shipping_method->isShipToAllCountries = true;
		$shipping_method->shippingCountries    = array();

		if ( ShippingMethod::PRICING_POLICY_PERCENT === $shipping_method->pricePolicy ) {
			$percent_price_policy                = $payload['percentPricePolicy'];
			$shipping_method->percentPricePolicy = new PercentPricePolicy( $percent_price_policy['increase'], $percent_price_policy['amount'] );
		} elseif ( ShippingMethod::PRICING_POLICY_FIXED_PRICE_BY_WEIGHT === $shipping_method->pricePolicy ) {
			$shipping_method->fixedPriceByWeightPolicy = array();
			foreach ( $payload['fixedPriceByWeightPolicy'] as $item ) {
				$shipping_method->fixedPriceByWeightPolicy[] = new FixedPricePolicy( $item['from'], $item['to'], $item['amount'] );
			}
		} elseif ( ShippingMethod::PRICING_POLICY_FIXED_PRICE_BY_VALUE === $shipping_method->pricePolicy ) {
			$shipping_method->fixedPriceByValuePolicy = array();
			foreach ( $payload['fixedPriceByValuePolicy'] as $item ) {
				$shipping_method->fixedPriceByValuePolicy[] = new FixedPricePolicy( $item['from'], $item['to'], $item['amount'] );
			}
		}

		return $shipping_method;
	}

	/**
	 * Returns flag is user logged in.
	 *
	 * @return bool Authenticated flag.
	 */
	private function is_user_logged_in() {
		/**
		 * Configuration service.
		 *
		 * @var Configuration $configuration
		 */
		$configuration = ServiceRegister::getService( Configuration::CLASS_NAME );
		$token         = $configuration->getAuthorizationToken();

		return null !== $token;
	}
}
