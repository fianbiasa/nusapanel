// Package version holds the agent version, set at build time with
// -ldflags "-X nusapanel/agent/internal/version.Version=1.2.3".
package version

// Version is reported by agent.ping and system.info.
var Version = "dev"
