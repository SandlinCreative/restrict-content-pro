<?php
/**
 * Subscription Details
 *
 * This template displays the current user's membership details with [subscription_details]
 * @link http://docs.restrictcontentpro.com/article/1600-subscriptiondetails
 *
 * For modifying this template, please see: http://docs.restrictcontentpro.com/article/1738-template-files
 *
 * @package     Restrict Content Pro
 * @subpackage  Templates/Subscription
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */

global $user_ID, $rcp_options;

$member = new RCP_Member( $user_ID );

do_action( 'rcp_subscription_details_top' );

if( isset( $_GET['profile'] ) && 'cancelled' == $_GET['profile'] ) : ?>
<p class="rcp_success"><span><?php printf( __( 'Your %s subscription has been successfully cancelled. Your membership will expire on %s.', 'rcp' ), rcp_get_subscription(), rcp_get_expiration_date() ); ?></span></p>
<?php endif; ?>
<table class="rcp-table" id="rcp-account-overview">
	<thead>
		<tr>
			<th><?php _e( 'Status', 'rcp' ); ?></th>
			<th><?php _e( 'Subscription', 'rcp' ); ?></th>
			<?php if( rcp_is_recurring() && ! rcp_is_expired() ) : ?>
			<th><?php _e( 'Renewal Date', 'rcp' ); ?></th>
			<?php else : ?>
			<th><?php _e( 'Expiration', 'rcp' ); ?></th>
			<?php endif; ?>
			<th><?php _e( 'Actions', 'rcp' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td data-th="<?php esc_attr_e( 'Status', 'rcp' ); ?>"><?php rcp_print_status(); ?></td>
			<td data-th="<?php esc_attr_e( 'Subscription', 'rcp' ); ?>">
				<?php if ( 'pending' === $member->get_status() ) {
					echo $member->get_pending_subscription_name();
				} else {
					echo $member->get_subscription_name();
				} ?>
			</td>
			<td data-th="<?php ( rcp_is_recurring() && ! rcp_is_expired() ) ? esc_attr_e( 'Renewal Date', 'rcp' ) : esc_attr_e( 'Expiration', 'rcp' ); ?>"><?php echo rcp_get_expiration_date(); ?></td>
			<td data-th="<?php esc_attr_e( 'Actions', 'rcp' ); ?>">
				<?php
				$links = array();
				if ( rcp_can_member_renew() ) {
					$links[] = apply_filters( 'rcp_subscription_details_action_renew', '<a href="' . esc_url( get_permalink( $rcp_options['registration_page'] ) ) . '" title="' . __( 'Renew your subscription', 'rcp' ) . '" class="rcp_sub_details_renew">' . __( 'Renew your subscription', 'rcp' ) . '</a>', $user_ID );
				}

				if ( rcp_subscription_upgrade_possible( $user_ID ) ) {
					$links[] = apply_filters( 'rcp_subscription_details_action_upgrade', '<a href="' . esc_url( get_permalink( $rcp_options['registration_page'] ) ) . '" title="' . __( 'Upgrade or change your subscription', 'rcp' ) . '" class="rcp_sub_details_renew">' . __( 'Upgrade or change your subscription', 'rcp' ) . '</a>', $user_ID );
				}

				if ( rcp_is_active( $user_ID ) && rcp_can_member_cancel( $user_ID ) ) {
					$links[] = apply_filters( 'rcp_subscription_details_action_cancel', '<a href="' . rcp_get_member_cancel_url( $user_ID ) . '" title="' . __( 'Cancel your subscription', 'rcp' ) . '" class="rcp_sub_details_cancel">' . __( 'Cancel your subscription', 'rcp' ) . '</a>', $user_ID );
				}

				echo apply_filters( 'rcp_subscription_details_actions', implode( '<br/>', $links ), $links, $user_ID );

				do_action( 'rcp_subscription_details_action_links', $links );
				?>
			</td>
		</tr>
	</tbody>
</table>
<table class="rcp-table" id="rcp-payment-history">
	<thead>
		<tr>
			<th><?php _e( 'Invoice #', 'rcp' ); ?></th>
			<th><?php _e( 'Subscription', 'rcp' ); ?></th>
			<th><?php _e( 'Amount', 'rcp' ); ?></th>
			<th><?php _e( 'Payment Status', 'rcp' ); ?></th>
			<th><?php _e( 'Date', 'rcp' ); ?></th>
			<th><?php _e( 'Actions', 'rcp' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	$payments = rcp_get_user_payments( get_current_user_id() );
	if( $payments ) : ?>
		<?php foreach( $payments as $payment ) : ?>
			<tr>
				<td data-th="<?php esc_attr_e( 'Invoice #', 'rcp' ); ?>"><?php echo $payment->id; ?></td>
				<td data-th="<?php esc_attr_e( 'Subscription', 'rcp' ); ?>"><?php echo esc_html( $payment->subscription ); ?></td>
				<td data-th="<?php esc_attr_e( 'Amount', 'rcp' ); ?>"><?php echo rcp_currency_filter( $payment->amount ); ?></td>
				<td data-th="<?php esc_attr_e( 'Payment Status', 'rcp' ); ?>"><?php echo rcp_get_payment_status_label( $payment ); ?></td>
				<td data-th="<?php esc_attr_e( 'Date', 'rcp' ); ?>"><?php echo date_i18n( get_option( 'date_format' ), strtotime( $payment->date, current_time( 'timestamp' ) ) ); ?></td>
				<td data-th="<?php esc_attr_e( 'Actions', 'rcp' ); ?>"><a href="<?php echo esc_url( rcp_get_invoice_url( $payment->id ) ); ?>"><?php _e( 'View Receipt', 'rcp' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
	<?php else : ?>
		<tr><td data-th="<?php _e( 'Subscription', 'rcp' ); ?>" colspan="6"><?php _e( 'No payments found.', 'rcp' ); ?></td></tr>
	<?php endif; ?>
	</tbody>
</table>
<script>
	// Adds a confirm dialog to the cancel link
	var cancel_link = document.querySelector(".rcp_sub_details_cancel");

	if ( cancel_link ) {

		cancel_link.addEventListener("click", function(event) {
			event.preventDefault();

			var message = '<?php printf( __( "Are you sure you want to cancel your subscription? If you cancel, your membership will expire on %s.", "rcp" ), rcp_get_expiration_date() ); ?>';
			var confirmed = confirm( message );

			if ( true === confirmed ) {
				location.assign(event.target.href);
			} else {
				return false;
			}
		});

	}
</script>
<?php do_action( 'rcp_subscription_details_bottom' );
