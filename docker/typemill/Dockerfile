FROM php:8.3-apache

ARG TYPEMILL_VERSION=v2.23.0

# Install OS dependencies required (libzip-dev enables ZipArchive for exports and folder ZIP downloads)
RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y git unzip zlib1g-dev libpng-dev libjpeg-dev libzip-dev && \
    rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite && \
    echo "ServerName 127.0.0.1" >> /etc/apache2/apache2.conf

# Configure GD with JPEG support and install GD + zip
RUN docker-php-ext-configure gd --with-jpeg && \
    docker-php-ext-install gd zip

WORKDIR /var/www/html
RUN git clone --depth 1 --branch ${TYPEMILL_VERSION} https://github.com/typemill/typemill.git /var/www/html

RUN [ -f system/typemill/author/js/vue.js ] \
 && [ -f system/typemill/author/js/vue.global.prod.js ] \
 && mv system/typemill/author/js/vue.global.prod.js system/typemill/author/js/vue.js

# Install server dependencies (like Composer)
RUN chmod +x /var/www/html/docker-utils/install-composer && \
    /var/www/html/docker-utils/install-composer && \
    ./composer.phar update && \
    chmod +x /var/www/html/docker-utils/init-server

# Create a default copy of content and theme in case of empty directories binding
RUN mkdir -p /var/www/html/content.default/ && \
    cp -R /var/www/html/content/* /var/www/html/content.default/ && \
    mkdir -p /var/www/html/themes.default/ && \
    cp -R /var/www/html/themes/* /var/www/html/themes.default/ && \
    mkdir -p /var/www/html/media.default/ && \
    cp -R /var/www/html/media/* /var/www/html/media.default/ && \
    mkdir -p /var/www/html/settings.default/ && \
    cp -R /var/www/html/settings/* /var/www/html/settings.default/

VOLUME /var/www/html/settings
VOLUME /var/www/html/media
VOLUME /var/www/html/cache
VOLUME /var/www/html/plugins
VOLUME /var/www/html/data
VOLUME /var/www/html/content
VOLUME /var/www/html/themes

CMD ["/var/www/html/docker-utils/init-server"]
