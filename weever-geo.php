<?php
/*
Plugin Name: Weever Apps - Advanced Geolocation
Plugin URI: http://weeverapps.com/
Description: Plugin to work with Weever Apps to improve performance of geolocation searches with large
Version: 1.0
Author: Brian Hogg
Author URI: http://brianhogg.com/
License: GPL3
*/

register_activation_hook( __FILE__, 'weevergeo_activate' );

function weevergeo_activate() {
    global $wpdb;

    $wpdb->query("CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}weever_geo` (
          `post_id` int(10) unsigned NOT NULL,
          `lat` float NOT NULL,
          `lon` float NOT NULL,
          PRIMARY KEY  (`post_id`),
          KEY `lat` (`lat`,`lon`)
          ) ENGINE=InnoDB DEFAULT CHARSET=latin1;");

    // TODO: Run this each time activated to re-save?
    /*
    if ( isset( $_GET['key'] ) and '09asd890fsalkjdkl23908fslkndvnka0923jldsg093q4uvjlkds' == $_GET['key'] ) {
        $result = $wpdb->get_results("SELECT ID FROM wp_posts WHERE post_status = 'publish' AND post_type = 'post'");

        foreach ( $result as $post ) {
            weever_save_geolocation( $post->ID );
        }
    }
    */
}

function weevergeo_save_geolocation($post_id, $post = false) {
    global $wpdb;

    if ( $post !== false and ( $post->post_type != 'post' or wp_is_post_revision( $post_id ) ) ) {
        return;
    }

    // TODO: Add a filter so field names could be changed (ie. another plugin)
    $lat = floatval( get_post_meta( $post_id, 'geo_latitude', true ) );
    $lon = floatval( get_post_meta( $post_id, 'geo_longitude', true ) );

    // TODO: Add a filter so other vars to confirm can be checked
    if ( $lat and $lon and get_post_meta( $post_id, 'geo_public', true ) and get_post_meta( $post_id, 'geo_enabled', true ) ) {
        $result = $wpdb->replace(
            $wpdb->prefix . 'weever_geo',
            array(
                'post_id' => $post_id,
                'lat' => $lat,
                'lon' => $lon,
            ),
            array('%s', '%F', '%F')
        );
    } else {
        weevergeo_delete_geolocation( $post_id );
    }
}

function weevergeo_delete_geolocation($post_id) {
    global $wpdb;

    $result = $wpdb->query( "DELETE FROM " . $wpdb->prefix . 'weever_geo WHERE post_id = ' . intval($post_id) );
}

add_action('delete_post', 'weevergeo_delete_geolocation');
add_action('save_post', 'weevergeo_save_geolocation', 10, 2);

function weevergeo_geolocation_filter( $clauses, $query ) {
    global $wpdb;

    if ( $query->is_main_query() and isset( $query->query_vars['latitude'] ) and isset( $query->query_vars['longitude'] ) and isset( $query->query_vars['feed'] ) and ( $query->query_vars['feed'] == 'r3s' ) ) {
        if ( ! isset( $query->query_vars['distance'] ) )
            // Temp distance for a bounding box for munchspot, can filter this out...
            $distance = 50;
        else
            $distance = floatval($query->query_vars['distance']);

        // Apply any filters on it
        $distance = apply_filters( 'weever_geolocation_distance', $distance, $query );

        // Get the bounding lat/lon from a lat/lon distance - theory at:
        // http://janmatuschek.de/LatitudeLongitudeBoundingCoordinates
        $minimum_lat = deg2rad(-90);  // -PI/2
        $maximum_lat = deg2rad(90);   //  PI/2
        $minimum_lon = deg2rad(-180); // -PI
        $maximum_lon = deg2rad(180);  //  PI

        // angular distance in radians on a great circle
        $rad_distance = floatval($distance) / 6371.01;

        $rad_lat = deg2rad($query->query_vars['latitude']);
        $rad_lon = deg2rad($query->query_vars['longitude']);

        $min_lat = $rad_lat - $rad_distance;
        $max_lat = $rad_lat + $rad_distance;

        if ($min_lat > $minimum_lat && $max_lat < $maximum_lat) {
            $delta_lon = asin(sin($rad_distance) / cos($rad_lat));
            $min_lon = $rad_lon - $delta_lon;
            if ($min_lon < $minimum_lon) $min_lon += 2.0 * pi();
            $max_lon = $rad_lon + $delta_lon;
            if ($max_lon > $maximum_lon) $max_lon -= 2.0 * pi();
        } else {
            // a pole is within the distance
            $min_lat = max($min_lat, $minimum_lat);
            $max_lat = min($max_lat, $maximum_lat);
            $min_lon = $minimum_lon;
            $max_lon = $maximum_lon;
        }

        $min_lat = rad2deg($min_lat);
        $min_lon = rad2deg($min_lon);
        $max_lat = rad2deg($max_lat);
        $max_lon = rad2deg($max_lon);

        // Add the geo table
        $clauses['join'] .= ' INNER JOIN ' . $wpdb->prefix . 'weever_geo ON ' . $wpdb->prefix . 'posts.ID = ' . $wpdb->prefix . 'weever_geo.post_id ';
        $clauses['where'] .= " AND `lat` BETWEEN $min_lat AND $max_lat AND `lon` BETWEEN $min_lon AND $max_lon ";

        if ( $distance )
            $clauses['groupby'] .= " HAVING weever_geodistance <= $distance ";

        $lat = floatval($query->query_vars['latitude']);
        $lon = floatval($query->query_vars['longitude']);
        $geotable = $wpdb->prefix . 'weever_geo';

        // Add distance in miles
        $clauses['fields'] .= ", ((ACOS(SIN($lat * PI() / 180) * SIN($geotable.lat * PI() / 180) + COS($lat * PI() / 180) * COS($geotable.lat * PI() / 180) * COS(($lon - $geotable.lon) * PI() / 180)) * 180 / PI()) * 60 * 1.1515) AS `weever_geodistance`";

        // Order by the distance
        $clauses['orderby'] = "weever_geodistance ASC";

        // And off we go!
        return $clauses;
    } else {
        // Just return
        return $clauses;
    }
}

add_action( 'posts_clauses', 'weevergeo_geolocation_filter', 10, 2 );
