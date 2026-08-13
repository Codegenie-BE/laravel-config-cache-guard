ARG PHP_VERSION=8.5

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache git unzip

RUN git config --system --add safe.directory /package

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /package
