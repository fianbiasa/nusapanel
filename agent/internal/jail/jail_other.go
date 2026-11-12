//go:build !linux

package jail

// DefaultConfig is a configuration without mounts: jails need Linux.
func DefaultConfig() Config {
	return Config{Root: "/var/lib/rayxo/jail", Server: "/", MountInfo: "/proc/self/mountinfo"}
}
