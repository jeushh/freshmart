FROM node:20 AS web
WORKDIR /repo
COPY apps/web ./apps/web
RUN mkdir -p apps/api/public
WORKDIR /repo/apps/web
RUN npm ci && npm run build

FROM php:8.3-cli
RUN apt-get update && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY apps/api ./
COPY --from=web /repo/apps/api/public/app ./public/app
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY render-start.sh /render-start.sh
RUN chmod +x /render-start.sh
CMD ["/render-start.sh"]
