FROM php:7.4-cli
RUN apt-get update && apt-get install -y openssl aptitude git
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libssl-dev \
        libcurl4-openssl-dev \
        libpcre3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd
RUN apt-get install -y libicu-dev libcurl4-openssl-dev && docker-php-ext-install curl
RUN docker-php-ext-install intl
RUN apt-get install -y libtidy-dev && docker-php-ext-install tidy
RUN apt-get install -y libxml2-dev && docker-php-ext-install soap
RUN apt-get install -y libzip-dev && docker-php-ext-install zip
RUN docker-php-ext-install bcmath
RUN docker-php-ext-install opcache

#install postgres
RUN apt-get install -y libpq-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql

#install mysql
RUN docker-php-ext-install mysqli

#install firebird extension and firebird support
RUN apt-get install -y firebird-dev
RUN git clone https://github.com/FirebirdSQL/php-firebird.git
WORKDIR php-firebird
RUN phpize
RUN CPPFLAGS=-I/usr/include/firebird ./configure
RUN make
RUN make install
RUN echo "extension=interbase.so" > /usr/local/etc/php/conf.d/docker-php-ext-interbase.ini
#install mongodb support
RUN pecl install mongodb
RUN echo "extension=mongodb.so" > /usr/local/etc/php/conf.d/docker-php-ext-mongodb.ini
#install swoole
RUN pecl install openswoole
RUN echo "extension=openswoole.so" > /usr/local/etc/php/conf.d/docker-php-ext-openswoole.ini
#install composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php
RUN php -r "unlink('composer-setup.php');"
RUN mv composer.phar /usr/local/bin/composer
#install wine for the report engine
RUN dpkg --add-architecture i386 && apt-get update && apt-get install wine32 -y
RUN apt-get install wine -y
WORKDIR /app



