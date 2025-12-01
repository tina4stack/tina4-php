FROM --platform=linux/amd64 php:8.4-cli

RUN apt-get update \
    && apt-get install -y apt-transport-https gnupg2 libpng-dev libzip-dev nano unzip unixodbc  \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
    && apt-get -y install locales-all && locale-gen \
    && apt-get -y install gettext \
    && apt-get -y install poedit \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*


RUN apt-get update \
    && apt-get -y install locales \
    && apt-get -y install gettext \
    && apt-get -y install poedit \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update && apt-get install -y openssl aptitude git
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libssl-dev \
        libcurl4-openssl-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN apt-get install -y libicu-dev libcurl4-openssl-dev && docker-php-ext-install curl
RUN docker-php-ext-install intl
RUN apt-get install -y libtidy-dev && docker-php-ext-install tidy
RUN apt-get install -y libxml2-dev && docker-php-ext-install soap
RUN apt-get install -y libzip-dev && docker-php-ext-install zip
RUN apt-get install -y && docker-php-ext-install gettext
RUN docker-php-ext-install bcmath
RUN docker-php-ext-install opcache

# Retrieve the script used to install PHP extensions from the source container.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/install-php-extensions

RUN chmod uga+x /usr/bin/install-php-extensions \
    && sync \
    && install-php-extensions bcmath exif gd imagick intl opcache pcntl pcov pdo_sqlsrv redis sqlite3


# Retrieve the script used to install PHP extensions from the source container.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/install-php-extension

#install composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php
RUN php -r "unlink('composer-setup.php');"
RUN mv composer.phar /usr/local/bin/composer

RUN pecl install openswoole
RUN echo "extension=openswoole.so" > /usr/local/etc/php/conf.d/docker-php-ext-openswoole.ini

RUN dpkg --add-architecture i386 && apt-get update && apt-get install wine32 -y
RUN apt-get install wine -y

WORKDIR /app



