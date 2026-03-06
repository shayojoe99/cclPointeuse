FROM php:8.2-apache

# ── System dependencies ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────────
RUN docker-php-ext-install pdo pdo_mysql

# ── Apache: enable mod_rewrite + set document root ───────────────────────────
RUN a2enmod rewrite

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/>|<Directory /var/www/html/public>|g' \
        /etc/apache2/apache2.conf \
    && sed -i 's|AllowOverride None|AllowOverride All|g' \
        /etc/apache2/apache2.conf

# ── Copy application ──────────────────────────────────────────────────────────
COPY . /var/www/html

# ── Permissions for logs directory ───────────────────────────────────────────
RUN mkdir -p /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html/logs \
    && chmod -R 755 /var/www/html/logs

# ── Apache listens on 8012 ────────────────────────────────────────────────────
RUN sed -i 's/Listen 80/Listen 8012/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8012>/' \
        /etc/apache2/sites-available/000-default.conf

EXPOSE 8012
