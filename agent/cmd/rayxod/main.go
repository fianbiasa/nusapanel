// Command rayxod is the rayxo agent: the only process that changes the
// server. It runs as root and answers the panel over a Unix socket.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"os/user"
	"strconv"
	"syscall"
	"time"

	"rayxo/agent/internal/actions"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/version"
)

type config struct {
	socketPath  string
	allowUser   string
	socketGroup string
	callTimeout time.Duration
}

func main() {
	var cfg config

	flag.StringVar(&cfg.socketPath, "socket", "/run/rayxo/agent.sock", "Unix socket to listen on")
	flag.StringVar(&cfg.allowUser, "allow-user", "rayxo", "system user that may call the agent besides root")
	flag.StringVar(&cfg.socketGroup, "socket-group", "rayxo", "group that owns the socket (empty keeps the current group)")
	flag.DurationVar(&cfg.callTimeout, "call-timeout", 10*time.Minute, "maximum time one call may run")
	showVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println(version.Version)

		return
	}

	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	// Actions log details that must not reach the panel through the
	// default logger.
	slog.SetDefault(logger)

	if err := run(logger, cfg); err != nil {
		logger.Error("rayxod stopped", "error", err)
		os.Exit(1)
	}
}

func run(logger *slog.Logger, cfg config) error {
	allowed, err := user.Lookup(cfg.allowUser)
	if err != nil {
		return fmt.Errorf("look up allowed user %q: %w", cfg.allowUser, err)
	}

	allowedUID, err := strconv.ParseUint(allowed.Uid, 10, 32)
	if err != nil {
		return fmt.Errorf("parse uid of %q: %w", cfg.allowUser, err)
	}

	gid := -1
	if cfg.socketGroup != "" {
		group, err := user.LookupGroup(cfg.socketGroup)
		if err != nil {
			return fmt.Errorf("look up socket group %q: %w", cfg.socketGroup, err)
		}

		if gid, err = strconv.Atoi(group.Gid); err != nil {
			return fmt.Errorf("parse gid of %q: %w", cfg.socketGroup, err)
		}
	}

	registry := rpc.NewRegistry()
	actions.Register(registry)

	listener, err := rpc.Listen(cfg.socketPath, 0o660, gid)
	if err != nil {
		return fmt.Errorf("listen on %s: %w", cfg.socketPath, err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()

	server := &rpc.Server{
		Registry:    registry,
		Logger:      logger,
		CallTimeout: cfg.callTimeout,
		Authorize: func(uid uint32) bool {
			return uid == 0 || uint64(uid) == allowedUID
		},
	}

	logger.Info("rayxod listening",
		"socket", cfg.socketPath,
		"version", version.Version,
		"allowed_uid", allowedUID,
		"methods", registry.Names(),
	)

	err = server.Serve(ctx, listener)
	logger.Info("rayxod shut down")

	return err
}
