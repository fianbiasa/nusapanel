package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"

	"rayxo/agent/internal/files"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sitelogs"
	"rayxo/agent/internal/validate"
)

// siteLogs reads the websites' logs. *sitelogs.Manager implements it.
type siteLogs interface {
	Tail(user, domain, kind string, lines int, filter string) (sitelogs.Tail, error)
	Download(user, domain, kind string, w io.Writer) error
	Report(ctx context.Context, user, domain string, w io.Writer) error
}

type logParams struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Kind     string `json:"kind"`
}

func (p logParams) account() string { return p.Username }

func (p logParams) validate() error {
	if err := validate.Username(p.Username); err != nil {
		return err
	}

	if err := validate.Domain(p.Domain); err != nil {
		return err
	}

	if _, ok := sitelogs.Kinds[p.Kind]; !ok {
		return fmt.Errorf("kind must be access, error or apache-error")
	}

	return nil
}

// tailParams are the params of logs.tail.
type tailParams struct {
	logParams
	Lines  int    `json:"lines"`
	Filter string `json:"filter"`
}

func (p tailParams) validate() error {
	if p.Lines < 1 || p.Lines > sitelogs.MaxTailLines {
		return fmt.Errorf("lines must be between 1 and %d", sitelogs.MaxTailLines)
	}

	if len(p.Filter) > sitelogs.MaxFilterLength {
		return fmt.Errorf("filter may have at most %d characters", sitelogs.MaxFilterLength)
	}

	return p.logParams.validate()
}

// logDownloadParams are the params of logs.download.
type logDownloadParams struct {
	logParams
	Transfer string `json:"transfer"`
}

func (p logDownloadParams) validate() error {
	if err := files.CheckTransferID(p.Transfer); err != nil {
		return err
	}

	return p.logParams.validate()
}

// reportParams are the params of stats.report.
type reportParams struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
	Transfer string `json:"transfer"`
}

func (p reportParams) account() string { return p.Username }

func (p reportParams) validate() error {
	if err := files.CheckTransferID(p.Transfer); err != nil {
		return err
	}

	return logParams{Username: p.Username, Domain: p.Domain, Kind: "access"}.validate()
}

// logActions implements logs.* and stats.report.
type logActions struct {
	accounts  accountActions
	logs      siteLogs
	transfers fileTransfers
}

// logMethods returns the methods that read the websites' logs. They only
// read, so they take no account lock.
func logMethods(system accountSystem, logs siteLogs, transfers fileTransfers) map[string]rpc.Method {
	l := logActions{accounts: accountActions{system: system}, logs: logs, transfers: transfers}

	return map[string]rpc.Method{
		"logs.tail":     {Handler: l.tail},
		"logs.download": {Handler: l.download},
		"stats.report":  {Handler: l.report},
	}
}

// tail implements logs.tail: the last lines of a log of the website.
func (l logActions) tail(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[tailParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := l.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	tail, err := l.logs.Tail(params.Username, params.Domain, params.Kind, params.Lines, params.Filter)
	if err != nil {
		return nil, logError(err, params.Domain)
	}

	return tail, nil
}

// download implements logs.download: every kept log of the kind, gzipped,
// in a file only the panel user can read.
func (l logActions) download(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[logDownloadParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := l.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	return l.toDownload(params.Transfer, params.Domain, func(w io.Writer) error {
		return l.logs.Download(params.Username, params.Domain, params.Kind, w)
	})
}

// report implements stats.report: the GoAccess report of the website's
// visits, as HTML, in a file only the panel user can read.
func (l logActions) report(ctx context.Context, raw json.RawMessage) (any, error) {
	params, err := decodeWebParams[reportParams](raw)
	if err != nil {
		return nil, err
	}

	if _, err := l.accounts.requireAccount(ctx, params.Username); err != nil {
		return nil, err
	}

	return l.toDownload(params.Transfer, params.Domain, func(w io.Writer) error {
		return l.logs.Report(ctx, params.Username, params.Domain, w)
	})
}

// toDownload writes the download transfer with write, and removes it again
// when that fails.
func (l logActions) toDownload(transfer, domain string, write func(io.Writer) error) (any, error) {
	target, err := l.transfers.CreateDownload(transfer)
	if err != nil {
		slog.Warn("log download refused", "transfer", transfer, "error", err)

		return nil, rpc.Errorf(rpc.CodeActionFailed, "the download could not be prepared")
	}

	err = errors.Join(write(target), target.Close())
	if err != nil {
		_ = os.Remove(l.transfers.DownloadPath(transfer))

		return nil, logError(err, domain)
	}

	return struct{}{}, nil
}

// logError tells the panel why a log could not be read; anything else
// stays in the agent log.
func logError(err error, domain string) error {
	switch {
	case errors.Is(err, sitelogs.ErrNoWebsite):
		return &rpc.Error{Code: rpc.CodeActionFailed, Message: "the account has no website " + domain + " on this server", Data: map[string]string{"reason": "no_website"}}
	case errors.Is(err, sitelogs.ErrNoVisits):
		return &rpc.Error{Code: rpc.CodeActionFailed, Message: domain + " has no visits in its logs yet", Data: map[string]string{"reason": "no_visits"}}
	case errors.Is(err, errors.ErrUnsupported):
		return rpc.Errorf(rpc.CodeActionFailed, "site statistics can only be made on Linux")
	}

	return err
}
