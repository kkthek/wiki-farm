####################
#
# power-search / Installs a new core
#
# $SOLR_DATA_DIR
#     Directory for live / writable SOLR files, such as logs, pid files, and index data
#
####################

export POWER_SEARCH=/vagrant/solr
export SOLR_DATA_DIR=/var/solr

if [ ! -e $SOLR_DATA_DIR ]; then
  echo "SOLR not installed"
  return
fi

printf "####################  Stopping SOLR -server\n"
sudo service solr stop

printf "####################  Copying SOLR configuration files\n"
sudo mkdir $SOLR_DATA_DIR/data/mw
sudo cp -r $POWER_SEARCH/mw/* $SOLR_DATA_DIR/data/mw
sudo chown -R solr:solr $SOLR_DATA_DIR/

printf "####################  Starting SOLR -server\n"
sudo service solr start

curl -I --silent "http://localhost:8983/solr/"
sudo ls -l $SOLR_DATA_DIR/data
