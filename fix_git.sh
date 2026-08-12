#!/bin/bash
sudo chown -R $USER:$USER .git
sudo git fetch --all
sudo git reset --hard origin/main
sudo bash aapanel.sh
