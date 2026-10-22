#!/usr/bin/env bash
set -Eeuo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
port="${PORT:-5000}"

if [[ ! "$port" =~ ^[0-9]+$ ]] || (( port < 1 || port > 65535 )); then
    echo "PORT must be a valid TCP port number." >&2
    exit 1
fi

nginx_binary="$(readlink -f "$(command -v nginx)")"
nginx_prefix="$(dirname "$(dirname "$nginx_binary")")"
nginx_config_dir="$nginx_prefix/conf"

if [[ ! -f "$nginx_config_dir/mime.types" || ! -f "$nginx_config_dir/fastcgi_params" ]]; then
    echo "The installed Nginx configuration files could not be found." >&2
    exit 1
fi

runtime_dir="$(mktemp -d)"
php_fpm_pid=""
nginx_pid=""

cleanup() {
    if [[ -n "$nginx_pid" ]]; then
        kill "$nginx_pid" 2>/dev/null || true
        wait "$nginx_pid" 2>/dev/null || true
    fi
    if [[ -n "$php_fpm_pid" ]]; then
        kill "$php_fpm_pid" 2>/dev/null || true
        wait "$php_fpm_pid" 2>/dev/null || true
    fi
    rm -rf "$runtime_dir"
}
trap cleanup EXIT
trap 'exit 143' INT TERM

sed \
    -e "s|__APP_ROOT__|$app_root|g" \
    -e "s|__NGINX_CONFIG_DIR__|$nginx_config_dir|g" \
    -e "s|__NGINX_PID__|$runtime_dir/nginx.pid|g" \
    -e "s|__PORT__|$port|g" \
    deployment/nginx.conf.template > "$runtime_dir/nginx.conf"

sed \
    -e "s|__PHP_FPM_PID__|$runtime_dir/php-fpm.pid|g" \
    deployment/php-fpm.conf > "$runtime_dir/php-fpm.conf"

php-fpm --nodaemonize --force-stderr --fpm-config "$runtime_dir/php-fpm.conf" &
php_fpm_pid=$!

# Fail early if PHP-FPM cannot start; otherwise Nginx may bind successfully
# while every dynamic request returns 502.
sleep 0.2
if ! kill -0 "$php_fpm_pid" 2>/dev/null; then
    wait "$php_fpm_pid"
    echo "PHP-FPM exited before the web server started." >&2
    exit 1
fi

nginx -c "$runtime_dir/nginx.conf" -p "$runtime_dir" -g 'daemon off;' &
nginx_pid=$!

# Keep the container alive while both managed processes are healthy. The EXIT
# trap stops the other process if either server exits.
wait -n "$php_fpm_pid" "$nginx_pid"
