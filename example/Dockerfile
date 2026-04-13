FROM tina4stack/tina4-php:v3

WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader
COPY . .
RUN mkdir -p data src/public/uploads

EXPOSE 7145
CMD ["php", "index.php"]
