#!/bin/sh
set -e

# Les volumes montés démarrent vides (ou appartiennent à root) : on s'assure que
# www-data peut y écrire avant de démarrer Apache.
mkdir -p /var/www/html/data /var/www/html/music /var/www/html/covers
chown -R www-data:www-data /var/www/html/data /var/www/html/music /var/www/html/covers

exec apache2-foreground
