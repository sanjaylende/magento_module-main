#!/bin/bash
export COMPOSER_ALLOW_SUPERUSER=1
for pair in "2.4.4:103.0.4" "2.4.5:103.0.5" "2.4.6:103.0.6" "2.4.7:103.0.7" "2.4.8:103.0.8"; do
  v=${pair%%:*}; fw=${pair#*:}; d=/tmp/m$v
  mkdir -p $d && cd $d && composer init -n --name=tmp/m >/dev/null 2>&1
  composer config repositories.mage composer https://mirror.mage-os.org/ >/dev/null 2>&1
  composer config allow-plugins true >/dev/null 2>&1
  timeout 280 composer require magento/framework:$fw magento/module-backend magento/module-integration magento/module-store magento/module-ui magento/module-catalog magento/module-eav magento/module-config --ignore-platform-reqs --no-scripts --no-interaction --no-security-blocking -W >/tmp/composer-$v.log 2>&1
  echo "== Magento $v (framework $fw)"
  AUTOLOAD=$d/vendor/autoload.php MODULE=/var/www/html/app/code/Flipick/VideoGenerator php -d error_reporting=24575 /tmp/compat-check.php 2>&1 | tail -4
done
