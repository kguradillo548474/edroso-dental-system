FROM php:8.2-apache

RUN docker-php-ext-install mysqli \
    && for f in /etc/apache2/mods-enabled/mpm_*.load; do \
         [ "$f" = "/etc/apache2/mods-enabled/mpm_prefork.load" ] || rm -f "$f" "${f%.load}.conf"; \
       done \
    && a2enmod mpm_prefork headers expires rewrite

COPY assets/uploads/ /opt/edroso-seed-uploads/
COPY docker/entrypoint.sh /usr/local/bin/edroso-entrypoint
RUN chmod +x /usr/local/bin/edroso-entrypoint

WORKDIR /var/www/html
EXPOSE 80
ENTRYPOINT ["edroso-entrypoint"]
CMD ["apache2-foreground"]
