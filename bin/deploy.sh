#!/usr/bin/env bash
# Script de arranque para Render: cachea config, corre migraciones
# y arranca el servidor. Se ejecuta en cada deploy.
set -e

echo "==> Cacheando configuración..."
php artisan config:cache

echo "==> Cacheando rutas..."
php artisan route:cache

echo "==> Cacheando vistas..."
php artisan view:cache

echo "==> Ejecutando migraciones..."
php artisan migrate --force

echo "==> Sembrando roles y administrador configurado (idempotente)..."
php artisan db:seed --force

echo "==> Arrancando servidor en el puerto ${PORT:-10000}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
