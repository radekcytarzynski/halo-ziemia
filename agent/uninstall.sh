#!/usr/bin/env bash
#
# Usuwa agenta halo-ziemia. Konfiguracja (/etc/halo-ziemia) zostaje, chyba że podasz --purge.

set -euo pipefail

PURGE=0
[[ ${1:-} == --purge ]] && PURGE=1
((EUID == 0)) || { echo "Uruchom jako root: sudo $0 $*" >&2; exit 1; }

systemctl disable --now halo-ziemia-agent.timer 2> /dev/null || true
systemctl stop halo-ziemia-agent.service 2> /dev/null || true
rm -f /etc/systemd/system/halo-ziemia-agent.service /etc/systemd/system/halo-ziemia-agent.timer
systemctl daemon-reload

rm -f /usr/local/bin/halo-ziemia-agent
rm -rf /usr/local/lib/halo-ziemia /usr/local/share/doc/halo-ziemia /var/lib/halo-ziemia

if ((PURGE == 1)); then
    rm -rf /etc/halo-ziemia
    echo "Usunięto agenta i konfigurację."
else
    echo "Usunięto agenta. Konfiguracja została w /etc/halo-ziemia (usuń: sudo $0 --purge)."
fi
echo "Pamiętaj o unieważnieniu tokenu w panelu, jeśli urządzenie nie wróci."
