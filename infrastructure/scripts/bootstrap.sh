#!/usr/bin/env bash
# Instala o WordPress, ativa o plugin e cria dados de demonstração.
# Idempotente: pode rodar várias vezes sem quebrar nada.
set -euo pipefail

WP="wp --allow-root --path=/var/www/html"

echo "==> Aguardando arquivos do WordPress..."
until [ -f /var/www/html/wp-config.php ]; do sleep 2; done

if ! $WP core is-installed 2>/dev/null; then
  echo "==> Instalando WordPress"
  $WP core install \
    --url="${WP_URL}" \
    --title="EquiGov AI" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
fi

$WP language core install pt_BR --activate || true
$WP rewrite structure '/%postname%/' --hard
$WP plugin activate equigov-core

echo "==> Dados de demonstração"
$WP equigov seed

echo "==> Health check"
$WP eval 'echo wp_json_encode(rest_do_request("/equigov/v1/health")->get_data()), PHP_EOL;'

echo "==> Pronto: ${WP_URL}"
