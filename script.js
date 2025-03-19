jQuery(document).ready(function($) {
    function showLoadingIndicator() {
        $('#loading-indicator').show();
    }

    function hideLoadingIndicator() {
        $('#loading-indicator').hide();
    }

    $('#upload-form').on('submit', function(e) {
        e.preventDefault();
        showLoadingIndicator();

        var formData = new FormData(this);
        formData.append('action', 'upload_backup');
        formData.append('nonce', wpMultiBackup.nonce);

        $('#upload-progress').show();

        $.ajax({
            url: wpMultiBackup.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            timeout: 0, // Desactivar el tiempo de espera para archivos grandes
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percentComplete = (e.loaded / e.total) * 100;
                        $('#upload-progress').val(percentComplete);
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                } else {
                    alert(response.data);
                }
                $('#upload-progress').hide();
                $('#upload-form')[0].reset();
                hideLoadingIndicator();
                location.reload();
            },
            error: function(error) {
                console.log(error.responseText);
                var errorMessage = error.responseText.match(/<p>(.*?)<\/p>/);
                if (errorMessage) {
                    alert('Error: ' + errorMessage[1]);
                } else {
                    alert('Error al subir el archivo.');
                }
                $('#upload-progress').hide();
                hideLoadingIndicator();
            }
        });
    });

    $('#backup-form').on('click', 'button', function(e) {
        e.preventDefault();
        showLoadingIndicator();

        var actionType = '';
        switch (this.id) {
            case 'create-db-backup':
                actionType = 'create_db_backup';
                break;
            case 'create-themes-backup':
                actionType = 'create_themes_backup';
                break;
            case 'create-plugins-backup':
                actionType = 'create_plugins_backup';
                break;
            case 'create-uploads-backup':
                actionType = 'create_uploads_backup';
                break;
        }

        $.ajax({
            url: wpMultiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'handle_ajax_requests',
                nonce: wpMultiBackup.nonce,
                action_type: actionType
            },
            success: function(response) {
                let responseData = JSON.parse(response);
                if (responseData.success) {
                    alert(responseData.data);
                    location.reload();
                } else {
                    alert('Error: ' + responseData.data);
                }
                hideLoadingIndicator();
            },
            error: function(error) {
                console.log(error.responseText);
                var errorMessage = error.responseText.match(/<p>(.*?)<\/p>/);
                if (errorMessage) {
                    alert('Error: ' + errorMessage[1]);
                } else {
                    alert('Error al realizar la acción.');
                }
                hideLoadingIndicator();
            }
        });
    });

    $('.delete-backup').on('click', function(e) {
        e.preventDefault();
        showLoadingIndicator();

        var filename = $(this).data('filename');

        $.ajax({
            url: wpMultiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'handle_ajax_requests',
                nonce: wpMultiBackup.nonce,
                action_type: 'delete_backup',
                filename: filename
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
                hideLoadingIndicator();
            },
            error: function(error) {
                console.log(error.responseText);
                var errorMessage = error.responseText.match(/<p>(.*?)<\/p>/);
                if (errorMessage) {
                    alert('Error: ' + errorMessage[1]);
                } else {
                    alert('Error al eliminar el respaldo.');
                }
                hideLoadingIndicator();
            }
        });
    });

    $('.restore-backup').on('click', function(e) {
        e.preventDefault();
        showLoadingIndicator();

        var filename = $(this).data('filename');
        var type = $(this).data('type');

        $.ajax({
            url: wpMultiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'handle_ajax_requests',
                nonce: wpMultiBackup.nonce,
                action_type: 'restore_backup',
                filename: filename,
                backup_type: type
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
                hideLoadingIndicator();
            },
            error: function(error) {
                console.log(error.responseText);
                var errorMessage = error.responseText.match(/<p>(.*?)<\/p>/);
                if (errorMessage) {
                    alert('Error: ' + errorMessage[1]);
                } else {
                    alert('Error al restaurar el respaldo.');
                }
                hideLoadingIndicator();
            }
        });
    });
});
