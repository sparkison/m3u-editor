#!/bin/bash

./vendor/bin/sail build --no-cache && ./vendor/bin/sail --env-file .env.docker up --remove-orphans
