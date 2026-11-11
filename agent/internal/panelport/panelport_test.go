package panelport

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

const panelSite = `# rayxo control panel.
server {
    listen 2222 ssl http2;
    listen [::]:2222 ssl http2;
    server_name _;
    error_page 497 =301 https://$host:2222$request_uri;
}
`

type env struct {
	t       *testing.T
	cfg     Config
	calls   []string
	now     time.Time
	busy    map[int]bool
	nginxOK bool
}

func newEnv(t *testing.T) *env {
	t.Helper()

	dir := t.TempDir()
	e := &env{t: t, now: time.Date(2026, 11, 11, 8, 0, 0, 0, time.UTC), busy: map[int]bool{}, nginxOK: true}
	e.cfg = Config{
		SiteFile:  filepath.Join(dir, "rayxo.conf"),
		TrialFile: filepath.Join(dir, "rayxo-panel-trial.conf"),
		PortFile:  filepath.Join(dir, "panel-port"),
		StateFile: filepath.Join(dir, "panel-port-trial.json"),
		Window:    5 * time.Minute,
		Run:       e.run,
		Now:       func() time.Time { return e.now },
		Free:      func(port int) bool { return !e.busy[port] },
	}

	if err := os.WriteFile(e.cfg.SiteFile, []byte(panelSite), 0o644); err != nil {
		t.Fatal(err)
	}

	return e
}

func (e *env) run(_ context.Context, program string, args ...string) ([]byte, error) {
	e.calls = append(e.calls, filepath.Base(program)+" "+strings.Join(args, " "))

	if program == nginxProgram && !e.nginxOK {
		return []byte("emerg"), errors.New("exit status 1")
	}

	return nil, nil
}

func (e *env) manager() *Manager {
	return New(e.cfg)
}

func exists(path string) bool {
	_, err := os.Stat(path)

	return err == nil
}

func confirmed(context.Context, int) error { return nil }

func TestOpenServesThePanelOnTheNewPortToo(t *testing.T) {
	e := newEnv(t)

	trial, err := e.manager().Open(context.Background(), 8443)
	if err != nil {
		t.Fatal(err)
	}

	if trial.Port != 8443 || trial.Previous != 2222 || !trial.ExpiresAt.Equal(e.now.Add(5*time.Minute)) {
		t.Fatalf("unexpected trial %+v", trial)
	}

	site, _ := os.ReadFile(e.cfg.TrialFile)
	for _, want := range []string{"listen 8443 ssl http2;", "listen [::]:8443 ssl http2;", "https://$host:8443$request_uri"} {
		if !strings.Contains(string(site), want) {
			t.Fatalf("trial site lacks %q:\n%s", want, site)
		}
	}

	if strings.Contains(string(site), "2222") {
		t.Fatalf("trial site still mentions 2222:\n%s", site)
	}

	if !slices.Equal(e.calls, []string{"nginx -t", "systemctl reload nginx"}) {
		t.Fatalf("calls = %q", e.calls)
	}

	// The panel's own site is untouched: 2222 keeps working.
	if current, _ := os.ReadFile(e.cfg.SiteFile); string(current) != panelSite {
		t.Fatal("the panel's site changed")
	}

	if pending, err := e.manager().Pending(); err != nil || pending == nil || pending.Port != 8443 {
		t.Fatalf("pending = %+v, %v", pending, err)
	}
}

func TestOpenRefusesPortsThatCannotBeThePanels(t *testing.T) {
	e := newEnv(t)
	e.busy[9000] = true

	for _, tc := range []struct {
		port int
		want error
	}{
		{80, ErrReservedPort},
		{3306, ErrReservedPort},
		{8080, ErrReservedPort},
		{8081, ErrReservedPort},
		{35500, ErrReservedPort},
		{70000, ErrReservedPort},
		{2222, ErrSamePort},
		{9000, ErrPortInUse},
	} {
		if _, err := e.manager().Open(context.Background(), tc.port); !errors.Is(err, tc.want) {
			t.Errorf("Open(%d) = %v, want %v", tc.port, err, tc.want)
		}
	}

	if exists(e.cfg.TrialFile) || len(e.calls) != 0 {
		t.Fatalf("a refused port touched Nginx: %q", e.calls)
	}

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	if _, err := e.manager().Open(context.Background(), 8444); !errors.Is(err, ErrTrialRunning) {
		t.Fatalf("second trial: err = %v, want ErrTrialRunning", err)
	}
}

