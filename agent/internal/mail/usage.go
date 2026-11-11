package mail

import (
	"bufio"
	"bytes"
	"context"
	"fmt"
	"strconv"
	"strings"
)

const doveadmProgram = "/usr/bin/doveadm"

// MailboxUsage is how much mail a mailbox holds, as Dovecot's quota counts
// it (also for mailboxes without a quota).
type MailboxUsage struct {
	Address  string `json:"address"`
	UsedKB   int64  `json:"used_kb"`
	Messages int64  `json:"messages"`
}

// Usage returns the size and message count of every mailbox Dovecot knows,
// from its quota bookkeeping, without walking the mail folders.
func (m *Manager) Usage(ctx context.Context) ([]MailboxUsage, error) {
	output, err := m.cfg.Run(ctx, doveadmProgram, "-f", "tab", "quota", "get", "-A")
	if err != nil {
		return nil, err
	}

	return parseQuota(output)
}

// parseQuota reads doveadm's tab separated quota report: a header, then a
// STORAGE (kilobytes) and a MESSAGE (count) row per mailbox.
func parseQuota(output []byte) ([]MailboxUsage, error) {
	byAddress := map[string]*MailboxUsage{}
	var order []string

	scanner := bufio.NewScanner(bytes.NewReader(output))

	for line := 0; scanner.Scan(); line++ {
		fields := strings.Split(scanner.Text(), "\t")
		if line == 0 && len(fields) > 0 && fields[0] == "Username" {
			continue
		}

		if len(fields) < 4 {
			continue
		}

		address, kind := fields[0], fields[2]
		if !strings.Contains(address, "@") {
			continue
		}

		value, err := strconv.ParseInt(fields[3], 10, 64)
		if err != nil {
			return nil, fmt.Errorf("unreadable quota value %q for %s", fields[3], address)
		}

		usage, ok := byAddress[address]
		if !ok {
			usage = &MailboxUsage{Address: address}
			byAddress[address] = usage
			order = append(order, address)
		}

		switch kind {
		case "STORAGE":
			usage.UsedKB = value
		case "MESSAGE":
			usage.Messages = value
		}
	}

	if err := scanner.Err(); err != nil {
		return nil, err
	}

	usages := make([]MailboxUsage, 0, len(order))
	for _, address := range order {
		usages = append(usages, *byAddress[address])
	}

	return usages, nil
}
