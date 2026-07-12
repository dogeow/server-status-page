package secret

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveJSONAndRedact(t *testing.T) {
	t.Setenv("STATUS_TEST_PASSWORD", "highly-secret-value")
	resolver := NewResolver("")
	raw, err := resolver.ResolveJSON(json.RawMessage(`{"password":{"secretRef":"env://STATUS_TEST_PASSWORD"}}`))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(raw), "highly-secret-value") {
		t.Fatal("secret was not resolved")
	}
	if got := resolver.Redact("failed with highly-secret-value"); strings.Contains(got, "highly-secret-value") {
		t.Fatal("secret was not redacted")
	}
}

func TestSecretFileRootContainment(t *testing.T) {
	root := t.TempDir()
	outside := filepath.Join(t.TempDir(), "secret")
	if err := os.WriteFile(outside, []byte("secret"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := NewResolver(root).Resolve("file://" + outside); err == nil {
		t.Fatal("outside secret file was accepted")
	}
}
