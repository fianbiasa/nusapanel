// Command nusactl calls the nusapanel agent from a terminal, for emergencies,
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
	"time"

	"nusapanel/agent/internal/client"
	"nusapanel/agent/internal/rpc"
	"nusapanel/agent/internal/version"
)

const usage = `Usage:
  nusactl [flags] ping
  nusactl [flags] call <method> [params-json]
  nusactl version

Flags:
`

func main() {
	os.Exit(run())
}

func run() int {
	flags := flag.NewFlagSet("nusactl", flag.ContinueOnError)
	socketPath := flags.String("socket", "/run/nusapanel/agent.sock", "agent socket")
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
