<?php

/**
 * @var $list_order
 * @var $layout_listing
 * @var $search_form
 * @var $archive_listing_search_form_position
 * @var $map_position
 * @var $sidebar
 * @var $sidebar_position
 * @var $items_per_page
 * @var $column_listing_grid
 * @var $column_listing_list
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// shortcode options
$layout_archive_listing               = $layout_listing ? $layout_listing : tfcl_get_option( 'layout_archive_listing' );
$map_position                         = $map_position ? $map_position : tfcl_get_option( 'map_position' );
$archive_listing_search_form          = $search_form ? $search_form : tfcl_get_option( 'archive_listing_search_form' );
$archive_listing_sidebar              = $sidebar ? $sidebar : tfcl_get_option( 'archive_listing_sidebar' );
$archive_listing_sidebar_position     = $sidebar_position ? $sidebar_position : tfcl_get_option( 'archive_listing_sidebar_position' );
$column_layout_grid                   = $column_listing_grid ? $column_listing_grid : tfcl_get_option( 'column_layout_grid' );
$column_layout_list                   = $column_listing_list ? $column_listing_list : tfcl_get_option( 'column_layout_list' );
$archive_listing_search_form_position = $archive_listing_search_form_position ? $archive_listing_search_form_position : tfcl_get_option( 'archive_listing_search_form_position' );
$listing_public                       = new Car_Listing;
$order_by                             = isset( $_GET['orderBy'] ) ? sanitize_text_field( wp_unslash( $_GET['orderBy'] ) ) : '';
$enable_condition                     = tfcl_get_option( 'enable_condition_search' );
$value_condition                      = isset( $_GET['condition'] ) ? ( wp_unslash( $_GET['condition'] ) ) : 'all';
$params                               = array(
	'keyword',
	'engine_size',
	'condition',
	'make',
	'model',
	'body',
	'driver_type',
	'cylinders',
	'transmission',
	'fuel_type',
	'car_color',
	'features',
	'max-price',
	'min-price',
	'max-year',
	'min-year',
	'enable_search_features',
	'features',
	'featured',
	'min-mileage',
	'max-mileage',
	'door',
	'min-door',
	'max-door',
	'orderBy'
);

$values = array();

// assign value for param search
foreach ( $params as $param ) {
	$values[ $param ] = isset( $_GET[ $param ] ) ? wp_unslash( $_GET[ $param ] ) : null;
}

if ( ! empty( $values['features'] ) ) {
	$values['features'] = explode( ',', $values['features'] );
}

$item_per_page_archive_listing = tfcl_get_option( 'item_per_page_archive_listing', '-1' );
$options                       = array(
	'ajax_url' => esc_url( TF_AJAX_URL ),
);
$css_class_col                 = 'col-md-4 col-sm-3 col-xs-12';
$metabox_query                 = $taxonomy_query = array();
$parameters = $keyword_array = '';

if ( $layout_archive_listing == 'list' ) {
	switch ( $column_layout_list ) {
		case '1':
			$css_class_col = 'col-md-12';
			break;
		case '2':
			$css_class_col = 'col-md-6';
			break;
		default:
			$css_class_col = 'col-md-12';
			break;
	}
}

if ( $layout_archive_listing == 'grid' ) {
	switch ( $column_layout_grid ) {
		case '2':
			$css_class_col = 'col-md-6';
			break;
		case '3':
			$css_class_col = 'col-lg-4 col-md-6 col-6';
			break;
		case '4':
			$css_class_col = 'col-xl-3 col-md-6 col-6';
			break;
		default:
			break;
	}
}

if ( $map_position == 'half-map-right' || $map_position == 'half-map-left' ) {
	$css_class_col = 'col-lg-6 col-md-6';
}

$args = array(
	'posts_per_page'      => $item_per_page_archive_listing,
	'post_type'           => 'listing',
	'orderby'             => array(
		'menu_order' => 'ASC',
		'date'       => 'DESC',
	),
	'offset'              => ( max( 1, get_query_var( 'paged' ) ) - 1 ) * $item_per_page_archive_listing,
	'ignore_sticky_posts' => 1,
	'post_status'         => 'publish',
);

if ( ! empty( $values['keyword'] ) ) {
	$keyword_field = tfcl_get_option( 'search_criteria_keyword_field', 'criteria_title' );

	$values['keyword'] = sanitize_text_field( $values['keyword'] );
	if ( $keyword_field === 'criteria_address' ) {
		$metabox_query[] = array(
			'key'     => 'listing_address',
			'value'   => $values['keyword'],
			'type'    => 'CHAR',
			'compare' => 'LIKE',
		);
	}

	if ( $keyword_field == 'criteria_title' ) {
		$args['s'] = $values['keyword'];
	}

	$parameters .= sprintf( __( 'Keyword: <strong>%s</strong> | ', 'tf-car-listing' ), $values['keyword'] );
}

if ( ! empty( $values['engine_size'] ) ) {
	$metabox_query[] = array(
		'key'     => 'engine_size',
		'value'   => $values['engine_size'],
		'type'    => 'CHAR',
		'compare' => 'LIKE',
	);

$parameters .= sprintf( __( 'Engine Size: <strong>%s</strong> | ', 'tf-car-listing' ), $values['engine_size'] );
}

// set query taxonomy condition field
if ( ! empty( $values['condition'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'condition', $values['condition'] );
	$parameters .= sprintf( __( 'Condition: <strong>%s</strong> | ', 'tf-car-listing' ), $values['condition'] );
}

// set query taxonomy make field
if ( ! empty( $values['make'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'make', $values['make'] );
	$parameters .= sprintf( __( 'Make: <strong>%s</strong> | ', 'tf-car-listing' ), $values['make'] );
}

// set query taxonomy model field
if ( ! empty( $values['model'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'model', $values['model'] );
	$parameters .= sprintf( __( 'Model: <strong>%s</strong> | ', 'tf-car-listing' ), $values['model'] );
}

// set query taxonomy body field
if ( ! empty( $values['body'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'body', $values['body'] );
	$parameters .= sprintf( __( 'Body: <strong>%s</strong> | ', 'tf-car-listing' ), $values['body'] );
}

// set query taxonomy driver type field
if ( ! empty( $values['driver_type'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'drive-type', $values['driver_type'] );
	$parameters .= sprintf( __( 'Driver Type: <strong>%s</strong> | ', 'tf-car-listing' ), $values['driver_type'] );
}

// set query taxonomy cylinder field
if ( ! empty( $values['cylinders'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'cylinders', $values['cylinders'] );
	$parameters .= sprintf( __( 'Cylinders: <strong>%s</strong> | ', 'tf-car-listing' ), $values['cylinders'] );
}

// set query taxonomy transmission field 
if ( ! empty( $values['transmission'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'transmission', $values['transmission'] );
	$parameters .= sprintf( __( 'Transmission: <strong>%s</strong> | ', 'tf-car-listing' ), $values['transmission'] );
}

// set query taxonomy fuel type field 
if ( ! empty( $values['fuel_type'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'fuel-type', $values['fuel_type'] );
	$parameters .= sprintf( __( 'Fuel Type: <strong>%s</strong> | ', 'tf-car-listing' ), $values['fuel_type'] );
}

// set query color field
if ( ! empty( $values['car_color'] ) ) {
	tfcl_add_taxonomy_query_search( $taxonomy_query, 'car-color', $values['car_color'] );
	$parameters .= sprintf( __( 'Color: <strong>%s</strong> | ', 'tf-car-listing' ), $values['car_color'] );
}

// set query taxonomy features field
if ( ! empty( $values['features'] ) ) {
	$features_taxonomy_queries = array( 'relation' => 'OR' );
	$features_parameters       = array();
	foreach ( $values['features'] as $feature ) {
		tfcl_add_taxonomy_query_search( $features_taxonomy_queries, 'features', $feature, 'IN' );
		$features_parameters[] = $feature;
	}
	if ( is_array( $features_parameters ) && ! empty( $features_parameters ) ) {
		$features_parameters = implode( ',', $features_parameters );
	}
	$taxonomy_query[] = $features_taxonomy_queries;
	$parameters .= sprintf( __( 'Features: <strong>%s</strong> | ', 'tf-car-listing' ), $features_parameters );
}

// set query orderby
if ( in_array( $values['orderBy'], array( 'default', 'name_asc', 'name_desc', 'price_asc', 'price_desc' ) ) ) {
	switch ( $values['orderBy'] ) {
		case 'name_asc':
			$args['orderby'] = 'title';
			$args['order'] = 'asc';
			break;
		case 'name_desc':
			$args['orderby'] = 'title';
			$args['order'] = 'desc';
			break;
		case 'price_asc':
			$args['meta_key'] = 'regular_price';
			$args['orderby'] = 'meta_value_num';
			$args['order'] = 'asc';
			break;
		case 'price_desc':
			$args['meta_key'] = 'regular_price';
			$args['orderby'] = 'meta_value_num';
			$args['order'] = 'desc';
			break;
		default:
			$args['orderby'] = 'date';
			$args['order'] = 'desc';
			break;
	}
}

// parameters for display in save search popup
$param_prices = $param_years = $param_mileage = $param_door = array();

// set query metabox price
if ( isset( $values['max-price'] ) ) {
	tfcl_add_meta_query_price( $metabox_query, $values['max-price'], 'max-price' );
	$param_prices['max-price'] = $values['max-price'];
}
if ( isset( $values['min-price'] ) ) {
	tfcl_add_meta_query_price( $metabox_query, $values['min-price'], 'min-price' );
	$param_prices['min-price'] = $values['min-price'];
}

// set query metabox year
if ( isset( $values['max-year'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['max-year'], 'year', '<=' );
	$param_years['max-year'] = $values['max-year'];
}
if ( isset( $values['min-year'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['min-year'], 'year', '>=' );
	$param_years['min-year'] = $values['min-year'];
}

// set query metabox mileage
if ( isset( $values['max-mileage'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['max-mileage'], 'mileage', '<=' );
	$param_mileage['max-mileage'] = $values['max-mileage'];
}
if ( isset( $values['min-mileage'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['min-mileage'], 'mileage', '>=' );
	$param_mileage['min-mileage'] = $values['min-mileage'];
}

// set query metabox door in slider mode
if ( isset( $values['max-door'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['max-door'], 'door', '<=' );
	$param_door['max-door'] = $values['max-door'];
}
if ( isset( $values['min-door'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['min-door'], 'door', '>=' );
	$param_door['min-door'] = $values['min-door'];
}

// set query metabox door in dropdown mode
if ( isset( $values['door'] ) ) {
	tfcl_add_numeric_meta_query( $metabox_query, $values['door'], 'door', '=' );
	$param_door['door'] = $values['door'];
}

// set query metabox featured
if ( $values['featured'] ) {
	tfcl_add_featured_query( $metabox_query, $values['featured'] );
	$parameters .= sprintf( __( 'Featured: <strong>%s</strong> | ', 'tf-car-listing' ), $values['featured'] );
}

$taxonomy_count = count( $taxonomy_query );
if ( $taxonomy_count > 0 ) {
	$args['tax_query'] = array(
		'relation' => 'AND',
		$taxonomy_query
	);
}

$metabox_count = count( $metabox_query );
if ( $metabox_count > 0 ) {
	$args['meta_query'] = array(
		'relation' => 'AND',
		array(
			'relation' => 'AND',
			$metabox_query
		)
	);
}

$query      = new WP_Query( $args );
$total_post = $query->found_posts;

?>
<?php if ( $map_position != 'hide-map' && $map_position == 'map-header' ) : ?>
<div class="map-container <?php echo esc_attr( $map_position ); ?> <?php echo esc_attr( tfcl_get_option( 'hide_map_mobile' ) == 'y' ? 'hide-map-mobile' : '' ); ?>"
    style="height:400px">
    <div id="map" class="map no-fixed"></div>
</div>
<?php endif; ?>

<?php if ( $map_position == 'hide-map' || $map_position == 'map-header' ) : ?>
<div class="wrap-advanced-search <?php echo esc_attr( $map_position ); ?>">
    <?php ( $archive_listing_search_form == 'enable' && $archive_listing_search_form_position == 'top' ) && do_action( 'listing_advanced_search_form' ); ?>
</div>
<?php endif; ?>

<?php if ( $archive_listing_search_form == 'disable' ) : ?>
<div class="tfcl_sidebar popup-search-map">
    <div class="overlay"></div>
    <div class="tfcl-sidebar">
        <?php tfcl_get_template_with_arguments( "shortcodes/search-by-filter/search-filter.php",
					array(
						'search_form_position' => 'side',
					) );
				?>
    </div>
</div>
<?php endif; ?>

<div class="tfcl_message"></div>
<div
    class="cards-container row <?php echo esc_attr( $map_position ); ?> <?php echo esc_attr( $archive_listing_search_form == 'enable' ? 'has-searchform' : 'no-searchform' ); ?> <?php echo esc_attr( $archive_listing_sidebar == 'enable' ? 'has-sidebar' : 'no-sidebar' ); ?> <?php echo esc_attr( $archive_listing_sidebar_position ); ?>">
    <?php if ( $map_position == 'hide-map' || $map_position == 'map-header' ) : ?>
    <div
        class="<?php echo esc_attr( $archive_listing_sidebar == 'enable' ? 'col-xl-9 col-md-12' : 'col-md-12' ) ?> listing-list-wrap">
        <form method="get" action="<?php echo $query->have_posts() ? esc_url( get_page_link() ) : ''; ?>" class="tfcl-my-listing-search">
            <input type="hidden" name="layout_archive_listing" id="layout_archive_listing"
                value="<?php echo esc_attr( $layout_archive_listing ); ?>">
            <input type="hidden" name="column_layout" id="column_layout"
                value="<?php echo esc_attr( $layout_archive_listing == 'grid' ? $column_layout_grid : $column_layout_list ); ?>">
            <?php if ( $archive_listing_search_form_position == 'top' ) : ?>
            <div class="row">
                <div class="col-md-4">
                    <h2><?php esc_html_e( 'Listings', 'tf-car-listing' ); ?></h2>
					<?php if ( $total_post !== 0) :?>
                    	<p class="count-results">
                    	    <?php echo sprintf( esc_html__('There are currently', 'tf-car-listing').' <span class="count-result count-total">%d</span> <span class="text-result">%s</span>', $total_post, tfcl_get_number_text( $total_post, esc_html__( 'results', 'tf-car-listing' ), esc_html__( 'result', 'tf-car-listing' ) ) ); ?>
                    	</p>
					<?php endif; ?>
                </div>

				<?php if ( tfcl_get_option( 'enable_switch_layout_column_button') == 'y') :?>
				<ul class="tf-control-layout col-md-4">
					<li class="btn-layout layout-2 <?php echo esc_attr($column_layout_grid == 2 ? 'active' : ''); ?>" data-col="col-md-6 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
					<li class="btn-layout layout-3 <?php echo esc_attr($column_layout_grid == 3 ? 'active' : ''); ?>" data-col="col-md-4 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
					<li class="btn-layout layout-4 <?php echo esc_attr($column_layout_grid == 4 ? 'active' : ''); ?>" data-col="col-xl-3 col-md-4 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
					<li class="btn-layout layout-5 " data-col="col-xl-2 col-md-4 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
				</ul>
				<?php endif; ?>

                <div class="toolbar-search-list <?php echo esc_attr(tfcl_get_option( 'enable_switch_layout_column_button') == 'y' ? 'col-md-4' : 'col-md-8');  ?>">

                    <?php if ( tfcl_get_option( 'enable_switch_layout_button') == 'y') :?>
                    <div class="inner">
					<?php if ( $layout_archive_listing == 'grid' ) : ?>
                        <div class="form-group">
                            <a class="btn btn-display-listing-grid"><i class="icon-autodeal-grid"></i></a>
                        </div>
					<?php endif; ?>
                        <div class="form-group">
                            <a class="btn btn-display-listing-list"><i class="icon-autodeal-icon-157"></i></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( tfcl_get_option( 'enable_sort_by_option') == 'y') :?>
                    <div class="form-group">
                        <select name="sortby" id="listing_order_by" class="form-control"
                            title="<?php esc_attr_e( 'Sort By', 'tf-car-listing' ) ?>">
                            <?php foreach ( $list_order as $key => $order ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $order_by ); ?>>
                                <?php printf( __( $order, 'tf-car-listing' ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
					<?php if ( $archive_listing_search_form == 'disable' ) : ?>
						<div class="filter-popup ">
							<?php 
								esc_html_e( 'Filter', 'tf-car-listing' );
							 	echo themesflat_svg('filter');
							 ?>
						</div>
                    <?php endif; ?>
					<div class="wrap-reset-filter">
                        <span class="btn-clear-filter"><i class="icon-autodeal-close2"></i>
                            <span><?php esc_html_e( 'Clear Filters', 'tf-car-listing' ) ?></span></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ( $archive_listing_search_form_position == 'side' ) : ?>
				<div class="col-md-12 mb-4">
                    <h2 class="mb-1"><?php esc_html_e( 'Listings', 'tf-car-listing' ); ?></h2>
					<?php if ( $total_post !== 0) :?>
                    	<p class="count-results">
                    	    <?php echo sprintf( esc_html__('There are currently', 'tf-car-listing').' <span class="count-result count-total">%d</span> <span class="text-result">%s</span>', $total_post, tfcl_get_number_text( $total_post, esc_html__( 'results', 'tf-car-listing' ), esc_html__( 'result', 'tf-car-listing' ) ) ); ?>
                    	</p>
					<?php endif; ?>
                </div>
            <div class="row group-filter-listing">
                <div class="col-md-5">
                    <div class="condition-tab-wrap tf-search-condition-tab">
                        <?php
									$condition       = tfcl_get_taxonomies( 'condition' );
									$value_condition = ! empty( $value_condition ) ? $value_condition : 'all';
									if ( is_tax() ) {
										$current_term  = get_queried_object();
										$taxonomy_name = get_query_var( 'taxonomy' );
										if ( $taxonomy_name == 'condition' ) {
											$value_condition = $current_term->slug;
										}
									}
									if ( ! empty( $condition ) ) :
										?>
                        <input type="hidden" class="search-field" name="condition" id="condition"
                            value="<?php echo esc_attr( $value_condition ); ?>" data-default-value="">
                        <a data-value="all"
                            class="btn-condition-filter <?php echo esc_attr( $value_condition == 'all' ? 'active' : '' ); ?>">
                            <?php esc_html_e( 'All', 'tf-car-listing' ); ?>
                        </a>
                        <?php foreach ( $condition as $key => $cond ) : ?>
                        <a data-value="<?php echo esc_attr( $key ) ?>"
                            class="btn-condition-filter <?php echo esc_attr( $value_condition == $key ? 'active' : '' ); ?>">
                            <?php echo esc_html( $cond ); ?>
                        </a>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
				<?php if ( tfcl_get_option( 'enable_switch_layout_column_button') == 'y') :?>
				<ul class="tf-control-layout col-md-3">
					<li class="btn-layout layout-2 <?php echo esc_attr($column_layout_grid == 2 ? 'active' : ''); ?>" data-col="col-md-6 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
					<li class="btn-layout layout-3 <?php echo esc_attr($column_layout_grid == 3 ? 'active' : ''); ?>" data-col="col-md-4 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
				</ul>
				<?php endif; ?>
                <div class="toolbar-search-list <?php echo esc_attr(tfcl_get_option( 'enable_switch_layout_column_button') == 'y' ? 'col-md-4' : 'col-md-7');  ?>">

                    <?php if ( tfcl_get_option( 'enable_switch_layout_button') == 'y') :?>
						<?php if ( $layout_archive_listing == 'grid' ) : ?>
                        <div class="form-group">
                            <a class="btn btn-display-listing-grid"><i class="icon-autodeal-grid"></i></a>
                        </div>
					<?php endif; ?>
                    <div class="form-group">
                        <a class="btn btn-display-listing-list"><i class="icon-autodeal-icon-157"></i></a>
                    </div>
                    <?php endif; ?>
                    <?php if ( tfcl_get_option( 'enable_sort_by_option') == 'y') :?>
                    <div class="form-group">
                        <select name="sortby" id="listing_order_by" class="form-control"
                            title="<?php esc_attr_e( 'Sort By', 'tf-car-listing' ) ?>">
                            <?php foreach ( $list_order as $key => $order ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $order_by ); ?>>
                                <?php printf( __( $order, 'tf-car-listing' ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
					<div class="filter-popup enable-mobile">
						<?php 
							esc_html_e( 'Filter', 'tf-car-listing' );
						 	echo themesflat_svg('filter');
						 ?>
					</div>
					<div class="wrap-reset-filter">
                        <span class="btn-clear-filter"><i class="icon-autodeal-close2"></i>
                            <span><?php esc_html_e( 'Clear Filters', 'tf-car-listing' ) ?></span></span>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </form>
        <div class="group-card-item-listing row">
            <?php if ( $query->have_posts() ) :
					while ( $query->have_posts() ) :

						$query->the_post();
						$listing_id = get_the_ID();
						$attach_id  = get_post_thumbnail_id();
						tfcl_get_template_with_arguments(
							'listing/card-item-listing.php',
							array(
								'listing_id'             => $listing_id,
								'attach_id'              => $attach_id,
								'css_class_col'          => $css_class_col,
								'layout_archive_listing' => $layout_archive_listing
							)
						);
						?>
            <?php endwhile;
						wp_reset_postdata();
				else : ?>
            <?php tfcl_get_template_with_arguments( 'advanced-search/reset-search.php' ); ?>
            <?php endif; ?>
        </div>
        <div class="overlay-filter-tab" style="display: none;">
            <div class="filter-loader"></div>
        </div>
        <div class="pagination-wrap"><?php tfcl_pagination_ajax( $query ); ?></div>
    </div>
    <?php if ( $archive_listing_sidebar == 'enable' ) : ?>
    <div class="col-xl-3 col-md-12 sidebar-archive">
        <div class="tfcl_sidebar">
            <?php if ( is_active_sidebar( 'archive-listing-sidebar' ) ) { ?>
            <?php dynamic_sidebar( 'archive-listing-sidebar' ); ?>
            <?php } ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ( $map_position == 'half-map-right' || $map_position == 'half-map-left' ) : ?>
    <div class="tfcl_sidebar popup-search-map">
        <div class="overlay"></div>
        <?php if ( is_active_sidebar( 'archive-listing-sidebar' ) ) { ?>
        <?php dynamic_sidebar( 'archive-listing-sidebar' ); ?>
        <?php } ?>
    </div>
    <div class="col-lg-6 col-md-12 listing-list-wrap">
        <form method="get" action="<?php echo $query->have_posts() ? esc_url( get_page_link() ) : ''; ?>" class="tfcl-my-listing-search">
            <input type="hidden" name="layout_archive_listing" id="layout_archive_listing"
                value="<?php echo esc_attr( $layout_archive_listing ); ?>">
            <input type="hidden" name="column_layout" id="column_layout"
                value="<?php echo esc_attr( $layout_archive_listing == 'grid' ? $column_layout_grid : $column_layout_list ); ?>">
            <div class="row">
                <div class="col-md-4">
                    <h2 class="mb-1"><?php esc_html_e( 'Listing', 'tf-car-listing' ); ?></h2>
					<?php if ( $total_post !== 0) :?>
                    	<p class="count-results">
                    	    <?php echo sprintf( esc_html__('There are currently', 'tf-car-listing').' <span class="count-result count-total">%d</span> <span class="text-result">%s</span>', $total_post, tfcl_get_number_text( $total_post, esc_html__( 'results', 'tf-car-listing' ), esc_html__( 'result', 'tf-car-listing' ) ) ); ?>
                    	</p>
					<?php endif; ?>
                </div>
                <div class="col-md-8 toolbar-search-list">

                    <?php if ( tfcl_get_option( 'enable_switch_layout_button') == 'y') :?>
						<?php if ( $layout_archive_listing == 'grid' ) : ?>
                        <div class="form-group">
                            <a class="btn btn-display-listing-grid"><i class="icon-autodeal-grid"></i></a>
                        </div>
					<?php endif; ?>
                    <div class="form-group">
                        <a class="btn btn-display-listing-list"><i class="icon-autodeal-icon-157"></i></a>
                    </div>
                    <?php endif; ?>
                    <?php if ( tfcl_get_option( 'enable_sort_by_option') == 'y') :?>
                    <div class="form-group">
                        <select name="sortby" id="listing_order_by" class="form-control"
                            title="<?php esc_attr_e( 'Sort By', 'tf-car-listing' ) ?>">
                            <?php foreach ( $list_order as $key => $order ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $order_by ); ?>>
                                <?php printf( __( $order, 'tf-car-listing' ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="filter-popup">
                        <?php 
							esc_html_e( 'Filter', 'tf-car-listing' );
						 	echo themesflat_svg('filter');
						 ?>
                    </div>
				<?php if ( tfcl_get_option( 'enable_switch_layout_column_button') == 'y') :?>
					<ul class="tf-control-layout">
						<li class="btn-layout layout-2 <?php echo esc_attr($column_layout_grid == 2 ? 'active' : ''); ?>" data-col="col-md-6 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
						<li class="btn-layout layout-3 <?php echo esc_attr($column_layout_grid == 3 ? 'active' : ''); ?>" data-col="col-md-4 col-6"><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?><?php echo themesflat_svg('card'); ?></li>
					</ul>
                    <?php endif; ?>
					<div class="wrap-reset-filter">
                        <span class="btn-clear-filter"><i class="icon-autodeal-close2"></i>
                            <span><?php esc_html_e( 'Clear Filters', 'tf-car-listing' ) ?></span></span>
                    </div>
                </div>
            </div>
        </form>
        <div class="group-card-item-listing row">
            <?php if ( $query->have_posts() ) :
					while ( $query->have_posts() ) :
						$query->the_post();
						$listing_id = get_the_ID();
						$attach_id  = get_post_thumbnail_id();
						tfcl_get_template_with_arguments(
							'listing/card-item-listing.php',
							array(
								'listing_id'             => $listing_id,
								'attach_id'              => $attach_id,
								'css_class_col'          => $css_class_col,
								'layout_archive_listing' => $layout_archive_listing
							)
						);
						?>
            <?php endwhile;
						wp_reset_postdata();
				else : ?>
            <?php tfcl_get_template_with_arguments( 'advanced-search/reset-search.php' ); ?>
            <?php endif; ?>
        </div>
        <div class="overlay-filter-tab" style="display: none;">
            <div class="filter-loader"></div>
        </div>
        <div class="pagination-wrap"><?php tfcl_pagination_ajax( $query ); ?></div>
    </div>
    <div class="col-lg-6 col-md-12">
        <div
            class="map-container <?php echo esc_attr( tfcl_get_option( 'hide_map_mobile' ) == 'y' ? 'hide-map-mobile' : '' ); ?>">
            <div id="map" class="map"></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<div class="fixed-map-stopper"></div>
