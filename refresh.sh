#!/bin/bash

# Refresh the data
php artisan blueprint:erase && php artisan blueprint:build && php artisan migrate:fresh

# Notify the user
echo "====================="
echo "  Data refreshed 🎉  "
echo "====================="