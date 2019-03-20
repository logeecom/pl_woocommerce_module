<?php

use Logeecom\Infrastructure\Logger\Logger;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\TaskExecution\Exceptions\QueueStorageUnavailableException;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\User\UserAccountService;
use Packlink\WooCommerce\Components\Order\Order_Meta_Keys;

try {
	$api_key = get_option( 'wc_settings_tab_packlink_api_key' );
	if ( $api_key ) {
		/** @var UserAccountService $user_service */
		$user_service = ServiceRegister::getService( UserAccountService::CLASS_NAME );
		$user_service->login( $api_key );
	}
} catch ( QueueStorageUnavailableException $e ) {
	Logger::logError( 'Migration of users API key failed.', 'Integration' );
}

try {
	$args = array(
		'limit'  => 1000,
		'offset' => 0,
	);

	/** @var OrderRepository $repository */
	$repository = ServiceRegister::getService( OrderRepository::CLASS_NAME);
	do {
		$query  = new WC_Order_Query( $args );
		$orders = $query->get_orders();
		if ( empty( $orders ) ) {
			break;
		}

		/** @var WC_Order $order */
		foreach ( $orders as $order ) {
			$reference = get_post_meta( $order->get_id(), '_packlink_draft_reference', true );
			if ( ! $reference ) {
				continue;
			}

			$order->update_meta_data( Order_Meta_Keys::IS_PACKLINK, 'yes' );
			$order->save();

			$repository->setReference($order->get_id(), $reference);
		}

		$args['offset'] += $args['limit'];
	} while ( $args['limit'] === count( $orders ) );
} catch ( \Exception $e ) {
	Logger::logError( 'Migration of order shipments failed.', 'Integration' );
}

return array();