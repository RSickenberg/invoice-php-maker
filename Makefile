# Executables (local)
PHP_EXEC      = php
COMPOSER_EXEC = composer

# Misc
.DEFAULT_GOAL = help
NUMBER        =
ARGS          =
.PHONY        : help install update fonts generate mark-paid delete lint lint-check

## —— 🧾 The Invoice PHP Maker Makefile 🧾 ——————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Composer 🧙 ——————————————————————————————————————————————
install: ## Install Composer dependencies
	@$(COMPOSER_EXEC) install

update: ## Update Composer dependencies
	@$(COMPOSER_EXEC) update

fonts: ## Rebuild TCPDF standard fonts (runs automatically after install/update)
	@$(PHP_EXEC) bin/build-fonts.php

## —— Invoice 🧾 ——————————————————————————————————————————————
generate: ## Generate a new invoice (interactive)
	@$(PHP_EXEC) bin/console invoice:generate $(ARGS)

mark-paid: ## Mark an invoice as paid, e.g. make mark-paid NUMBER=2026-001
	@$(PHP_EXEC) bin/console invoice:mark-paid $(NUMBER)

delete: ## Delete an invoice (ledger entry and PDF), e.g. make delete NUMBER=2026-001
	@$(PHP_EXEC) bin/console invoice:delete $(NUMBER)

## —— Quality ✅ ——————————————————————————————————————————————
lint: ## Fix code style with php-cs-fixer
	@$(PHP_EXEC) vendor/bin/php-cs-fixer fix

lint-check: ## Check code style without fixing (CI-friendly)
	@$(PHP_EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff
