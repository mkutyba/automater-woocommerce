#!/usr/bin/env bash

composer install
zip -r automater-pl.zip includes languages lib vendor automater-pl.php index.php readme.txt
