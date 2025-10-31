#!/usr/bin/env bash

set -euo pipefail

ENV_FILE=".env.docker"
FULL_COMPOSE="docker-compose.yml"
VPN_COMPOSE="docker-compose.proxy-vpn.yml"

# Ensure .env.docker exists (copy from .env.example if missing)
ENV_FILE=".env.docker"
if [ ! -f "${ENV_FILE}" ]; then
    echo "-- Missing environment file, creating now..."
    cp "${ENV_FILE}.example" "${ENV_FILE}"
fi

print_menu() {
	# print directly to the controlling terminal so the menu isn't captured
	# by command substitution that collects the function's stdout
	cat <<'EOF' >/dev/tty
Select which compose file to build & run:
1) Full (standard stack: m3u-editor, m3u-proxy, redis)
2) VPN (gluetun - run stack inside the VPN namespace)
q) Quit
EOF
}

choose_from_arg_or_prompt() {
	# allow passing a choice as the first argument (full|vpn|both)
	if [ "$#" -gt 0 ] && [ -n "${1:-}" ]; then
		case "${1,,}" in
			full|1) echo 1 ; return 0 ;;
			vpn|gluetun|2) echo 2 ; return 0 ;;
			q|quit|exit) echo q ; return 0 ;;
			*) ;;
		esac
	fi

	# interactive prompt if running in a TTY (read from /dev/tty so prompt is visible)
	if [ -t 0 ] || [ -t 1 ]; then
		print_menu
		if read -rp "Enter choice [1/2/q] (default 1): " choice </dev/tty; then
			choice="${choice:-1}"
			echo "$choice"
			return 0
		fi
	fi

	# non-interactive default
	echo 1
}

run_compose() {
	local file="$1"
	echo "Building ${file} (no-cache) and starting..."
	docker compose -f "$file" build --no-cache
	docker compose --env-file "$ENV_FILE" -f "$file" up --remove-orphans
}

CHOICE=$(choose_from_arg_or_prompt "$@")

case "$CHOICE" in
	1)
		run_compose "$FULL_COMPOSE"
		;;
	2)
		run_compose "$VPN_COMPOSE"
		;;
	q|Q)
		echo "Cancelled."
		exit 0
		;;
	*)
		echo "Unrecognized choice: $CHOICE" >&2
		exit 2
		;;
esac
