# PHP-image voor php-fpm, game-writer en seed. Alpine = klein; php:8.3-fpm-alpine heeft een arm64-variant (Pi).
FROM php:8.3-fpm-alpine AS app

# opcache zit al in het basisimage (geen installatie nodig, dus bouwen werkt ook zonder internet).
# Het bewaart gecompileerde PHP-code in geheugen. Instellingen: docker/php.ini.
# De map /data (database) en /runtime (game.json) moeten van www-data zijn, anders kan PHP er niet in schrijven.
RUN mkdir -p /data /runtime \
 && chown www-data:www-data /data /runtime

# Niet aanpassen tenzij je weet wat je doet: tuning, zie commentaar in beide bestanden.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-klosterke.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-klosterke.conf

# De code zit in het image. Na elke codewijziging: ./bin/klosterke start (bouwt opnieuw).
# Dat is nodig omdat opcache de bestanden niet opnieuw controleert (validate_timestamps=0).
WORKDIR /var/www/html
COPY src src
COPY bin bin
COPY database database
COPY public public

# Alleen voor de specs (docker compose --profile test run --rm test), niet voor het event zelf.
# Composer en Kahlan komen hier in, niet in het image dat op de Pi draait.
FROM app AS test
RUN apk add --no-cache unzip git
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY extra/composer.json ./
RUN composer install --no-interaction --prefer-dist
COPY extra/kahlan-config.php ./
COPY extra/spec spec
CMD ["vendor/bin/kahlan"]

# Alleen voor bin/calibrate.php (docker compose --profile loadtest), niet voor het event zelf.
FROM app AS tools
RUN apk add --no-cache sysbench
