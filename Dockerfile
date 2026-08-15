FROM php:8.3-apache

# Extensions PHP requises (pdo_sqlite = base de données, gd = optimisation des
# covers en WebP, curl = récupération des paroles sur lrclib.net).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libgd-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        ca-certificates \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite gd mbstring curl \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY . /var/www/html/

# Les données persistantes (config.php généré à l'installation + la base SQLite)
# vivent hors du code applicatif pour survivre aux mises à jour d'image.
ENV PURPLEMUSIC_DATA_DIR=/var/www/html/data

RUN mkdir -p /var/www/html/data /var/www/html/music /var/www/html/covers \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
