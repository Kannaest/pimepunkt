#!/usr/bin/env bash
set -euo pipefail

sudo a2enmod proxy proxy_http headers
sudo cp /home/kanna/pimepunkt/deploy/apache-pimepunkt.conf /etc/apache2/conf-available/pimepunkt.conf
sudo a2enconf pimepunkt
sudo apache2ctl configtest
sudo systemctl reload apache2

echo "Pimepunkt Apache proxy enabled at /pimepunkt"
