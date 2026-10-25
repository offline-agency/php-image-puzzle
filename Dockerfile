# syntax=docker/dockerfile:1
#
# Development and test images. Base images are pinned by digest; Dependabot
# (docker ecosystem) proposes updates.
#
#   minimal  PHP + GD (PNG/JPEG/WebP/FreeType) + Composer only: no exif,
#            pcntl or zip. Proves the optional-extension fallbacks.
#   dev      minimal + pcntl, exif, zip and pcov for the full suite and coverage.
#
# Select the PHP version with --build-arg PHP_IMAGE=... (see compose.yaml).

ARG PHP_IMAGE=php:8.5-cli-trixie@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663

FROM composer:2.10@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer

FROM ${PHP_IMAGE} AS minimal
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
ARG UID=1000
ARG GID=1000
# -o: the host IDs may already exist in the image (macOS users are in group
# 20, which Debian calls dialout).
RUN groupadd -o --gid "${GID}" app && useradd -o --uid "${UID}" --gid "${GID}" --create-home app
ENV COMPOSER_HOME=/home/app/.composer
# Named vendor volumes inherit this ownership on first use.
RUN mkdir -p /app/vendor && chown -R app:app /app
WORKDIR /app
USER app

FROM minimal AS dev
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl exif zip \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
USER app
