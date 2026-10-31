#!/usr/bin/env bash
# Drop outside connections to ports nothing outside needs: Coolify's 8000, 6001, 6002 (published by Docker)
# and two admin panels that listen on the host itself, 2053 and 20128.
#
# Usage: fahares-docker-firewall.sh <apply|remove|status>
#   apply   add the DROP rules to DOCKER-USER and INPUT (iptables and, if DOCKER-USER exists, ip6tables); safe to run again
#   remove  delete the rules
#   status  print DOCKER-USER and this script's INPUT rules
#
# Docker DNATs a published port before the filter table sees it, so ufw's INPUT rules never apply and
# DOCKER-USER sees the container port (8000 -> 8080). The rules therefore match the port the client asked
# for (conntrack --ctorigdstport), and only on the external interface, so containers keep reaching Coolify.
# Host ports are dropped in INPUT, again only on the external interface, so local callers and traffic
# the host forwards to itself (it arrives on lo) still get through. ufw is not active on this host.
# Neither chain is saved across reboots; fahares-docker-firewall.service runs "apply" after docker starts.
set -Eeuo pipefail

PORTS="${FAHARES_BLOCKED_PORTS:-8000 6001 6002}"
HOST_PORTS="${FAHARES_BLOCKED_HOST_PORTS:-2053 20128}"
IFACE="${FAHARES_EXT_IFACE:-$(ip -o route show default | awk '{print $5; exit}')}"
[ -n "$IFACE" ] || { echo "No external interface found" >&2; exit 2; }

rule() { # <port>
    echo "-i $IFACE -p tcp -m conntrack --ctorigdstport $1 --ctdir ORIGINAL -m comment --comment fahares-docker-firewall -j DROP"
}

host_rule() { # <port>
    echo "-i $IFACE -p tcp --dport $1 -m comment --comment fahares-docker-firewall -j DROP"
}

tables() {
    echo iptables
    if ip6tables -S DOCKER-USER >/dev/null 2>&1; then echo ip6tables; fi
}

apply() {
    iptables -S DOCKER-USER >/dev/null 2>&1 || { echo "DOCKER-USER chain missing; is docker running?" >&2; exit 1; }
    for t in $(tables); do
        for p in $PORTS; do
            # shellcheck disable=SC2046
            $t -C DOCKER-USER $(rule "$p") 2>/dev/null || $t -I DOCKER-USER $(rule "$p")
        done
        for p in $HOST_PORTS; do
            # shellcheck disable=SC2046
            $t -C INPUT $(host_rule "$p") 2>/dev/null || $t -I INPUT $(host_rule "$p")
        done
    done
}

remove() {
    for t in $(tables); do
        for p in $PORTS; do
            # shellcheck disable=SC2046
            while $t -C DOCKER-USER $(rule "$p") 2>/dev/null; do $t -D DOCKER-USER $(rule "$p"); done
        done
        for p in $HOST_PORTS; do
            # shellcheck disable=SC2046
            while $t -C INPUT $(host_rule "$p") 2>/dev/null; do $t -D INPUT $(host_rule "$p"); done
        done
    done
}

status() {
    for t in $(tables); do echo "== $t"; $t -S DOCKER-USER; $t -S INPUT | grep fahares-docker-firewall || true; done
}

case "${1:-}" in
    apply) apply; status ;;
    remove) remove; status ;;
    status) status ;;
    *) echo "Usage: $0 <apply|remove|status>" >&2; exit 2 ;;
esac
