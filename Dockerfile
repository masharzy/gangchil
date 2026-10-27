FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev && docker-php-ext-install pdo_mysql mbstring && a2enmod rewrite && rm -rf /var/lib/apt/lists/*
RUN sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY private/ /var/www/private/
EXPOSE 10000
CMD ["apache2-foreground"]
