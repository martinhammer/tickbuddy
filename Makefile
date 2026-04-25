app_name = tickbuddy
project_dir = $(CURDIR)
build_dir = $(project_dir)/build
release_dir = $(build_dir)/release
release_stage = $(release_dir)/$(app_name)
version = $(shell xmllint --xpath "string(//info/version)" appinfo/info.xml 2>/dev/null || grep -oPm1 "(?<=<version>)[^<]+" appinfo/info.xml)
zip_name = $(app_name)-v$(version).zip

.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "Tickbuddy build targets"
	@echo ""
	@echo "  make build        Build frontend + install PHP runtime deps"
	@echo "  make package      Build and produce $(zip_name) under build/release/"
	@echo "  make dev          Install all dev dependencies (npm + composer with tooling)"
	@echo "  make lint         Run all linters (PHP, ESLint, Stylelint)"
	@echo "  make test         Run PHPUnit"
	@echo "  make psalm        Run Psalm"
	@echo "  make clean        Remove build artifacts (js/, css/, vendor/, build/)"
	@echo "  make distclean    clean + remove node_modules/ and vendor-bin/*/vendor/"
	@echo ""
	@echo "Detected version: $(version)"

# --- Dependency installs ---------------------------------------------------

.PHONY: npm-install
npm-install:
	npm ci

.PHONY: composer-install-prod
composer-install-prod:
	composer install --no-dev --no-scripts --optimize-autoloader

.PHONY: composer-install-dev
composer-install-dev:
	composer install

# --- Builds ----------------------------------------------------------------

.PHONY: build-frontend
build-frontend: npm-install
	npm run build

.PHONY: build
build: build-frontend composer-install-prod

.PHONY: dev
dev:
	npm install
	composer install

# --- Quality gates ---------------------------------------------------------

.PHONY: lint
lint:
	composer lint
	composer cs:check
	npm run lint
	npm run stylelint

.PHONY: test
test:
	composer test:unit

.PHONY: psalm
psalm:
	composer psalm

.PHONY: check
check: lint psalm test

# --- Packaging -------------------------------------------------------------
#
# Produces a Nextcloud-app-store-compatible zip:
#   build/release/$(app_name)-v$(version).zip
# containing a single top-level $(app_name)/ directory.
#
# Sources files from `git archive` so untracked or local-only files are
# never included. Adds the build artifacts (js/, css/, vendor/) on top.

.PHONY: package
package: build
	@command -v git >/dev/null || { echo "git is required"; exit 1; }
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "Warning: working tree has uncommitted changes — release will reflect HEAD, not the working tree."; \
	fi
	rm -rf $(release_dir)
	mkdir -p $(release_stage)
	git archive --format=tar --prefix=$(app_name)/ HEAD | tar -x -C $(release_dir)
	# Layer in build artifacts that are gitignored
	cp -r js $(release_stage)/
	cp -r css $(release_stage)/
	cp -r vendor $(release_stage)/
	# Strip dev-only files that are tracked but should not ship
	rm -rf $(release_stage)/src \
	       $(release_stage)/tests \
	       $(release_stage)/vendor-bin \
	       $(release_stage)/.github
	# Keep only app.svg and app-dark.svg in img/ (drop screenshots etc.)
	find $(release_stage)/img -mindepth 1 -maxdepth 1 \
	     ! -name 'app.svg' ! -name 'app-dark.svg' -exec rm -rf {} +
	rm -f  $(release_stage)/package.json \
	       $(release_stage)/package-lock.json \
	       $(release_stage)/vite.config.ts \
	       $(release_stage)/tsconfig.json \
	       $(release_stage)/stylelint.config.cjs \
	       $(release_stage)/rector.php \
	       $(release_stage)/psalm.xml \
	       $(release_stage)/composer.json \
	       $(release_stage)/composer.lock \
	       $(release_stage)/openapi.json \
	       $(release_stage)/CLAUDE.md \
	       $(release_stage)/mobile_instructions.md \
	       $(release_stage)/Makefile \
	       $(release_stage)/.eslintrc.cjs \
	       $(release_stage)/.nvmrc \
	       $(release_stage)/.php-cs-fixer.dist.php \
	       $(release_stage)/.gitignore
	# Build the zip
	cd $(release_dir) && zip -r -q $(zip_name) $(app_name)/ -x '*.DS_Store'
	@echo ""
	@echo "Built $(release_dir)/$(zip_name)"
	@du -h $(release_dir)/$(zip_name) | awk '{print "Size: " $$1}'
	@unzip -l $(release_dir)/$(zip_name) | tail -1

# --- Cleaning --------------------------------------------------------------

.PHONY: clean
clean:
	rm -rf $(build_dir) js css vendor

.PHONY: distclean
distclean: clean
	rm -rf node_modules vendor-bin/*/vendor
