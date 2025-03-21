<?php
/*
Plugin Name: WP Multi Backup
Plugin URI: wisus.dev
Description: Plugin para exportar, listar, descargar y eliminar respaldos de la base de datos en WordPress Multisite.
Version: 0.0.26
Author: Jesús Avelar
Author URI: linkedin.com/in/wisusdev
License: GPL2
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit; // Seguridad

// Importamos vistas
require_once plugin_dir_path(__FILE__) . 'views/logs.php';
require_once plugin_dir_path(__FILE__) . 'views/php_wp_info.php';
require_once plugin_dir_path(__FILE__) . 'views/update_multisite_domains.php';
require_once plugin_dir_path(__FILE__) . 'views/backups_manager.php';

// Carpeta donde se guardarán los respaldos
const BACKUP_DIR = WP_CONTENT_DIR . '/wp-multi-backups/';

// Crear la carpeta si no existe
if (!file_exists(BACKUP_DIR)) {
    try {
        mkdir(BACKUP_DIR, 0755, true);
    } catch (Exception $e) {
        logs($e->getMessage());
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
        logs($e->getMessage());
    }
}

// Función para agregar el menú de administración
function add_backup_menu(): void
{
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
    add_submenu_page(
        'wp-multi-backup', // Slug del menú principal
        'PHP & WP Info', // Título de la página
        'PHP & WP Info', // Título del submenú
        $capability, // Capacidad requerida
        'wp-multi-backup-php-info', // Slug del submenú
        'php_version_notice' // Contenido de la página
    );
}

add_action('admin_menu', 'add_backup_menu');
add_action('network_admin_menu', 'add_backup_menu');

// Función para crear un respaldo de la base de datos con barra de progreso
function backup_multisite_db(): bool
{
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
        logs($e->getMessage());
        return false;
    }
}

// Función para crear un respaldo de un directorio con barra de progreso
function backup_directory($directory, $backup_name): bool
{
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



// Función para eliminar un respaldo
function delete_backup($filename): bool
{
    $file_path = BACKUP_DIR . $filename;
    if (file_exists($file_path)) {
        unlink($file_path);
        return true;
    }
    return false;
}

// Función para restaurar un respaldo de la base de datos
function restore_db_backup($filename): bool
{
    global $wpdb;

    $current_domain = parse_url(home_url(), PHP_URL_HOST);
    $scheme = is_ssl() ? 'https' : 'http';
    $full_url = $scheme . '://' . $current_domain;

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
                $wpdb->query($wpdb->prepare("UPDATE wp_options SET option_value = %s WHERE option_name IN ('home', 'siteurl');", $full_url));

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
function restore_directory_backup($backup_file, $restore_dir): bool
{
    $zip = new ZipArchive;
    if ($zip->open($backup_file) === TRUE) {
        $zip->extractTo($restore_dir);
        $zip->close();
        return true;
    }
    return false;
}

// Función para subir un respaldo
function upload_backup($file): string
{
    try {
        $max_upload_size = min(ini_get_bytes('upload_max_filesize'), ini_get_bytes('post_max_size'));

        if ($file["size"] > $max_upload_size) {
            logs("El archivo sobrepasa upload_max_filesize o post_max_size. Ajusta php.ini para permitir archivos más grandes. Tamaño del archivo: " . $file["size"] . " bytes y máximo permitido: " . $max_upload_size . " bytes.");
            return "El archivo es demasiado grande. Máximo permitido: " . format_bytes($max_upload_size) . " y el archivo es de " . format_bytes($file["size"]) . ".";
        }

        if (!isset($file['error']) || is_array($file['error'])) {
            return "Error en la subida del archivo. " . json_encode($file['error']);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return "Error en la subida del archivo: " . upload_error_message($file['error']);
        }

        $target_dir = BACKUP_DIR;
        $file_name = basename($file["name"]);
        $target_file = $target_dir . DIRECTORY_SEPARATOR . $file_name;
        $file_type = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Validar el tipo de archivo
        if ($file_type !== "zip") {
            return "Solo se permiten archivos .zip.";
        }

        // Validar si el archivo ya existe
        if (file_exists($target_file)) {
            return "El archivo ya existe.";
        }

        // Validar permisos de escritura
        if (!is_writable($target_dir)) {
            return "No se puede escribir en el directorio de respaldos.";
        }

        // Mover el archivo subido al directorio de respaldos
        if (!move_uploaded_file($file["tmp_name"], $target_file)) {
            return "Error al mover el archivo.";
        }

        return "El archivo ha sido subido correctamente.";
    } catch (Exception $e) {
        logs($e->getMessage());
        return "Error inesperado: " . $e->getMessage();
    }
}

/**
 * Convierte valores de PHP ini como '2M' en bytes.
 */
function ini_get_bytes($key): int
{
    $val = trim(ini_get($key));
    $last = strtolower($val[strlen($val) - 1]);

    $multipliers = [
        'k' => 1024,
        'm' => 1048576,
        'g' => 1073741824
    ];

    return (int)$val * ($multipliers[$last] ?? 1);
}

/**
 * Formatea bytes en KB, MB o GB.
 */
function format_bytes($size, $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $precision) . ' ' . $units[$i];
}

/**
 * Mapea los códigos de error de subida a mensajes más descriptivos.
 */
function upload_error_message($error_code): string
{
    $errors = [
        UPLOAD_ERR_INI_SIZE   => "El archivo excede el tamaño máximo permitido por el servidor.",
        UPLOAD_ERR_FORM_SIZE  => "El archivo excede el tamaño máximo permitido en el formulario.",
        UPLOAD_ERR_PARTIAL    => "El archivo solo se subió parcialmente.",
        UPLOAD_ERR_NO_FILE    => "No se seleccionó ningún archivo.",
        UPLOAD_ERR_NO_TMP_DIR => "Falta un directorio temporal en el servidor.",
        UPLOAD_ERR_CANT_WRITE => "Error al escribir el archivo en el servidor.",
        UPLOAD_ERR_EXTENSION  => "La subida del archivo fue bloqueada por una extensión de PHP."
    ];

    return $errors[$error_code] ?? "Error desconocido.";
}

// Función para manejar las peticiones AJAX
function handle_ajax_requests(): void
{
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

// Descargar archivo si se solicita
add_action('admin_init', function() {
    if (!empty($_GET['download'])) {
        $filename = sanitize_text_field($_GET['download']);
        download_backup($filename);
    }
});

// Función para descargar un respaldo
function download_backup($filename): void
{
    $file_url = site_url('/wp-content/wp-multi-backups/' . $filename);

    if (!file_exists(ABSPATH . 'wp-content/wp-multi-backups/' . $filename)) {
        wp_die(__('El archivo no existe.', 'text-domain'));
    }

    // Redirige al usuario a la URL del archivo (descarga limpia)
    wp_redirect($file_url);
    exit;
}

function wp_multi_backup_enqueue_scripts(): void
{
    wp_enqueue_style('wp-multi-backup-style', plugin_dir_url(__FILE__) . 'style.css');
    wp_enqueue_script('wp-multi-backup-script', plugin_dir_url(__FILE__) . 'script.js', array('jquery'), null, true);
    wp_localize_script('wp-multi-backup-script', 'wpMultiBackup', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wp_multi_backup_nonce'),
        'max_upload_size' => ini_get_bytes('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
    ));
}

add_action('admin_enqueue_scripts', 'wp_multi_backup_enqueue_scripts');