func TestOpenTakesBackWhatNginxRejects(t *testing.T) {
	e := newEnv(t)
	e.nginxOK = false

	if _, err := e.manager().Open(context.Background(), 8443); !errors.Is(err, ErrConfigRejected) {
		t.Fatalf("err = %v, want ErrConfigRejected", err)
	}

	if exists(e.cfg.TrialFile) || exists(e.cfg.StateFile) {
		t.Fatal("a rejected trial left files behind")
	}
}

func TestConfirmRunsSetupForThePort(t *testing.T) {
	e := newEnv(t)

	if _, err := e.manager().Confirm(context.Background(), confirmed); !errors.Is(err, ErrNoTrial) {
		t.Fatalf("confirm without a trial: err = %v", err)
	}

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	var started []int
	trial, err := e.manager().Confirm(context.Background(), func(_ context.Context, port int) error {
		started = append(started, port)

		return nil
	})
	if err != nil || !trial.Confirmed || !slices.Equal(started, []int{8443}) {
		t.Fatalf("trial = %+v, %v; started %v", trial, err, started)
	}
}

func TestConfirmAfterTheWindowClosesThePort(t *testing.T) {
	e := newEnv(t)

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	e.now = e.now.Add(6 * time.Minute)

	_, err := e.manager().Confirm(context.Background(), func(context.Context, int) error {
		t.Fatal("setup.sh started for an ended trial")

		return nil
	})
	if !errors.Is(err, ErrTrialExpired) || exists(e.cfg.TrialFile) || exists(e.cfg.StateFile) {
		t.Fatalf("err = %v, trial left: %v", err, exists(e.cfg.TrialFile))
	}
}

func TestTickClosesAnUnconfirmedPortWhenTheWindowEnds(t *testing.T) {
	e := newEnv(t)
	notRunning := func(context.Context) bool { return false }

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	e.now = e.now.Add(4 * time.Minute)
	if err := e.manager().Tick(context.Background(), notRunning); err != nil || !exists(e.cfg.TrialFile) {
		t.Fatalf("closed before the window ended: %v", err)
	}

	e.now = e.now.Add(2 * time.Minute)
	if err := e.manager().Tick(context.Background(), notRunning); err != nil || exists(e.cfg.TrialFile) || exists(e.cfg.StateFile) {
		t.Fatalf("still open after the window: %v", err)
	}
}

func TestTickClosesThePortWhenSetupStoppedWithoutMovingThePanel(t *testing.T) {
	e := newEnv(t)

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	if _, err := e.manager().Confirm(context.Background(), confirmed); err != nil {
		t.Fatal(err)
	}

	e.now = e.now.Add(10 * time.Second)
	if err := e.manager().Tick(context.Background(), func(context.Context) bool { return false }); err != nil || !exists(e.cfg.TrialFile) {
		t.Fatal("closed while setup.sh may still be starting")
	}

	e.now = e.now.Add(time.Minute)
	if err := e.manager().Tick(context.Background(), func(context.Context) bool { return true }); err != nil || !exists(e.cfg.TrialFile) {
		t.Fatal("closed while setup.sh runs")
	}

	if err := e.manager().Tick(context.Background(), func(context.Context) bool { return false }); err != nil || exists(e.cfg.TrialFile) {
		t.Fatal("still open after setup.sh failed")
	}
}

func TestTickForgetsAPortSetupMadeThePanels(t *testing.T) {
	e := newEnv(t)

	if _, err := e.manager().Open(context.Background(), 8443); err != nil {
		t.Fatal(err)
	}

	if _, err := e.manager().Confirm(context.Background(), confirmed); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(e.cfg.PortFile, []byte("8443\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	e.calls = nil
	e.now = e.now.Add(time.Minute)

	if err := e.manager().Tick(context.Background(), func(context.Context) bool { return false }); err != nil ||
		exists(e.cfg.StateFile) || len(e.calls) != 0 {
		t.Fatalf("err = %v, state left %v, calls %q", err, exists(e.cfg.StateFile), e.calls)
	}

	if got := e.manager().Current(); got != 8443 {
		t.Fatalf("Current() = %d, want 8443", got)
	}
}

func TestCurrentFallsBackToThePanelsSite(t *testing.T) {
	e := newEnv(t)

	if got := e.manager().Current(); got != 2222 {
		t.Fatalf("Current() = %d, want 2222 from the site", got)
	}
}
