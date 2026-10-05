# Deploys the payroll app to any Docker host (Render, Railway, Fly.io, Koyeb).
# The MySQL database is NOT in here — it stays on Aiven, and nothing about it
# is in the image either: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS and the
# CA certificate (Secret File "ca.pem" or DB_SSL_CA_PEM) are set on the host.
FROM php:8.2-apache

# mbstring, curl and openssl are already compiled into this image
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# PHP for a public server: errors go to the log (Render shows it), never to
# visitors; the PHP version is not advertised; session cookie locked down.
RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'session.cookie_httponly = 1'; \
      echo 'session.cookie_samesite = Lax'; \
      echo 'session.use_strict_mode = 1'; \
      echo 'session.use_only_cookies = 1'; \
      echo 'max_execution_time = 300'; \
      echo 'post_max_size = 20M'; \
      echo 'upload_max_filesize = 20M'; \
    } > "$PHP_INI_DIR/conf.d/payroll.ini"

# Apache: no version banner, no folder listings, code-only folders and
# non-page files never served (see docker/apache-security.conf)
COPY docker/apache-security.conf /etc/apache2/conf-available/payroll-security.conf
RUN a2enconf payroll-security

# App files become the web root
COPY payroll2/ /var/www/html/
RUN rm -f /var/www/html/ca.pem \
 && chown -R www-data:www-data /var/www/html

# Stricter rules on the live site (e.g. the default admin password is refused)
ENV APP_ENV=production

# Render/Railway inject $PORT; Apache must listen on it
COPY docker-start.sh /usr/local/bin/docker-start.sh
RUN chmod +x /usr/local/bin/docker-start.sh

ENV PORT=8080
EXPOSE 8080
CMD ["/usr/local/bin/docker-start.sh"]
