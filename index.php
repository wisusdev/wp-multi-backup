<?php
/*
Plugin Name: WP Multi Backup
Plugin URI: wisus.dev
Description: Plugin para exportar, listar, descargar y eliminar respaldos de la base de datos en WordPress Multisite.
Version: 0.0.14
Author: Jesús Avelar
Author URI: linkedin.com/in/wisusdev
License: GPL2
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit; // Seguridad

// Carpeta donde se guardarán los respaldos
define('BACKUP_DIR', WP_CONTENT_DIR . '/wp-multi-backups/');

// Crear la carpeta si no existe
if (!file_exists(BACKUP_DIR)) {
    try {
        mkdir(BACKUP_DIR, 0755, true);
    } catch (Exception $e) {
        log_error($e->getMessage());
    }
}

// Crear el archivo .htaccess si no existe
$htaccess_path = BACKUP_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    try {
        $htaccess_content = <<<HTACCESS
        # Evitar listado de directorios
        Options -Indexes

        # Permitir descarga de archivos
        <FilesMatch "\\.(zip)$">
            Order allow,deny
            Allow from all
        </FilesMatch>
        HTACCESS;
        file_put_contents($htaccess_path, $htaccess_content);
    } catch (Exception $e) {
        log_error($e->getMessage());
    }
}

// Función para registrar errores en un archivo de log
function log_error($message) {
    $log_file = BACKUP_DIR . 'error_log.txt';
    $current_time = date("Y-m-d H:i:s");
    $log_message = "[$current_time] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
    echo '<div class="error"><p>' . esc_html($message) . '</p></div>';
}

// Función para crear un respaldo de la base de datos con barra de progreso
function backup_multisite_db() {
    global $wpdb;

    try {
        $backup_file = BACKUP_DIR . "db-backup-" . date("Y-m-d_H-i-s") . ".sql";
        $zip_file = BACKUP_DIR . "db-backup-" . date("Y-m-d_H-i-s") . ".zip";

        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $sql_dump = "";

        foreach ($tables as $table) {
            $table_name = $table[0];
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
            $sql_dump .= "\n\n--\n-- Table structure for table `$table_name`\n--\n\n";
            $sql_dump .= "DROP TABLE IF EXISTS `$table_name`;\n";
            $sql_dump .= "/*!40101 SET @saved_cs_client     = @@character_set_client */;\n";
            $sql_dump .= "/*!50503 SET character_set_client = utf8mb4 */;\n";
            $sql_dump .= $create_table[1] . ";\n";
            $sql_dump .= "/*!40101 SET character_set_client = @saved_cs_client */;\n";

            $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
            if (!empty($rows)) {
                $sql_dump .= "\n--\n-- Dumping data for table `$table_name`\n--\n\n";
                $sql_dump .= "LOCK TABLES `$table_name` WRITE;\n";
                $sql_dump .= "/*!40000 ALTER TABLE `$table_name` DISABLE KEYS */;\n";
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($wpdb) {
                        return $wpdb->prepare('%s', $value);
                    }, array_values($row));
                    $sql_dump .= "INSERT INTO `$table_name` VALUES (" . implode(", ", $values) . ");\n";
                }
                $sql_dump .= "/*!40000 ALTER TABLE `$table_name` ENABLE KEYS */;\n";
                $sql_dump .= "UNLOCK TABLES;\n";
            }

            flush();
        }

        file_put_contents($backup_file, $sql_dump);

        if (!file_exists($backup_file)) {
            throw new Exception('El archivo de respaldo no se creó correctamente.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($backup_file, basename($backup_file));
            $zip->close();
            unlink($backup_file); // Eliminar el archivo .sql después de comprimirlo
        } else {
            throw new Exception('No se pudo crear el archivo zip.');
        }

        return true;
    } catch (Exception $e) {
        log_error($e->getMessage());
        return false;
    }
}

// Función para crear un respaldo de un directorio con barra de progreso
function backup_directory($directory, $backup_name) {
    $zip = new ZipArchive();
    $backup_file = BACKUP_DIR . $backup_name . '-' . date("Y-m-d_H-i-s") . '.zip';

    if ($zip->open($backup_file, ZipArchive::CREATE) === TRUE) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory), RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($directory) + 1);
                $zip->addFile($file_path, $relative_path);
                flush();
            }
        }
        $zip->close();
        return file_exists($backup_file);
    }
    
    return false;
}

// Función para listar los respaldos por tipo
function list_backups_by_type($type) {
    $files = glob(BACKUP_DIR . "$type-backup-*.zip");
    return array_map('basename', $files);
}

// Función para eliminar un respaldo
function delete_backup($filename) {
    $file_path = BACKUP_DIR . $filename;
    if (file_exists($file_path)) {
        unlink($file_path);
        return true;
    }
    return false;
}

