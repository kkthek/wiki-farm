####################
#
# power-search
#
# $SOLR_DATA_DIR
#     Directory for live / writable SOLR files, such as logs, pid files, and index data
# $SOLR_INSTALL_DIR
#     Directory to extract the SOLR installation archive
#
####################

export POWER_SEARCH=/vagrant/solr
export SOLR_DATA_DIR=/var/solr
export SOLR_INSTALL_DIR=/opt/solr

if [ -e $SOLR_DATA_DIR ]; then
  echo "SOLR already installed"
  return
fi

if [ ! -e $POWER_SEARCH/solr-8.11.3.zip ]; then
  sudo wget -O /tmp/solr-8.11.3.zip "https://www.apache.org/dyn/closer.lua/lucene/solr/8.11.3/solr-8.11.3.zip?action=download"
  sudo mv /tmp/solr-8.11.3.zip $POWER_SEARCH
fi

printf "\n\n\n#################### Deleting old SOLR installation and old indexex\n"
sudo service solr stop
sudo rm -R $SOLR_DATA_DIR
sudo rm -R $SOLR_INSTALL_DIR

printf "####################  Installing new SOLR server\n"
sudo dos2unix $POWER_SEARCH/install_solr_service.sh
sudo mkdir $SOLR_DATA_DIR
sudo $POWER_SEARCH/install_solr_service.sh $POWER_SEARCH/solr*.zip -n -f
sudo chown -R solr:solr $SOLR_INSTALL_DIR/

printf "####################  Starting SOLR -server\n"
sudo service solr start

curl -I --silent "http://localhost:8983/solr/"
sudo ls -l $SOLR_DATA_DIR/data
