<?php

function php_version_notice(): void
{
    // PHP info
    $php_version = phpversion();
    $upload_max_filesize = ini_get('upload_max_filesize');
    $post_max_size = ini_get('post_max_size');
    $memory_limit = ini_get('memory_limit');
    $max_execution_time = ini_get('max_execution_time');
    $max_input_time = ini_get('max_input_time');

    // WordPress info
    $wp_version = get_bloginfo('version');
    $wp_multisite = is_multisite() ? 'Yes' : 'No';
    $wp_debug = defined('WP_DEBUG') && WP_DEBUG ? 'Yes' : 'No';
    $wp_memory_limit = WP_MEMORY_LIMIT;
    $wp_max_upload_size = size_format(wp_max_upload_size());

    // System info
    $os = PHP_OS;
    $os_version = php_uname();
    $server_software = $_SERVER['SERVER_SOFTWARE'];
    $server_protocol = $_SERVER['SERVER_PROTOCOL'];
    $server_name = $_SERVER['SERVER_NAME'];
    $server_addr = $_SERVER['SERVER_ADDR'];
    $server_port = $_SERVER['SERVER_PORT'];
    $server_admin = $_SERVER['SERVER_ADMIN'];

    $phpInfo = '<div class="wrap">
        <h1 class="wp-heading-inline">System Info</h1>
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
            
            <br>
            <p>System</p>
            <p><strong>OS:</strong> ' . esc_html($os) . '</p>
            <p><strong>OS Version:</strong> ' . esc_html($os_version) . '</p>
            <p><strong>Server Software:</strong> ' . esc_html($server_software) . '</p>
            <p><strong>Server Protocol:</strong> ' . esc_html($server_protocol) . '</p>
            <p><strong>Server Domain:</strong> ' . esc_html($server_name) . '</p>
            <p><strong>Server Addr:</strong> ' . esc_html($server_addr) . '</p>
            <p><strong>Server Port:</strong> ' . esc_html($server_port) . '</p>
            <p><strong>Server Admin:</strong> ' . esc_html($server_admin) . '</p>
        </div>
    </div>';
    echo $phpInfo;
}