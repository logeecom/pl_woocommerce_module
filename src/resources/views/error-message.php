<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

$url = wp_nonce_url( add_query_arg( 'packlink-hide-error-notice', '1' ), 'packlink_hide_error_notices_nonce', '_packlink_error_notice_nonce' )

?>
<div class="notice notice-error" style="position: relative;">
	<p><strong><?php esc_html_e( 'Packlink PRO Shipping', 'packlink-pro-shipping' ); ?>:</strong>
		<?php echo esc_attr( get_transient( 'packlink-pro-error-messages' ) ); ?>
	</p>
	<a style="text-decoration: none;display:flex;" class="notice-dismiss" href="<?php echo esc_url( $url ); ?>">
		<?php esc_html_e( 'Dismiss' ); ?>
	</a>
</div>
