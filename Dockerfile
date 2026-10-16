FROM php:8.2-apache

# Install PDO MySQL and mbstring extensions
RUN apt-get update && apt-get install -y libonig-dev && \
    docker-php-ext-install pdo pdo_mysql mbstring && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set Apache DocumentRoot and permissions
WORKDIR /var/www/html
COPY . /var/www/html/

# Expose HTTP port
EXPOSE 80

CMD ["apache2-foreground"]
