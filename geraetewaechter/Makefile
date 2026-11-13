VERSION ?= 1.0.0
LDFLAGS = -s -w -X geraetewaechter/internal/agent.Version=$(VERSION)
TARGETS = linux/amd64 linux/arm64 linux/arm windows/amd64 windows/arm64 darwin/amd64 darwin/arm64

.PHONY: test server agents winres run

test:
	go vet ./... && go test ./...

server:
	CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o dist/gw-server ./cmd/gw-server

# Herstellerangaben (Stockwerk IT), Version und Manifest in die Windows-.exe
# einbetten. Eigener Generator ohne Fremdcode (tools/winres); erzeugt
# cmd/gw-agent/rsrc_windows_*.syso, die "go build" automatisch einbindet.
winres:
	go run ./tools/winres -version $(VERSION) -out cmd/gw-agent

agents: winres
	mkdir -p dist/downloads
	@for t in $(TARGETS); do os=$${t%/*}; arch=$${t#*/}; ext=""; [ $$os = windows ] && ext=".exe"; \
	  echo "baue $$os/$$arch"; \
	  CGO_ENABLED=0 GOOS=$$os GOARCH=$$arch GOARM=7 go build -trimpath -ldflags "$(LDFLAGS)" -o dist/downloads/gw-agent-$${os}_$${arch}$$ext ./cmd/gw-agent || exit 1; \
	done
	cd dist/downloads && sha256sum gw-agent-* > SHA256SUMS

# Lokaler Testlauf ohne HTTPS
run: server agents
	GW_DATA_DIR=dist/data GW_DOWNLOADS_DIR=dist/downloads GW_COOKIE_SECURE=false GW_ADMIN_PASSWORD=testpasswort123 ./dist/gw-server

# Windows-Agenten signieren (Authenticode). Beispiel mit jsign und einem
# Cloud-HSM bzw. USB-Token; Parameter je nach Zertifikatsanbieter anpassen:
#   make sign SIGN="jsign --storetype ... --storepass ... --alias ... --tsaurl http://timestamp.digicert.com"
sign:
	@test -n "$(SIGN)" || (echo "SIGN=... angeben (siehe docs/SICHERHEIT.md)"; exit 1)
	for f in dist/downloads/gw-agent-windows_*.exe; do $(SIGN) "$$f" || exit 1; done
	cd dist/downloads && sha256sum gw-agent-* > SHA256SUMS
