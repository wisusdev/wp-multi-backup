<?php

// Función para actualizar dominios de multisitio
function update_multisite_domains(): void
{
    global $wpdb;

    $current_domain = parse_url(home_url(), PHP_URL_HOST);
    $scheme = is_ssl() ? 'https' : 'http';
    $full_url = $scheme . '://' . $current_domain;

    echo '<div class="wrap"><h1 class="wp-heading-inline">Actualizar dominios</h1></div>';

    $blog_id = isset($_POST['blog_id']) ? intval($_POST['blog_id']) : 0;
    $new_domain = isset($_POST['new_domain']) ? esc_url($_POST['new_domain']) : '';

    if (isset($_POST['update_blog_domains_' . $blog_id])) {
        // Update option value where option name is 'home' and 'siteurl'
        $wpdb->update('wp_' . $blog_id . '_options', array('option_value' => $new_domain), array('option_name' => 'home'));
        $wpdb->update('wp_' . $blog_id . '_options', array('option_value' => $new_domain), array('option_name' => 'siteurl'));
    }

    // Get wp_blog table
    $blogs = $wpdb->get_results("SELECT blog_id FROM wp_blogs", ARRAY_A);

    echo '<table id="table-update-domain" class="widefat">
        <thead>
            <tr>
                <th>ID</th>
                <th>Actions</th>
                <th>URL</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($blogs as $blog) {
        $blog_id = $blog['blog_id'];
        
        if($blog_id == 1) {
            continue;
        }

        $domain = $wpdb->get_results("SELECT * FROM wp_" . $blog_id . "_options WHERE option_name = 'siteurl'");

        foreach ($domain as $d) {
            $option_value = $d->option_value;

            // Extraer el path de la URL
            $path = parse_url($option_value, PHP_URL_PATH);
            
            // Si existe el path, concatenar el nuevo dominio
            if ($path) {
                $new_url = $full_url . $path;
            } else {
                $new_url = $full_url;
            }

            echo '<tr>
                <td>' . esc_html($blog_id) . '</td>
                <td>
                    <form method="post">
                        <input class="input-update-domain" type="text" name="new_domain" value="' . esc_attr($new_url) . '">
                        <input type="hidden" name="blog_id" value="' . esc_attr($blog_id) . '">
                        <input type="submit" name="update_blog_domains_' . $blog_id . '" class="button button-primary" value="Actualizar">
                    </form>
                </td>
                <td><a href="' . esc_url($new_url) . '" target="_blank">' . esc_html($option_value) . '</a></td>
            </tr>';
        }
    }

    echo '</tbody></table>';
}