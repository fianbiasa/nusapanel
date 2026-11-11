package actions

import (
	"slices"
	"testing"

	"rayxo/agent/internal/rpc"
)

func TestMailMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"mail.domain_apply", "mail.domain_remove", "mail.mailbox_remove", "mail.sieve_apply", "mail.send_limits_apply", "mail.queue_list", "mail.queue_retry", "mail.queue_delete"} {
		if !slices.Contains(registry.Names(), name) {
			t.Fatalf("%s is not registered", name)
		}
	}
}

func TestMailParamsAreChecked(t *testing.T) {
	valid := []webParams{
		mailDomainParams{Username: "budi", Domain: "example.com", Selector: "rayxo"},
		mailboxParams{Username: "budi", Domain: "example.com", LocalPart: "info.sales"},
		mailSieveParams{Username: "budi", Domain: "example.com", LocalPart: "info", Script: "keep;"},
		mailSieveParams{Username: "budi", Domain: "example.com", LocalPart: "info", Script: ""},
	}

	for _, params := range valid {
		if err := params.validate(); err != nil {
			t.Errorf("%+v: %v", params, err)
		}
	}

	invalid := []webParams{
		mailDomainParams{Username: "budi", Domain: "example.com", Selector: "a.b"},
		mailDomainParams{Username: "root", Domain: "example.com", Selector: "rayxo"},
		mailboxParams{Username: "budi", Domain: "example.com", LocalPart: "../sari"},
		mailboxParams{Username: "budi", Domain: "../x", LocalPart: "info"},
		mailSieveParams{Username: "budi", Domain: "example.com", LocalPart: "../sari", Script: "keep;"},
		mailSieveParams{Username: "budi", Domain: "example.com", LocalPart: "info", Script: "keep;\x00"},
	}

	for _, params := range invalid {
		if err := params.validate(); err == nil {
			t.Errorf("%+v was accepted", params)
		}
	}
}
