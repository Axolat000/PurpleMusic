FROM php:8.3-apache

# Extensions PHP requises (pdo_sqlite = base de données, gd = optimisation des
# covers en WebP, curl = récupération des paroles sur lrclib.net).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libgd-dev \
        libcurl4-openssl-dev \
        libzip-dev \
        libonig-dev \
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

# Les données persistantes (config.php généré à l'installation + la base SQLite) vivent
# HORS du DocumentRoot Apache (/var/www/html) — pas juste dans un sous-dossier bloqué par
# .htaccess. La base SQLite contient les hash de mots de passe de tous les utilisateurs ;
# la sortir physiquement du docroot évite qu'elle soit jamais servable en HTTP, quelle que
# soit la config du serveur (AllowOverride oublié, mauvais module chargé, etc.) — pas de
# single point of failure sur une seule règle .htaccess.
ENV PURPLEMUSIC_DATA_DIR=/var/www/purplemusic-data

RUN mkdir -p /var/www/purplemusic-data /var/www/html/music /var/www/html/covers \
    && chown -R www-data:www-data /var/www/purplemusic-data /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
