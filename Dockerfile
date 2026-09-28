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
    npm

# Install PHP extensions
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    bcmath \
    pcntl \
    zip \
    intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP settings
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Default command
CMD ["php", "-a"]
