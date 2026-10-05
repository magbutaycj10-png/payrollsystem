#!/bin/sh
set -e
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s#:80>#:${PORT}>#" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
