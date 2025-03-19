<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Car_Listing' ) ) {
	class Car_Listing {
		protected $listing_id;
		protected $alert_message;
		public function tfcl_enqueue_listing_scripts() {
			$save_listing_nonce              = wp_create_nonce( 'save_listing_nonce' );
			$listing_upload_nonce            = wp_create_nonce( 'allow_upload_nonce' );
			$listing_ajax_upload_gallery_url = add_query_arg( 'action', 'img_upload', TF_AJAX_URL );
			$listing_ajax_upload_gallery_url = add_query_arg( 'nonce', $listing_upload_nonce, $listing_ajax_upload_gallery_url );

			$listing_ajax_upload_file_attachment_url = add_query_arg( 'action', 'file_attachment_upload', TF_AJAX_URL );
			$listing_ajax_upload_file_attachment_url = add_query_arg( 'nonce', $listing_upload_nonce, $listing_ajax_upload_file_attachment_url );

			$listing_variables = array(
				'plugin_url'                      => TF_PLUGIN_URL,
				'ajax_url'                        => TF_AJAX_URL,
				'ajax_url_upload_gallery'         => $listing_ajax_upload_gallery_url,
				'ajax_url_upload_file_attachment' => $listing_ajax_upload_file_attachment_url,
				'upload_nonce'                    => $listing_upload_nonce,
				'save_listing_nonce'              => $save_listing_nonce,
				'file_type_title'                 => esc_html__( 'Valid file formats', 'tf-car-listing' ),
				'max_listing_images'              => tfcl_get_option( 'maximum_images', '20' ),
				'image_max_file_size'             => tfcl_get_option( 'maximum_image_size', '1000kb' ),
				'image_file_type'                 => tfcl_get_option( 'image_types', 'jpg,jpeg,gif,png' ),
				'max_listing_attachments'         => tfcl_get_option( 'maximum_attachments', '5' ),
				'attachment_max_file_size'        => tfcl_get_option( 'maximum_attachment_size', '1200kb' ),
				'attachment_file_type'            => tfcl_get_option( 'attachment_types', 'pdf,txt,doc,docx,png' ),
				'form_invalid_message'            => esc_html__( 'Form Invalid', 'tf-car-listing' ),
				'required_listing_fields'         => array(
					// Information
					'listing_title' => tfcl_check_required_field( 'listing_title', 'required_listing_fields' ),
					'make'          => tfcl_check_required_field( 'make', 'required_listing_fields' ),
					'model'         => tfcl_check_required_field( 'model', 'required_listing_fields' ),
					'body'          => tfcl_check_required_field( 'body', 'required_listing_fields' ),
					'year'          => tfcl_check_required_field( 'year', 'required_listing_fields' ),
					'condition'     => tfcl_check_required_field( 'condition', 'required_listing_fields' ),
					'stock_number'  => tfcl_check_required_field( 'stock_number', 'required_listing_fields' ),
					'vin_number'    => tfcl_check_required_field( 'vin_number', 'required_listing_fields' ),
					'mileage'       => tfcl_check_required_field( 'mileage', 'required_listing_fields' ),
					'transmission'  => tfcl_check_required_field( 'transmission', 'required_listing_fields' ),
					'drive-type'    => tfcl_check_required_field( 'drive-type', 'required_listing_fields' ),
					'engine_size'   => tfcl_check_required_field( 'engine_size', 'required_listing_fields' ),
					'cylinders'     => tfcl_check_required_field( 'cylinders', 'required_listing_fields' ),
					'fuel-type'     => tfcl_check_required_field( 'fuel-type', 'required_listing_fields' ),
					'door'          => tfcl_check_required_field( 'door', 'required_listing_fields' ),
					'car-color'     => tfcl_check_required_field( 'car-color', 'required_listing_fields' ),
					'seat'          => tfcl_check_required_field( 'seat', 'required_listing_fields' ),
					'city_mpg'      => tfcl_check_required_field( 'city_mpg', 'required_listing_fields' ),
					'highway_mpg'   => tfcl_check_required_field( 'highway_mpg', 'required_listing_fields' ),
					'regular_price' => tfcl_check_required_field( 'regular_price', 'required_listing_fields' ),
					'sale_price'    => tfcl_check_required_field( 'sale_price', 'required_listing_fields' ),
				),
				'map_service'                     => tfcl_get_option( 'map_service' ),
				'api_key_google_map'              => tfcl_get_option( 'google_map_api_key' ),
				'api_key_map_box'                 => tfcl_get_option( 'map_box_api_key' ),
				'map_zoom'                        => tfcl_get_option( 'map_zoom' ),
				'default_marker_image'            => tfcl_get_option( 'default_marker_image' )['url'] != '' ? tfcl_get_option( 'default_marker_image' )['url'] : '',
				'marker_image_width'              => tfcl_get_option( 'marker_image_width' ) != '' ? tfcl_get_option( 'marker_image_width' ) : '90px',
				'marker_image_height'             => tfcl_get_option( 'marker_image_height' ) != '' ? tfcl_get_option( 'marker_image_height' ) : '119px',
				'confirm_remove_listing_favorite' => esc_html__( 'Are you sure you want to remove this listing from your favorites?', 'tf-car-listing' ),
				'default_placeholder_select'      => esc_html__( 'None', 'tf-car-listing' )
			);

			// third-party
			wp_enqueue_script( 'plupload' );
			wp_register_script( 'light-gallery', TF_PLUGIN_URL . 'public/assets/third-party/light-gallery/lightgallery-all.js', array( 'jquery' ), '', true );
			wp_register_script( 'owl.carousel', TF_PLUGIN_URL . 'public/assets/third-party/owl-carousel/owl.carousel.min.js', array( 'jquery' ), '', true );

			// listing
			wp_enqueue_script( 'listing-js', TF_PLUGIN_URL . 'public/assets/js/listing.js', array( 'jquery', 'plupload' ), false, true );
			wp_localize_script( 'listing-js', 'listing_variables', $listing_variables );
			wp_register_script( 'loan-calculator', TF_PLUGIN_URL . 'public/templates/shortcodes/loan-calculator/assets/js/loan-calculator.js', array( 'jquery' ), false, true );
			wp_register_script( 'related-listings', TF_PLUGIN_URL . 'public/templates/shortcodes/related-listings/assets/js/related-listings.js', array( 'jquery' ), false, true );
		}

		public function tfcl_enqueue_listing_styles() {
			wp_register_style( 'listing-css', TF_PLUGIN_URL . 'public/assets/css/single-listing.css', array(), '', 'all' );
			wp_enqueue_style( 'single-listing-css', TF_PLUGIN_URL . 'public/assets/css/listing.css', array(), '', 'all' );
			wp_register_style( 'shortcode-listing-css', TF_PLUGIN_URL . 'public/assets/css/shortcode-listing.css', array(), '', 'all' );
			wp_register_style( 'dashboard-css', TF_PLUGIN_URL . 'public/assets/css/dashboard.css', array(), '', 'all' );
			wp_enqueue_style( 'form-custom-css', TF_PLUGIN_URL . 'public/assets/css/form-custom.css', array(), '', 'all' );
			wp_enqueue_style( 'owl.carousel', TF_PLUGIN_URL . 'public/assets/third-party/owl-carousel/owl.carousel.min.css', array(), '', 'all' );
			wp_register_style( 'light-gallery', TF_PLUGIN_URL . 'public/assets/third-party/light-gallery/lightgallery.css', array(), '', 'all' );
		}

		public static function tfcl_get_total_my_listing( $post_status ) {
			if ( ! is_user_logged_in() )
				return;

			$args = array(
				'post_type'   => 'listing',
				'post_status' => $post_status,
				'author'      => get_current_user_id(),
			);
			if ( current_user_can( 'administrator' ) ) {
				$args['author'] = 0;
			}
			$listing = new WP_Query( $args );
			wp_reset_postdata();
			return $listing->found_posts;
		}

		public function tfcl_get_listing_detail() {
			$listing_id                   = $_POST['listing_id'];
			$listing                      = get_post( $listing_id );
			$prop_dealer_info             = get_post_meta( $listing_id, 'listing_dealer_info', true );
			$dealer_post_meta_data        = get_post_custom( $prop_dealer_info );
			$author_name                  = $prop_dealer_info ? ( isset( $dealer_post_meta_data['dealer_full_name'] ) ? $dealer_post_meta_data['dealer_full_name'][0] : '' ) : get_the_author_meta( 'user_login', $listing->post_author );
			$prop_address                 = get_post_meta( $listing_id, 'listing_address', true );
			$prop_year                    = get_post_meta( $listing_id, 'year', true );
			$prop_price_value             = get_post_meta( $listing_id, 'regular_price', true );
			$car_enable_short_price_unit = tfcl_get_option( 'enable_short_price_unit', 0 ) == 1 ? true : false;

			$prop_price_unit              = get_post_meta( $listing_id, 'listing_price_unit', true );
			$prop_enable_short_price_unit = tfcl_get_option( 'enable_short_price_unit', 0 ) == 1 ? true : false;
			$prop_price_prefix            = get_post_meta( $listing_id, 'listing_price_prefix', true );
			$prop_price_postfix           = get_post_meta( $listing_id, 'listing_price_postfix', true );
			$prop_size                    = get_post_meta( $listing_id, 'listing_size', true ) ? get_post_meta( $listing_id, 'listing_size', true ) : 0;
			$prop_land_area               = get_post_meta( $listing_id, 'listing_land', true ) ? get_post_meta( $listing_id, 'listing_land', true ) : 0;
			$prop_rooms                   = get_post_meta( $listing_id, 'listing_rooms', true ) ? get_post_meta( $listing_id, 'listing_rooms', true ) : 0;
			$prop_bedrooms                = get_post_meta( $listing_id, 'listing_bedrooms', true ) ? get_post_meta( $listing_id, 'listing_bedrooms', true ) : 0;
			$prop_bathrooms               = get_post_meta( $listing_id, 'listing_bathrooms', true ) ? get_post_meta( $listing_id, 'listing_bathrooms', true ) : 0;
			$prop_garages                 = get_post_meta( $listing_id, 'listing_garage', true ) ? get_post_meta( $listing_id, 'listing_garage', true ) : 0;
			$prop_garages_size            = get_post_meta( $listing_id, 'listing_garage_size', true ) ? get_post_meta( $listing_id, 'listing_garage_size', true ) : 0;
			$prop_size                    = get_post_meta( $listing_id, 'listing_size', true ) ? get_post_meta( $listing_id, 'listing_size', true ) : 0;
			$prop_featured                = get_post_meta( $listing_id, 'car_featured', true ) ? get_post_meta( $listing_id, 'car_featured', true ) : false;
			$prop_gallery_images          = get_post_meta( $listing_id, 'gallery_images', true ) ? get_post_meta( $listing_id, 'gallery_images', true ) : '';
			$listing_gallery_Id           = json_decode( $prop_gallery_images );
			$list_gallery_images          = array();
			foreach ( $listing_gallery_Id as $image_id ) {
				$image_src = wp_get_attachment_image_src( $image_id, 'large' );
				if ( is_array( $image_src ) ) {
					$list_gallery_images[] = $image_src[0];
				}
			}
			$modal_content = ''; ?>
			<?php ob_start(); ?>
			<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
					aria-hidden="true">&times;</span></button>
			<div class="popup-listing-container">
				<div class="listing-gallery">
					<div style="--swiper-navigation-color: #fff; --swiper-pagination-color: #fff; margin-bottom: 10px"
						class="swiper-container main-swiper">
						<div class="swiper-wrapper">
							<?php foreach ( $list_gallery_images as $key => $value ) : ?>
								<?php if ( $key === 0 ) : ?>
									<div class="swiper-slide"><img loading="lazy" src="<?php echo esc_attr( $value ); ?>"
											class="swiper-image tfcl-light-gallery" alt="images"></div>
								<?php else : ?>
									<?php if ( isset( $toggle_lazy_load ) && $toggle_lazy_load == 'on' ) : ?>
										<div class="swiper-slide"><img loading="lazy" data-src="<?php echo esc_attr( $value ); ?>"
												class="swiper-image lazy tfcl-light-gallery" alt="images"></div>
									<?php else : ?>
										<div class="swiper-slide"><img loading="lazy" src="<?php echo esc_attr( $value ); ?>"
												class="swiper-image tfcl-light-gallery" alt="images"></div>
									<?php endif; ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<div class="swiper-button-next"></div>
						<div class="swiper-button-prev"></div>
					</div>
					<div class="swiper-container thumb-swiper">
						<div class="swiper-wrapper">
							<?php foreach ( $list_gallery_images as $key => $value ) : ?>
								<?php if ( $key === 0 ) : ?>
									<div class="swiper-slide"><img loading="lazy" src="<?php echo esc_attr( $value ); ?>"
											class="swiper-image tfcl-light-gallery" alt="images"></div>
								<?php else : ?>
									<?php if ( isset( $toggle_lazy_load ) && $toggle_lazy_load == 'on' ) : ?>
										<div class="swiper-slide"><img loading="lazy" data-src="<?php echo esc_attr( $value ); ?>"
												class="swiper-image lazy tfcl-light-gallery" alt="images"></div>
									<?php else : ?>
										<div class="swiper-slide"><img loading="lazy" src="<?php echo esc_attr( $value ); ?>"
												class="swiper-image tfcl-light-gallery" alt="images"></div>
									<?php endif; ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="listing-content">
					<h2 class="listing-title"><?php esc_html_e( $listing->post_title ); ?>
					</h2>
					<?php if ( $prop_featured == true ) : ?>
						<span class="featured-text"><?php esc_html_e( 'Featured', 'tf-car-listing' ); ?></span>
					<?php endif; ?>
					<div class="tfcl-listing-price">
						<?php if ( $prop_price_prefix !== '' ) : ?>
							<span class="tfcl-prop-price-postfix"><?php echo esc_html( $prop_price_prefix ) ?></span>
						<?php endif; ?>
						<span
							class="tfcl-prop-price-value"><?php echo tfcl_format_price( $prop_price_value, $prop_price_unit, false, $prop_enable_short_price_unit ) ?></span>
						<?php if ( $prop_price_postfix !== '' ) : ?>
							<span class="tfcl-prop-price-postfix"> <?php echo esc_html( $prop_price_postfix ) ?></span>
						<?php endif; ?>
					</div>
					<div class="entry-meta">
						<?php if ( $author_name != '' ) : ?>
							<span><i class="fal fa-user"></i> <?php esc_html_e( $author_name, 'tf-car-listing' ) ?></span>
						<?php endif; ?>
						<?php if ( $prop_address != '' ) : ?>
							<div>
								<img src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/map2.svg'; ?>" alt="icon-map">
								<span><?php echo $prop_address; ?></span>
							</div>
						<?php endif; ?>
						<?php if ( $prop_year != '' ) : ?>
							<span class="year"><i class="icon-dreamhome-date"></i>
								<?php echo wp_kses_post( $prop_year ); ?></span>
						<?php endif; ?>
					</div>
					<div class="description">
						<?php if ( $prop_land_area != '' ) : ?>
							<div class="listing-information">
								<img loading="lazy" src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/size2.svg'; ?>"
									class="icon-listing" alt="icon-size">
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_land_area, esc_html__( 'Lands', 'tf-car-listing' ), esc_html__( 'Land', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . number_format( intval( $prop_land_area ) ) . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_rooms != '' ) : ?>
							<div class="listing-information">
								<i class="fal fa-door-closed"></i>
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_rooms, esc_html__( 'Rooms', 'tf-car-listing' ), esc_html__( 'Room', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . $prop_rooms . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_bedrooms != '' ) : ?>
							<div class="listing-information">
								<img loading="lazy" src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/bed.svg'; ?>"
									class="icon-listing" alt="icon-bed">
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_bedrooms, esc_html__( 'Beds', 'tf-car-listing' ), esc_html__( 'Bed', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . $prop_bedrooms . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_bathrooms != '' ) : ?>
							<div class="listing-information">
								<img src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/bath2.svg'; ?>" alt="icon-map">
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_bathrooms, esc_html__( 'Baths', 'tf-car-listing' ), esc_html__( 'Bath', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . $prop_bathrooms . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_size != '' ) : ?>
							<div class="listing-information">
								<img src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/size2.svg'; ?>" alt="icon-map">
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_size, esc_html__( 'Sizes', 'tf-car-listing' ), esc_html__( 'Size', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . number_format( intval( $prop_size ) ) . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_garages != '' ) : ?>
							<div class="listing-information">
								<i class="fal fa-warehouse"></i>
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_garages, esc_html__( 'Garages', 'tf-car-listing' ), esc_html__( 'Garage', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . $prop_garages . '</span>' ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $prop_garages_size != '' ) : ?>
							<div class="listing-information">
								<img loading="lazy" src="<?php echo TF_PLUGIN_URL . 'public/assets/image/icon/size2.svg'; ?>"
									class="icon-listing" alt="icon-size">
								<?php echo sprintf( esc_html( tfcl_get_number_text( $prop_garages_size, esc_html__( 'Garage Sizes', 'tf-car-listing' ), esc_html__( 'Garage Size', 'tf-car-listing' ) ) . ' %s', 'tf-car-listing' ), '<span class="value">' . number_format( intval( $prop_garages_size ) ) . '</span>' ); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
			$modal_content = ob_get_clean();
			echo $modal_content;
			wp_die();
		}

		public static function tfcl_get_total_my_favorites() {
			if ( ! is_user_logged_in() ) {
				return;
			}
			$user_id      = get_current_user_id();
			$my_favorites = get_user_meta( $user_id, 'favorites_listing', true );
			if ( empty( $my_favorites ) ) {
				$my_favorites = array( 0 );
			}
			$args      = array(
				'post_type' => 'listing',
				'post__in'  => $my_favorites
			);
			$favorites = new WP_Query( $args );
			wp_reset_postdata();
			return $favorites->found_posts;
		}

		public function tfcl_my_listing_shortcode() {
			$posts_per_page       = tfcl_get_option( 'item_per_page_my_listing', '8' );
			$list_post_status     = array( 'publish', 'expired', 'pending', 'hidden', 'sold' );
			$total_post       = wp_count_posts( 'listing' );
			$total_count      = 0;
			
			// Sum up the counts for all post statuses
			foreach ( $total_post as $status => $statusCount ) {
				$total_count += $statusCount;
			}
			
			$selected_post_status = $title_search = '';
			$from_date = ! empty( $_REQUEST['from_date'] ) ? wp_unslash( $_REQUEST['from_date'] ) : '';
			$to_date   = ! empty( $_REQUEST['to_date'] ) ? wp_unslash( $_REQUEST['to_date'] ) : '';
			$title_search = ! empty( $_REQUEST['title_search'] ) ? wp_unslash( $_REQUEST['title_search'] ) : '';
			$class_listing  = new Car_Listing();


			$args = array(
				'post_type'      => 'listing',
				'post_status'    => $list_post_status,
				'posts_per_page' => $posts_per_page,
				'author'         => get_current_user_id(),
				'offset'         => ( max( 1, get_query_var( 'paged' ) ) - 1 ) * $posts_per_page,
				'orderby'             => 'date',
				'order'               => 'desc',
				's'                   => $title_search,
				'date_query'          => array(
					'after'     => $from_date,
					'before'    => $to_date,
					'inclusive' => true,
				),
			);

			if ( current_user_can( 'administrator' ) ) {
				$args['author'] = 0;
			}

			if ( ! empty( $_REQUEST['post_status'] ) && $_REQUEST['post_status'] != 'default' ) {
				$selected_post_status = wp_unslash( $_REQUEST['post_status'] );
				$args['post_status']  = $selected_post_status;
			} else {
				$args['post_status'] = [ 'any', 'hidden', 'sold', 'expired' ];
			}

			$listing = new WP_Query( $args );
			$total_post_listing = $listing->found_posts;

			ob_start();
			tfcl_get_template_with_arguments(
				'listing/my-listing.php',
				array(
					'listings'              => $listing->posts,
					'max_num_pages'        => $listing->max_num_pages,
					'list_post_status'     => $list_post_status,
					'selected_post_status' => $selected_post_status,
					'title_search'         => $title_search,
					'total_post'              => $total_count,
					'total_post_listing'      => $total_post_listing,
					'search'                   => $title_search,
					'from_date'                => $from_date,
					'to_date'                  => $to_date,
				)
			);
			return ob_get_clean();
		}

		public function tfcl_handle_actions_my_listing() {
			if ( ! empty( $_REQUEST['action'] ) && ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), 'tfcl_my_listing_actions' ) ) {
				$action     = isset( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : '';
				$listing_id = isset( $_REQUEST['listing_id'] ) ? absint( wp_unslash( $_REQUEST['listing_id'] ) ) : '';
				$listing    = get_post( $listing_id );
				global $current_user;
				wp_get_current_user();
				$user_id = $current_user->ID;
				try {
					switch ( $action ) {
						case 'delete':
							wp_trash_post( $listing_id );
							// Alert Message
							$this->alert_message = '<div class="alert alert-success" role="alert">' . sprintf( wp_kses_post( __( '<strong>Success!</strong> %s has been deleted', 'tf-car-listing' ) ), $listing->post_title ) . '</div>';
							break;
						case 'hide':
							$data_update = array(
								'ID'          => $listing_id,
								'post_type'   => 'listing',
								'post_status' => 'hidden'
							);
							wp_update_post( $data_update );
							$this->alert_message = '<div class="alert alert-success" role="alert">' . sprintf( wp_kses_post( __( '<strong>Success!</strong> %s has been hidden', 'tf-car-listing' ) ), $listing->post_title ) . '</div>';
							break;
						case 'sold':
							$data_update = array(
								'ID'          => $listing_id,
								'post_type'   => 'listing',
								'post_status' => 'sold'
							);
							wp_update_post( $data_update );
							$this->alert_message = '<div class="alert alert-success" role="alert">' . sprintf( wp_kses_post( __( '<strong>Success!</strong> %s has been sold', 'tf-car-listing' ) ), $listing->post_title ) . '</div>';
							break;
						case 'show':
							$data = array(
								'ID'          => $listing_id,
								'post_type'   => 'listing',
								'post_status' => 'publish'
							);
							wp_update_post( $data );
							$this->alert_message = '<div class="alert alert-success" role="alert">' . sprintf( wp_kses_post( __( '<strong>Success!</strong> %s has been publish', 'tf-car-listing' ) ), $listing->post_title ) . '</div>';
							break;
						default:
							# code...
							break;
					}
				} catch (\Throwable $th) {
					//throw $th;
				}
			}
		}

		public function tfcl_get_link_filter_post_status( $post_status ) {
			$link_filter = add_query_arg( array( 'post_status' => $post_status ) );
			return $link_filter;
		}

		public function tfcl_save_listing_shortcode() {
			if ( tfcl_get_option( "map_service" ) == 'map-box' ) {
				$map_box_variables = array(
					'plugin_url'           => TF_PLUGIN_URL,
					'ajax_url'             => TF_AJAX_URL,
					'map_service'          => tfcl_get_option( 'map_service' ),
					'api_key_google_map'   => tfcl_get_option( 'google_map_api_key' ),
					'api_key_map_box'      => tfcl_get_option( 'map_box_api_key' ),
					'map_zoom'             => tfcl_get_option( 'map_zoom' ),
					'default_marker_image' => tfcl_get_option( 'default_marker_image' )['url'] != '' ? tfcl_get_option( 'default_marker_image' )['url'] : '',
					'marker_image_width'   => tfcl_get_option( 'marker_image_width' ) != '' ? tfcl_get_option( 'marker_image_width' ) : '90px',
					'marker_image_height'  => tfcl_get_option( 'marker_image_height' ) != '' ? tfcl_get_option( 'marker_image_height' ) : '119px',
				);
				wp_enqueue_script( 'map-box-script', TF_PLUGIN_URL . 'public/assets/js/map-box.js', array( 'jquery' ), false, true );
				wp_localize_script( 'map-box-script', 'map_box_variables', $map_box_variables );
			}

			$action             = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			$mode               = 'listing-add';
			$submit_button_text = tfcl_get_option( 'add_listing_button_text', 'List Now' );
			if ( isset( $_GET['action'] ) && $_GET['action'] == 'edit' && isset( $_GET['listing_id'] ) ) {
				$mode               = 'listing-edit';
				$submit_button_text = tfcl_get_option( 'update_listing_button_text', 'Update listing' );
				$this->listing_id   = $_GET['listing_id'];
			}

			ob_start();
			echo $this->alert_message;
			tfcl_get_template_with_arguments(
				'listing/save-listing.php',
				array(
					'listing_id'         => $this->listing_id,
					'action'             => $action,
					'mode'               => $mode,
					'submit_button_text' => esc_html__( $submit_button_text, 'tf-car-listing' ),
				)
			);
			return ob_get_clean();
		}

		public function tfcl_check_user_has_permission_edit( $listing_id ) {
			$permission_edit = true;

			if ( ! $listing_id || ! is_user_logged_in() ) {
				$permission_edit = false;
			} else {
				$listing = get_post( $listing_id );

				if ( ! $listing || ( $listing->post_author !== get_current_user_id() && ! current_user_can( 'edit_post', $listing_id ) ) ) {
					$permission_edit = false;
				}
			}
			return $permission_edit;
		}

		public function tfcl_listing_image_upload_ajax() {
			$nonce = isset( $_REQUEST['nonce'] ) ? wp_unslash( $_REQUEST['nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'allow_upload_nonce' ) ) {
				$response = array( 'success' => false, 'reason' => esc_html__( 'Check nonce failed!', 'tf-car-listing' ) );
				echo json_encode( $response );
				wp_die();
			}

			$submitted_file = $_FILES['image_file_name'];

			$uploaded_image = wp_handle_upload( $submitted_file, array( 'test_form' => false ) );

			if ( isset( $uploaded_image['file'] ) ) {
				$file_name          = basename( $submitted_file['name'] );
				$file_type          = wp_check_filetype( $uploaded_image['file'] );
				$attachment_details = array(
					'guid'           => $uploaded_image['url'],
					'post_mime_type' => $file_type['type'],
					'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_name ) ),
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				$attach_id   = wp_insert_attachment( $attachment_details, $uploaded_image['file'] );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded_image['file'] );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				$thumbnail_url  = wp_get_attachment_thumb_url( $attach_id );
				$full_image_url = wp_get_attachment_image_src( $attach_id, 'full' );

				$response = array(
					'success'       => true,
					'url'           => $thumbnail_url,
					'attachment_id' => $attach_id,
					'full_image'    => is_array( $full_image_url ) ? $full_image_url[0] : ''
				);
				echo json_encode( $response );
				wp_die();

			} else {
				$response = array( 'success' => false, 'reason' => esc_html__( 'Upload failed!', 'tf-car-listing' ) );
				echo json_encode( $response );
				wp_die();
			}
		}

		public function tfcl_listing_attachment_upload_ajax() {
			$nonce = isset( $_REQUEST['nonce'] ) ? wp_unslash( $_REQUEST['nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'allow_upload_nonce' ) ) {
				$response = array( 'success' => false, 'reason' => esc_html__( 'Check nonce failed!', 'tf-car-listing' ) );
				echo json_encode( $response );
				wp_die();
			}

			$submitted_file  = $_FILES['file_attachments_name'];
			$file_attachment = wp_handle_upload( $submitted_file, array( 'test_form' => false ) );

			if ( isset( $file_attachment['file'] ) ) {
				$file_name = basename( $submitted_file['name'] );
				$file_type = wp_check_filetype( $file_attachment['file'] );


				$attachment_details = array(
					'guid'           => $file_attachment['url'],
					'post_mime_type' => $file_type['type'],
					'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_name ) ),
					'post_content'   => '',
					'post_status'    => 'inherit'
				);

				$attach_id   = wp_insert_attachment( $attachment_details, $file_attachment['file'] );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $file_attachment['file'] );
				wp_update_attachment_metadata( $attach_id, $attach_data );
				$attach_url     = wp_get_attachment_url( $attach_id );
				$file_type_name = isset( $file_type['ext'] ) ? $file_type['ext'] : '';
				$thumb_url      = TF_PLUGIN_URL . 'public/assets/attachment/attach-' . $file_type_name . '.png';

				$response = array(
					'success'       => true,
					'url'           => $attach_url,
					'attachment_id' => $attach_id,
					'thumb_url'     => $thumb_url,
					'file_name'     => $file_name
				);
				echo json_encode( $response );
				wp_die();

			} else {
				$response = array( 'success' => false, 'reason' => esc_html__( 'Upload file failed!', 'tf-car-listing' ) );
				echo json_encode( $response );
				wp_die();
			}
		}

		public function tfcl_delete_listing_image_or_file_ajax() {
			$nonce = isset( $_POST['deleteNonce'] ) ? wp_unslash( $_POST['deleteNonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'allow_upload_nonce' ) ) {
				$response = array(
					'success' => false,
					'reason'  => esc_html__( 'Security check fails', 'tf-car-listing' )
				);
				echo json_encode( $response );
				wp_die();
			}
			$success = false;
			if ( isset( $_POST['listing_id'] ) && isset( $_POST['attachment_id'] ) ) {
				$listing_id    = absint( wp_unslash( $_POST['listing_id'] ) );
				$type          = isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '';
				$attachment_id = absint( wp_unslash( $_POST['attachment_id'] ) );
				if ( $listing_id > 0 ) {
					if ( $type === 'image' ) {
						delete_post_meta( $listing_id, 'listing_images', $attachment_id );
					}
					if ( $type === 'attachment' ) {
						delete_post_meta( $listing_id, 'listing_attachments', $attachment_id );
					}
					$success = true;
				}
				if ( $attachment_id > 0 ) {
					wp_delete_attachment( $attachment_id );
					$success = true;
				}
			}
			$response = array(
				'success' => $success,
			);
			echo json_encode( $response );
			wp_die();
		}

		public function tfcl_save_listing() {
			$tfcl_allow_submit_listing = tfcl_allow_submit_listing();
			if ( $tfcl_allow_submit_listing ) {
				$auto_publish_submitted = tfcl_get_option( 'auto_publish_submitted', 'n' );
				$auto_publish_edited    = tfcl_get_option( 'auto_publish_edited', 'n' );
				$listing                = array();
				global $current_user;
				wp_get_current_user();
				$user_id                = $current_user->ID;
				$listing['post_author'] = $user_id;
				$listing['post_type']   = 'listing';
				$listing['post_status'] = 'pending';

				$submit_mode             = isset( $_POST['listing_mode'] ) ? wp_unslash( $_POST['listing_mode'] ) : '';
				$listing_author          = isset( $_POST['listing_author'] ) ? absint( wp_unslash( $_POST['listing_author'] ) ) : '';
				$listing_id              = 0;
				$check_package_available = User_Package_Public::tfcl_check_user_package_available( $user_id );

				if ( isset( $_POST['listing_title'] ) ) {
					$listing['post_title'] = wp_unslash( $_POST['listing_title'] );
				}

				if ( isset( $_POST['listing_description'] ) ) {
					$listing['post_content'] = wp_filter_post_kses( $_POST['listing_description'] );
				}

				if ( $submit_mode === 'listing-add' ) {
					if ( $auto_publish_submitted == 'y' ) {
						$listing['post_status'] = 'publish';
					}

					if ( $check_package_available != 0 || $check_package_available != -1 || $check_package_available != -2 ) {
						$listing_id = wp_insert_post( $listing, true );
					}

					if ( $listing_id > 0 ) {
						$package_number_listing = intval( get_user_meta( $user_id, 'package_number_listing', true ) );
						if ( ( $package_number_listing - 1 ) >= 0 ) {
							update_user_meta( $user_id, 'package_number_listing', ( $package_number_listing - 1 ) );
						}
					}
				} else if ( $submit_mode === 'listing-edit' ) {
					if ( $auto_publish_edited == 'y' ) {
						$listing['post_status'] = 'publish';
					}
					$listing_id    = absint( wp_unslash( $_POST['listing_id'] ) );
					$listing['ID'] = intval( $listing_id );
					if ( $user_id == $listing_author ) {
						$listing_id = wp_update_post( $listing );
					}
				}
				$this->listing_id = $listing_id;

				if ( $listing_id > 0 ) {
					if ( isset( $_POST['listing_address'] ) ) {
						update_post_meta( $listing_id, 'listing_address', wp_unslash( $_POST['listing_address'] ) );
					}

					if ( isset( $_POST['listing_location'] ) ) {
						update_post_meta( $listing_id, 'listing_location', wp_unslash( $_POST['listing_location'] ) );
					}

					if ( isset( $_POST['listing_neighborhood'] ) ) {
						$listing_neighborhood = wp_unslash( $_POST['listing_neighborhood'] );
						wp_set_object_terms( $listing_id, $listing_neighborhood, 'neighborhood' );
					}

					if ( isset( $_POST['listing_location'] ) ) {
						$listing_listing_location = wp_unslash( $_POST['listing_location'] );
						update_post_meta( $listing_id, 'listing_location', $listing_listing_location );
					}

					if ( isset( $_POST['regular_price'] ) ) {
						$regular_price = wp_unslash( $_POST['regular_price'] );
						update_post_meta( $listing_id, 'regular_price', $regular_price );
						if ( is_numeric( $regular_price ) ) {
							$regular_price = doubleval( $regular_price );
							update_post_meta( $listing_id, 'regular_price', $regular_price );
						} else {
							update_post_meta( $listing_id, 'regular_price', '' );
						}
					}
					if ( isset( $_POST['sale_price'] ) ) {
						update_post_meta( $listing_id, 'sale_price', wp_unslash( $_POST['sale_price'] ) );
					}
					if ( isset( $_POST['price_custom_label'] ) ) {
						update_post_meta( $listing_id, 'price_custom_label', wp_unslash( $_POST['price_custom_label'] ) );
					}
					if ( isset( $_POST['listing_price_unit'] ) ) {
						$listing_price_unit = isset( $_POST['listing_price_unit'] ) ? wp_unslash( $_POST['listing_price_unit'] ) : '1';
						update_post_meta( $listing_id, 'listing_price_unit', $listing_price_unit );
					}
					if ( isset( $_POST['price_prefix'] ) ) {
						update_post_meta( $listing_id, 'price_prefix', wp_unslash( $_POST['price_prefix'] ) );
					}
					if ( isset( $_POST['price_suffix'] ) ) {
						update_post_meta( $listing_id, 'price_suffix', wp_unslash( $_POST['price_suffix'] ) );
					}

					$listing_make = isset( $_POST['make'] ) ? array_map( 'strval', wp_unslash( $_POST['make'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_make, 'make' );

					$listing_model = isset( $_POST['model'] ) ? array_map( 'strval', wp_unslash( $_POST['model'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_model, 'model' );

					$listing_body = isset( $_POST['body'] ) ? array_map( 'intval', wp_unslash( $_POST['body'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_body, 'body' );

					$listing_condition = isset( $_POST['condition'] ) ? array_map( 'intval', wp_unslash( $_POST['condition'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_condition, 'condition' );

					$listing_transmission = isset( $_POST['transmission'] ) ? array_map( 'intval', wp_unslash( $_POST['transmission'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_transmission, 'transmission' );

					$listing_drive_type = isset( $_POST['drive-type'] ) ? array_map( 'intval', wp_unslash( $_POST['drive-type'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_drive_type, 'drive-type' );

					$listing_cylinders = isset( $_POST['cylinders'] ) ? array_map( 'intval', wp_unslash( $_POST['cylinders'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_cylinders, 'cylinders' );

					$listing_fuel_type = isset( $_POST['fuel-type'] ) ? array_map( 'intval', wp_unslash( $_POST['fuel-type'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_fuel_type, 'fuel-type' );

					$listing_car_color = isset( $_POST['car-color'] ) ? array_map( 'intval', wp_unslash( $_POST['car-color'] ) ) : null;
					wp_set_object_terms( $listing_id, $listing_car_color, 'car-color' );

					if ( isset( $_POST['year'] ) ) {
						update_post_meta( $listing_id, 'year', wp_unslash( $_POST['year'] ) );
					}

					if ( isset( $_POST['stock_number'] ) ) {
						update_post_meta( $listing_id, 'stock_number', wp_unslash( $_POST['stock_number'] ) );
					}

					if ( isset( $_POST['vin_number'] ) ) {
						update_post_meta( $listing_id, 'vin_number', wp_unslash( $_POST['vin_number'] ) );
					}

					if ( isset( $_POST['mileage'] ) ) {
						update_post_meta( $listing_id, 'mileage', wp_unslash( $_POST['mileage'] ) );
					}

					if ( isset( $_POST['engine_size'] ) ) {
						update_post_meta( $listing_id, 'engine_size', wp_unslash( $_POST['engine_size'] ) );
					}

					if ( isset( $_POST['door'] ) ) {
						update_post_meta( $listing_id, 'door', wp_unslash( $_POST['door'] ) );
					}

					if ( isset( $_POST['seat'] ) ) {
						update_post_meta( $listing_id, 'seat', wp_unslash( $_POST['seat'] ) );
					}

					if ( isset( $_POST['city_mpg'] ) ) {
						update_post_meta( $listing_id, 'city_mpg', wp_unslash( $_POST['city_mpg'] ) );
					}

					if ( isset( $_POST['highway_mpg'] ) ) {
						update_post_meta( $listing_id, 'highway_mpg', wp_unslash( $_POST['highway_mpg'] ) );
					}

					if ( isset( $_POST['car_featured'] ) ) {
						$car_featured = $_POST['car_featured'] == '1' ? true : false;
						update_post_meta( $listing_id, 'car_featured', $car_featured );
					}

					$features = isset( $_POST['features'] ) ? wp_unslash( $_POST['features'] ) : '';
					if ( is_array( $features ) ) {
						wp_set_object_terms( $listing_id, $features, 'features' );
					}

					$gallery_images = isset( $_POST['gallery_images'] ) ? wp_unslash( $_POST['gallery_images'] ) : '';
					if ( ! empty( $gallery_images ) && is_array( $gallery_images ) ) {
						update_post_meta( $listing_id, 'gallery_images', json_encode( $gallery_images ) );
						update_post_meta( $listing_id, '_thumbnail_id', $gallery_images[0] );
					}

					if ( isset( $_POST['text_brochures'] ) ) {
						update_post_meta( $listing_id, 'text_brochures', wp_unslash( $_POST['text_brochures'] ) );
					}

					$attachments_file = isset( $_POST['attachments_file'] ) ? wp_unslash( $_POST['attachments_file'] ) : '';
					if ( ! empty( $attachments_file ) && is_array( $attachments_file ) ) {
						update_post_meta( $listing_id, 'attachments_file', json_encode( $attachments_file ) );
					}

					if ( isset( $_POST['virtual_tour_type'] ) ) {
						$listing_virtual_tour_type = wp_unslash( $_POST['virtual_tour_type'] );
						update_post_meta( $listing_id, 'virtual_tour_type', $listing_virtual_tour_type );

						if ( $listing_virtual_tour_type === '0' ) {
							$virtual_tour_embedded_code = isset( $_POST['virtual_tour_embedded_code'] ) ? wp_unslash( $_POST['virtual_tour_embedded_code'] ) : '';
							update_post_meta( $listing_id, 'virtual_tour_embedded_code', $virtual_tour_embedded_code );
						} else if ( $listing_virtual_tour_type === '1' ) {
							$virtual_tour_upload_image = isset( $_POST['virtual_tour_upload_image'] ) ? wp_unslash( $_POST['virtual_tour_upload_image'] ) : '';
							update_post_meta( $listing_id, 'virtual_tour_upload_image', $virtual_tour_upload_image );
						}
					} else {
						update_post_meta( $listing_id, 'virtual_tour_type', '0' );
					}

					if ( isset( $_POST['video_url'] ) ) {
						update_post_meta( $listing_id, 'video_url', wp_unslash( $_POST['video_url'] ) );
					}

					if ( isset( $_POST['listing_additional_detail'] ) ) {
						$listing_additional_detail = $_POST['listing_additional_detail'];
						if ( ! empty( $listing_additional_detail ) ) {
							update_post_meta( $listing_id, 'listing_additional_detail', $listing_additional_detail );
						}
					} else {
						update_post_meta( $listing_id, 'listing_additional_detail', array() );
					}

					$get_additional_fields = tfcl_get_additional_fields();
					if ( is_array( $get_additional_fields ) && count( $get_additional_fields ) > 0 ) {
						foreach ( $get_additional_fields as $key => $field ) {
							if ( isset( $_POST[ $key ] ) ) {
								if ( $field['type'] == 'textarea' ) {
									update_post_meta( $listing_id, $key, wp_filter_post_kses( $_POST[ $key ] ) );
								} else if ( $field['type'] == 'checkbox' ) {
									$array_value = array();
									foreach ( $_POST[ $key ] as $value ) {
										$array_value[] = $value;
									}
									update_post_meta( $listing_id, $key, $array_value );
								} else {
									update_post_meta( $listing_id, $key, wp_unslash( $_POST[ $key ] ) );
								}
							} else {
								if ( $field[ $key ] == 'checkbox' ) {
									update_post_meta( $listing_id, $key, array() );
								} else {
									update_post_meta( $listing_id, $key, '' );
								}
							}
						}
					}

					$dealer_id = get_user_meta( $user_id, 'author_dealer_id', true );
					update_post_meta( $listing_id, 'listing_dealer_info', $dealer_id );
					if ( isset( $_POST['create_information_options'] ) ) {
						$listing_dealer_information_options = wp_unslash( $_POST['create_information_options'] );
						update_post_meta( $listing_id, 'create_information_options', $listing_dealer_information_options );

						if ( $listing_dealer_information_options === 'other_info' ) {
							if ( isset( $_POST['listing_other_dealer_name'] ) ) {
								update_post_meta( $listing_id, 'listing_other_dealer_name', wp_unslash( $_POST['listing_other_dealer_name'] ) );
							}
							if ( isset( $_POST['listing_other_dealer_email'] ) ) {
								update_post_meta( $listing_id, 'listing_other_dealer_email', sanitize_email( wp_unslash( $_POST['listing_other_dealer_email'] ) ) );
							}
							if ( isset( $_POST['listing_other_dealer_phone'] ) ) {
								update_post_meta( $listing_id, 'listing_other_dealer_phone', wp_unslash( $_POST['listing_other_dealer_phone'] ) );
							}
							if ( isset( $_POST['listing_other_dealer_description'] ) ) {
								update_post_meta( $listing_id, 'listing_other_dealer_description', wp_filter_post_kses( $_POST['listing_other_dealer_description'] ) );
							}
						}
					} else {
						update_post_meta( $listing_id, 'create_information_options', 'dealer_info' );
					}
					return $listing_id;
				}
			}
			return null;
		}

		public function tfcl_handle_save_listing_ajax() {
			$response = array(
				'status'  => false,
				'message' => esc_html__( 'Error, try again!', 'tf-car-listing' ),
			);
			header( 'Content-Type: application/json' );
			$submit_mode = isset( $_POST['listing_mode'] ) ? wp_unslash( $_POST['listing_mode'] ) : '';
			if ( $submit_mode === '' ) {
				return;
			}
			if ( ! is_user_logged_in() ) {
				tfcl_get_template_with_arguments( 'global/access-permission.php', array( 'type' => 'not_login' ) );
				return;
			}

			$nonce = isset( $_REQUEST['nonce'] ) ? wp_unslash( $_REQUEST['nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'save_listing_nonce' ) ) {
				$response = array( 'status' => false, 'message' => esc_html__( 'Check nonce failed!', 'tf-car-listing' ) );
				echo json_encode( $response );
				wp_die();
			}

			$listing_id = $this->tfcl_save_listing();
			if ( $listing_id ) {
				$response['status']       = true;
				$response['message']      = esc_html__( 'Save listing successfully!', 'tf-car-listing' );
				$response['listing_id']   = $listing_id;
				$response['submit_mode']  = $submit_mode;
				$response['redirect_url'] = tfcl_get_permalink( 'my_listing_page' );
			} else {
				$response['status']  = false;
				$response['message'] = esc_html__( 'You are not permission!', 'tf-car-listing' );
			}

			echo json_encode( $response );
			wp_die();
		}

		public function tfcl_listing_favorite() {

			ob_start();
			tfcl_get_template_with_arguments( 'listing/favorite.php', array() );
			echo ob_get_clean();
		}

		public function tfcl_listing_compare() {

			ob_start();
			tfcl_get_template_with_arguments( 'listing/compare-button.php', array() );
			echo ob_get_clean();
		}

		public function tfcl_car_listing_shortcode() {
			$list_post_status = array( 'publish' );
			$posts_per_page   = tfcl_get_option( 'item_per_page_archive_listing', '10' );
			$args             = array(
				'post_type'      => 'listing',
				'post_status'    => $list_post_status,
				'posts_per_page' => $posts_per_page,
				'offset'         => ( max( 1, get_query_var( 'paged' ) ) - 1 ) * $posts_per_page,
				'orderby'        => 'date',
				'order'          => 'desc',
			);

			$listing = new WP_Query( $args );

			ob_start();
			tfcl_get_template_with_arguments(
				'listing/car-listing.php',
				array(
					'listing'          => $listing,
					'max_num_pages'    => $listing->max_num_pages,
					'list_post_status' => $list_post_status,
				)
			);
			return ob_get_clean();
		}

		public function tfcl_favorite_ajax() {
			global $current_user;
			$listing_id = isset( $_POST['listing_id'] ) ? absint( wp_unslash( $_POST['listing_id'] ) ) : 0;
			wp_get_current_user();
			$user_id  = $current_user->ID;
			$added    = $removed = false;
			$response = '';

			if ( $user_id > 0 ) {
				$my_favorites = get_user_meta( $user_id, 'favorites_listing', true );

				if ( ! is_array( $my_favorites ) ) {
					$my_favorites = array();
				}

				if ( in_array( $listing_id, $my_favorites ) ) {
					$my_favorites = array_diff( $my_favorites, array( $listing_id ) );
					$removed      = true;
				} else {
					$my_favorites[] = $listing_id;
					$added          = true;
				}

				update_user_meta( $user_id, 'favorites_listing', $my_favorites );
				if ( $added ) {
					$response = array( 'added' => 1, 'message' => esc_html__( 'Added', 'tf-car-listing' ) );
				}
				if ( $removed ) {
					$response = array( 'added' => 0, 'message' => esc_html__( 'Removed', 'tf-car-listing' ) );
				}
			} else {
				$response = array(
					'added'   => -1,
					'message' => esc_html__( 'To continue, you need to log in.', 'tf-car-listing' )
				);
			}

			echo wp_json_encode( $response );
			wp_die();
		}

		public function tfcl_set_views() {
			global $post;

			if ( is_singular( 'listing' ) ) {
				$visited_posts = array();
				// Check cookie for list of visited posts
				$_ctp_listing_views = isset( $_COOKIE['ctp_listing_views'] ) ? wp_unslash( $_COOKIE['ctp_listing_views'] ) : '';

				if ( ! empty( $_ctp_listing_views ) ) {
					$visited_posts = array_map( 'intval', explode( ',', $_ctp_listing_views ) );
				}

				// User already visited this post, don't count post view
				if ( in_array( $post->ID, $visited_posts ) ) {
					return;
				}

				// Add post id and set cookie for 12 hours
				$visited_posts[] = $post->ID;
				setcookie( 'ctp_listing_views', implode( ',', $visited_posts ), time() + ( 3600 * 12 ), '/' );

				// Set views in a day
				$today         = date( 'Y-m-d', time() );
				$views_by_date = get_post_meta( $post->ID, 'listings_views_by_date', true );
				if ( $views_by_date != '' || is_array( $views_by_date ) ) {
					if ( ! isset( $views_by_date[ $today ] ) ) {
						if ( count( $views_by_date ) > 90 ) {
							array_shift( $views_by_date );
						}
						$views_by_date[ $today ] = 1;
					} else {
						$views_by_date[ $today ] = intval( $views_by_date[ $today ] ) + 1;
					}
				} else {
					$views_by_date           = array();
					$views_by_date[ $today ] = 1;
				}
				update_post_meta( $post->ID, 'listings_views_by_date', $views_by_date );

				// The visitor is reading this post first time in day, so we count
				$views = (int) get_post_meta( $post->ID, 'listing_views', true );
				$views = empty( $views ) ? 1 : $views++;
				update_post_meta( $post->ID, 'listing_views', $views );
			}
		}

		public function tfcl_listing_map_shortcode( $atts ) {

			
			wp_enqueue_style( 'mapbox-gl' );
			wp_enqueue_style( 'mapbox-gl-geocoder' );
			wp_enqueue_style( 'map-styles' );
			wp_enqueue_script( 'mapbox-gl' );
			wp_enqueue_script( 'mapbox-gl-geocoder' );
			wp_dequeue_script('listing-js');
			wp_enqueue_script( 'listing-js' );
			wp_dequeue_script('archive-taxonomy-js');
			wp_enqueue_script( 'archive-taxonomy-js' );

			extract(
				shortcode_atts(
					array(
						'layout_listing'                       => '',
						'search_form'                          => '',
						'archive_listing_search_form_position' => '',
						'map_position'                         => '',
						'sidebar'                              => '',
						'sidebar_position'                     => '',
						'items_per_page'                       => '',
						'column_listing_grid'                  => '',
						'column_listing_list'                  => ''
					),
					$atts
				)
			);

			$list_order = array(
				'default'    => esc_html__( 'Sort by (Defaut)', 'tf-car-listing' ),
				'name_asc'   => esc_html__( 'Sort by name ( A-Z)', 'tf-car-listing' ),
				'name_desc'  => esc_html__( 'Sort by name ( Z-A)', 'tf-car-listing' ),
				'price_asc'  => esc_html__( 'Sort by price: Low to High', 'tf-car-listing' ),
				'price_desc' => esc_html__( 'Sort by price: High to Low', 'tf-car-listing' )
			);

			ob_start();
			tfcl_get_template_with_arguments(
				'listing/listing-map.php',
				array(
					'list_order'                           => $list_order,
					'layout_listing'                       => $layout_listing,
					'search_form'                          => $search_form,
					'archive_listing_search_form_position' => $archive_listing_search_form_position,
					'map_position'                         => $map_position,
					'sidebar'                              => $sidebar,
					'sidebar_position'                     => $sidebar_position,
					'items_per_page'                       => $items_per_page,
					'column_listing_grid'                  => $column_listing_grid,
					'column_listing_list'                  => $column_listing_list
				)
			);
			return ob_get_clean();
		}

		public function tfcl_listing_map_get_url( $atts ) {

			wp_enqueue_style( 'mapbox-gl' );
			wp_enqueue_style( 'mapbox-gl-geocoder' );
			wp_enqueue_style( 'map-styles' );
			wp_enqueue_script( 'mapbox-gl' );
			wp_enqueue_script( 'mapbox-gl-geocoder' );
			wp_dequeue_script('listing-js');
			wp_enqueue_script( 'listing-js' );
			wp_dequeue_script('archive-taxonomy-js');
			wp_enqueue_script( 'archive-taxonomy-js' );

			extract(
				shortcode_atts(
					array(
						'layout_listing'                       => '',
						'search_form'                          => '',
						'archive_listing_search_form_position' => '',
						'map_position'                         => '',
						'sidebar'                              => '',
						'sidebar_position'                     => '',
						'items_per_page'                       => '',
						'column_listing_grid'                  => '',
						'column_listing_list'                  => ''
					),
					$atts
				)
			);

			$list_order = array(
				'default'    => esc_html__( 'Sort by (Defaut)', 'tf-car-listing' ),
				'name_asc'   => esc_html__( 'Sort by name ( A-Z)', 'tf-car-listing' ),
				'name_desc'  => esc_html__( 'Sort by name ( Z-A)', 'tf-car-listing' ),
				'price_asc'  => esc_html__( 'Sort by price: Low to High', 'tf-car-listing' ),
				'price_desc' => esc_html__( 'Sort by price: High to Low', 'tf-car-listing' )
			);

			ob_start();
			tfcl_get_template_with_arguments(
				'listing/listing-get-url.php',
				array(
					'list_order'                           => $list_order,
					'layout_listing'                       => $layout_listing,
					'search_form'                          => $search_form,
					'archive_listing_search_form_position' => $archive_listing_search_form_position,
					'map_position'                         => $map_position,
					'sidebar'                              => $sidebar,
					'sidebar_position'                     => $sidebar_position,
					'items_per_page'                       => $items_per_page,
					'column_listing_grid'                  => $column_listing_grid,
					'column_listing_list'                  => $column_listing_list
				)
			);
			return ob_get_clean();

		}

		public function tfcl_get_link_order_listing( $order ) {
			$link_order = add_query_arg( array( 'orderBy' => $order ) );
			return $link_order;
		}

		public function tfcl_single_listing_gallery() {
			tfcl_get_template_with_arguments( 'single-listing/gallery.php' );
		}

		public function tfcl_single_listing_header() {
			tfcl_get_template_with_arguments( 'single-listing/header.php' );
		}

		public function tfcl_single_listing_overview() {
			tfcl_get_template_with_arguments( 'single-listing/overview.php' );
		}

		public function tfcl_single_listing_description() {
			tfcl_get_template_with_arguments( 'single-listing/description.php' );
		}

		public function tfcl_single_listing_detail() {
			tfcl_get_template_with_arguments( 'single-listing/listing-detail.php' );
		}

		public function tfcl_single_listing_features() {
			tfcl_get_template_with_arguments( 'single-listing/features.php' );
		}

		public function tfcl_single_listing_location() {
			tfcl_get_template_with_arguments( 'single-listing/map-location.php' );
		}

		public function tfcl_single_listing_floors() {
			global $post;
			$listing_meta_data          = get_post_custom( $post->ID );
			$listing_floors             = get_post_meta( $post->ID, 'floors_plan', true );
			$listing_floors_plan_toggle = get_post_meta( $post->ID, 'floors_plan_toggle', true );
			if ( $listing_floors ) {
				tfcl_get_template_with_arguments( 'single-listing/floors.php', array( 'listing_floors' => $listing_floors, 'listing_floors_plan_toggle' => $listing_floors_plan_toggle ) );
			}
		}

		public function tfcl_single_listing_video_virtual() {
			tfcl_get_template_with_arguments( 'single-listing/video-virtual.php' );
		}

		public function tfcl_single_listing_attachments() {
			tfcl_get_template_with_arguments( 'single-listing/attachments.php' );
		}

		public function tfcl_single_listing_loan_calculator() {
			tfcl_get_template_with_arguments( 'single-listing/loan-calculator.php' );
		}

		public function tfcl_single_listing_nearby_places() {
			tfcl_get_template_with_arguments( 'single-listing/nearby-places.php' );
		}

		public function tfcl_author_listing_shortcode() {
			ob_start();
			include TF_THEME_PATH . '/shortcodes/author-contact/author-contact.php';
			return ob_get_clean();
		}

		public function tfcl_loan_calculator_shortcode() {
			ob_start();
			include TF_THEME_PATH . '/shortcodes/loan-calculator/loan-calculator.php';
			return ob_get_clean();
		}

		public function tfcl_nearby_places_shortcode() {
			ob_start();
			include TF_THEME_PATH . '/shortcodes/nearby-places/nearby-places.php';
			return ob_get_clean();
		}

		public function tfcl_related_listing_shortcode( $atts ) {
			extract( shortcode_atts( array( 'current_listing_id' => '' ), $atts ) );
			ob_start();
			tfcl_get_template_with_arguments(
				'/shortcodes/related-listings/related-listings.php',
				array( 'current_listing_id' => $current_listing_id )
			);
			return ob_get_clean();
		}

		public function tfcl_get_model_by_make_ajax() {
			if ( ! isset( $_POST['make'] ) ) {
				return;
			}
			$make = wp_unslash( $_POST['make'] );
			$type = isset( $_POST['type'] ) ? wp_unslash( $_POST['type'] ) : '';
			if ( ! empty( $make ) ) {
				$taxonomy_terms = get_categories(
					array(
						'taxonomy'   => 'model',
						'orderby'    => 'name',
						'order'      => 'ASC',
						'hide_empty' => false,
						'parent'     => 0,
						'meta_query' => array(
							array(
								'key'     => 'model_of_make',
								'value'   => $make,
								'compare' => '=',
							)
						)
					)
				);
			} else {
				$taxonomy_terms = tfcl_get_categories( 'model' );
			}

			$html = '';
			if ( $type == 0 ) {
				$html = '<option value="">' . esc_html__( 'None', 'tf-car-listing' ) . '</option>';
			}
			if ( ! empty( $taxonomy_terms ) ) {
				if ( isset( $_POST['is_slug'] ) && ( $_POST['is_slug'] == '0' ) ) {
					foreach ( $taxonomy_terms as $term ) {
						$html .= '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
					}
				} else {
					foreach ( $taxonomy_terms as $term ) {
						$html .= '<option value="' . esc_attr( $term->slug ) . '">' . esc_html( $term->name ) . '</option>';
					}
				}
			}

			echo wp_kses( $html, array(
				'option' => array(
					'value'    => true,
					'selected' => true
				)
			) );
			wp_die();
		}
	}
}
