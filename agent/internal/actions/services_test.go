package actions

import (
	"context"
	"encoding/json"
	"slices"
	"testing"

	"rayxo/agent/internal/rpc"
	"rayxo/agent/internal/services"
)

// fakeServiceManager records actions and refuses what it is told to.
type fakeServiceManager struct {
	acted  []string
	refuse *services.Error
}

func (f *fakeServiceManager) Status(context.Context) (services.Report, error) {
	return services.Report{Services: []services.Status{{Service: services.Service{Unit: "nginx.service", Label: "Nginx"}, Active: "active"}}}, nil
}

func (f *fakeServiceManager) Act(_ context.Context, unit, action string) error {
	f.acted = append(f.acted, action+" "+unit)

	if f.refuse != nil {
		return f.refuse
	}

	return nil
}

func callService(t *testing.T, manager *fakeServiceManager, method string, params any) (json.RawMessage, error) {
	t.Helper()

	raw, _ := json.Marshal(params)
	handler := serviceMethods(manager)[method]

	result, err := handler.Handler(context.Background(), raw)
	if err != nil {
		return nil, err
	}

	encoded, _ := json.Marshal(result)

	return encoded, nil
}

func TestServiceMethodsAreRegistered(t *testing.T) {
	registry := rpc.NewRegistry()
	Register(registry, Config{})

	for _, name := range []string{"service.status", "service.action"} {
		if !slices.Contains(registry.Names(), name) {
			t.Errorf("%s is not registered", name)
		}
	}
}

func TestServiceActionChecksTheUnitAndPassesRefusalsOn(t *testing.T) {
	manager := &fakeServiceManager{}

	if _, err := callService(t, manager, "service.action", map[string]string{"unit": "pure-ftpd-mysql.service", "action": "restart"}); err != nil {
		t.Fatal(err)
	}

	for _, unit := range []string{"../../etc/passwd", "nginx", "Nginx.service", "a b.service"} {
		_, err := callService(t, manager, "service.action", map[string]string{"unit": unit, "action": "restart"})
		assertCode(t, err, rpc.CodeInvalidParams)
	}

	if !slices.Equal(manager.acted, []string{"restart pure-ftpd-mysql.service"}) {
		t.Fatalf("acted = %v", manager.acted)
	}

	manager.refuse = &services.Error{Reason: "not_allowed", Message: "Nginx is needed by the panel itself and cannot be stopped"}

	_, err := callService(t, manager, "service.action", map[string]string{"unit": "nginx.service", "action": "stop"})

	agentErr := assertCode(t, err, rpc.CodeActionFailed)
	if data, _ := json.Marshal(agentErr.Data); string(data) != `{"reason":"not_allowed"}` {
		t.Fatalf("data = %s", data)
	}

	key, err := serviceMethods(manager)["service.action"].LockKey(json.RawMessage(`{"unit":"nginx.service","action":"reload"}`))
	if err != nil || key != "service:nginx.service" {
		t.Fatalf("lock key = %q, %v", key, err)
	}

	result, err := callService(t, manager, "service.status", map[string]string{})
	if err != nil || len(result) == 0 {
		t.Fatalf("status = %s, %v", result, err)
	}
}
