<?php
/*
Plugin Name: WP Multi Backup
Plugin URI: wisus.dev
Description: Plugin para exportar, listar, descargar y eliminar respaldos de la base de datos en WordPress Multisite.
Version: 0.0.9
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

        $total_tables = count($tables);
        $current_table = 0;

        foreach ($tables as $table) {
            $table_name = $table[0];
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table_name`", ARRAY_N);
            $sql_dump .= "\n\n" . $create_table[1] . ";\n\n";

            $rows = $wpdb->get_results("SELECT * FROM `$table_name`", ARRAY_A);
            foreach ($rows as $row) {
                $values = array_map(function($value) use ($wpdb) {
                    return $wpdb->prepare('%s', $value);
                }, array_values($row));
                $sql_dump .= "INSERT INTO `$table_name` VALUES (" . implode(", ", $values) . ");\n";
            }

            $current_table++;
            $progress = ($current_table / $total_tables) * 100;
            echo '<script>document.getElementById("backup-progress").value = ' . $progress . ';</script>';
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
        $total_files = iterator_count($files);
        $current_file = 0;

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($directory) + 1);
                $zip->addFile($file_path, $relative_path);

                $current_file++;
                $progress = ($current_file / $total_files) * 100;
                echo '<script>document.getElementById("backup-progress").value = ' . $progress . ';</script>';
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

// Función para descargar un respaldo
function download_backup($filename) {
    $file_path = BACKUP_DIR . $filename;

    // Validar que el archivo exista y tenga la extensión .zip
    if (!file_exists($file_path) || pathinfo($file_path, PATHINFO_EXTENSION) !== 'zip') {
        wp_die(__('Archivo no encontrado o formato incorrecto.', 'text-domain'));
    }

    // Asegurar que no haya contenido previo en el buffer de salida
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Configurar encabezados para la descarga
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip'); // Tipo MIME correcto
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));

    // Forzar la descarga sin interrupciones
    flush();
    readfile($file_path);
    exit;
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
                        $wpdb->query($query);
                    }
                }
                unlink($sql_path); // Eliminar el archivo .sql después de restaurar
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

    // Validar el tipo de archivo
    if ($file_type != "zip") {
        return "Solo se permiten archivos .zip.";
    }

    // Validar si el archivo ya existe
    if (file_exists($target_file)) {
        return "El archivo ya existe.";
    }

    // Mover el archivo subido al directorio de respaldos
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return "El archivo ha sido subido.";
    } else {
        return "Error al subir el archivo.";
    }
}

// Función para mostrar el contenido de la página de administración
function backup_menu_page_content() {
    if($_POST){
        echo '<div class="wrap"><h2>Estamos procesando su solicitud, por favor espere...</h2></div>';
    } else {
        echo '<div class="wrap">
            <h1 class="wp-heading-inline">WP Multi Backup</h1> 
            <button id="show-upload-form" class="wrap page-title-action" style="float: right;">Subir respaldo</button>
        </div>';
    }

    // Formulario para subir un respaldo (oculto por defecto)
    echo '<div class=""><form class="upload-form-backup" id="upload-form" method="post" enctype="multipart/form-data" style="display: none;">
            <input type="file" name="backup_file">
            <input type="submit" class="button button-primary" value="Subir Respaldo">
          </form></div>';

    echo '<script>
            document.getElementById("show-upload-form").addEventListener("click", function() {
                var form = document.getElementById("upload-form");
                form.style.display = form.style.display === "none" ? "block" : "none";
            });
          </script>';

    // Subir respaldo si se presiona el botón
    if (isset($_FILES['backup_file'])) {
        $message = upload_backup($_FILES['backup_file']);
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=' . urlencode($_GET['tab']) . '&message=' . urlencode($message) . '";</script>';
        exit;
    }
    
    // Crear respaldo de la base de datos si se presiona el botón
    if (isset($_POST['db'])) {
        echo '<progress id="backup-progress" value="0" max="100" style="width: 100%;"></progress>';
        echo '<script>document.getElementById("backup-progress").style.display = "block";</script>';
        $success = backup_multisite_db();
        $message = $success ? 'Respaldo de la base de datos creado con éxito.' : 'Error al crear el respaldo de la base de datos.';
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=db&message=' . urlencode($message) . '";</script>';
        exit;
    }

    // Crear respaldo de los temas si se presiona el botón
    if (isset($_POST['themes'])) {
        echo '<progress id="backup-progress" value="0" max="100" style="width: 100%;"></progress>';
        echo '<script>document.getElementById("backup-progress").style.display = "block";</script>';
        $success = backup_directory(get_theme_root(), 'themes-backup');
        $message = $success ? 'Respaldo de los temas creado con éxito.' : 'Error al crear el respaldo de los temas.';
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=themes&message=' . urlencode($message) . '";</script>';
        exit;
    }

    // Crear respaldo de los plugins si se presiona el botón
    if (isset($_POST['plugins'])) {
        echo '<progress id="backup-progress" value="0" max="100" style="width: 100%;"></progress>';
        echo '<script>document.getElementById("backup-progress").style.display = "block";</script>';
        $success = backup_directory(WP_PLUGIN_DIR, 'plugins-backup');
        $message = $success ? 'Respaldo de los plugins creado con éxito.' : 'Error al crear el respaldo de los plugins.';
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=plugins&message=' . urlencode($message) . '";</script>';
        exit;
    }

    // Crear respaldo de los uploads si se presiona el botón
    if (isset($_POST['uploads'])) {
        echo '<progress id="backup-progress" value="0" max="100" style="width: 100%;"></progress>';
        echo '<script>document.getElementById("backup-progress").style.display = "block";</script>';
        $success = backup_directory(WP_CONTENT_DIR . '/uploads', 'uploads-backup');
        $message = $success ? 'Respaldo de los uploads creado con éxito.' : 'Error al crear el respaldo de los uploads.';
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=uploads&message=' . urlencode($message) . '";</script>';
        exit;
    }

    // Eliminar respaldo si se solicita
    if (isset($_POST['delete_backup'])) {
        $filename = sanitize_text_field($_POST['delete_backup']);
        $deleted = delete_backup($filename);
        $message = $deleted ? 'Respaldo eliminado.' : 'Error al eliminar el respaldo.';
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=' . urlencode($_GET['tab']) . '&message=' . urlencode($message) . '";</script>';
        exit;
    }

    // Restaurar respaldo si se solicita
    if (isset($_POST['restore_backup'])) {
        $filename = sanitize_text_field($_POST['restore_backup']);
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
        echo '<script>window.location.href = "?page=wp-multi-backup&tab=' . urlencode($_GET['tab']) . '&message=' . urlencode($message) . '";</script>';
        exit;
    }

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
            $input = '<input type="submit" name="themes" class="button button-primary" value="Crear respaldo de temas">';
            $backups = list_backups_by_type('themes');
            break;
        case 'plugins':
            $input = '<input type="submit" name="plugins" class="button button-primary" value="Crear respaldo de plugins">';
            $backups = list_backups_by_type('plugins');
            break;
        case 'uploads':
            $input = '<input type="submit" name="uploads" class="button button-primary" value="Crear respaldo de archivos subidos">';
            $backups = list_backups_by_type('uploads');
            break;
        case 'db':
        default:
            $input = '<input type="submit" name="db" class="button button-primary" value="Crear respaldo de la base de datos">';
            $backups = list_backups_by_type('db');
            break;
    }

    echo '<div class="wrap">';
    echo '<form method="post">' . $input . '</form>';
    
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
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_backup" value="' . esc_attr($backup) . '">
                            <input type="submit" class="button button-danger" value="Eliminar">
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="restore_backup" value="' . esc_attr($backup) . '">
                            <input type="hidden" name="backup_type" value="' . esc_attr($tab) . '">
                            <input type="submit" class="button button-primary" value="Restaurar">
                        </form>
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

function wp_multi_backup_enqueue_styles() {
    wp_enqueue_style('wp-multi-backup-style', plugin_dir_url(__FILE__) . 'style.css');
}

add_action('admin_enqueue_scripts', 'wp_multi_backup_enqueue_styles');