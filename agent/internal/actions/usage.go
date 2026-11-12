package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/bandwidth"
	"rayxo/agent/internal/rpc"
)

// maxCursorLogs bounds the logs a cursor may name; a server has one access
// log per website, the mail log and the FTP log.
const maxCursorLogs = 100_000

// bandwidthCounter is what usage.bandwidth needs; *bandwidth.Counter
// implements it.
type bandwidthCounter interface {
	Count(cursor bandwidth.Cursor) (bandwidth.Report, error)
}

// usageParams are the params of usage.bandwidth: where the previous count
// stopped, as it returned it; empty for the first count.
type usageParams struct {
	Cursor bandwidth.Cursor `json:"cursor"`
}

// usageMethods returns the usage.* methods. usage.bandwidth counts the
// traffic of websites, mail and FTP from their logs since the cursor
// the panel kept; it only reads, so it locks nothing, and the panel keeps
// the returned cursor with what it stored, so nothing is counted twice.
func usageMethods(counter bandwidthCounter) map[string]rpc.Method {
	return map[string]rpc.Method{
		"usage.bandwidth": {Handler: func(_ context.Context, raw json.RawMessage) (any, error) {
			var params usageParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if len(params.Cursor) > maxCursorLogs {
				return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: the cursor names more than %d logs", maxCursorLogs)
			}

			report, err := counter.Count(params.Cursor)
			if err != nil {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "count bandwidth: %v", err)
			}

			return report, nil
		}},
	}
}
