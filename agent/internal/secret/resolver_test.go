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

func TestSecretRefAllowlist(t *testing.T) {
	t.Setenv("PATH", "/usr/bin")
	t.Setenv("STATUS_DB_PASSWORD", "db-secret")
	resolver := NewResolver("")
	if _, err := resolver.Resolve("env://PATH"); err == nil {
		t.Fatal("non STATUS_ environment variable was accepted")
	}
	if _, err := resolver.Resolve("file:///tmp/secret"); err == nil {
		t.Fatal("file secret without secret_file_root was accepted")
	}
	raw := json.RawMessage(`{"headers":{"Authorization":{"secretRef":"env://STATUS_DB_PASSWORD"}},"password":{"secretRef":"env://STATUS_DB_PASSWORD"}}`)
	if _, err := resolver.ResolveJSON(raw); err == nil {
		t.Fatal("secretRef inside headers was accepted")
	}
	resolved, err := resolver.ResolveJSON(json.RawMessage(`{"password":{"secretRef":"env://STATUS_DB_PASSWORD"}}`))
	if err != nil || !strings.Contains(string(resolved), "db-secret") {
		t.Fatal("allowed secretRef was rejected")
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
