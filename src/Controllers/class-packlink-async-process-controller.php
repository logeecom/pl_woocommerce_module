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

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Interfaces\AsyncProcessService;
use Packlink\WooCommerce\Components\Utility\Shop_Helper;

/**
 * Class Packlink_Async_Process_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Async_Process_Controller extends Packlink_Base_Controller {
	/**
	 * Packlink_Async_Process_Controller constructor.
	 */
	public function __construct() {
		$this->is_internal = false;
	}

	/**
	 * Runs process defined by guid request parameter.
	 */
	public function run() {
		if ( ! Shop_Helper::is_plugin_enabled() ) {
			exit();
		}

		if ( ! $this->is_post() ) {
			$this->redirect404();
		}

		Logger::logDebug( 'Received async process request.', 'Integration', array( 'guid' => $this->get_param( 'guid' ) ) );

		/**
		 * Async process service.
		 *
		 * @var AsyncProcessService $service
		 */
		$service = ServiceRegister::getService( AsyncProcessService::CLASS_NAME );
		$service->runProcess( $this->get_param( 'guid' ) );

		exit();
	}
}
