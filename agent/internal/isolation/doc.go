// Package isolation proves, on a real RAYXO server, that one hosting
// account cannot read another account's files: not from its Linux user
// (SFTP, cron, the file manager), not from its website's PHP, and not
// through the web server, which may enter every home.
//
// It creates two throwaway accounts through the agent, plants a secret in
// the second, attacks it from the first and deletes both accounts again.
// Build it with "make isolation" and run it as root on the server:
//
//	./isolation.test -test.v
package isolation
