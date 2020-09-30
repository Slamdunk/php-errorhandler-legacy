all: csfix static-analysis test
	@echo "Done."

vendor: composer.json composer.lock
	composer update
	touch vendor

.PHONY: csfix
csfix: vendor
	vendor/bin/php-cs-fixer fix --verbose

.PHONY: static-analysis
static-analysis: vendor
	vendor/bin/phpstan analyse

.PHONY: test
test: vendor
	vendor/bin/phpunit --coverage-text --coverage-xml=coverage/coverage-xml --log-junit=coverage/junit.xml

#.PHONY: code-coverage
#code-coverage: test
#	vendor/bin/infection --threads=$(shell nproc) --coverage=coverage --skip-initial-tests
