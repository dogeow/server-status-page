# syntax=docker/dockerfile:1.7
FROM golang:1.23.12-bookworm AS build

WORKDIR /src
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod go mod download
COPY . .

ARG VERSION=dev
ARG TARGETOS
ARG TARGETARCH
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=0 GOOS=${TARGETOS:-linux} GOARCH=${TARGETARCH} \
    go build -trimpath -ldflags="-s -w -X github.com/statusforge/status-agent/internal/version.Version=${VERSION}" \
    -o /out/status-agent ./cmd/status-agent
RUN mkdir -p /state

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/status-agent /usr/local/bin/status-agent
COPY --from=build --chown=65532:65532 /state /var/lib/status-agent
COPY --chown=65532:65532 config.example.yaml /etc/status-agent/config.yaml

USER 65532:65532
VOLUME ["/var/lib/status-agent"]
ENTRYPOINT ["/usr/local/bin/status-agent"]
CMD ["-config", "/etc/status-agent/config.yaml"]
