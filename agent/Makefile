VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS := -s -w -X rayxo/agent/internal/version.Version=$(VERSION)
BINARIES := rayxod rayxo

.PHONY: build linux isolation test vet check clean

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

# The proof that accounts cannot read each other's files, as a Linux test
# binary to run as root on a RAYXO server (see internal/isolation):
#   ./isolation.test -test.v
isolation:
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go test -c -trimpath -tags integration \
		-o bin/linux-amd64/isolation.test ./internal/isolation

test:
	go test -race ./...

vet:
	go vet ./...
	GOOS=linux go vet ./...
	GOOS=linux go vet -tags integration ./internal/isolation

check: vet test
	@test -z "$$(gofmt -l .)" || (gofmt -l . && echo "run gofmt -w ." && exit 1)

clean:
	rm -rf bin
