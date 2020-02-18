<?php

/** @noinspection PhpUnhandledExceptionInspection */

use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\QueueService;
use Packlink\BusinessLogic\Configuration;
use Packlink\BusinessLogic\Http\DTO\ShipmentLabel;
use Packlink\BusinessLogic\OrderShipmentDetails\Models\OrderShipmentDetails;
use Packlink\BusinessLogic\ShipmentDraft\OrderSendDraftTaskMapService;
use Packlink\BusinessLogic\Tasks\UpdateShippingServicesTask;
use Packlink\WooCommerce\Components\Order\Order_Drop_Off_Map;
use Packlink\WooCommerce\Components\Repositories\Base_Repository;
use Packlink\WooCommerce\Components\Utility\Database;

// This section will be triggered when upgrading to 2.2.0 or later version of plugin.

global $wpdb;

$database = new Database( $wpdb );

$order_ids = $database->get_packlink_order_ids();

if ( ! empty( $order_ids ) ) {
	/** @var Base_Repository $order_shipment_details_repository */
	$order_shipment_details_repository = RepositoryRegistry::getRepository( OrderShipmentDetails::CLASS_NAME );
	/** @var Base_Repository $order_drop_off_map_repository */
	$order_drop_off_map_repository = RepositoryRegistry::getRepository( Order_Drop_Off_Map::CLASS_NAME );
	/** @var OrderSendDraftTaskMapService $order_draft_task_map_service */
	$order_draft_task_map_service = ServiceRegister::getService( OrderSendDraftTaskMapService::CLASS_NAME );

	foreach ( $order_ids as $order_id ) {
		if ( metadata_exists( 'post', $order_id, '_packlink_shipment_reference' ) ) {
			$order_shipment_details = new OrderShipmentDetails();
			$order_shipment_details->setOrderId( (string) $order_id );
			$order_shipment_details->setReference( get_post_meta( $order_id, '_packlink_shipment_reference', true ) );

			if ( metadata_exists( 'post', $order_id, '_packlink_shipment_status' ) ) {
				$order_shipment_details->setShippingStatus(
					get_post_meta( $order_id, '_packlink_shipment_status', true ),
					metadata_exists( 'post', $order_id, '_packlink_shipment_status_update_time' )
						? get_post_meta( $order_id, '_packlink_shipment_status_update_time', true ) : null
				);
			}

			if ( metadata_exists( 'post', $order_id, '_packlink_carrier_tracking_code' ) ) {
				$order_shipment_details->setCarrierTrackingNumbers( get_post_meta( $order_id, '_packlink_carrier_tracking_code', true ) );
			}

			if ( metadata_exists( 'post', $order_id, '_packlink_carrier_tracking_url' ) ) {
				$order_shipment_details->setCarrierTrackingUrl( get_post_meta( $order_id, '_packlink_carrier_tracking_url', true ) );
			}

			if ( metadata_exists( 'post', $order_id, '_packlink_shipment_price' ) ) {
				$order_shipment_details->setShippingCost( get_post_meta( $order_id, '_packlink_shipment_price', true ) );
			}

			if ( metadata_exists( 'post', $order_id, '_packlink_shipment_labels' ) ) {
				$labels = get_post_meta( $order_id, '_packlink_shipment_labels', true );
				if ( ! empty( $labels ) ) {
					$label_printed  = 'yes' === get_post_meta( $order_id, '_packlink_label_printed', true );
					$shipment_label = new ShipmentLabel( $labels[0], $label_printed );
					$order_shipment_details->setShipmentLabels( array( $shipment_label ) );
				}
			}

			if ( metadata_exists( 'post', $order_id, '_packlink_drop_off_point_id' ) ) {
				$order_shipment_details->setDropOffId( get_post_meta( $order_id, '_packlink_drop_off_point_id', true ) );

				$order_drop_off_map = new Order_Drop_Off_Map();
				$order_drop_off_map->set_order_id( $order_id );
				$order_drop_off_map->set_drop_off_point_id( get_post_meta( $order_id, '_packlink_drop_off_point_id', true ) );

				$order_drop_off_map_repository->save( $order_drop_off_map );
			}

			$order_shipment_details_repository->save( $order_shipment_details );

			if ( metadata_exists( 'post', $order_id, '_packlink_send_draft_task_id' ) ) {
				$order_draft_task_map_service->createOrderTaskMap(
					(string) $order_id,
					get_post_meta( $order_id, '_packlink_send_draft_task_id', true )
				);
			}
		}
	}
}

$database->remove_packlink_meta_data();

/** @var QueueService $queue_service */
$queue_service = ServiceRegister::getService( QueueService::CLASS_NAME );
/** @var \Packlink\WooCommerce\Components\Services\Config_Service $config_service */
$config_service = ServiceRegister::getService( Configuration::CLASS_NAME );

if ( null !== $queue_service->findLatestByType( 'UpdateShippingServicesTask' ) ) {
	$queue_service->enqueue( $config_service->getDefaultQueueName(), new UpdateShippingServicesTask() );
}