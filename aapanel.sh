#!/bin/bash

echo "=========================================="
echo "    Mailpro aaPanel Deployment Script     "
echo "=========================================="

# Check if git is installed
if ! command -v git &> /dev/null; then
    echo "Git is not installed! Installing git..."
    if command -v apt-get &> /dev/null; then
        sudo apt-get update && sudo apt-get install -y git
    elif command -v yum &> /dev/null; then
        sudo yum install -y git
    fi
fi

# Make sure we are in a valid directory
if [[ "$PWD" != *"wwwroot"* ]]; then
    echo "Warning: You do not appear to be in an aaPanel website directory (/www/wwwroot/...)"
    read -p "Are you sure you want to proceed? (y/n): " proceed
    if [[ "$proceed" != "y" ]]; then
        exit 1
    fi
fi

echo "[*] Pulling latest updates from GitHub..."
git pull origin main

echo "[*] Creating uploads directories if missing..."
mkdir -p uploads/images

echo "[*] Setting ownership to www:www (aaPanel Web User)..."
sudo chown -R www:www .

echo "[*] Setting permissions for uploads folders..."
sudo chmod -R 755 uploads

echo "=========================================="
echo " Update and Deployment Complete!"
echo "=========================================="
