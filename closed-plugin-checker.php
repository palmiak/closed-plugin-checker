<?php
/**
 * Plugin Name: Closed Plugin Checker
 */

function check_if_wp_repo( $update_url ) {
    $parsed_repo_url = wp_parse_url( $update_url );
	$repo_host = isset( $parsed_repo_url['host'] ) ? $parsed_repo_url['host'] : $update_url;
    return ( $repo_host === 'w.org' || empty( $repo_host ) );
}   

function closed_plugins_checker() {
    $all_plugins = get_plugins();

    $base_url = 'https://api.wordpress.org/plugins/info/1.0/';

    $closed_plugins = array();
    $status = true;

    $requests = array();
    $cached = array();

    foreach( $all_plugins as $plugin_file => $plugin_data ) {
        $slug = dirname( $plugin_file );
        if ( $slug != '.' ) {
            
            if ( ! check_if_wp_repo( $plugin_data['UpdateURI'] ) ) {
                continue;
            }
            
            if ( false === get_transient( 'status_check_'.$slug ) ) {
                $url = $base_url . $slug . '.json';
                $requests[ $slug ] = array( 'url' => $url );
                $i++;
            } else {
                $cached[ $slug ] = get_transient( 'status_check_'.$slug );
            }
        }
    }

    $return_requests = Requests::request_multiple( $requests );

    foreach( $return_requests as $slug => $request ) {
        if ( is_object( $request ) && ! is_wp_error( $request ) ) {
            $body = json_decode( $request->body );
            
            if( isset( $body->error ) && 'closed' === $body->error ) {
                $closed_plugins[] = $body;
                $status = false;
            }
            set_transient( 'status_check_'.$slug, $request, 24 * HOUR_IN_SECONDS );
        }
    }

    foreach( $cached as $slug => $request ) {
        if ( is_object( $request ) && ! is_wp_error( $request ) ) {
            $body = json_decode( $request->body );
            
            if( isset( $body->error ) && 'closed' === $body->error ) {
                $closed_plugins[] = $body;
                $status = false;
            }
        }
    }

    $return_val = (object)[];
    $return_val->status = $status;
    $return_val->closed_plugins = get_plugin_array( $closed_plugins );
    
    return $return_val;
}

//add_filter( 'all_plugins', 'closed_plugins_checker' );

function add_closed_plugins_test( $tests ) {
    $tests['async']['closed_plugins'] = array(
        'label' => __( 'Closed plugins' ),
        'test'  => 'closed_plugins_test',
    );

    return $tests;
}

function closed_plugins_test() {
    $closed_plugins_test = closed_plugins_checker();

    $result = array(
        'label'       => __( 'None of the plugins is closed' ),
        'status'      => 'good',
        'badge'       => array(
            'label' => __( 'Security' ),
            'color' => 'green',
        ),
        'description' => sprintf(
            '<p>%s</p><p>%s</p>',
            __( 'None of the installed plugins are closed in the repository. That\'s probably good news.' ),
            __( 'The fact that plugin is avialable in the official repository doesn\'t mean it\'s safe. You should always check the plugin\'s reputation, reviews and security databases.' )
        ),
        'actions'     => '',
        'test'        => 'closed_plugins_test',
    );

    if ( ! $closed_plugins_test->status ) {
        
        $result['label'] = __( 'Some of installed plugins are closed' );
        $result['status'] = 'critical';
        $result['badge']['color'] = 'red';
        $result['description'] = sprintf(
            '<p>%s</p>',
            __( 'Some of the installed plugins are closed in the repository. This might be a security risk. Try to find more information why the plugin was closed.' )
        );
        $result['actions'] = sprintf(
            '<p>%s<br/><ul>%s</ul></p>',
            __( 'You should consider replacing the closed plugins with alternatives. Here is the list of closed plugins:' ),
            $closed_plugins_test->closed_plugins
        );
    }

    wp_send_json_success( $result );
}

add_filter( 'site_status_tests', 'add_closed_plugins_test' );
add_action( 'wp_ajax_health-check-closed-plugins_test', 'closed_plugins_test' );

function get_plugin_array( $array ){
    $tmp_array = array();
    foreach ( $array as $plugin ) {
        $tmp_array[] = '<li><strong>' . $plugin->name . '</strong> - ' . $plugin->description . '</li>';
    }

    return implode( '', $tmp_array );
}