// Función para restaurar un respaldo de la base de datos
function restore_db_backup($filename) {
    global $wpdb;
    $file_path = BACKUP_DIR . $filename;
    if (file_exists($file_path)) {
        $zip = new ZipArchive;
        if ($zip->open($file_path) === TRUE) {
            $sql_file = $zip->getNameIndex(0);
            $zip->extractTo(BACKUP_DIR, $sql_file);
            $zip->close();

            $sql_path = BACKUP_DIR . $sql_file;
            if (file_exists($sql_path)) {
                $sql = file_get_contents($sql_path);
                $queries = explode(";\n", $sql);
                foreach ($queries as $query) {
                    if (!empty(trim($query))) {
                        if (preg_match('/^CREATE TABLE `([^`]+)`/', $query, $matches)) {
                            $table_name = $matches[1];
                            echo $table_name . '<br>';
                            $wpdb->query("TRUNCATE TABLE `$table_name`");
                        }
                        $wpdb->query($query);
                    }
                }
                unlink($sql_path);

                // Obtener el dominio actual
                $current_domain = parse_url(home_url(), PHP_URL_HOST);

                // Consultas adicionales para modificar las tablas en multisite
                $wpdb->query($wpdb->prepare("UPDATE wp_site SET domain = %s WHERE id = 1;", $current_domain));
                $wpdb->query($wpdb->prepare("UPDATE wp_blogs SET domain = %s;", $current_domain));
                $wpdb->query($wpdb->prepare("UPDATE wp_options SET option_value = %s WHERE option_name IN ('home', 'siteurl');", 'http://' . $current_domain));

                if(is_multisite()) {
                    $wpdb->query("UPDATE wp_options SET option_value = 'a:0:{}' WHERE option_name = 'active_plugins';");
                }

                return true;
            }
        }
    }
    return false;
}

// Función para restaurar un respaldo de un directorio
function restore_directory_backup($backup_file, $restore_dir) {
    $zip = new ZipArchive;
    if ($zip->open($backup_file) === TRUE) {
        $zip->extractTo($restore_dir);
        $zip->close();
        return true;
    }
    return false;
}

// Función para subir un respaldo
function upload_backup($file) {
    $target_dir = BACKUP_DIR;
    $target_file = $target_dir . basename($file["name"]);
    $file_type = pathinfo($target_file, PATHINFO_EXTENSION);
    $max_upload_size = wp_max_upload_size();

    // Validar el tipo de archivo
    if ($file_type != "zip") {
        return "Solo se permiten archivos .zip.";
    }

    // Validar el tamaño del archivo
    if ($file["size"] > $max_upload_size) {
        return "El archivo es demasiado grande.";
    }

    // Validar si el archivo ya existe
    if (file_exists($target_file)) {
        return "El archivo ya existe.";
    }

    // Mover el archivo subido al directorio de respaldos
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        log_error("Archivo subido: " . $file["name"]);
        return "El archivo ha sido subido.";
    } else {
        log_error("Error al subir el archivo: " . $file["name"]);
        return "Error al subir el archivo.";
    }
}

// Función para manejar las peticiones AJAX
function handle_ajax_requests() {
    check_ajax_referer('wp_multi_backup_nonce', 'nonce');

    $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';

    switch ($action) {
        case 'create_db_backup':
            $success = backup_multisite_db();
            $message = $success ? 'Respaldo de la base de datos creado con éxito.' : 'Error al crear el respaldo de la base de datos.';
            wp_send_json_success($message);
            break;
        case 'create_themes_backup':
            $success = backup_directory(get_theme_root(), 'themes-backup');
            $message = $success ? 'Respaldo de los temas creado con éxito.' : 'Error al crear el respaldo de los temas.';
            wp_send_json_success($message);
            break;
        case 'create_plugins_backup':
            $success = backup_directory(WP_PLUGIN_DIR, 'plugins-backup');
            $message = $success ? 'Respaldo de los plugins creado con éxito.' : 'Error al crear el respaldo de los plugins.';
            wp_send_json_success($message);
            break;
        case 'create_uploads_backup':
            $success = backup_directory(WP_CONTENT_DIR . '/uploads', 'uploads-backup');
            $message = $success ? 'Respaldo de los uploads creado con éxito.' : 'Error al crear el respaldo de los uploads.';
            wp_send_json_success($message);
            break;
        case 'delete_backup':
            $filename = sanitize_text_field($_POST['filename']);
            $deleted = delete_backup($filename);
            $message = $deleted ? 'Respaldo eliminado.' : 'Error al eliminar el respaldo.';
            wp_send_json_success($message);
            break;
        case 'restore_backup':
            $filename = sanitize_text_field($_POST['filename']);
            $type = sanitize_text_field($_POST['backup_type']);
            $restored = false;

            if ($type === 'db') {
                $restored = restore_db_backup($filename);
            } else {
                $restore_dir = '';
                switch ($type) {
                    case 'themes':
                        $restore_dir = get_theme_root();
                        break;
                    case 'plugins':
                        $restore_dir = WP_PLUGIN_DIR;
                        break;
                    case 'uploads':
                        $restore_dir = WP_CONTENT_DIR . '/uploads';
                        break;
                }
                $restored = restore_directory_backup(BACKUP_DIR . $filename, $restore_dir);
            }

            $message = $restored ? 'Respaldo restaurado con éxito.' : 'Error al restaurar el respaldo.';
            wp_send_json_success($message);
            break;
        default:
            wp_send_json_error('Acción no válida.');
            break;
    }
}

