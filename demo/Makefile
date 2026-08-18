# Aggregate demo targets (FrankenPHP under demo/symfony8).

.PHONY: help up down update-bundle release-check

help:
	@echo "HttpLogBundle demos"
	@echo "  make up             Start demo/symfony8"
	@echo "  make down           Stop demo/symfony8"
	@echo "  make update-bundle  Sync path-repo bundle in the demo"
	@echo "  make release-check  Delegate to demo/symfony8"

up:
	@$(MAKE) -C symfony8 up

down:
	@$(MAKE) -C symfony8 down

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

release-check:
	@$(MAKE) -C symfony8 release-check
