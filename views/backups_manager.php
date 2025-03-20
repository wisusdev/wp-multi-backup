<?php 

// Función para mostrar el contenido de la página de administración
function backup_menu_page_content(): void
{
    echo '<div class="wrap">
        <h1 class="wp-heading-inline">WP Multi Backup <span id="loading-indicator" style="display:none;"><img class="loader-image" src="' . plugin_dir_url(__FILE__) . 'loading.gif" alt="Loading..." /></span></h1>
        <button id="show-upload-form" class="wrap page-title-action" style="float: right;">Subir respaldo</button>
    </div>';

    // Formulario para subir un respaldo (oculto por defecto)
    echo '<div class="upload-file-form" style="display: none;">
            <p>
                Antes de subir un respaldo, asegúrate de que el archivo sea un <strong>.zip</strong> y que no exceda el tamaño máximo permitido por el servidor.
                El tamaño máximo permitido para subir archivos es de <strong>' . ini_get('upload_max_filesize') . '</strong>.
                El tamaño máximo permitido para subir archivos en un formulario es de <strong>' . ini_get('post_max_size') . '</strong>.
                El límite de memoria actual es de <strong>' . ini_get('memory_limit') . '</strong>.
            </p>
            <p><strong>Nota:</strong> Si el archivo es muy grande, es posible que la subida tarde un poco. Por favor, no cierres la página hasta que se complete la subida.</p>
            
            <form class="upload-form-backup" id="upload-form" method="post" enctype="multipart/form-data">
                <input type="file" name="backup_file" id="backup_file" required accept="application/zip">
                <input type="submit" class="button button-primary" value="Subir Respaldo">
                <progress id="upload-progress" value="0" max="100"></progress>
            </form>
          </div>';

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

    $tab = $_GET['tab'] ?? 'db';

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

// Función para listar los respaldos por tipo
function list_backups_by_type($type): array
{
    $files = glob(BACKUP_DIR . "$type-backup-*.zip");
    return array_map('basename', $files);
}

// Función para manejar la subida de archivos vía AJAX
function handle_ajax_upload(): void
{
    $start = microtime(true);

    check_ajax_referer('wp_multi_backup_nonce', 'nonce');

    if (!empty($_FILES['backup_file'])) {
        $file = $_FILES['backup_file'];
        $message = upload_backup($file);

        $end = microtime(true);
        $duration = $end - $start;
        logs("Resultado de la subida vía AJAX: " . $message . ". Tiempo de ejecución: " . round($duration, 3) . " seg.");

        wp_send_json_success($message . " (Tardó " . round($duration, 3) . " seg.)");
    } else {
        logs("No se recibió ningún archivo en la subida vía AJAX.");
        wp_send_json_error('No se recibió ningún archivo.');
    }
}

add_action('wp_ajax_upload_backup', 'handle_ajax_upload');