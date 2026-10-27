FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev && docker-php-ext-install pdo_mysql mbstring && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
COPY public/ /var/www/html/
COPY private/ /var/www/private/
COPY db/mysql.sql /var/www/db/mysql.sql
EXPOSE 10000
CMD ["bash","-c","sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
