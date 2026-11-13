package actions

import (
	"context"
	"encoding/json"
	"slices"
	"testing"

	"rayxo/agent/internal/cgroup"
	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/sysuser"
)

type fakeResources struct {
	asked []cgroup.Account
}

func (f *fakeResources) Usage(accounts []cgroup.Account) ([]cgroup.Usage, error) {
	f.asked = accounts
	usages := []cgroup.Usage{}

	for _, account := range accounts {
		usages = append(usages, cgroup.Usage{Username: account.Name, MemoryBytes: 1 << 20, CPU: []cgroup.GroupCPU{{ID: 7, Usec: uint64(account.UID)}}})
	}

	return usages, nil
}

func TestResourceMethodIsRegisteredAndLocksNothing(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	if !slices.Contains(registry.Names(), "usage.resources") {
		t.Fatal("usage.resources is not registered")
	}

	if resourceMethods(newFakeSystem(), &fakeResources{})["usage.resources"].LockKey != nil {
		t.Fatal("usage.resources locks")
	}
}

func TestResourcesMeasureOnlyRayxoAccounts(t *testing.T) {
	system := newQuotaSystem()
	reader := &fakeResources{}

	result, err := resourceMethods(system, reader)["usage.resources"].Handler(context.Background(), json.RawMessage(`{}`))
	if err != nil {
		t.Fatal(err)
	}

	encoded, _ := json.Marshal(result)
	want := `{"accounts":[{"username":"budi","memory_bytes":1048576,"cpu":[{"id":7,"usec":1001}]},{"username":"sari","memory_bytes":1048576,"cpu":[{"id":7,"usec":1002}]},{"username":"tono","memory_bytes":1048576,"cpu":[{"id":7,"usec":1003}]}]}`

	if string(encoded) != want {
		t.Errorf("result = %s", encoded)
	}

	for _, account := range reader.asked {
		if account.Name == "root" || account.Name == "ubuntu" || account.Name == "svc" || account.Name == "moved" {
			t.Errorf("measured %s, which rayxo does not manage", account.Name)
		}
	}
}

func TestResourcesTakeNoParams(t *testing.T) {
	_, err := resourceMethods(newFakeSystem(), &fakeResources{})["usage.resources"].Handler(context.Background(), json.RawMessage(`{"username":"budi"}`))
	assertCode(t, err, rpc.CodeInvalidParams)

	var _ accountSystem = sysuser.System{}
}
