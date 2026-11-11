#!/bin/sh
# Sweep: per CPU-niveau het hoogste aanbod (req/s) dat binnen de drempels van k6.js blijft.
# Elk niveau begint bij het aanbod dat het vorige (grotere) niveau haalde: minder CPU kan niet meer halen.
# Per run een verse stack (bin/loadtest.sh). Vasthouden is hier 30 s (HOLD_SECONDS); bevestig de uitkomst
# daarna met een volle run: APP_CPUSET=.. APP_CPUS=.. TARGET_RPS=<uitkomst> ./bin/loadtest.sh
# Resultaat: loadtest/results/sweep.txt
#
# Niveaus "cpuset cpus totaal": hele cores begrenzen het TOTAAL via cpuset (alle containers samen).
# Onder 1 core kan dat niet; dan krijgt elke container de helft (nginx en php zijn de drukke twee).
set -u
cd "$(dirname "$0")/.."

LEVELS="${LEVELS:-0-3,4,4 0-1,2,2 0,1,1 0,0.29,0.57 0,0.04,0.07}"
RATES="3500 2500 1700 1200 800 500 300 200 120 70 40 20 10 5"
export HOLD_SECONDS="${HOLD_SECONDS:-30}"
out=loadtest/results/sweep.txt
res=loadtest/results/laatste-run.txt
pick() { sed -En "$2" "$res" | head -1; }

printf '%-8s %-10s %-9s %-8s %-14s %-14s %-8s %s\n' cores_tot cpu_per_ct aanbod uitslag game_95pct order_95pct fouten dropped | tee "$out"
start=3500
for level in $LEVELS; do
  cpuset=${level%%,*}; rest=${level#*,}; per=${rest%%,*}; total=${rest#*,}
  best=niets
  for rate in $RATES; do
    [ "$rate" -gt "$start" ] && continue
    rc=0
    APP_CPUSET=$cpuset APP_CPUS=$per TARGET_RPS=$rate ./bin/loadtest.sh >/dev/null 2>&1 || rc=$?
    game=$(pick x '/name:game/ s/.*p\(95\)=([^ ]+).*/\1/p')
    order=$(pick x '/name:order_new/ s/.*p\(95\)=([^ ]+).*/\1/p')
    fail=$(pick x '/http_req_failed/ s/.*: *([0-9.]+%).*/\1/p')
    drop=$(pick x '/dropped_iterations/ s/.*: *([0-9]+) .*/\1/p')
    verdict=GEFAALD; [ "$rc" -eq 0 ] && verdict=GEHAALD
    printf '%-8s %-10s %-9s %-8s %-14s %-14s %-8s %s\n' "$total" "$per" "$rate" "$verdict" "$game" "$order" "$fail" "$drop" | tee -a "$out"
    if [ "$rc" -eq 0 ]; then best=$rate; start=$rate; break; fi
  done
  echo ">> $total cores: hoogste gehaalde aanbod = $best req/s" | tee -a "$out"
done
