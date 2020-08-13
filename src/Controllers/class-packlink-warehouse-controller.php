<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Controllers;

use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\BusinessLogic\Controllers\LocationsController;
use Packlink\BusinessLogic\Controllers\RegistrationRegionsController;
use Packlink\BusinessLogic\Controllers\WarehouseController;
use Packlink\BusinessLogic\DTO\Exceptions\FrontDtoNotRegisteredException;
use Packlink\BusinessLogic\DTO\Exceptions\FrontDtoValidationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Packlink_Warehouse_Controller
 *
 * @package Packlink\WooCommerce\Controllers
 */
class Packlink_Warehouse_Controller extends Packlink_Base_Controller {

	/**
	 * Warehouse controller.
	 *
	 * @var WarehouseController
	 */
	private $warehouse_controller;

	/**
	 * Country controller.
	 *
	 * @var RegistrationRegionsController
	 */
	private $country_controller;

	/**
	 * Locations controller.
	 *
	 * @var LocationsController
	 */
	private $locations_controller;

	/**
	 * Packlink_Warehouse_Controller constructor.
	 */
	public function __construct() {
		$this->warehouse_controller = new WarehouseController();
		$this->country_controller   = new RegistrationRegionsController();
	}

	/**
	 * Retrieves senders warehouse.
	 */
	public function get() {
		$warehouse = $this->warehouse_controller->getWarehouse();

		$this->return_json( $warehouse ? $warehouse->toArray() : array() );
	}

	/**
	 * Updates warehouse data.
	 *
	 * @throws QueueStorageUnavailableException When queue storage is unavailable.
	 * @throws FrontDtoNotRegisteredException When front dto is not registered.
	 * @throws FrontDtoValidationException When warehouse data is not valid.
	 */
	public function submit() {
		$this->validate( 'yes', true );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );
		$result  = $this->warehouse_controller->updateWarehouse( $payload );

		$this->return_json( $result->toArray() );
	}

	/**
	 * Retrieves supported countries.
	 */
	public function get_countries() {
		$countries = $this->country_controller->getRegions();

		$this->return_dto_entities_response( $countries );
	}

	/**
	 * Searches postal coded with given country and query.
	 */
	public function search_postal_codes() {
		$this->validate( 'yes', true );
		$raw     = $this->get_raw_input();
		$payload = json_decode( $raw, true );

		$this->return_dto_entities_response( $this->locations_controller->searchLocations( $payload ) );
	}
}
