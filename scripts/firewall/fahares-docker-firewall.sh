#!/usr/bin/env bash
# Drop outside connections to ports Docker publishes but nothing outside needs (Coolify's 8000, 6001, 6002).
#
# Usage: fahares-docker-firewall.sh <apply|remove|status>
#   apply   add the DROP rules to DOCKER-USER (iptables and, if the chain exists, ip6tables); safe to run again
#   remove  delete the rules
#   status  print DOCKER-USER
#
# Docker DNATs a published port before the filter table sees it, so ufw's INPUT rules never apply and
# DOCKER-USER sees the container port (8000 -> 8080). The rules therefore match the port the client asked
# for (conntrack --ctorigdstport), and only on the external interface, so containers keep reaching Coolify.
# DOCKER-USER is not saved across reboots; fahares-docker-firewall.service runs "apply" after docker starts.
set -Eeuo pipefail

PORTS="${FAHARES_BLOCKED_PORTS:-8000 6001 6002}"
IFACE="${FAHARES_EXT_IFACE:-$(ip -o route show default | awk '{print $5; exit}')}"
[ -n "$IFACE" ] || { echo "No external interface found" >&2; exit 2; }

rule() { # <port>
    echo "-i $IFACE -p tcp -m conntrack --ctorigdstport $1 --ctdir ORIGINAL -m comment --comment fahares-docker-firewall -j DROP"
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
    done
}

remove() {
    for t in $(tables); do
        for p in $PORTS; do
            # shellcheck disable=SC2046
            while $t -C DOCKER-USER $(rule "$p") 2>/dev/null; do $t -D DOCKER-USER $(rule "$p"); done
        done
    done
}

status() {
    for t in $(tables); do echo "== $t"; $t -S DOCKER-USER; done
}

case "${1:-}" in
    apply) apply; status ;;
    remove) remove; status ;;
    status) status ;;
    *) echo "Usage: $0 <apply|remove|status>" >&2; exit 2 ;;
esac
