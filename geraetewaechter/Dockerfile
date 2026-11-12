# Baut Server und alle Agent-Varianten in einem Schritt.
FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
ARG VERSION=1.0.0
ENV CGO_ENABLED=0
RUN go build -trimpath -ldflags "-s -w" -o /out/gw-server ./cmd/gw-server
RUN mkdir -p /out/downloads && \
    for t in linux/amd64 linux/arm64 linux/arm windows/amd64 windows/arm64 darwin/amd64 darwin/arm64; do \
      os=${t%/*}; arch=${t#*/}; ext=""; [ "$os" = windows ] && ext=".exe"; \
      GOOS=$os GOARCH=$arch GOARM=7 go build -trimpath -ldflags "-s -w -X geraetewaechter/internal/agent.Version=${VERSION}" \
        -o /out/downloads/gw-agent-${os}_${arch}${ext} ./cmd/gw-agent || exit 1; \
    done && \
    cd /out/downloads && sha256sum gw-agent-* > SHA256SUMS

FROM alpine:3.20
RUN apk add --no-cache ca-certificates tzdata && adduser -D -H -u 10001 gw && mkdir -p /data && chown gw /data
COPY --from=build /out/gw-server /usr/local/bin/gw-server
COPY --from=build /out/downloads /downloads
ENV GW_DATA_DIR=/data GW_DOWNLOADS_DIR=/downloads GW_LISTEN=:8080 TZ=Europe/Berlin
USER gw
VOLUME /data
EXPOSE 8080
HEALTHCHECK CMD wget -qO- http://127.0.0.1:8080/healthz || exit 1
ENTRYPOINT ["/usr/local/bin/gw-server"]
