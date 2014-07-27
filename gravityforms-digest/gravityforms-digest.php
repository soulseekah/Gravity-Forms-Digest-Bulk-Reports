<?php
	/*
		Plugin Name: Gravity Forms Digest Bulk Reports
		Author: Gennady Kovshenin
		Description: Generates bulk reports for submitted form entries and e-mails these as a digest to specific addresses
		Version: 0.2.1
		Author URI: http://codeseekah.com
	*/

	class GFDigestNotifications {
		private static $textdomain = 'gravityforms-digest';
		public $m;

		public function __construct() {
			$this->bootstrap();
		}

		/** Main initialization routines, adding hooks etc. */
		public function bootstrap() {
			add_action( 'plugins_loaded', array( $this, 'early_init' ) );
			add_action( 'gf_digest_send_notifications', array( $this, 'send_notifications' ), null, 1 );

			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

			/* Attach hooks and other early initialization */
			if ( !isset( $_GET['page']) || $_GET['page'] != 'gf_edit_forms' )
				return; // Nothing else to do, we're not on the setting page

			add_action( 'init', array( $this, 'init' ) );
			add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		}

		/** Activation housekeeping */
		public function activate() {
			$this->reschedule_existing();
		}

		/** Deactivation, bye-bye */
		public function deactivate() {
			$this->remove_schedules();
		}

		/** Remove all schedules */
		public function remove_schedules() {
			$cron = _get_cron_array();

			foreach ( $cron as $timestamp => $schedule ) {
				if ( !isset( $schedule['gf_digest_send_notifications'] ) )
					continue;

				unset( $cron[$timestamp]['gf_digest_send_notifications'] );
				if ( empty( $cron[$timestamp] ) )
					unset( $cron[$timestamp] );
			}

			_set_cron_array( $cron );
		}

		/** Reschedule all existing forms */
		public function reschedule_existing() {
			
			$this->remove_schedules();

			$groups = array();
			foreach( RGFormsModel::get_forms( true ) as $existing_form ) {
				$existing_form = RGFormsModel::get_form_meta( $existing_form->id );

				if ( !isset( $existing_form['digests'] ) )
					continue;
				if ( !isset( $existing_form['digests']['enable_digest'] ) )
					continue;
				if ( !$existing_form['digests']['enable_digest'] )
					continue;
				if ( !isset( $existing_form['digests']['digest_interval'] ) )
					continue;
				if ( !$existing_form['digests']['digest_interval'] )
					continue;

				$group = isset( $existing_form['digests']['digest_group'] ) ? $existing_form['digests']['digest_group'] : '';
				$interval = $existing_form['digests']['digest_interval'];

				if ( !$group || !isset( $groups["$group.$interval"] ) ) {
					wp_schedule_event( // Schedule only once
						apply_filters( 'gf_digest_schedule_next', time() + 3600, $interval ),
						$interval, 'gf_digest_send_notifications', array( intval( $existing_form['id'] ) ) );
					$groups["$group.$interval"] = $existing_form['id'];
				}
			}
		}

		/** Early initialization */
		public function early_init() {
			/* Load languages if available */
			load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		/** Early initializtion for admin interface */
		public function plugins_loaded() {
			/* Add a new meta box to the settings; use `gform_notification_ui_settings` in 1.7 */
			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				add_action( 'gform_form_settings_page_digests', array( $this, 'show_notification_settings' ) );
				add_filter( 'gform_form_settings_menu', array( $this, 'add_notification_settings_tab' ) );
				return;
			}
			add_filter( 'gform_save_notification_button', array( $this, 'add_notification_settings' ) );
		}

		/** Adding a tab in GF 1.7+ */
		public function add_notification_settings_tab( $tabs ) {
			$tabs []= array( 'name' => 'digests', 'label' => __( 'Notification Digest', self::$textdomain ), 'query' => array( 'nid' => null ) );
			return $tabs;
		}

		/** This is a GF 1.7+ UI */
		public function show_notification_settings() {
			
			GFFormSettings::page_header();
			echo '<form method="post">';
			echo $this->add_notification_settings( '' );
			echo '<input type="submit" id="gform_save_settings" name="save" value="Update" class="button-primary gfbutton"></form>';
			GFFormSettings::page_footer();
		}

		/** Save */
		public function init() {
			if ( isset( $_POST['save'] ) )
				$this->process_post_request();
		}

		/** Parse save data */
		private function process_post_request() {

			if ( !current_user_can( 'manage_options' ) ) {
				wp_die( __('Cheatin&#8217; uh?') );
			}

			if ( !isset( $_POST['form_notification_digest_screen'] ) )
				return; // Wrong screen

			$form_id = isset( $_GET['id'] ) ? $_GET['id'] : null;
			if ( !$form_id ) return; // Not supposed to be here

			$form = RGFormsModel::get_form_meta( $form_id );
			if ( !$form ) return; // Nuh-uh

			/* Process the settings bit by bit */
			if ( !isset( $_POST['form_notification_enable_digest'] ) ) {
				$form['digests']['enable_digest'] = false;

				if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
					/* Seems like 1.7 really messed up the meta structure */
					unset( $form['notifications'] );
					unset( $form['confirmations'] );
				}
				RGFormsModel::update_form_meta( $form_id, $form );
				if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
					/* In 1.7 there seems to be an issue with saving */
					GFFormsModel::flush_current_forms();
				}

				$this->reschedule_existing();

				return; // Nothing of interest here, move on
			}

			// TODO: This has to be a function of its own, the tests need it

			$digest_emails = isset( $_POST['form_notification_digest_emails'] ) ? $_POST['form_notification_digest_emails'] : '';
			$digest_interval = isset( $_POST['form_notification_digest_interval'] ) ? $_POST['form_notification_digest_interval'] : '';
			$digest_group = isset( $_POST['form_notification_digest_group'] ) ? $_POST['form_notification_digest_group'] : '';
			$digest_report_always = isset( $_POST['form_notification_digest_report_always'] ) ? $_POST['form_notification_digest_report_always'] : '';
			
			$form['digests']['enable_digest'] = true;
			$form['digests']['digest_emails'] = array_map( 'trim', explode( ',', $digest_emails ) );

			$form['digests']['digest_interval'] = $digest_interval;
			$form['digests']['digest_group'] = $digest_group;
			$form['digests']['digest_report_always'] = $digest_report_always;

			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				/* Seems like 1.7 really messed up the meta structure */
				unset( $form['notifications'] );
				unset( $form['confirmations'] );
			}
			RGFormsModel::update_form_meta( $form_id, $form );

			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				/* In 1.7 there seems to be an issue with saving */
				GFFormsModel::flush_current_forms();
			}

			$this->reschedule_existing();
		}

		/** Add an extra screen to the Notification settings of a Form */
		public function add_notification_settings( $out ) {

			$form_id = isset( $_GET['id'] ) ? $_GET['id'] : null;
			if ( !$form_id ) return $out; // Not supposed to be here

			$form = RGFormsModel::get_form_meta( $form_id );

			$is_digest_enabled = isset( $form['digests']['enable_digest'] ) ? $form['digests']['enable_digest'] : false;
			$digest_emails = isset( $form['digests']['digest_emails'] ) ? $form['digests']['digest_emails'] : false;
			$digest_emails = ( $digest_emails ) ? implode( ',', $digest_emails ) : '';
			$digest_interval = isset( $form['digests']['digest_interval'] ) ? $form['digests']['digest_interval'] : false;
			$digest_group = isset( $form['digests']['digest_group'] ) ? $form['digests']['digest_group'] : false;
			$digest_report_always = isset( $form['digests']['digest_report_always'] ) ? $form['digests']['digest_report_always'] : false;

			$next = wp_next_scheduled( 'gf_digest_send_notifications', array( intval( $form_id ) ) );
			if ( $next ) $next = 'next scheduled in ' . ( $next - time() ) . ' seconds';
			$last = isset( $form['digests']['digest_last_sent'] ) ? $form['digests']['digest_last_sent'] : 0;
			$last = $last ? 'last sent lead ' . $last : '';

			?>
				<div id="submitdiv" class="stuffbox">
					<h3><span class="hndle"><?php _e( 'Notification Digest', self::$textdomain ); ?></span></h3>
					<div class="inside" style="padding: 10px;">
						<input type="hidden" name="form_notification_digest_screen" value="true">
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
							<input type="checkbox" name="form_notification_digest_report_always" id="form_notification_digest_report_always" value="1" <?php checked( $digest_report_always ); ?> /> <label for="form_notification_digest_report_always"><?php _e("Generate digest report even if there are no new entries.", self::$textdomain); ?></label>
							<br>
							<br>
							<code><?php echo $next; ?></code>
							<code><?php echo $last; ?></code>
						</div>
					</div>
				</div>
			<?php

			return $out;
		}

		/** Sends an e-mail out, good stuff */
		public function send_notifications( $form_id ) {
			$form = RGFormsModel::get_form_meta( $form_id );

			if ( !$form ) {
				// TODO: Yet, groups will only be sent out in the next schedule
				// TODO: perhaps add a $now = 'group' flag for instant turnaround?
				$this->reschedule_existing();
				return;
			}

			$digest_group = isset( $form['digests']['digest_group'] ) ? $form['digests']['digest_group'] : false;
			$digest_interval = isset( $form['digests']['digest_interval'] ) ? $form['digests']['digest_interval'] : false;
			$digest_report_always = isset( $form['digests']['digest_report_always'] ) ? $form['digests']['digest_report_always'] : false;

			$forms = array( $form['id'] => $form );
			if ( $digest_group ) {
				/* We may want to send out a group of forms in one e-mail if possible */
				foreach( RGFormsModel::get_forms( true ) as $existing_form ) {
					if ( $existing_form->id == $form_id )
						continue; // It is I!
					$existing_form = RGFormsModel::get_form_meta( $existing_form->id );

					if ( !isset( $existing_form['digests']['enable_digest'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['digests']['digest_group'] ) )
						continue; // Meh, not interesting
					if ( !isset( $existing_form['digests']['digest_interval'] ) )
						continue; // Meh, not interesting

					if ( $existing_form['digests']['digest_group'] == $digest_group )
						if ( $existing_form['digests']['digest_interval'] == $digest_interval ) {
							$forms[$existing_form['id']]= $existing_form; // Add them all
						}
				}
			}

			$emails = array();

			/* Gather all the leads and update the last_sent counters */
			foreach ( $forms as $i => $form ) {
				$last_sent = isset( $form['digests']['digest_last_sent'] ) ? $form['digests']['digest_last_sent'] : 0;

				/* Retrieve form entries newer than the last sent ID */
				global $wpdb;
				$leads_table = RGFormsModel::get_lead_table_name();
				$leads = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $leads_table WHERE form_id = %d AND id > %d AND status = 'active';", $form['id'], $last_sent ) );

				if ( !sizeof( $leads ) ){
					if(!$digest_report_always){
						continue; // Nothing to report on
					}
				} else {
					/* Update the reported id counter */
					$form['digests']['digest_last_sent'] = $leads[sizeof($leads) - 1]->id;
				}
				if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
					/* Seems like 1.7 really messed up the meta structure */
					unset( $form['notifications'] );
					unset( $form['confirmations'] );
				}
				RGFormsModel::update_form_meta( $form['id'], $form );

				$forms[$i]['leads'] = $leads;

				/* Also make a lookup table of all e-mail addresses to forms */
				foreach ( $form['digests']['digest_emails'] as $email ) {
					if ( !isset( $emails[$email] ) ) $emails[$email] = array();
					$emails[$email] []= $form['id'];
				}
			}

			/* Now, let's try and mail stuff */
			foreach ( $emails as $email => $form_ids ) {

				if ( defined( 'GF_DIGESTS_AS_CSV' ) && GF_DIGESTS_AS_CSV ) {
					/* CSV e-mails */
					$report = 'Report generated at ' . date( 'Y-m-d H:i:s' ) . "\n";
					$csv_attachment = tempnam( sys_get_temp_dir(), '' );
					$csv = fopen( $csv_attachment, 'w' );

					$from = null; $to = null;

					$names = array();
					foreach ( $form_ids as $form_id ) {
						$form = $forms[$form_id];

						$names []= $form['title'];
						fputcsv( $csv, array( 'Form: ' . $form['title'] . ' (#' . $form_id . ')' ) );

						$headers = array( 'Date Submitted' );

						foreach( $form['fields'] as $field )
							if ( $field['label'] ) $headers []= $field['label'];

						fputcsv( $csv, $headers );

						foreach ( $form['leads'] as $lead ) {
							$data = array();

							$lead_data = RGFormsModel::get_lead( $lead->id );
							$data []= $lead->date_created;

							if ( !$from )
								$from = $lead->date_created;
							else
								$to = $lead->date_created;

							foreach( $form['fields'] as $field ) {
								if ( !$field['label'] ) continue;
								$data []= RGFormsModel::get_lead_field_value( $lead_data, $field );
							}

							fputcsv( $csv, $data );
						}

						fputcsv( $csv, array( '--' ) ); /* new line */
					}

					if ( !$to )
						$to = $from;

					$report .= 'Contains entries from ' . $from . " to $to\n";
					$report .= 'See CSV attachment';

					fclose( $csv );
					$new_csv_attachment = $csv_attachment . '-' . date( 'YmdHis' ) . '.csv';
					rename( $csv_attachment, $new_csv_attachment );
					
					wp_mail(
						$email,
						apply_filters(
							'gf_digest_email_subject',
							'Form Digest Report (CSV): ' . implode( ', ', $names ),
							$names, array( $from, $to ), $new_csv_attachment ),
						$report, null, array( $new_csv_attachment )
					);

					if ( !defined( 'GF_DIGEST_DOING_TESTS' ) )
						unlink( $new_csv_attachment );
				} else {
					/* Regular e-mails */
					$report = 'Report generated at ' . date( 'Y-m-d H:i:s' ) . "\n";

					$names = array();
					foreach ( $form_ids as $form_id ) {
						$form = $forms[$form_id];
						$report .= "\nForm name:\t" . $form['title'] . "\n";
						$names []= $form['title'];

						$from = null; $to = null;

						foreach ( $form['leads'] as $lead ) {
							$lead_data = RGFormsModel::get_lead( $lead->id );
							$report .= "\n--\n";
							$report .= "submitted on:\t" . $lead->date_created . "\n";

							if ( !$from )
								$from = $lead->date_created;
							else
								$to = $lead->date_created;

							foreach ( $lead_data as $index => $data ) {
								if ( !is_numeric( $index ) || !$data ) continue;
								$field = RGFormsModel::get_field( $form, $index );
								$report .= "{$field['label']}:\t$data\n";
							}
						}

						/* If no new entries (and user has opted to receive digests always)*/
						if (!$form['leads']){
							$report .= __('No new entries.', self::$textdomain);
							$report .= "\n--\n";
						}
					}

					if ( !$to )
						$to = $from;

					wp_mail(
						$email,
						apply_filters(
							'gf_digest_email_subject',
							'Form Digest Report: ' . implode( ', ', $names ),
							$names, array( $from, $to ), null ),
						$report
					);
				}
			}

			if ( version_compare( GFCommon::$version, '1.7' ) >= 0 ) {
				/* In 1.7 there seems to be an issue with saving */
				GFFormsModel::flush_current_forms();
			}
		}
	}

	if ( defined( 'WP_CONTENT_DIR' ) && !defined( 'GF_DIGEST_DOING_TESTS' ) )
		new GFDigestNotifications; /* initialize */
?>
