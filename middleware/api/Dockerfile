FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

COPY public/ /var/www/html/

RUN printf '%s\n' \
    '<VirtualHost *:80>' \
    '    DocumentRoot /var/www/html' \
    '    <Directory /var/www/html>' \
    '        AllowOverride All' \
    '        Require all granted' \
    '    </Directory>' \
    '    RewriteEngine On' \
    '    RewriteCond %{REQUEST_FILENAME} !-f' \
    '    RewriteRule ^ index.php [QSA,L]' \
    '</VirtualHost>' \
    > /etc/apache2/sites-available/000-default.conf