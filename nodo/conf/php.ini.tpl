; iProy Portable · php.ini del nodo (generado desde conf/php.ini.tpl)
memory_limit = 512M
max_execution_time = 180
max_input_time = 120
post_max_size = 40M
upload_max_filesize = 32M
upload_tmp_dir = "@DATA@/run"
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "@DATA@/logs/php_errors.log"
error_reporting = E_ALL & ~E_DEPRECATED
date.timezone = America/Argentina/San_Juan
default_charset = "UTF-8"
expose_php = Off
session.save_path = "@DATA@/php/sesiones"
session.cookie_httponly = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
; Modo autónomo (v0.4): inyecta /_iproy/offline/iproy-offline.js en las páginas HTML sin tocar los archivos de la app
auto_prepend_file = "@APP@/_iproy/offline/prepend.php"
