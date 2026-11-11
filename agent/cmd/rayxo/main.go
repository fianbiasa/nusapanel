// Command rayxo calls the rayxo agent from a terminal, for emergencies,
// scripts and the installer.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"os/exec"
	"syscall"
	"time"

	"rayxo/agent/internal/client"
	"rayxo/agent/internal/dns"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/version"
)

const usage = `Usage:
  rayxo [flags] ping
  rayxo [flags] call <method> [params-json]
  rayxo rebuild
  rayxo version
  rayxo acme-dns-hook auth|cleanup   (certbot's hook for wildcard certificates)

Flags:
`

// How "rayxo rebuild" reaches the panel: its artisan, run as the panel's
// own user, as the installer does.
const (
	runuserPath = "/usr/sbin/runuser"
	panelUser   = "rayxo"
	phpPath     = "/usr/bin/php8.4"
	panelDir    = "/usr/local/rayxo/current/panel"
)

func main() {
	os.Exit(run())
}

func run() int {
	flags := flag.NewFlagSet("rayxo", flag.ContinueOnError)
	socketPath := flags.String("socket", "/run/rayxo/agent.sock", "agent socket")
	timeout := flags.Duration("timeout", 30*time.Second, "how long to wait for the agent")
	flags.Usage = func() {
		fmt.Fprint(os.Stderr, usage)
		flags.PrintDefaults()
	}

	if err := flags.Parse(os.Args[1:]); err != nil {
		return 2
	}

	args := flags.Args()
	if len(args) == 0 {
		flags.Usage()

		return 2
	}

	var (
		method string
		params any
	)

	switch args[0] {
	case "version":
		fmt.Println(version.Version)

		return 0
	case "rebuild":
		if len(args) != 1 {
			flags.Usage()

			return 2
		}

		return rebuild()
	case "acme-dns-hook":
		if len(args) != 2 || (args[1] != "auth" && args[1] != "cleanup") {
			flags.Usage()

			return 2
		}

		return acmeDNSHook(args[1])
	case "ping":
		method = "agent.ping"
	case "call":
		if len(args) < 2 || len(args) > 3 {
			flags.Usage()

			return 2
		}

		method = args[1]

		if len(args) == 3 {
			if !json.Valid([]byte(args[2])) {
				fmt.Fprintln(os.Stderr, "params must be valid JSON, for example '{\"domain\":\"example.com\"}'")

				return 2
			}

			params = json.RawMessage(args[2])
		}
	default:
		flags.Usage()

		return 2
	}

	ctx, cancel := context.WithTimeout(context.Background(), *timeout)
	defer cancel()

	result, err := client.Call(ctx, *socketPath, method, params)
	if err != nil {
		var agentErr *rpc.Error
		if errors.As(err, &agentErr) {
			fmt.Fprintf(os.Stderr, "agent error %d: %s\n", agentErr.Code, agentErr.Message)
		} else {
			fmt.Fprintln(os.Stderr, err)
		}

		return 1
	}

	var pretty bytes.Buffer
	if err := json.Indent(&pretty, result, "", "  "); err != nil {
		fmt.Println(string(result))

		return 0
	}

	fmt.Println(pretty.String())

	return 0
}

// rebuild writes the server's configuration again from the panel's
// database: the panel's rayxo:rebuild queues every website, disk quota,
// crontab and FTP folder, and the queue worker and the agent apply them.
// Like DirectAdmin's rewrite_confs, for configs that were deleted or edited
// by hand.
func rebuild() int {
	if os.Geteuid() != 0 {
		fmt.Fprintln(os.Stderr, "rayxo rebuild must run as root")

		return 1
	}

	cmd := exec.Command(runuserPath, "-u", panelUser, "--", phpPath, "artisan", "rayxo:rebuild", "--no-interaction")
	cmd.Dir = panelDir
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr

	// As the installer: files the panel writes (its log) stay away from
	// other local users.
	syscall.Umask(0o027)

	if err := cmd.Run(); err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			return exitErr.ExitCode()
		}

		fmt.Fprintln(os.Stderr, err)

		return 1
	}

	return 0
}

// acmeDNSHook is certbot's --manual-auth-hook and --manual-cleanup-hook for
// wildcard certificates: it puts the DNS-01 challenge certbot passes in
// CERTBOT_DOMAIN and CERTBOT_VALIDATION into the server's PowerDNS
// ("auth"), or takes it out again ("cleanup"). certbot runs it as root,
// which may read PowerDNS's API key.
func acmeDNSHook(step string) int {
	domain, value := os.Getenv("CERTBOT_DOMAIN"), os.Getenv("CERTBOT_VALIDATION")

	ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
	defer cancel()

	manager := dns.New(dns.DefaultConfig())

	var err error
	if step == "auth" {
		err = manager.AddChallenge(ctx, domain, value)
	} else {
		err = manager.RemoveChallenge(ctx, domain, value)
	}

	if err != nil {
		fmt.Fprintf(os.Stderr, "rayxo acme-dns-hook %s %s: %v\n", step, domain, err)

		return 1
	}

	return 0
}
