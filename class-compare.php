<?php
session_start();

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'TFCL_Compare' ) ) {
	class TFCL_Compare {
		const SETTINGS_COMPARE_PAGE = 'compare_page';

		function tfcl_enqueue_compare_scripts () {
            wp_enqueue_script('compare-js', TF_PLUGIN_URL . '/public/assets/js/compare.js', array('jquery'), null, false);
            wp_localize_script('compare-js', 'compare_variables', array(
                    'ajax_url' => TF_AJAX_URL,
					'compare_button_url' => tfcl_get_permalink(self::SETTINGS_COMPARE_PAGE),
                    'alert_message' => esc_html__('Only allowed to compare up to 3 listing!', 'tf-car-listing'),
                    'alert_not_found' => esc_html__('Compare Page Not Found!', 'tf-car-listing')
                )
            );
			wp_enqueue_style('compare-css', TF_PLUGIN_URL . 'public/assets/css/compare.css', array(), '', 'all');
        }

		private static $_instance;

		public static function getInstance()
		{
			if (self::$_instance == null) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

        public function tfcl_show_compare_listings() {
			$this::tfcl_open_session();
			$tfcl_compare_listings = isset($_SESSION['tfcl_compare_listings']) ? (wp_unslash($_SESSION['tfcl_compare_listings'])) : array();
			?>
			<div id="tfcl-compare-listing-listings">
				<?php if (true ): ?>
					<div class="compare-listing-body">
						<div class="compare-thumb-main">
							<?php
							$width             = get_option('thumbnail_width', '100px');
							$height            = get_option('thumbnail_height', '100px');
							$no_image_src      = tfcl_get_option('default_listing_image', '')['url'] != '' ? tfcl_get_option('default_listing_image', '')['url'] : TF_PLUGIN_URL . 'includes/elementor-widget/assets/images/no-image.jpg';
							foreach ( $tfcl_compare_listings as $key ) : ?>
								<?php if ( $key != 0 ) :
									$listing_id = $key;
									$measurement_units           = tfcl_get_option( 'measurement_units' ) == 'custom' ? tfcl_get_option( 'custom_measurement_units' ) : tfcl_get_option( 'measurement_units' );
									$attach_id = get_post_thumbnail_id( (double) $key );
									$image_src = wp_get_attachment_image_url($attach_id);
									$car_mileage = get_post_meta( $listing_id, 'mileage', true ) ? get_post_meta( $listing_id, 'mileage', true ) . ' ' . $measurement_units : 0;
									$car_fuel_type           = get_the_terms( $listing_id, 'fuel-type', true );
									$car_fuel_type_att       = ! empty( $car_fuel_type[0]->name ) ? $car_fuel_type[0]->name : 'none';
									$car_transmission        = get_the_terms( $listing_id, 'transmission' );
									$car_transmission_att    = ! empty( $car_transmission[0]->name ) ? $car_transmission[0]->name : 'none';
									?>
									<div class="compare-thumb tfcl-compare-listing"
									     data-listing-id="<?php echo esc_attr( $key ); ?>">
										 <button type="button" class="compare-listing-remove">
										 <i class="icon-autodeal-close"></i></button>
										 <img loading="lazy" class="compare-listing-img" width="<?php echo esc_attr( $width ) ?>"
										     height="<?php echo esc_attr( $height ) ?>"
										     src="<?php echo esc_url( $image_src ) ?>"
										     onerror="this.src = '<?php echo esc_url( $no_image_src ) ?>';">
											 <div class="content">
												 <h3 class="tfcl-listing-title title">
													 <a title="<?php the_title() ?>"
														 href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>"><?php echo get_the_title( $listing_id ); ?></a>
												 </h3>
												 <ul class="description">
													 <li class="mileage"><?php echo esc_html( $car_mileage ); ?></li>
													 <li class="fuel"><?php echo esc_html( $car_fuel_type_att, 'tf-car-listing' ); ?></li>
													 <li class="trans"><?php echo esc_html( $car_transmission_att ); ?></li>
												 </ul>
											 </div>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button tfcl-compare-listing-button"><?php esc_html_e( 'Compare', 'tf-car-listing' ); ?></button>
					</div>
					<button type="button" class="tfcl-listing-btn"><i class="icon-autodeal-icon-87"></i></button>
				<?php endif; ?>
			</div>
			<?php
        }

		public function tfcl_close_session() {
			if ( isset( $_SESSION ) ) {
				session_destroy();
			}
		}

		public static function tfcl_open_session() {
			if ( ( function_exists( 'session_status' ) && session_status() !== PHP_SESSION_ACTIVE )
			     || ! session_id() ) {
				if ( ! isset( $_SESSION['tfcl_compare_starttime'] ) ) {
					$_SESSION['tfcl_compare_starttime'] = time();
				}
				if ( ! isset( $_SESSION['tfcl_compare_listings'] ) ) {
					$_SESSION['tfcl_compare_listings'] = array();
				}
			}
			if ( isset( $_SESSION['tfcl_compare_starttime'] ) ) {
				if ( (int) $_SESSION['tfcl_compare_starttime'] > time() + 86400 ) {
					unset( $_SESSION['tfcl_compare_listings'] );
				}
			}
		}
		public function tfcl_compare_add_remove_listing_ajax() {
			$listing_id    = isset($_POST['listing_id']) ? absint(wp_unslash($_POST['listing_id'])) : 0;
			if ($listing_id > 0) {
				$max_items      = tfcl_get_option('max_items_compare', 4);
				$this::tfcl_open_session();
				$current_number = ( isset( $_SESSION['tfcl_compare_listings'] ) && is_array( $_SESSION['tfcl_compare_listings'] ) ) ? count(wp_unslash($_SESSION['tfcl_compare_listings'])  ) : 0;

				if ( is_array( $_SESSION['tfcl_compare_listings'] ) && in_array( $listing_id, $_SESSION['tfcl_compare_listings'] ) ) {
					unset( $_SESSION['tfcl_compare_listings'][ array_search( $listing_id, $_SESSION['tfcl_compare_listings'] ) ] );
				} elseif ( $current_number < $max_items ) {

					$_SESSION['tfcl_compare_listings'][] = $listing_id;
				}

				$_SESSION['tfcl_compare_listings'] = array_unique( $_SESSION['tfcl_compare_listings'] );

				$this->tfcl_show_compare_listings();
			}
			wp_die();
		}

		public function tfcl_template_compare_listing() {
			tfcl_get_template_with_arguments( 'listing/compare-listing.php' , array());
		}

		public static function tfcl_compare_shortcode () {
			ob_start();
			tfcl_get_template_with_arguments( 'listing/compare.php' , array());
            return ob_get_clean();
		}
	}
}
