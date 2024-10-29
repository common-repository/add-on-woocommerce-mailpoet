<?php
/**
 * Run actions after place order
 *
 * @since      1.0.0
 * @package    Add-on WooCommerce MailPoet 3
 * @subpackage add-on-woocommerce-mailpoet/includes
 * @author     Tikweb <kasper@tikjob.dk>
 */

use MailPoet\Models\Subscriber;
use MailPoet\Models\Segment;
use MailPoet\Subscribers\ConfirmationEmailMailer;

if ( ! class_exists( 'MPWA_Place_Order' ) ) {
	class MPWA_Place_Order {
		// Helper trait
		use MPWA_Helper_Function;

		/**
		 * Get user information and subscribe
		 */
		public static function subscribe_user() {

			// Form Data
			$posted_data = $_POST;

			// If Multi-Subscription enable
			if ( isset( $posted_data['mailpoet_multi_subscription'] ) ) {

				$list_id_array = $posted_data['mailpoet_multi_subscription'];
				self::save_subscriber_record( $list_id_array, $posted_data );

			} elseif ( isset( $posted_data['mailpoet_checkout_subscribe'] ) && ! empty( $posted_data['mailpoet_checkout_subscribe'] ) ) {

				$list_id_array = get_option( 'wc_mailpoet_segment_list' );
				self::save_subscriber_record( $list_id_array, $posted_data );

			}//End if

			// If unsubscribe requested.
			if ( isset( $posted_data['gdpr_unsubscribe'] ) && $posted_data['gdpr_unsubscribe'] == 'on' ) {

				self::unsubscribe_user( $posted_data );

			} //End if

		}//end subscribe_user()

		/**
		 * Save subscriber record
		 */
		public static function save_subscriber_record( $list_id_array = '', $posted_data ) {
			// List id array must not be empty
			if ( is_array( $list_id_array ) && ! empty( $list_id_array ) ) {

				$subscriber = Subscriber::findOne( $posted_data['billing_email'] );

				if ( $subscriber ) {
					$subscriber->withSubscriptions();
					$old_lists = $subscriber->subscriptions;

					foreach ( $old_lists as $key => $value ) {
						$list_ids[] = $value['segment_id'];
					}
					$list_id_array = array_unique( array_merge( $list_id_array, $list_ids ) );
				}

				// If registered user is in woocommerce or wp user list, remove that list ids first
				$wp_segment = Segment::whereEqual( 'type', Segment::TYPE_WP_USERS )->findArray();
				$wc_segment = Segment::whereEqual( 'type', Segment::TYPE_WC_USERS )->findArray();

				if ( ( $key = array_search( $wp_segment[0]['id'], $list_id_array ) ) ) {
					unset( $list_id_array[ $key ] );
				}

				if ( ( $key = array_search( $wc_segment[0]['id'], $list_id_array ) ) ) {
					unset( $list_id_array[ $key ] );
				}

				$subscribe_data = array(
					'email'      => $posted_data['billing_email'],
					'first_name' => $posted_data['billing_first_name'],
					'last_name'  => $posted_data['billing_last_name'],
					'segments'   => $list_id_array,
				);

				// Get `Enable Double Opt-in` value
				$double_optin = get_option( 'wc_mailpoet_double_optin' );

				if ( $double_optin == 'yes' ) { // If Double Opt-in enable

					/**
					 * NoTe: In API, it will always through the exception because woocommerce already subscribe/register user whenever a user checkout. So below the catch block will be executed
					 */

					try {
						$subscriber = \MailPoet\API\API::MP( 'v1' )->addSubscriber(
							$subscribe_data,
							$list_id_array
						);
					} catch ( Exception $exception ) {
						if ( 'This subscriber already exists.' == $exception->getMessage() ) {
							try {
								$subscribe_data['status'] = 'unconfirmed';
								$subscriber               = Subscriber::createOrUpdate( $subscribe_data );
								// $subscription = \MailPoet\API\API::MP( 'v1' )->subscribeToLists( $subscriber->id,
								// $list_id_array, $options['send_confirmation_email'] = true );

									$subscription = \MailPoet\API\API::MP( 'v1' )->subscribeToLists(
										$subscriber->id,
										$list_id_array,
										array( 'send_confirmation_email' => true )
									);

							} catch ( Exception $exception ) {
								$output = print_r( $exception, true );
								file_put_contents( 'exception.txt', $output );
							}
						} else {

						}
					}

					// Display success notice to the customer.
					if ( ! empty( $subscriber ) ) {
						wc_add_notice(
							apply_filters(
								'mailpoet_woocommerce_subscribe_confirm',
								self::__( 'We have sent you an email to confirm your newsletter subscription. Please confirm your subscription. Thank you.' )
							)
						);

						// Send signup confirmation email
						// $sender = new ConfirmationEmailMailer();
						// $sender->sendConfirmationEmail($subscriber);

						// Show error notice if unable to save data
					} else {
						self::subscribe_error_notice();
					}//End of if $subscriber !== false
				} else { // If Double Opt-in disable

					try {
						$subscriber = \MailPoet\API\API::MP( 'v1' )->addSubscriber(
							$subscribe_data,
							$list_id_array
						);
					} catch ( Exception $exception ) {
						if ( 'This subscriber already exists.' == $exception->getMessage() ) {
							try {
								$subscribe_data['status'] = 'subscribed';
								$subscriber               = Subscriber::createOrUpdate( $subscribe_data );
								// $subscription = \MailPoet\API\API::MP( 'v1' )->subscribeToLists( $subscriber->id,
								// $list_id_array, $options['send_confirmation_email'] = false );

									$subscription = \MailPoet\API\API::MP( 'v1' )->subscribeToLists(
										$subscriber->id,
										$list_id_array,
										array( 'send_confirmation_email' => false )
									);

							} catch ( Exception $exception ) {

							}
						} else {

						}
					}

					// Display success notice to the customer.
					if ( $subscriber !== false ) {
						wc_add_notice(
							apply_filters(
								'mailpoet_woocommerce_subscribe_thank_you',
								self::__( 'Thank you for subscribing to our newsletters.' )
							)
						);

						// Show error notice if unable to save data
					} else {
						self::subscribe_error_notice();

					}//End of if $subscriber !== false
				}//End of if $double_optin == 'yes'
			}//End of if is_array($list_id_array)

		}//end save_subscriber_record()

		/**
		 * Unsubscribe User
		 */
		public static function unsubscribe_user( $posted_data ) {

			$email      = isset( $posted_data['billing_email'] ) ? $posted_data['billing_email'] : false;
			$subscriber = Subscriber::findOne( $email );

			if ( $subscriber !== false ) {

				// You can't use unsubscribe API here. You will get error in debug

				$subscriber->status = 'unsubscribed';
				$subscriber->save();

				wc_add_notice(
					apply_filters(
						'mailpoet_woocommerce_unsubscribe_confirm',
						self::__( 'You will no longer receive our newletter! Feel free to subscribe our newsletter anytime you want.' )
					)
				);

			}

		} // End of unsubscribe_user

		/**
		 * Save data Error notice
		 */
		public static function subscribe_error_notice() {
			wc_add_notice(
				apply_filters(
					'mailpoet_woocommerce_subscribe_error',
					self::__( 'There appears to be a problem subscribing you to our newsletters. Please let us know so we can manually add you ourselves. Thank you.' )
				),
				'error'
			);
		}//end subscribe_error_notice()

	}//end class

}//End if
