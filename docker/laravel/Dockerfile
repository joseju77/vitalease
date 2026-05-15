FROM php:8.5.10-fpm-alpine3.24

ARG ENV=dev

ENV USER=www-data
ARG UID=1000
ARG GID=1000

# delete default www-data user, then create a new user with specified UID and GID
# `adduser -D` leaves the account password-locked ("!" in /etc/shadow), which OpenSSH
# refuses to log into via ANY method (including pubkey) — unlock it, no password is ever
# set or usable since PasswordAuthentication is disabled below.
RUN deluser $USER \
    && addgroup -g $GID $USER \
    && adduser -u $UID -D -G $USER $USER \
    && sed -i "s/^$USER:!/$USER:*/" /etc/shadow

# install composer
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer
RUN chmod +x /usr/bin/composer

# install dependencies
RUN apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS \
      icu-dev \
      libpq-dev \
      libzip-dev \
      linux-headers \
    && apk add --no-cache \
      icu-libs \
      libpq \
      libzip \
      bash \
      supervisor openssh tini \
      nodejs npm \
    && pecl install -o -f redis xdebug \
    && docker-php-ext-install \
      intl \
      pdo_pgsql \
      zip \
    && docker-php-ext-enable redis xdebug \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# enable and configure xdebug
RUN echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# configure SSH (key-based auth only, no password)
RUN ssh-keygen -A \
    && sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config \
    && mkdir -p /home/$USER/.ssh \
    && chown $USER:$USER /home/$USER/.ssh \
    && chmod 700 /home/$USER/.ssh

# define command to run
ENTRYPOINT ["tini", "--"]
CMD ["supervisord", "-c", "/etc/supervisord/supervisord.conf"]
