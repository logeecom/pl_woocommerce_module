<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ServiceRegister;
use Packlink\BusinessLogic\ShipmentDraft\ShipmentDraftService;
use WC_Order;

/**
 * Class Order_Details_Helper
 *
 * @package Packlink\WooCommerce\Components\Utility
 */
class Order_Details_Helper {
	/**
	 * Fully qualified name of this interface.
	 */
	const CLASS_NAME = __CLASS__;

	/**
	 * Creates and queues shipment drafts for paid orders.
	 *
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * @param int      $order_id Order identifier.
	 * @param WC_Order $order WooCommerce order instance.
	 */
	public static function queue_draft( $order_id, WC_Order $order ) {
		if ( $order->is_paid() ) {
			/** @var ShipmentDraftService $draft_service */
			$draft_service = ServiceRegister::getService( ShipmentDraftService::CLASS_NAME );
			$draft_service->enqueueCreateShipmentDraftTask( (string)$order_id );
		}
	}
}
