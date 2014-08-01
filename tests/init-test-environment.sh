#!/bin/bash

set -e
set -x

# Configuration
read -p "Username (root):" DB_USER
stty -echo
read -p "Password: " DB_PASS; echo
stty echo
read -p "Tablename (gfdigest_test): " DB_NAME

DB_NAME=${DB_NAME:-gfdigest_test}
DB_USER=${DB_USER:-root}
DB_HOST="localhost:/run/mysqld/mysqld.sock"

# The working directory
WORKING_DIR=/tmp/gfdigest_test
rm -rf $WORKING_DIR
mkdir -p $WORKING_DIR

cd $WORKING_DIR

wget -nv -O wordpress.tar.gz https://github.com/WordPress/WordPress/tarball/3.5.2
mkdir wordpress
tar --strip-components=1 -zxmf wordpress.tar.gz -C wordpress
svn co --ignore-externals --quiet http://unit-tests.svn.wordpress.org/trunk/ wordpress_tests

cd wordpress_tests

cp wp-tests-config-sample.php wp-tests-config.php
sed -i "s:dirname( __FILE__ ) . '/wordpress/':'$WORKING_DIR/wordpress/':" wp-tests-config.php
sed -i "s/yourdbnamehere/$DB_NAME/" wp-tests-config.php
sed -i "s/yourusernamehere/$DB_USER/" wp-tests-config.php
sed -i "s/yourpasswordhere/$DB_PASS/" wp-tests-config.php
sed -i "s|localhost|${DB_HOST}|" wp-tests-config.php

cd ../wordpress/wp-content/plugins/
wget http://codeseekah.com/etc/gravityforms_${GFVERSION:-1.8}.zip -O gravityforms.zip
unzip gravityforms.zip

cd -

# Remove any old tables
mysqladmin drop -f $DB_NAME --user="$DB_USER" --password="$DB_PASS" || true
mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"