add_action('wp_ajax_handle_ajax_requests', 'handle_ajax_requests');

// Función para mostrar el contenido de la página de administración
function backup_menu_page_content() {
    echo '<div class="wrap">
        <h1 class="wp-heading-inline">WP Multi Backup <span id="loading-indicator" style="display:none;"><img class="loader-image" src="' . plugin_dir_url(__FILE__) . 'loading.gif" alt="Loading..." /></span></h1>
        <button id="show-upload-form" class="wrap page-title-action" style="float: right;">Subir respaldo</button>
    </div>';

    // Formulario para subir un respaldo (oculto por defecto)
    echo '<div class=""><form class="upload-form-backup" id="upload-form" method="post" enctype="multipart/form-data" style="display: none;">
            <input type="file" name="backup_file" id="backup_file">
            <input type="submit" class="button button-primary" value="Subir Respaldo">
            <progress id="upload-progress" value="0" max="100"></progress>
          </form></div>';

    echo '<script>
            document.getElementById("show-upload-form").addEventListener("click", function() {
                var form = document.getElementById("upload-form");
                form.style.display = form.style.display === "none" ? "block" : "none";
            });
          </script>';

    // Mostrar mensajes
    if (isset($_GET['message'])) {
        echo '<div class="updated"><p>' . esc_html($_GET['message']) . '</p></div>';
    }

    // Contadores de respaldos
    $db_count = count(list_backups_by_type('db'));
    $themes_count = count(list_backups_by_type('themes'));
    $plugins_count = count(list_backups_by_type('plugins'));
    $uploads_count = count(list_backups_by_type('uploads'));

    // Tabs para mostrar respaldos
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=wp-multi-backup&tab=db" class="nav-tab ' . (isset($_GET['tab']) && $_GET['tab'] == 'db' ? 'nav-tab-active' : '') . '">Base de datos (' . $db_count . ')</a>';
    echo '<a href="?page=wp-multi-backup&tab=themes" class="nav-tab ' . (isset($_GET['tab']) && $_GET['tab'] == 'themes' ? 'nav-tab-active' : '') . '">Temas (' . $themes_count . ')</a>';
    echo '<a href="?page=wp-multi-backup&tab=plugins" class="nav-tab ' . (isset($_GET['tab']) && $_GET['tab'] == 'plugins' ? 'nav-tab-active' : '') . '">Plugins (' . $plugins_count . ')</a>';
    echo '<a href="?page=wp-multi-backup&tab=uploads" class="nav-tab ' . (isset($_GET['tab']) && $_GET['tab'] == 'uploads' ? 'nav-tab-active' : '') . '">Archivos subidos (' . $uploads_count . ')</a>';
    echo '</h2>';

    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'db';
    $backups = [];

    switch ($tab) {
        case 'themes':
            $input = '<button id="create-themes-backup" class="button button-primary">Crear respaldo de temas</button>';
            $backups = list_backups_by_type('themes');
            break;
        case 'plugins':
            $input = '<button id="create-plugins-backup" class="button button-primary">Crear respaldo de plugins</button>';
            $backups = list_backups_by_type('plugins');
            break;
        case 'uploads':
            $input = '<button id="create-uploads-backup" class="button button-primary">Crear respaldo de archivos subidos</button>';
            $backups = list_backups_by_type('uploads');
            break;
        case 'db':
        default:
            $input = '<button id="create-db-backup" class="button button-primary">Crear respaldo de la base de datos</button>';
            $backups = list_backups_by_type('db');
            break;
    }

    echo '<div class="wrap">';
    echo '<form id="backup-form">' . $input . '</form>';
    
    if (!empty($backups)) {
        echo '<br>';
        echo '<table class="widefat"><thead><tr><th>Archivo</th><th>Tamaño (MB)</th><th>Acciones</th></tr></thead><tbody>';
        foreach ($backups as $backup) {
            $file_path = BACKUP_DIR . $backup;
            $file_size = file_exists($file_path) ? round(filesize($file_path) / 1048576, 2) : 'N/A';
            echo '<tr>
                    <td>' . esc_html($backup) . '</td>
                    <td>' . esc_html($file_size) . '</td>
                    <td>
                        <a href="' . esc_url(admin_url('admin.php?page=wp-multi-backup&download=' . urlencode($backup))) . '" class="button">Descargar</a>
                        <button class="button button-danger delete-backup" data-filename="' . esc_attr($backup) . '">Eliminar</button>
                        <button class="button button-primary restore-backup" data-filename="' . esc_attr($backup) . '" data-type="' . esc_attr($tab) . '">Restaurar</button>
                    </td>
                </tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No hay respaldos disponibles.</p>';
    }
    echo '</div>';
}

// Función para mostrar el contenido de la página de logs
function logs_menu_page_content() {
    echo '<div class="wrap"><h1 class="wp-heading-inline">Logs de WP Multi Backup</h1></div>';
    $log_file = BACKUP_DIR . 'error_log.txt';
    if (file_exists($log_file)) {
        if (isset($_POST['delete_logs'])) {
            unlink($log_file);
            echo '<div class="updated"><p>Logs eliminados.</p></div>';
        } else {
            $logs = file_get_contents($log_file);
            echo '<form method="post"><input type="submit" name="delete_logs" class="button button-danger" value="Eliminar Logs"></form>';
            echo '<pre>' . esc_html($logs) . '</pre>';
        }
    } else {
        echo '<p>No hay logs disponibles.</p>';
    }
}

// Función para actualizar dominios de multisitio
function update_multisite_domains() {
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
            $option_name = $d->option_name;
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

// Función para agregar el menú de administración
function add_backup_menu() {
    $capability = is_multisite() && is_super_admin() ? 'manage_network' : 'manage_options';
    add_menu_page(
        'WP Multi Backup', // Título de la página
        'WP Multi Backup',
        $capability, // Capacidad requerida
        'wp-multi-backup', // Slug de la página
        'backup_menu_page_content', // Contenido de la página
        'dashicons-backup', // Icono del menú
        6 // Posición del menú
    );
    add_submenu_page(
        'wp-multi-backup', // Slug del menú principal
        'Actualizar dominios', // Título de la página
        'Actualizar dominios', // Título del submenú
        $capability,
        'wp-multi-backup-update-domains', // Slug del submenú
        'update_multisite_domains' // Contenido de la página
    );
    add_submenu_page(
        'wp-multi-backup', // Slug del menú principal
        'Logs', // Título de la página
        'Logs', // Título del submenú
        $capability, // Capacidad requerida
        'wp-multi-backup-logs', // Slug del submenú
        'logs_menu_page_content' // Contenido de la página
    );
}

add_action('admin_menu', 'add_backup_menu');
add_action('network_admin_menu', 'add_backup_menu');

// Descargar archivo si se solicita
add_action('admin_init', function() {
    if (isset($_GET['download']) && !empty($_GET['download'])) {
        $filename = sanitize_text_field($_GET['download']);
        download_backup($filename);
    }
});

// Función para descargar un respaldo
function download_backup($filename) {
    $file_url = site_url('/wp-content/wp-multi-backups/' . $filename);

    if (!file_exists(ABSPATH . 'wp-content/wp-multi-backups/' . $filename)) {
        wp_die(__('El archivo no existe.', 'text-domain'));
    }

    // Redirige al usuario a la URL del archivo (descarga limpia)
    wp_redirect($file_url);
    exit;
}

function wp_multi_backup_enqueue_scripts() {
    wp_enqueue_style('wp-multi-backup-style', plugin_dir_url(__FILE__) . 'style.css');
    wp_enqueue_script('wp-multi-backup-script', plugin_dir_url(__FILE__) . 'script.js', array('jquery'), null, true);
    wp_localize_script('wp-multi-backup-script', 'wpMultiBackup', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_multi_backup_nonce')
    ));
}

add_action('admin_enqueue_scripts', 'wp_multi_backup_enqueue_scripts');

// Función para manejar la subida de archivos vía AJAX
function handle_ajax_upload() {
    check_ajax_referer('wp_multi_backup_nonce', 'nonce');

    if (!empty($_FILES['backup_file'])) {
        $file = $_FILES['backup_file'];
        $message = upload_backup($file);
        log_error("Resultado de la subida vía AJAX: " . $message);
        wp_send_json_success($message);
    } else {
        log_error("No se recibió ningún archivo en la subida vía AJAX.");
        wp_send_json_error('No se recibió ningún archivo.');
    }
}

add_action('wp_ajax_upload_backup', 'handle_ajax_upload');