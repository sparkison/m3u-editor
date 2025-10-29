#!/bin/bash

docker compose build --no-cache && docker compose --env-file .env.docker up --remove-orphans
