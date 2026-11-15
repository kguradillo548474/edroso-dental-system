FROM php:8.2-apache

RUN docker-php-ext-install mysqli

# The official php:apache image enables event MPM by default.
# Remove every enabled MPM module and enable only prefork.
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && a2enmod headers expires rewrite

COPY assets/uploads/ /opt/edroso-seed-uploads/
COPY docker/entrypoint.sh /usr/local/bin/edroso-entrypoint
RUN chmod +x /usr/local/bin/edroso-entrypoint

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["edroso-entrypoint"]
CMD ["apache2-foreground"]
