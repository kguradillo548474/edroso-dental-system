FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && a2enmod headers expires rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html

# Keep uploaded clinic files available when Railway mounts a persistent volume.
COPY assets/uploads/ /opt/edroso-seed-uploads/
COPY docker/entrypoint.sh /usr/local/bin/edroso-entrypoint
RUN chmod +x /usr/local/bin/edroso-entrypoint \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["edroso-entrypoint"]
CMD ["apache2-foreground"]
