<?php
/**
 * Spanish (Español) Translation File
 *
 * NOTE: This file contains PLACEHOLDER translations.
 * Professional translation recommended for production use.
 *
 * @package Kyte
 * @version 4.0.0
 * @language Spanish (es / Español)
 * @status DRAFT - Requires professional translation
 */

return [
    // Common Errors
    'error.not_found' => 'Registro no encontrado',
    'error.unauthorized' => 'Acceso no autorizado',
    'error.forbidden' => 'Acceso prohibido',
    'error.invalid_request' => 'Solicitud inválida',
    'error.validation_failed' => 'Validación fallida: {field}',
    'error.required_field' => '{field} es obligatorio',
    'error.invalid_format' => 'Formato inválido para {field}',
    'error.duplicate_entry' => '{field} ya existe',
    'error.database_error' => 'Error de base de datos',
    'error.server_error' => 'Error interno del servidor',
    'error.not_implemented' => 'Función no implementada',
    'error.rate_limit_exceeded' => 'Límite de tasa excedido. Inténtelo más tarde',
    'error.service_unavailable' => 'Servicio temporalmente no disponible',
    'error.invalid_credentials' => 'Credenciales inválidas',
    'error.session_expired' => 'Su sesión ha expirado. Por favor, inicie sesión nuevamente',
    'error.permission_denied' => 'Permiso denegado',
    'error.invalid_token' => 'Token inválido o expirado',
    'error.file_too_large' => 'El tamaño del archivo excede el máximo permitido',
    'error.unsupported_file_type' => 'Tipo de archivo no soportado',
    'error.upload_failed' => 'La carga del archivo falló',

    // Success Messages
    'success.created' => '{model} creado exitosamente',
    'success.updated' => '{model} actualizado exitosamente',
    'success.deleted' => '{model} eliminado exitosamente',
    'success.saved' => 'Cambios guardados exitosamente',
    'success.sent' => '{item} enviado exitosamente',
    'success.uploaded' => 'Archivo cargado exitosamente',
    'success.operation_complete' => 'Operación completada exitosamente',

    // Authentication
    'auth.login_success' => 'Inicio de sesión exitoso',
    'auth.logout_success' => 'Cierre de sesión exitoso',
    'auth.invalid_credentials' => 'Correo electrónico o contraseña inválidos',
    'auth.account_disabled' => 'Su cuenta ha sido deshabilitada',
    'auth.session_expired' => 'Su sesión ha expirado',
    'auth.session_invalid' => 'Sesión inválida',
    'auth.password_reset_sent' => 'Instrucciones de restablecimiento de contraseña enviadas a su correo',
    'auth.password_reset_success' => 'Contraseña restablecida exitosamente',
    'auth.password_reset_invalid' => 'Token de restablecimiento de contraseña inválido o expirado',
    'auth.email_not_found' => 'Dirección de correo no encontrada',

    // Validation
    'validation.required' => '{field} es obligatorio',
    'validation.email' => 'Dirección de correo inválida',
    'validation.min_length' => '{field} debe tener al menos {min} caracteres',
    'validation.max_length' => '{field} no debe exceder {max} caracteres',
    'validation.numeric' => '{field} debe ser un número',
    'validation.integer' => '{field} debe ser un entero',
    'validation.positive' => '{field} debe ser positivo',
    'validation.url' => 'URL inválida',
    'validation.date' => 'Formato de fecha inválido',
    'validation.unique' => '{field} ya existe',
    'validation.in' => 'Valor inválido para {field}',
    'validation.confirmed' => 'La confirmación de contraseña no coincide',
    'validation.min_value' => '{field} debe ser al menos {min}',
    'validation.max_value' => '{field} no debe exceder {max}',

    // Models
    'model.create_failed' => 'Error al crear {model}',
    'model.update_failed' => 'Error al actualizar {model}',
    'model.delete_failed' => 'Error al eliminar {model}',
    'model.not_found' => '{model} no encontrado',
    'model.already_exists' => '{model} ya existe',
    'model.invalid_id' => 'ID de {model} inválido',

    // Cron Jobs
    'cron.job_not_found' => 'Trabajo cron no encontrado',
    'cron.job_disabled' => 'Trabajo cron está deshabilitado',
    'cron.job_running' => 'El trabajo cron ya está en ejecución',
    'cron.job_triggered' => 'Trabajo cron activado exitosamente',
    'cron.job_in_dlq' => 'Trabajo cron está en cola de letras muertas',
    'cron.job_recovered' => 'Trabajo cron recuperado de cola de letras muertas',
    'cron.execution_failed' => 'Ejecución de trabajo cron falló',
    'cron.timeout' => 'Tiempo de ejecución del trabajo cron agotado',
    'cron.invalid_schedule' => 'Configuración de programación inválida',
    'cron.code_invalid' => 'Código de trabajo inválido',

    // Files
    'file.upload_success' => 'Archivo cargado exitosamente',
    'file.upload_failed' => 'La carga del archivo falló',
    'file.not_found' => 'Archivo no encontrado',
    'file.delete_success' => 'Archivo eliminado exitosamente',
    'file.delete_failed' => 'La eliminación del archivo falló',
    'file.invalid_type' => 'Tipo de archivo inválido',
    'file.too_large' => 'El archivo es demasiado grande. Tamaño máximo: {max}',
    'file.empty' => 'El archivo está vacío',

    // AWS
    'aws.invalid_credentials' => 'Credenciales de AWS inválidas',
    'aws.operation_failed' => 'Operación AWS falló: {operation}',
    'aws.s3_upload_failed' => 'Carga a S3 falló',
    'aws.s3_delete_failed' => 'Eliminación de S3 falló',
    'aws.ses_send_failed' => 'Envío de correo falló',
    'aws.cloudfront_invalid' => 'Distribución CloudFront no encontrada',

    // API
    'api.invalid_signature' => 'Firma API inválida',
    'api.missing_header' => 'Encabezado requerido faltante: {header}',
    'api.invalid_method' => 'Método HTTP inválido. Esperado: {expected}',
    'api.rate_limited' => 'Límite de tasa API excedido',
    'api.invalid_json' => 'JSON inválido en el cuerpo de la solicitud',
    'api.missing_parameter' => 'Parámetro requerido faltante: {param}',
    'api.invalid_parameter' => 'Parámetro inválido: {param}',

    // Database
    'db.connection_failed' => 'Conexión a base de datos falló',
    'db.query_failed' => 'Consulta de base de datos falló',
    'db.transaction_failed' => 'Transacción de base de datos falló',
    'db.integrity_violation' => 'Violación de restricción de integridad de base de datos',
    'db.duplicate_key' => 'Error de clave duplicada',

    // User & Account
    'user.not_found' => 'Usuario no encontrado',
    'user.created' => 'Usuario creado exitosamente',
    'user.updated' => 'Usuario actualizado exitosamente',
    'user.deleted' => 'Usuario eliminado exitosamente',
    'user.email_exists' => 'Dirección de correo ya existe',
    'user.invalid_email' => 'Dirección de correo inválida',
    'user.password_too_short' => 'La contraseña debe tener al menos {min} caracteres',
    'user.password_mismatch' => 'Las contraseñas no coinciden',
    'account.not_found' => 'Cuenta no encontrada',
    'account.suspended' => 'La cuenta está suspendida',

    // Actions
    'action.confirm_delete' => '¿Está seguro de que desea eliminar esto?',
    'action.confirm_action' => '¿Está seguro de que desea continuar?',
    'action.cannot_undo' => 'Esta acción no se puede deshacer',
    'action.processing' => 'Procesando...',
    'action.please_wait' => 'Por favor espere...',
    'action.loading' => 'Cargando...',
    'action.saving' => 'Guardando...',
    'action.deleting' => 'Eliminando...',

    // Dates
    'date.today' => 'Hoy',
    'date.yesterday' => 'Ayer',
    'date.tomorrow' => 'Mañana',
    'date.last_week' => 'La semana pasada',
    'date.next_week' => 'La próxima semana',
    'date.last_month' => 'El mes pasado',
    'date.next_month' => 'El próximo mes',

    // Pagination
    'pagination.showing' => 'Mostrando {from} a {to} de {total} entradas',
    'pagination.no_results' => 'No se encontraron resultados',
    'pagination.per_page' => 'Por página',
    'pagination.next' => 'Siguiente',
    'pagination.previous' => 'Anterior',
    'pagination.first' => 'Primero',
    'pagination.last' => 'Último',
];
