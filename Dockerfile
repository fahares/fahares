FROM php:8.3-cli-alpine

# Install system dependencies & libraries for PHP extensions
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libzip-dev \
    icu-dev \
    icu-data-full \
    mariadb-client \
    nodejs \
    npm \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev

# Install PHP extensions (gd with WebP for user avatars, as on production)
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    bcmath \
    pcntl \
    zip \
    intl \
    gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP settings
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Default command
CMD ["php", "-a"]
