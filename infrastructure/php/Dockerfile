FROM wordpress:6-php8.3-fpm

# WP-CLI para automação (instalação, seed, comandos do plugin)
RUN curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && apt-get update && apt-get install -y --no-install-recommends mariadb-client less \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/log/wordpress && chown www-data:www-data /var/log/wordpress

COPY php.ini /usr/local/etc/php/conf.d/equigov.ini
