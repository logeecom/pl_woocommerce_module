<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Services;

use Logeecom\Infrastructure\Logger\Logger;
use Packlink\BusinessLogic\Configuration;
use Packlink\BusinessLogic\ShippingMethod\Models\ShippingMethod;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Config_Service
 *
 * @package Packlink\WooCommerce\Components\Services
 */
class Config_Service extends Configuration {
	/**
	 * Threshold between two runs of scheduler.
	 */
	const SCHEDULER_TIME_THRESHOLD = 1800;
	/**
	 * Minimal log level.
	 */
	const MIN_LOG_LEVEL = Logger::ERROR;
	/**
	 * Max inactivity period for a task in seconds
	 */
	const MAX_TASK_INACTIVITY_PERIOD = 60;

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Retrieves integration name.
	 *
	 * @return string Integration name.
	 */
	public function getIntegrationName() {
		return 'WooCommerce';
	}

	/**
	 * Creates instance of this class.
	 *
	 * @return static
	 *
	 * @noinspection PhpDocSignatureInspection
	 */
	public static function create()
	{
		return new self();
	}

	/**
	 * Returns order draft source.
	 *
	 * @return string
	 */
	public function getDraftSource() {
		return 'module_woocommerce';
	}

	/**
	 * Gets the current version of the module/integration.
	 *
	 * @return string The version number.
	 */
	public function getModuleVersion() {
		return Shop_Helper::get_plugin_version();
	}

	/**
	 * Gets the name of the integrated e-commerce system.
	 * This name is related to Packlink API which can be different from the official system name.
	 *
	 * @return string The e-commerce name.
	 */
	public function getECommerceName() {
		return 'woocommerce_2';
	}

	/**
	 * Gets the current version of the integrated e-commerce system.
	 *
	 * @return string The version number.
	 */
	public function getECommerceVersion() {
		return \WooCommerce::instance()->version;
	}

	/**
	 * Returns current system identifier.
	 *
	 * @return string Current system identifier.
	 */
	public function getCurrentSystemId() {
		return (string) get_current_blog_id();
	}

	/**
	 * Gets max inactivity period for a task in seconds.
	 * After inactivity period is passed, system will fail such task as expired.
	 *
	 * @return int Max task inactivity period in seconds if set; otherwise, self::MAX_TASK_INACTIVITY_PERIOD.
	 */
	public function getMaxTaskInactivityPeriod() {
		return parent::getMaxTaskInactivityPeriod() ?: self::MAX_TASK_INACTIVITY_PERIOD;
	}

	/**
	 * Returns async process starter url, always in http.
	 *
	 * @param string $guid Process identifier.
	 *
	 * @return string Formatted URL of async process starter endpoint.
	 */
	public function getAsyncProcessUrl( $guid ) {
		$params = array( 'guid' => $guid );
		if ( $this->isAutoTestMode() ) {
			$params['auto-test'] = 1;
		}

		return Shop_Helper::get_controller_url( 'Async_Process', 'run', $params );
	}

	/**
	 * Returns web-hook callback URL for current system.
	 *
	 * @return string Web-hook callback URL.
	 */
	public function getWebHookUrl() {
		return Shop_Helper::get_controller_url( 'Web_Hook', 'index' );
	}

	/**
	 * Sets database version for migration scripts
	 *
	 * @param string $database_version Database version.
	 */
	public function set_database_version( $database_version ) {
		update_option( 'PACKLINK_VERSION', $database_version );
	}

	/**
	 * Returns database version
	 *
	 * @return string
	 */
	public function get_database_version() {
		return get_option( 'PACKLINK_VERSION', '2.0.1' );
	}

	/**
	 * Returns default shipping method.
	 *
	 * @return ShippingMethod|null Shipping method.
	 */
	public function get_default_shipping_method() {
		$value = $this->getConfigValue( 'Default_Shipping' );

		return $value && is_array( $value ) ? ShippingMethod::fromArray( $value ) : null;
	}

	/**
	 * Saves default shipping method.
	 *
	 * @param ShippingMethod $shipping_method Shipping method.
	 */
	public function set_default_shipping_method( ShippingMethod $shipping_method = null ) {
		$this->saveConfigValue( 'Default_Shipping', $shipping_method ? $shipping_method->toArray() : null );
	}
}
