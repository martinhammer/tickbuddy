app_name = tickbuddy
project_dir = $(CURDIR)
build_dir = $(project_dir)/build
release_dir = $(build_dir)/release
release_stage = $(release_dir)/$(app_name)
prod_install_dir = $(build_dir)/prod-install
version = $(shell xmllint --xpath "string(//info/version)" appinfo/info.xml 2>/dev/null || grep -oPm1 "(?<=<version>)[^<]+" appinfo/info.xml)
archive_name = $(app_name)-v$(version).tar.gz

.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "Tickbuddy build targets"
	@echo ""
	@echo "  make build        Build frontend + stage PHP runtime deps in build/"
	@echo "  make stage        Stage a deployable tree from the working tree (uncommitted changes)"	
	@echo "  make package      Build and produce $(archive_name) under build/release/"
	@echo "  make dev          Install all dev dependencies (npm + composer with tooling)"
	@echo "  make lint         Run all linters (PHP, ESLint, Stylelint)"
	@echo "  make test         Run PHPUnit"
	@echo "  make psalm        Run Psalm"
	@echo "  make openapi-check  Regenerate openapi.json and fail if it drifts"
	@echo "  make clean        Remove build artifacts (build/, js/, css/) — keeps dev deps installed"
	@echo "  make distclean    clean + remove node_modules/, vendor/, vendor-bin/*/vendor/"
	@echo ""
	@echo "Detected version: $(version)"

# --- Dependency installs ---------------------------------------------------

.PHONY: npm-install
npm-install:
	npm ci

# Install production-only PHP deps into an isolated directory under build/
# so the project's vendor/ (with dev tooling) is not disturbed.
.PHONY: composer-install-prod
composer-install-prod:
	rm -rf $(prod_install_dir)
	mkdir -p $(prod_install_dir)
	cp composer.json composer.lock $(prod_install_dir)/
	composer install --working-dir=$(prod_install_dir) --no-dev --no-scripts --optimize-autoloader

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
	npm run typecheck

.PHONY: test
test:
	composer test:unit

.PHONY: psalm
psalm:
	composer psalm

# Regenerate the OpenAPI spec and fail if it differs from what's committed —
# mirrors the openapi.yml GitHub Actions workflow so drift is caught locally.
.PHONY: openapi-check
openapi-check:
	composer openapi
	@if [ -n "$$(git status --porcelain openapi.json src/types/openapi 2>/dev/null)" ]; then \
		echo "OpenAPI spec is out of date. Commit the regenerated openapi.json (and src/types/openapi if present)."; \
		git --no-pager diff -- openapi.json src/types/openapi; \
		exit 1; \
	fi

.PHONY: check
check: lint psalm openapi-check test

# --- Packaging -------------------------------------------------------------
#
# Produces a Nextcloud-app-store-compatible tarball:
#   build/release/$(app_name)-v$(version).tar.gz
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
	cp -r $(prod_install_dir)/vendor $(release_stage)/
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
	# Build the tarball
	find $(release_stage) -name '.DS_Store' -delete
	tar -czf $(release_dir)/$(archive_name) -C $(release_dir) $(app_name)/
	@echo ""
	@echo "Built $(release_dir)/$(archive_name)"
	@du -h $(release_dir)/$(archive_name) | awk '{print "Size: " $$1}'
	@tar -tzf $(release_dir)/$(archive_name) | wc -l | awk '{print $$1 " files"}'

# --- Staging from the working tree ----------------------------------------
#
# Produces the same layout as `package` but reads from the working tree so
# uncommitted changes are included. Output:
#   $(release_stage)/
# rsync to a Nextcloud test server, e.g.:
#   rsync -a --delete build/release/$(app_name)/ user@host:/var/www/nextcloud/apps/$(app_name)/

.PHONY: stage
stage: build
	rm -rf $(release_dir)
	mkdir -p $(release_stage)
	rsync -a \
	      --exclude='.git/' --exclude='node_modules/' --exclude='vendor/' \
	      --exclude='vendor-bin/' --exclude='build/' --exclude='src/' \
	      --exclude='tests/' --exclude='.github/' \
	      --exclude='.eslintrc.cjs' --exclude='.nvmrc' \
	      --exclude='.php-cs-fixer.cache' --exclude='.php-cs-fixer.dist.php' \
	      --exclude='.gitignore' \
	      --exclude='package.json' --exclude='package-lock.json' \
	      --exclude='vite.config.ts' --exclude='tsconfig.json' \
	      --exclude='stylelint.config.cjs' --exclude='rector.php' \
	      --exclude='psalm.xml' --exclude='composer.json' --exclude='composer.lock' \
	      --exclude='openapi.json' --exclude='CLAUDE.md' \
	      --exclude='CHANGELOG.md' --exclude='CODE_OF_CONDUCT.md' \
	      --exclude='Makefile' \
	      ./ $(release_stage)/
	cp -r $(prod_install_dir)/vendor $(release_stage)/
	find $(release_stage)/img -mindepth 1 -maxdepth 1 \
	     ! -name 'app.svg' ! -name 'app-dark.svg' -exec rm -rf {} +
	@echo ""
	@echo "Staged at $(release_stage)/"
	@echo "Deploy with: rsync -a --delete $(release_stage)/ <user>@<host>:/path/to/nextcloud/apps/$(app_name)/"

# --- Cleaning --------------------------------------------------------------

# Remove build outputs only — the project's vendor/ stays so dev tooling
# (psalm, phpunit, php-cs-fixer) keeps working without a re-install.
.PHONY: clean
clean:
	rm -rf $(build_dir) js css

# Full reset: also remove all installed dependencies. After this you'll need
# `make dev` (or `composer install && npm install`) before linters/tests work.
.PHONY: distclean
distclean: clean
	rm -rf node_modules vendor vendor-bin/*/vendor
