FROM php:8.3-apache

ARG USER_ID
ARG GROUP_ID
ARG APACHE_DOCUMENT_ROOT

RUN usermod --non-unique --uid $USER_ID www-data
RUN groupmod --gid $GROUP_ID www-data

# install libs
RUN apt update && \
    apt install -y less vim wget unzip rsync git default-mysql-client libzip-dev ssl-cert git libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libxpm-dev \
    graphicsmagick libgraphicsmagick1-dev

# install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm && \
    docker-php-ext-install gd && \
    docker-php-ext-install zip exif pdo_mysql

# install PHP intl extension
RUN docker-php-ext-configure intl && docker-php-ext-install intl

# install gmagick (GraphicsMagick PHP extension)
RUN pecl install gmagick-2.0.6RC1 && docker-php-ext-enable gmagick

# install xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# setup document root
ENV APACHE_DOCUMENT_ROOT $APACHE_DOCUMENT_ROOT
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
COPY .docker/app/php.ini /usr/local/etc/php/

# apache mod rewrite
RUN a2enmod rewrite

# home directory owned by user
RUN chown -Rf $USER_ID:$GROUP_ID /var/www

# switch now to www-data
USER www-data

# source code
COPY --chown=www-data:www-data . /var/www/html

WORKDIR /var/www/html
RUN composer install --no-dev
RUN rm -rf ./var