FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

# Стандартний production php.ini: max_execution_time=30, upload_max_filesize=2M, post_max_size=8M.
# Ліміти навмисно НЕ піднімаємо — імпорт має вкладатися у стандартні налаштування.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
