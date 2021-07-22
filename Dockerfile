FROM php:7.4-cli
RUN apt-get update && apt-get install -y openssl aptitude git
RUN apt-get update && apt-get install -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd
RUN apt-get install -y libicu-dev libcurl4-openssl-dev && docker-php-ext-install curl
RUN docker-php-ext-install intl
RUN apt-get install -y libtidy-dev && docker-php-ext-install tidy
RUN apt-get install -y libxml2-dev && docker-php-ext-install soap
RUN apt-get install -y libzip-dev && docker-php-ext-install zip
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
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php -r "if (hash_file('sha384', 'composer-setup.php') === '756890a4488ce9024fc62c56153228907f1545c228516cbf63f885e036d37e9a59d27d63f46af1d4d07ee0f76181c7d3') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
RUN php composer-setup.php
RUN php -r "unlink('composer-setup.php');"
RUN mv composer.phar /usr/local/bin/composer
#install wine for the report engine
RUN dpkg --add-architecture i386 && apt-get update && apt-get install wine32 -y
RUN apt-get install wine -y
WORKDIR /app



