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
	"rayxo/agent/internal/files"
	"rayxo/agent/internal/panelport"
	"rayxo/agent/internal/reconfigure"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/services"
	"rayxo/agent/internal/srs"
	"rayxo/agent/internal/version"
)

type config struct {
	socketPath  string
	allowUser   string
	socketGroup string
	transferDir string
	callTimeout time.Duration
	watchdog    time.Duration
}

func main() {
	// The file manager runs every operation in rayxod itself, started again
	// as the account (see internal/files).
	if len(os.Args) > 1 && os.Args[1] == files.HelperArg {
		os.Exit(files.HelperMain(os.Args[2:]))
	}

	// Postfix's SRS lookups run in rayxod too, as their own unprivileged
	// service (rayxo-srs, see internal/srs).
	if len(os.Args) > 1 && os.Args[1] == srs.Arg {
		os.Exit(srs.Main(os.Args[2:]))
	}

	var cfg config

	flag.StringVar(&cfg.socketPath, "socket", "/run/rayxo/agent.sock", "Unix socket to listen on")
	flag.StringVar(&cfg.allowUser, "allow-user", "rayxo", "system user that may call the agent besides root")
	flag.StringVar(&cfg.socketGroup, "socket-group", "rayxo", "group that owns the socket (empty keeps the current group)")
	flag.StringVar(&cfg.transferDir, "transfer-dir", "/var/lib/rayxo/transfer", "folder, owned by root, that file manager uploads and downloads pass through")
	flag.DurationVar(&cfg.callTimeout, "call-timeout", 10*time.Minute, "maximum time one call may run")
	flag.DurationVar(&cfg.watchdog, "watchdog-interval", time.Minute, "how often the watchdog starts services that should run again (0 turns it off)")
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

	executable, err := os.Executable()
	if err != nil {
		return fmt.Errorf("find the rayxod binary: %w", err)
	}

	// Mounts do not survive a reboot: the SSH jails are mounted again
	// before any call can need them.
	if err := actions.RestoreJails(context.Background()); err != nil {
		logger.Error("could not restore every SSH jail", "error", err)
	}

	// Neither do the IP addresses rayxod added to the network interface.
	if err := actions.RestoreAddresses(context.Background()); err != nil {
		logger.Error("could not add every IP address again", "error", err)
	}

	manager := services.New(services.DefaultConfig())
	changer := reconfigure.New(reconfigure.DefaultConfig())
	ports := panelport.New(panelport.DefaultConfig())

	registry := rpc.NewRegistry()
	actions.Register(registry, actions.Config{
		Files: actions.FilesConfig{
			Executable:  executable,
			TransferDir: cfg.transferDir,
			PanelUID:    int(allowedUID),
		},
		Services:    manager,
		Reconfigure: changer,
		PanelPort:   ports,
	})

	listener, err := rpc.Listen(cfg.socketPath, 0o660, gid)
	if err != nil {
		return fmt.Errorf("listen on %s: %w", cfg.socketPath, err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()

	if cfg.watchdog > 0 {
		go manager.Watch(ctx, cfg.watchdog)
	}

	// Closes a panel port tried but not confirmed in time, also after the
	// agent restarted in the meantime.
	go ports.Watch(ctx, 15*time.Second, changer.Running)

	server := &rpc.Server{
		Registry:    registry,
		Logger:      logger,
		CallTimeout: cfg.callTimeout,
		// Room for a file saved from the file manager's editor (2 MB).
		MaxRequestBytes: 8 << 20,
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
