sudo apt update
sudo apt install apt-transport-https curl gnupg -y

printf "####################  Adding repository for elasticsearch\n"
curl -fsSL https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/8.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-8.x.list
sudo apt update

printf "####################  Installing elasticsearch\n"
sudo apt install elasticsearch -y
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch

# check installation
# curl -X GET "https://localhost:9200" --insecure --user elastic:<password>