# --- Shared: nextcloud plugin, app-project-files skill. Keep identical across apps. ---
# Everything app-specific comes from appinfo/info.xml; app-only targets go
# below the App-specific line at the end.

info_xml = appinfo/info.xml
xml_value = $(shell xmllint --xpath "string(/info/$(1))" $(info_xml) 2>/dev/null || grep -oPm1 "(?<=<$(1)>)[^<]+" $(info_xml))

app_name := $(call xml_value,id)
app_title := $(call xml_value,name)
version := $(call xml_value,version)

project_dir = $(CURDIR)
build_dir = $(project_dir)/build
tree_dir = $(build_dir)/tree
prod_install_dir = $(build_dir)/prod-install
release_dir = $(build_dir)/release
release_stage = $(release_dir)/$(app_name)
archive_name = $(app_name)-v$(version).tar.gz

.DEFAULT_GOAL := help

.PHONY: help
help:
	@echo "$(app_title) ($(app_name) $(version))"
	@echo ""
	@echo "  make dev          Install all dev dependencies (npm + composer with tooling)"
	@echo "  make build        Build the frontend + stage PHP runtime deps in build/"
	@echo "  make lint         PHP lint + coding standard, ESLint, Stylelint, vue-tsc"
	@echo "  make psalm        Psalm"
	@echo "  make test         PHPUnit, plus Vitest when the app has a vitest config"
	@echo "  make openapi      Regenerate openapi.json and fail if it differs from the committed copy"
	@echo "  make check        lint + psalm + test + openapi"
	@echo "  make stage        Deployable tree in build/release/$(app_name)/ from the working tree"
	@echo "  make package      Release tarball build/release/$(archive_name) from HEAD"
	@echo "  make appstore     package, copied to build/artifacts/$(app_name).tar.gz (org workflow layout)"
	@echo "  make clean        Remove build/, js/, css/ (keeps dev dependencies)"
	@echo "  make distclean    clean + node_modules/, vendor/, vendor-bin/*/vendor/"

# --- Dependencies -----------------------------------------------------------

.PHONY: npm-install
npm-install:
	npm ci

# Production PHP dependencies go to an isolated directory, so the project's
# vendor/ (with dev tooling) is not disturbed.
.PHONY: composer-install-prod
composer-install-prod:
	rm -rf $(prod_install_dir)
	mkdir -p $(prod_install_dir)
	cp composer.json composer.lock $(prod_install_dir)/
	composer install --working-dir=$(prod_install_dir) --no-dev --no-scripts --optimize-autoloader

.PHONY: dev
dev:
	npm install
	composer install

# --- Builds -----------------------------------------------------------------

.PHONY: build-frontend
build-frontend: npm-install
	npm run build

.PHONY: build
build: build-frontend composer-install-prod

# --- Quality gates ----------------------------------------------------------
# npm script names are the ones the org CI templates call (ts:check, test).

.PHONY: lint
lint:
	composer lint
	composer cs:check
	npm run lint
	npm run stylelint
	npm run ts:check

.PHONY: psalm
psalm:
	composer psalm

.PHONY: test
test:
	composer test:unit
	@if ls vitest.config.* >/dev/null 2>&1; then npm test; \
	else echo "No vitest config: no frontend tests."; fi

# Same check as the openapi CI workflow, so drift is caught locally.
.PHONY: openapi
openapi:
	@if [ ! -f openapi.json ]; then echo "No openapi.json: nothing to check."; exit 0; fi; \
	composer openapi && \
	if [ -n "$$(git status --porcelain -- 'openapi*.json' src/types/openapi 2>/dev/null)" ]; then \
		echo "openapi.json is out of date: commit the regenerated files."; \
		git --no-pager diff --stat -- 'openapi*.json' src/types/openapi; \
		exit 1; \
	fi

.PHONY: check
check: lint psalm test openapi

# --- Release tree -------------------------------------------------------------
# Both stage and package fill $(tree_dir) with the app's files plus the build
# output, then copy it to $(release_stage) without what .nextcloudignore lists.
# One exclusion list for both; the tree's own copy of .nextcloudignore is used,
# so package follows the committed one.

define assemble_release
	cp -r js css $(tree_dir)/
	rm -rf $(tree_dir)/vendor
	cp -r $(prod_install_dir)/vendor $(tree_dir)/
	@test -f $(tree_dir)/.nextcloudignore || { echo ".nextcloudignore is missing"; exit 1; }
	rsync -a --exclude-from=$(tree_dir)/.nextcloudignore $(tree_dir)/ $(release_stage)/
	find $(release_stage) -name '.DS_Store' -delete
	rm -rf $(tree_dir)
endef

.PHONY: release-dirs
release-dirs:
	rm -rf $(tree_dir) $(release_dir)
	mkdir -p $(tree_dir) $(release_stage)

# Working tree, uncommitted changes included; gitignored files left out.
# rsync the result to a test server:
#   rsync -a --delete build/release/<app>/ <user>@<host>:<nextcloud>/apps/<app>/
.PHONY: stage
stage: build release-dirs
	git ls-files -z --cached --others --exclude-standard \
		| rsync -a --from0 --files-from=- --ignore-missing-args ./ $(tree_dir)/
	$(assemble_release)
	@echo "Staged at $(release_stage)/ ($$(find $(release_stage) -type f | wc -l) files)"

# Committed HEAD only: an app-store-compatible tarball with one top-level
# $(app_name)/ directory.
.PHONY: package
package: build release-dirs
	@if [ -n "$$(git status --porcelain)" ]; then \
		echo "Warning: uncommitted changes. The package reflects HEAD, not the working tree."; \
	fi
	git archive --format=tar HEAD | tar -x -C $(tree_dir)
	$(assemble_release)
	tar -czf $(release_dir)/$(archive_name) -C $(release_dir) $(app_name)/
	@echo "Built $(release_dir)/$(archive_name): $$(du -h $(release_dir)/$(archive_name) | cut -f1), $$(tar -tzf $(release_dir)/$(archive_name) | grep -vc '/$$') files"

# The path the nextcloud/.github appstore-build-publish workflow signs and uploads.
.PHONY: appstore
appstore: package
	mkdir -p $(build_dir)/artifacts
	cp $(release_dir)/$(archive_name) $(build_dir)/artifacts/$(app_name).tar.gz

# --- Cleaning -------------------------------------------------------------------

.PHONY: clean
clean:
	rm -rf $(build_dir) js css

.PHONY: distclean
distclean: clean
	rm -rf node_modules vendor vendor-bin/*/vendor

# --- App-specific (below this line) ---
