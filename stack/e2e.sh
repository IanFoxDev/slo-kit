#!/bin/sh
# Starts the stack, checks /metrics against the SLO file, makes checkouts fail and waits for
# the page alert of the availability SLO. Needs Docker and `composer install` done.
set -eu
cd "$(dirname "$0")"
compose="docker compose -f compose.yaml"
trap '$compose down -v >/dev/null 2>&1' EXIT

$compose up -d
for i in $(seq 1 60); do
  curl -sf http://localhost:19090/api/v1/rules >/dev/null 2>&1 && curl -sf http://localhost:18080/metrics | grep -q shop_http_requests_total && break
  sleep 2
done

php ../bin/slo-kit check slo.yaml --metrics=http://localhost:18080/metrics

curl -sf 'http://localhost:18080/chaos?errors=0.3' >/dev/null
for i in $(seq 1 60); do
  if curl -s http://localhost:19090/api/v1/alerts | php -r '
      $alerts = json_decode(stream_get_contents(STDIN), true)["data"]["alerts"] ?? [];
      foreach ($alerts as $a) {
          if ($a["labels"]["alertname"] === "ShopCheckoutAvailabilityBudgetBurn" && $a["labels"]["severity"] === "page" && $a["state"] === "firing") {
              exit(0);
          }
      }
      exit(1);'; then
    echo "page alert firing after $((i * 5)) s of errors"
    exit 0
  fi
  sleep 5
done
echo "the page alert did not fire within 5 minutes" >&2
curl -s http://localhost:19090/api/v1/alerts >&2
exit 1
