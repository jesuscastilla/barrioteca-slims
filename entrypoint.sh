#!/bin/sh

# Write environment variables to env.php
cat <<EOF >/var/www/html/config/env.php
<?php
\$env = "${ENV}";
\$conditional_environment = "${ENV}";
\$based_on_ip = false;
\$range_ip = [''];
if (\$based_on_ip) {
    if (array_key_exists('HTTP_X_FORWARDED_FOR', \$_SERVER) && in_array(\$_SERVER['HTTP_X_FORWARDED_FOR'], \$range_ip)) {
        \$env = \$conditional_environment;
    } else if (in_array(\$_SERVER['REMOTE_ADDR'], \$range_ip)) {
        \$env = \$conditional_environment;
    }   
}
EOF

echo "PHP configuration file created: /var/www/html/config/env.php"
cat /var/www/html/config/env.php

# Write environment variables to database.php
cat <<EOF >/var/www/html/config/database.php
<?php
return [
    'default_profile' => 'SLiMS',
    'proxy' => false,
    'nodes' => [
        'SLiMS' => [
            'host' => '${DB_HOST}',
            'database' => '${DB_NAME}',
            'port' => '${DB_PORT}',
            'username' => '${DB_USER}',
            'password' => '${DB_PASS}',
            'options' => [
                'storage_engine' => 'MyISAM'
            ]
        ]
    ]
];
EOF

echo "PHP configuration file created: /var/www/html/config/database.php"
cat /var/www/html/config/database.php

# Ajustar permisos en runtime (necesario para volúmenes en Synology NAS)
chown -R www-data:www-data /var/www/html/files 2>/dev/null || true
chown -R www-data:www-data /var/www/html/images 2>/dev/null || true
chown -R www-data:www-data /var/www/html/repository 2>/dev/null || true
mkdir -p /var/www/html/images/docs 2>/dev/null || true
chown www-data:www-data /var/www/html/images/docs 2>/dev/null || true
chmod -R 775 /var/www/html/files /var/www/html/images /var/www/html/repository 2>/dev/null || true

# Start the PHP application
exec "$@"
