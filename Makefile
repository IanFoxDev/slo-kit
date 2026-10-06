# The Redis storage tests run when SLOKIT_REDIS is set; make redis-up starts the Redis it points at.
export SLOKIT_REDIS ?= 127.0.0.1:63791

.PHONY: test stan cs cs-fix check redis-up redis-down

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --no-progress

cs:
	vendor/bin/php-cs-fixer check --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

check: cs stan test

redis-up:
	docker run -d --rm --name slokit-redis -p 63791:6379 redis:7-alpine
	for i in $$(seq 1 30); do docker exec slokit-redis redis-cli ping >/dev/null 2>&1 && exit 0; sleep 1; done; exit 1

redis-down:
	docker rm -f slokit-redis
