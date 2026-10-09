#!/bin/bash
if [ -z $1 ]; then
  php test/phpunit test
else
  php test/phpunit test/$1
fi

