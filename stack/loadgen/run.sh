#!/bin/sh
# About ten requests a second: mostly checkouts, some searches, a few unknown paths.
while true; do
  for i in 1 2 3 4 5 6; do curl -s -o /dev/null -X POST http://app:8080/checkout & done
  for i in 1 2 3; do curl -s -o /dev/null http://app:8080/search & done
  curl -s -o /dev/null http://app:8080/favicon.ico &
  wait
  sleep 0.5
done
