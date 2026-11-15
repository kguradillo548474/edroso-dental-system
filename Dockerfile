FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod headers expires rewrite

# Keep uploaded clinic files available when Railway mounts a persistent volume.
COPY assets/uploads/ /opt/edroso-seed-uploads/
COPY docker/entrypoint.sh /usr/local/bin/edroso-entrypoint
RUN chmod +x /usr/local/bin/edroso-entrypoint

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["edroso-entrypoint"]
CMD ["apache2-foreground"]
