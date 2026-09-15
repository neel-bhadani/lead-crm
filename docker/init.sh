#!/bin/sh
set -eu
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public bootstrap/cache vendor
cp -a /opt/lead-crm-public/. public/
ln -sfn /var/www/html/storage/app/public public/storage
chown -R www-data:www-data storage bootstrap/cache vendor
# Node owns generated assets; PHP and Nginx only need read access.
chown -R 1000:1000 public
exec gosu www-data composer install --no-interaction --prefer-dist
