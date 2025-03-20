<?php

function php_version_notice(): void
{
    $php_version = phpversion();
    $upload_max_filesize = ini_get('upload_max_filesize');
    $post_max_size = ini_get('post_max_size');
    $memory_limit = ini_get('memory_limit');
    $max_execution_time = ini_get('max_execution_time');
    $max_input_time = ini_get('max_input_time');

    $wp_version = get_bloginfo('version');
    $wp_multisite = is_multisite() ? 'Yes' : 'No';
    $wp_debug = defined('WP_DEBUG') && WP_DEBUG ? 'Yes' : 'No';
    $wp_memory_limit = WP_MEMORY_LIMIT;
    $wp_max_upload_size = size_format(wp_max_upload_size());


    $phpInfo = '<div class="wrap">
        <h1 class="wp-heading-inline">PHP Info</h1>
        <div class="php-info">
            <p>PHP</p>
            <p><strong>PHP Version:</strong> ' . esc_html($php_version) . '</p>
            <p><strong>Upload Max Filesize:</strong> ' . esc_html($upload_max_filesize) . '</p>
            <p><strong>Post Max Size:</strong> ' . esc_html($post_max_size) . '</p>
            <p><strong>Memory Limit:</strong> ' . esc_html($memory_limit) . '</p>
            <p><strong>Max Execution Time:</strong> ' . esc_html($max_execution_time) . ' seconds</p>
            <p><strong>Max Input Time:</strong> ' . esc_html($max_input_time) . ' seconds</p>
            
            <br>
            
            <p>WordPress</p>
            <p><strong>WP Version:</strong> ' . esc_html($wp_version) . '</p>
            <p><strong>WP Multisite:</strong> ' . esc_html($wp_multisite) . '</p>
            <p><strong>WP Debug:</strong> ' . esc_html($wp_debug) . '</p>
            <p><strong>WP Memory Limit:</strong> ' . esc_html($wp_memory_limit) . '</p>
            <p><strong>WP Max Upload Size:</strong> ' . esc_html($wp_max_upload_size) . '</p>
        </div>
    </div>';
    echo $phpInfo;
}