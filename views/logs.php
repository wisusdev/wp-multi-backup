<?php 

// Función para registrar errores en un archivo de log
function logs($message): void
{
    $log_file = BACKUP_DIR . 'error_log.txt';
    $current_time = date("Y-m-d H:i:s");
    $log_message = "[$current_time] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Función para mostrar el contenido de la página de logs
function logs_menu_page_content(): void
{
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