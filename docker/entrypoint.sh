#!/bin/sh
set -e

# Les volumes montés démarrent vides (ou appartiennent à root) : on s'assure que
# www-data peut y écrire avant de démarrer Apache. /var/www/purplemusic-data est
# HORS du DocumentRoot (voir Dockerfile) : jamais servable en HTTP.
mkdir -p /var/www/purplemusic-data /var/www/html/music /var/www/html/covers
chown -R www-data:www-data /var/www/purplemusic-data /var/www/html/music /var/www/html/covers

exec apache2-foreground
