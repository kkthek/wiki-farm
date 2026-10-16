#!/usr/bin/env bash

printf "\n\n\n#################### INSTALLATION\n"
date

# Repo
sudo add-apt-repository multiverse
sudo add-apt-repository ppa:ondrej/php
sudo apt update

# Guest extensions
sudo apt -y install virtualbox-guest-dkms


printf "\n\n\n#################### Install apache\n"
date
sudo apt -y install apache2
sudo ufw allow "Apache Full"
sudo a2enmod rewrite
sudo sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf


printf "\n\n\n#################### Install PHP\n"
date
sudo apt -y install php8.3 php8.3-cli php8.3-{bz2,curl,mbstring,intl,gd,zip,xml,mysql,xdebug}
sudo apt -y install php8.3-fpm
sudo a2enconf php8.3-fpm

printf "\n\n\n#################### Install composer\n"
date
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo apt -y install unzip


printf "\n\n\n#################### Get Powersuche\n"
date
sudo wget https://downloads.diqa-pm.com/free/power-search/2.4.1/powersearch-2.4.1.zip


printf "\n\n\n#################### Java\n"
date
sudo apt -y install default-jre

printf "\n\n\n#################### Tools\n"
sudo apt -y install dos2unix
sudo dos2unix /vagrant/*.sh


printf "\n\n\n#################### Configure Apache changes\n"
date
sudo sh -c "echo '127.0.0.1   fs2.localhost' >> /etc/hosts"
sudo sh -c "echo '127.0.0.1   fs2.local' >> /etc/hosts"


printf "\n\n\n#################### Start services\n"
sudo service apache2 start


printf "\n\n\n#################### Show service status\n"
date
sudo service apache2 status

printf "\n\n\n#################### Finished bootstrap.sh\n"
date
