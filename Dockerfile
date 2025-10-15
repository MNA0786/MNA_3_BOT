FROM php:8.1-apache

# System dependencies install karo
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    ffmpeg \
    libzip-dev \
    && docker-php-ext-install \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        sockets

# Composer install karo
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Working directory set karo
WORKDIR /var/www/html

# Apache configuration enable karo
RUN a2enmod rewrite headers

# Copy project files FIRST
COPY . .

# THEN create directories and set permissions
RUN mkdir -p uploads temp \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 uploads temp \
    && touch users.json metadata.json bot_state.json error.log \
    && chmod 666 users.json metadata.json bot_state.json error.log

# Port expose karo
EXPOSE 80

CMD ["apache2-foreground"]
