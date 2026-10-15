```dockerfile
FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql \
 && a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
 && sed -ri 's/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

COPY . /var/www/html

CMD rm -f /etc/apache2/mods-enabled/mpm_event.* \
          /etc/apache2/mods-enabled/mpm_worker.* \
 && ln -sf /etc/apache2/mods-available/mpm_prefork.load \
           /etc/apache2/mods-enabled/mpm_prefork.load \
 && ln -sf /etc/apache2/mods-available/mpm_prefork.conf \
           /etc/apache2/mods-enabled/mpm_prefork.conf \
 && sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf \
 && sed -i "s/:80>/:${PORT}>/" \
           /etc/apache2/sites-available/000-default.conf \
 && apache2-foreground
```