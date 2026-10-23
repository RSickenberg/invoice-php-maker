# Executables (local)
PHP_EXEC      = php
COMPOSER_EXEC = composer

# Misc
.DEFAULT_GOAL = help
NUMBER        =
ID            =
ARGS          =
.PHONY        : help install update fonts generate list mark-paid delete edit regenerate edit-client lint lint-check

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

list: ## List all invoices (add ARGS=--unpaid for open ones only)
	@$(PHP_EXEC) bin/console invoice:list $(ARGS)

mark-paid: ## Mark an invoice as paid (interactive; or NUMBER=2026-001)
	@$(PHP_EXEC) bin/console invoice:mark-paid $(NUMBER)

edit: ## Edit an invoice (date, term, language, tasks) and regenerate its PDF (interactive; or NUMBER=2026-001)
	@$(PHP_EXEC) bin/console invoice:edit $(NUMBER)

regenerate: ## Regenerate an invoice's PDF from stored data, e.g. make regenerate NUMBER=2026-001
	@$(PHP_EXEC) bin/console invoice:edit $(NUMBER) --regenerate

delete: ## Delete an invoice (ledger entry and PDF), e.g. make delete NUMBER=2026-001
	@$(PHP_EXEC) bin/console invoice:delete $(NUMBER)

edit-client: ## Edit an existing client (interactive; or ID=acme-sa)
	@$(PHP_EXEC) bin/console client:edit $(ID)

## —— Quality ✅ ——————————————————————————————————————————————
lint: ## Fix code style with php-cs-fixer
	@$(PHP_EXEC) vendor/bin/php-cs-fixer fix

lint-check: ## Check code style without fixing (CI-friendly)
	@$(PHP_EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff
