<?php
	/*
		Plugin Name: Gravity Forms Digest Bulk Reports
		Author: Gennady Kovshenin
		Description: Generates bulk reports for submitted form entries and e-mails these as a digest to specific addresses
		Version: 0.1
		Author URI: http://codeseekah.com
	*/

	class GFDigestNotifications {
		private static $instance = null;
		private static $textdomain = 'gravitforms-digest';

		public function __construct() {
			if ( is_object( self::$instance ) && get_class( self::$instance == __CLASS__ ) )
				wp_die( __CLASS__.' can have only one instance; won\'t initialize, use '.__CLASS__.'::get_instance()' );
			self::$instance = $this;

			$this->bootstrap();
		}

		public static function get_instance() {
			return ( get_class( self::$instance ) == __CLASS__ ) ? self::$instance : new self;
		}

		public function bootstrap() {
			add_action( 'plugins_loaded', array( $this, 'early_init' ) );
			add_action( 'gf_digest_send_notifications', array( $this, 'send_notifications' ) );

			/* Attach hooks and other early initialization */
			if ( !isset( $_GET['page']) || $_GET['page'] != 'gf_edit_forms' )
				return; // Nothing else to do, we're not on the setting page

			if ( !isset( $_GET['view'] ) || ( $_GET['view'] != 'notification' && $_GET['view'] != 'settings' ) )
				return; // Same as above, nothing to be done

			add_action( 'init', array( $this, 'init' ) );
			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		}

		public function early_init() {
			/* Load languages if available */
			load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		public function plugins_loaded() {
			/* Add a new meta box to the settings; use `gform_notification_ui_settings` in 1.7 */
			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				add_action( 'gform_form_settings_page_digests', array( $this, 'show_notification_settings' ) );
				add_filter( 'gform_form_settings_menu', array( $this, 'add_notification_settings_tab' ) );
				return;
			}
			add_filter( 'gform_save_notification_button', array( $this, 'add_notification_settings' ) );
		}

		public function add_notification_settings_tab( $tabs ) {
			$tabs []= array( 'name' => 'digests', 'label' => __( 'Notification Digest', self::$textdomain ), 'query' => array( 'nid' => null ) );
			return $tabs;
		}

		public function show_notification_settings() {
			/* This is a GF 1.7+ UI */
			
			GFFormSettings::page_header();
			echo '<form method="post">';
			echo $this->add_notification_settings( '' );
			echo '<input type="submit" id="gform_save_settings" name="save" value="Update" class="button-primary gfbutton"></form>';
			GFFormSettings::page_footer();
		}

		public function init() {
			if ( isset( $_POST['save'] ) )
				$this->process_post_request();
		}

		private function process_post_request() {
			$form_id = isset( $_GET['id'] ) ? $_GET['id'] : null;
			if ( !$form_id ) return; // Not supposed to be here

			$form = RGFormsModel::get_form_meta( $form_id );
			if ( !$form ) return; // Nuh-uh

			/* Process the settings bit by bit */
			if ( !isset( $_POST['form_notification_enable_digest'] ) ) {
				$form['notification']['enable_digest'] = false;
				RGFormsModel::update_form_meta( $form_id, $form );
				return; // Nothing of interest here, move on
			}

			$digest_emails = isset( $_POST['form_notification_digest_emails'] ) ? $_POST['form_notification_digest_emails'] : '';
			$digest_interval = isset( $_POST['form_notification_digest_interval'] ) ? $_POST['form_notification_digest_interval'] : '';
			$digest_group = isset( $_POST['form_notification_digest_group'] ) ? $_POST['form_notification_digest_group'] : '';
			
			$form['notification']['enable_digest'] = true;
			$form['notification']['digest_emails'] = array_map( 'trim', explode( ',', $digest_emails ) );

			/* Schedule the next event if necessary */
			if ( $digest_group ) {
				/* Let's get all the forms in the digest group to find out
				whether there is already a digest group on schedule */
				foreach( RGFormsModel::get_forms( true ) as $existing_form ) {
					if ( $existing_form->id == $form_id )
						continue; // It is I!
					$existing_form = RGFormsModel::get_form_meta( $existing_form->id );

					if ( !isset( $existing_form['notification']['enable_digest'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['notification']['digest_group'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['notification']['digest_interval'] ) )
						continue; // Meh, not interesting

					if ( $existing_form['notification']['digest_group'] == $digest_group )
						if ( $existing_form['notification']['digest_interval'] == $digest_interval ) {
							$scheduled = true; // We'll combine the two, they'll go along
							/* And let's also clear any hooks we may have left behind */
							wp_clear_scheduled_hook( 'gf_digest_send_notifications', array( $form_id ) );
							break;
						}
				}
			}

			if ( !isset( $scheduled ) ) {
				/* We have to reschedule if group or interval have changed */
				if ( ( !isset( $form['notification']['digest_group'] ) || $form['notification']['digest_group'] != $digest_group )
					|| ( !isset( $form['notification']['digest_interval'] ) || $form['notification']['digest_interval'] != $digest_interval ) ) {
					/* Remove any old event schedules */
					wp_clear_scheduled_hook( 'gf_digest_send_notifications', array( $form_id ) );
					wp_schedule_event( apply_filters( 'gf_digest_schedule_next', time() + 3600, $digest_interval ), $digest_interval, 'gf_digest_send_notifications', array( $form_id ) );
				}
			}
			
			$form['notification']['digest_interval'] = $digest_interval;
			$form['notification']['digest_group'] = $digest_group;
			RGFormsModel::update_form_meta( $form_id, $form );

			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				/* In 1.7 there seems to be an issue with saving */
				GFFormsModel::flush_current_forms();
			}
		}

		public function add_notification_settings( $out ) {
			/* Add an extra screen to the Notification settings of a Form */

			$form_id = isset( $_GET['id'] ) ? $_GET['id'] : null;
			if ( !$form_id ) return $out; // Not supposed to be here

			$form = RGFormsModel::get_form_meta( $form_id );

			$is_digest_enabled = isset( $form['notification']['enable_digest'] ) ? $form['notification']['enable_digest'] : false;
			$digest_emails = isset( $form['notification']['digest_emails'] ) ? $form['notification']['digest_emails'] : false;
			$digest_emails = ( $digest_emails ) ? implode( ',', $digest_emails ) : '';
			$digest_interval = isset( $form['notification']['digest_interval'] ) ? $form['notification']['digest_interval'] : false;
			$digest_group = isset( $form['notification']['digest_group'] ) ? $form['notification']['digest_group'] : false;

			?>
				<div id="submitdiv" class="stuffbox">
					<h3><span class="hndle"><?php _e( 'Notification Digest', self::$textdomain ); ?></span></h3>
					<div class="inside" style="padding: 10px;">
						<input type="checkbox" name="form_notification_enable_digest" id="form_notification_enable_digest" value="1" <?php checked( $is_digest_enabled ); ?> onclick="if(this.checked) {jQuery('#form_notification_digest_container').show('slow');} else {jQuery('#form_notification_digest_container').hide('slow');}"/> <label for="form_notification_enable_digest"><?php _e("Enable digest notifications", self::$textdomain); ?></label>

						<div id="form_notification_digest_container" style="display:<?php echo $is_digest_enabled ? "block" : "none"?>;">
							<br>
							<label for="form_notification_digest_emails">Digest Addresses<a href="#" onclick="return false;" class="tooltip tooltip_notification_digest_emails" tooltip="<h6>Digest Addresses</h6>Comma-separated list of e-mail addresses that receive notification digests for this form.">(?)</a></label>
							<input class="fieldwidth-1" name="form_notification_digest_emails" id="form_notification_digest_emails" value="<?php echo esc_attr( $digest_emails ); ?>" type="text">
							<br>
							<br>
							<label for="form_notification_digest_interval">Digest Interval<a href="#" onclick="return false;" class="tooltip tooltip_notification_digest_interval" tooltip="<h6>Digest Interval</h6>An interval at which a digest is sent out. More intervals can be added using the WordPress <code>cron_schedules</code> filter.">(?)</a></label>
							<br>
							<select id="form_notification_digest_interval" name="form_notification_digest_interval">
								<?php foreach ( wp_get_schedules() as $value => $schedule ): ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $digest_interval, $value ); ?>><?php echo esc_html( $schedule['display'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p>Once the interval is changed, the first report will be sent out in an hour and then at the set intervals. This behavior can be changed by hooking into the <code>gf_digest_schedule_next</code> filter. Behavior may vary for forms grouped together, report will be sent out whenever the first or only group was scheduled.</p>
							<label for="form_notification_digest_group">Group<a href="#" onclick="return false;" class="tooltip tooltip_notification_digest_group" tooltip="<h6>Digest Group</h6>We will try and group forms with the same interval into one e-mail, leave blank for no grouping. Can be a number or keyword.">(?)</a></label>
							<input type="text" name="form_notification_digest_group" id="form_notification_digest_group" value="<?php echo esc_attr( $digest_group ); ?>">
							<p>Note that digest grouping will only work for members of a group with same intervals set. For example, forms with hourly digests in group 'sales' will be bound together, daily digests in group 'sales' will be bound together. So if you want to see two form digests in one e-mail set the same interval and the same group for the two forms. You may also receive out of band reports once after having changed groups or intervals.</p>
						</div>
					</div>
				</div>
			<?php

			return $out;
		}

		public function send_notifications( $args ) {
			/* Sends an e-mail out, good stuff */
			$form_id = $args[0];

			$form = RGFormsModel::get_form_meta( $form_id );

			$digest_group = isset( $form['notification']['digest_group'] ) ? $form['notification']['digest_group'] : false;
			$digest_interval = isset( $form['notification']['digest_interval'] ) ? $form['notification']['digest_interval'] : false;

			$forms = array( $form['id'] => $form );
			if ( $digest_group ) {
				/* We may want to send out a group of forms in one e-mail if possible */
				foreach( RGFormsModel::get_forms( true ) as $existing_form ) {
					if ( $existing_form->id == $form_id )
						continue; // It is I!
					$existing_form = RGFormsModel::get_form_meta( $existing_form->id );

					if ( !isset( $existing_form['notification']['enable_digest'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['notification']['digest_group'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['notification']['digest_interval'] ) )
						continue; // Meh, not interesting

					if ( $existing_form['notification']['digest_group'] == $digest_group )
						if ( $existing_form['notification']['digest_interval'] == $digest_interval ) {
							$forms[$existing_form['id']]= $existing_form; // Add them all
						}
				}
			}

			$emails = array();

			/* Gather all the leads and update the last_sent counters */
			foreach ( $forms as $i => $form ) {
				$last_sent = isset( $form['notification']['digest_last_sent'] ) ? $form['notification']['digest_last_sent'] : 0;

				/* Retrieve form entries newer than the last sent ID */
				global $wpdb;
				$leads_table = RGFormsModel::get_lead_table_name();
				$leads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $leads_table WHERE form_id = %d AND id > %d AND status = 'active';", $form['id'], $last_sent ) );

				if ( !sizeof( $leads ) ) continue; // Nothing to report on

				/* Update the reported id counter */
				$form['notification']['digest_last_sent'] = $leads[sizeof($leads) - 1]->id;
				RGFormsModel::update_form_meta( $form['id'], $form );

				$forms[$i]['leads'] = $leads;

				/* Also make a lookup table of all e-mail addresses to forms */
				foreach ( $form['notification']['digest_emails'] as $email ) {
					if ( !isset( $emails[$email] ) ) $emails[$email] = array();
					$emails[$email] []= $form['id'];
				}
			}

			/* Now, let's try and mail stuff */
			foreach ( $emails as $email => $form_ids ) {

				if ( defined( 'GF_DIGESTS_AS_CSV' ) && GF_DIGESTS_AS_CSV ) {
					/* CSV e-mails */
					$report = 'Report generated at ' . date( 'Y-m-d H:i:s' ) . "\n";
					$report .= 'See CSV attachment';

					$csv_attachment = tempnam( sys_get_temp_dir(), '' );
					$csv = fopen( $csv_attachment, 'w' );

					foreach ( $form_ids as $form_id ) {
						$form = $forms[$form_id];

						fputcsv( $csv, array( 'Form: ' . $form['title'] . ' (#' . $form_id . ')' ) );

						$headers = array( 'Date Submitted' );
						$index = 1;
						while ( $field = RGFormsModel::get_field( $form, $index++ ) )
							if ( $field['label'] ) $headers []= $field['label'];
						fputcsv( $csv, $headers );

						foreach ( $form['leads'] as $lead ) {
							$data = array();

							$lead_data = rgformsmodel::get_lead( $lead->id );
							$data []= $lead->date_created;

							foreach ( $lead_data as $index => $_data ) {
								if ( !is_numeric( $index ) || !$_data ) continue;
								$data []= $_data;
							}

							fputcsv( $csv, $data );
						}

						fputcsv( $csv, array( '--' ) ); /* new line */
					}

					fclose( $csv );
					$new_csv_attachment = $csv_attachment . '-' . date( 'YmdHis' ) . '.csv';
					rename( $csv_attachment, $new_csv_attachment );
					
					wp_mail( $email, 'Form Digest (CSV): ' . implode( ', ', $names ), $report, null, array( $new_csv_attachment ) );
					unlink( $new_csv_attachment );
				} else {
					/* Regular e-mails */
					$report = 'Report generated at ' . date( 'Y-m-d H:i:s' ) . "\n";

					$names = array();
					foreach ( $form_ids as $form_id ) {
						$form = $forms[$form_id];
						$report .= "\nForm name:\t" . $form['title'] . "\n";
						$names []= $form['title'];

						foreach ( $form['leads'] as $lead ) {
							$lead_data = rgformsmodel::get_lead( $lead->id );
							$report .= "\n--\n";
							$report .= "submitted on:\t" . $lead->date_created . "\n";

							foreach ( $lead_data as $index => $data ) {
								if ( !is_numeric( $index ) || !$data ) continue;
								$field = rgformsmodel::get_field( $form, $index );
								$report .= "{$field['label']}:\t$data\n";
							}
						}
					}
					wp_mail( $email, 'Form Digest: ' . implode( ', ', $names ), $report );
				}
			}
		}
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) new GFDigestNotifications; /* initialize */
?>
