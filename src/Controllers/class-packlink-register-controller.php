<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Packlink\BusinessLogic\Controllers\RegistrationController;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Register_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Register_Controller extends Packlink_Base_Controller {

	/**
	 * Array that identifies e-commerce
	 *
	 * @var string[]
	 */
	protected static $ecommerce_identifiers = array( 'Woocommerce' );

	/**
	 * Base controller that handles registration requests.
	 *
	 * @var RegistrationController
	 */
	private $base_controller;

	/**
	 * Packlink_Register_Controller constructor.
	 */
	public function __construct() {
		$this->base_controller = new RegistrationController();
	}

	/**
	 * Retrieves registration data.
	 */
	public function get() {
		$this->return_json( $this->base_controller->getRegisterData() );
	}

	/**
	 * Handles registration request.
	 */
	public function submit() {
		$this->validate( 'yes', true );
		$raw                   = $this->get_raw_input();
		$payload               = json_decode( $raw, true );
		$payload['ecommerces'] = static::$ecommerce_identifiers;
		$status                = $this->base_controller->register( $payload );

		$this->return_json( array( 'success' => $status ) );
	}
}
