package srs

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Arg is the first argument that makes rayxod run as the SRS socketmap.
const Arg = "srs"

const (
	// maxRequest is the longest request Postfix sends, in bytes.
	maxRequest = 100000
	// maxLengthDigits bounds the digits of a netstring's length.
	maxLengthDigits = 6
	// idleTimeout closes a connection Postfix left unused.
	idleTimeout = 5 * time.Minute
)

// Main runs the socketmap Postfix asks, as an unprivileged user, until
// SIGTERM. Its flags name the socket, the domain of rewritten addresses
// (the server's hostname) and the file with the secret key.
func Main(args []string) int {
	flags := flag.NewFlagSet("rayxod "+Arg, flag.ContinueOnError)
	socketPath := flags.String("socket", "", "Unix socket Postfix asks on")
	domain := flags.String("domain", "", "domain of rewritten addresses (the server's hostname)")
	secretFile := flags.String("secret-file", "", "file holding the secret key")

	if err := flags.Parse(args); err != nil {
		return 2
	}

	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))

	if err := run(*socketPath, *domain, *secretFile, logger); err != nil {
		logger.Error("SRS socketmap stopped", "error", err)

		return 1
	}

	return 0
}

func run(socketPath, domain, secretFile string, logger *slog.Logger) error {
	if os.Geteuid() == 0 {
		return errors.New("the SRS socketmap must not run as root")
	}

	if socketPath == "" || secretFile == "" {
		return errors.New("--socket and --secret-file are required")
	}

	secret, err := os.ReadFile(secretFile)
	if err != nil {
		return fmt.Errorf("read the secret: %w", err)
	}

	rewriter, err := New(bytes.TrimSpace(secret), domain)
	if err != nil {
		return err
	}

	// A socket left by an earlier run; anything else stays.
	if info, err := os.Lstat(socketPath); err == nil && info.Mode()&fs.ModeSocket != 0 {
		if err := os.Remove(socketPath); err != nil {
			return fmt.Errorf("remove the old socket: %w", err)
		}
	}

	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		return fmt.Errorf("listen on %s: %w", socketPath, err)
	}

	// Postfix (the group the service runs with) may connect; others not.
	if err := os.Chmod(socketPath, 0o660); err != nil {
		listener.Close()

		return fmt.Errorf("set the socket's mode: %w", err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()

	go func() {
		<-ctx.Done()
		listener.Close()
	}()

	logger.Info("SRS socketmap listening", "socket", socketPath, "domain", rewriter.domain)

	return Serve(listener, rewriter)
}

// Serve answers Postfix's socketmap lookups on listener until it is
// closed: "forward <address>" with the address to send as, "reverse
// <address>" with the address an SRS address stands for, or NOTFOUND.
func Serve(listener net.Listener, rewriter *Rewriter) error {
	for {
		conn, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return nil
			}

			return err
		}

		go serveConn(conn, rewriter)
	}
}

func serveConn(conn net.Conn, rewriter *Rewriter) {
	defer conn.Close()

	reader := bufio.NewReader(conn)

	for {
		if err := conn.SetDeadline(time.Now().Add(idleTimeout)); err != nil {
			return
		}

		request, err := readNetstring(reader)
		if err != nil {
			return
		}

		if _, err := conn.Write(netstring(rewriter.answer(request))); err != nil {
			return
		}
	}
}

// answer is the reply to one socketmap request, "<map> <key>".
func (r *Rewriter) answer(request string) string {
	name, key, found := strings.Cut(request, " ")
	if !found || key == "" {
		return "PERM malformed request"
	}

	var (
		result string
		ok     bool
	)

	switch name {
	case "forward":
		result, ok = r.Forward(key)
	case "reverse":
		result, ok = r.Reverse(key)
	default:
		return "PERM unknown map"
	}

	if !ok {
		return "NOTFOUND "
	}

	return "OK " + result
}

// readNetstring reads one "<length>:<data>," netstring.
func readNetstring(reader *bufio.Reader) (string, error) {
	var digits []byte

	for {
		c, err := reader.ReadByte()
		if err != nil {
			return "", err
		}

		if c == ':' {
			break
		}

		if len(digits) == maxLengthDigits {
			return "", errors.New("netstring length too long")
		}

		digits = append(digits, c)
	}

	length, err := strconv.Atoi(string(digits))
	if err != nil || length < 0 || length > maxRequest {
		return "", errors.New("malformed netstring length")
	}

	data := make([]byte, length+1)
	if _, err := io.ReadFull(reader, data); err != nil {
		return "", err
	}

	if data[length] != ',' {
		return "", errors.New("netstring without its comma")
	}

	return string(data[:length]), nil
}

func netstring(data string) []byte {
	return []byte(strconv.Itoa(len(data)) + ":" + data + ",")
}
