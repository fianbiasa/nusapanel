VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -X nusapanel/agent/internal/version.Version=$(VERSION)
BINARIES := nusad nusactl

.PHONY: build linux test vet check clean

# Build for this machine (macOS during development).
build:
	go build -trimpath -ldflags "$(LDFLAGS)" -o bin/ ./cmd/...

# Static Linux binaries for servers, in bin/linux-amd64 and bin/linux-arm64.
linux:
	@for arch in amd64 arm64; do \
		for binary in $(BINARIES); do \
			CGO_ENABLED=0 GOOS=linux GOARCH=$$arch go build -trimpath -ldflags "$(LDFLAGS)" \
				-o bin/linux-$$arch/$$binary ./cmd/$$binary || exit 1; \
		done; \
	done

test:
	go test -race ./...

vet:
	go vet ./...
	GOOS=linux go vet ./...

check: vet test
	@test -z "$$(gofmt -l .)" || (gofmt -l . && echo "run gofmt -w ." && exit 1)

clean:
	rm -rf bin
