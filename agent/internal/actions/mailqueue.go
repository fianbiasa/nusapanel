package actions

import (
	"context"
	"encoding/json"

	"rayxo/agent/internal/mailqueue"
	"rayxo/agent/internal/rpc"
)

// defaultQueueListed is how many messages mail.queue_list returns unless
// asked for another number.
const defaultQueueListed = 500

// mailQueue is what the mail.queue_* actions need. mailqueue.Queue
// implements it.
type mailQueue interface {
	List(ctx context.Context, limit int) (mailqueue.Listing, error)
	Retry(ctx context.Context, id string) error
	Delete(ctx context.Context, ids []string) (mailqueue.Outcome, error)
}

type queueListParams struct {
	Limit int `json:"limit"`
}

type queueRetryParams struct {
	// ID is the message to try again; empty tries every deferred message.
	ID string `json:"id"`
}

type queueDeleteParams struct {
	IDs []string `json:"ids"`
}

// mailQueueMethods returns the mail.queue_* methods by name. Changes to the
// queue happen one at a time.
func mailQueueMethods(queue mailQueue) map[string]rpc.Method {
	lock := func(json.RawMessage) (string, error) { return "mail:queue", nil }

	return map[string]rpc.Method{
		"mail.queue_list": {Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params queueListParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if params.Limit < 0 || params.Limit > mailqueue.MaxListed {
				return nil, rpc.Errorf(rpc.CodeInvalidParams, "limit must be 0 to %d", mailqueue.MaxListed)
			}

			if params.Limit == 0 {
				params.Limit = defaultQueueListed
			}

			listing, err := queue.List(ctx, params.Limit)
			if err != nil {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "the mail queue could not be read: %v", err)
			}

			return listing, nil
		}},
		"mail.queue_retry": {LockKey: lock, Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params queueRetryParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if params.ID != "" {
				if err := mailqueue.ValidateID(params.ID); err != nil {
					return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
				}
			}

			if err := queue.Retry(ctx, params.ID); err != nil {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "the mail could not be tried again: %v", err)
			}

			return map[string]string{"id": params.ID}, nil
		}},
		"mail.queue_delete": {LockKey: lock, Handler: func(ctx context.Context, raw json.RawMessage) (any, error) {
			var params queueDeleteParams
			if err := rpc.DecodeParams(raw, &params); err != nil {
				return nil, err
			}

			if len(params.IDs) == 0 || len(params.IDs) > mailqueue.MaxDeleted {
				return nil, rpc.Errorf(rpc.CodeInvalidParams, "give 1 to %d queue IDs", mailqueue.MaxDeleted)
			}

			for _, id := range params.IDs {
				if err := mailqueue.ValidateID(id); err != nil {
					return nil, rpc.Errorf(rpc.CodeInvalidParams, "invalid params: %v", err)
				}
			}

			outcome, err := queue.Delete(ctx, params.IDs)
			if err != nil {
				return nil, rpc.Errorf(rpc.CodeActionFailed, "the mail could not be deleted: %v", err)
			}

			return outcome, nil
		}},
	}
}
